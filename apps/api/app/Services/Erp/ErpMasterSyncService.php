<?php

namespace App\Services\Erp;

use App\Models\Barcode;
use App\Models\Customer;
use App\Models\ErpReconciliationEntry;
use App\Models\Product;
use App\Models\TaxCode;
use App\Models\Uom;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Lectura de maestros desde el ERP (F1-C, §12 del contrato).
 *
 * **Con ERP presente el dato maestro es del ERP y el POS solo lo lee** (P2). Lo
 * que baja pisa lo que el ERP manda y **no toca lo que es del POS**: estación de
 * preparación, modificadores, códigos de barras adicionales, ranking de uso.
 * Esos campos no existen del otro lado y perderlos en cada sincronización haría
 * la integración inservible para un restaurante.
 *
 * La fusión va por **referencia opaca → clave natural → alta**, nunca por
 * nombre (Q-04): dos "Distribuidora González" son dos empresas hasta que una
 * cédula diga lo contrario, y una fusión equivocada mezcla el crédito de dos
 * clientes — se descubre cuando uno reclama que le cobraron la deuda del otro.
 *
 * Lo que no case queda en la bandeja y **no bloquea**: el producto sigue
 * vendiéndose y el cliente sigue comprando (§12.8).
 */
class ErpMasterSyncService
{
    /** De a 200, nunca `all`: un tirón de tres mil filas es lo que la VPN no aguanta. */
    private const PAGE = 200;

    public function __construct(private SettingsRepository $settings) {}

    public function configured(): bool
    {
        return ! empty(config('pos.erp.base_url')) && ! empty(config('pos.erp.token'));
    }

    /**
     * @return array{status:string, products?:array<string,int>, customers?:array<string,int>, error?:string}
     */
    public function pull(string $branchId): array
    {
        if (! $this->configured()) {
            return ['status' => 'unconfigured'];
        }

        $products = $this->pullProducts($branchId);

        if ($products['status'] !== 'ok') {
            return $products;
        }

        $customers = $this->pullCustomers($branchId);

        if ($customers['status'] !== 'ok') {
            return $customers;
        }

        return [
            'status' => 'ok',
            'products' => $products['counts'],
            'customers' => $customers['counts'],
        ];
    }

    /** @return array{status:string, counts?:array<string,int>, error?:string} */
    private function pullProducts(string $branchId): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'pending' => 0];
        $since = $this->settings->get('erp.products_synced_at');
        $mark = null;

        foreach ($this->pages('/product', $since) as $page) {
            if ($page['status'] !== 'ok') {
                return $page;
            }

            $mark ??= $page['server_time'];

            foreach ($page['data'] as $row) {
                $result = $this->mergeProduct($row, $branchId);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
        }

        // La marca la pone el ERP (§12.3): el reloj del POS abriría una ventana
        // de cambios invisibles del tamaño de la diferencia entre relojes.
        if ($mark !== null) {
            $this->settings->set('erp.products_synced_at', $mark, 'string', 'business', null, 'erp');
        }

        // Solo tras una lectura **completa** se sabe qué quedó sin par: en una
        // incremental, lo que no vino es simplemente lo que no cambió. Es el
        // caso de la migración inicial, cuando el ERP se activa sobre un POS
        // que ya venía vendiendo (§12.8).
        if ($since === null) {
            $counts['pending'] += $this->flagOrphanProducts($branchId);
        }

        return ['status' => 'ok', 'counts' => $counts];
    }

    /** @return array{status:string, counts?:array<string,int>, error?:string} */
    private function pullCustomers(string $branchId): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'pending' => 0];
        $since = $this->settings->get('erp.customers_synced_at');
        $mark = null;

        foreach ($this->pages('/customer', $since) as $page) {
            if ($page['status'] !== 'ok') {
                return $page;
            }

            $mark ??= $page['server_time'];

            foreach ($page['data'] as $row) {
                $result = $this->mergeCustomer($row, $branchId);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
        }

        if ($mark !== null) {
            $this->settings->set('erp.customers_synced_at', $mark, 'string', 'business', null, 'erp');
        }

        if ($since === null) {
            $counts['pending'] += $this->flagOrphanCustomers($branchId);
        }

        return ['status' => 'ok', 'counts' => $counts];
    }

    /**
     * Recorre las páginas del ERP.
     *
     * @return \Generator<int,array{status:string, data?:array<int,array<string,mixed>>, server_time?:string, error?:string}>
     */
    private function pages(string $path, mixed $since): \Generator
    {
        $page = 1;

        do {
            $query = ['page' => $page, 'per_page' => self::PAGE];

            if ($since !== null) {
                $query['updated_since'] = $since;
            }

            try {
                $response = Http::withToken((string) config('pos.erp.token'))
                    ->withHeaders(['X-Company-Id' => (string) config('pos.erp.company_id')])
                    ->acceptJson()
                    ->timeout(30)
                    ->get(rtrim((string) config('pos.erp.base_url'), '/').$path, $query);
            } catch (ConnectionException $e) {
                // El enlace caído no impide vender (P4): se corta la lectura y
                // la marca no avanza, así que la próxima corrida retoma desde
                // donde estaba.
                yield ['status' => 'unreachable', 'error' => $e->getMessage()];

                return;
            }

            if (! $response->successful()) {
                yield ['status' => 'error', 'error' => (string) ($response->json('message') ?? $response->status())];

                return;
            }

            yield [
                'status' => 'ok',
                'data' => (array) ($response->json('data') ?? []),
                // La fecha del servidor del ERP, en UTC y con sufijo `Z`: un
                // `+00:00` sin codificar viaja como espacio en la consulta
                // siguiente y vuelve como 422.
                'server_time' => $this->serverTime($response->header('Date')),
            ];

            $last = (int) ($response->json('meta.last_page') ?? 1);
            $page++;
        } while ($page <= $last);
    }

    private function serverTime(?string $header): string
    {
        $moment = $header ? Carbon::parse($header) : now();

        return $moment->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Fusiona un producto del ERP.
     *
     * @param  array<string,mixed>  $row
     * @return string `created`, `updated` o `pending`
     */
    private function mergeProduct(array $row, string $branchId): string
    {
        $erpId = $row['id'] ?? null;
        $sku = $this->text($row['code_internal'] ?? null);
        $barcode = $this->text($row['barcode'] ?? null);

        if ($sku === null && $barcode === null) {
            $this->flag($branchId, ErpReconciliationEntry::KIND_PRODUCT, null,
                (string) ($row['name'] ?? '—'), null, ErpReconciliationEntry::NO_KEY,
                ['erp_id' => $erpId]);

            return 'pending';
        }

        $local = Product::where('erp_product_id', $erpId)->first()
            ?? ($sku ? Product::where('sku', $sku)->first() : null)
            ?? ($barcode ? Barcode::where('code', $barcode)->first()?->product : null);

        $attributes = array_filter([
            'sku' => $sku,
            'name' => $this->text($row['name'] ?? null),
            'description' => $this->text($row['description'] ?? null),
            'price' => isset($row['current_price']) ? (string) $row['current_price'] : null,
            'uom_id' => $this->uomId($row),
            'min_stock' => isset($row['min_stock']) ? (string) $row['min_stock'] : null,
            'erp_product_id' => $erpId,
        ], fn ($value) => $value !== null);

        // El precio viene con el impuesto adentro y se copia tal cual (§12.6):
        // convertirlo introduce un redondeo propio que reaparece después como
        // `tax_difference` sin que nadie sepa de dónde salió.
        $attributes['is_exempt'] = (bool) ($row['is_tax_exempt'] ?? false);
        $attributes['is_active'] = (bool) ($row['is_active'] ?? true);

        if ($attributes['is_exempt']) {
            $attributes['tax_code_id'] = TaxCode::where('code', 'EXE')->value('id');
        }

        if ($local && $local->erp_product_id !== null && $local->erp_product_id !== $erpId) {
            // El mismo código interno apunta a otro producto del ERP: cambiar la
            // referencia en silencio movería el histórico de ventas de un
            // producto a otro.
            $this->flag($branchId, ErpReconciliationEntry::KIND_PRODUCT, $local->id, $local->name,
                $sku ?? $barcode, ErpReconciliationEntry::CONFLICT,
                ['erp_id' => $erpId, 'already_linked_to' => $local->erp_product_id]);

            return 'pending';
        }

        if ($local) {
            // Se actualiza **solo lo del ERP**: `prep_station`, modificadores,
            // atributos por rubro y códigos adicionales son del POS y
            // sobreviven (§12.1).
            $local->update($attributes);
            $this->ensureBarcode($local, $barcode);

            return 'updated';
        }

        $created = Product::create($attributes + [
            'uom_id' => $attributes['uom_id'] ?? Uom::where('code', 'UND')->value('id'),
            'tracks_stock' => true,
            'cost' => '0',
        ]);

        $this->ensureBarcode($created, $barcode);

        return 'created';
    }

    /**
     * Fusiona un cliente del ERP.
     *
     * @param  array<string,mixed>  $row
     */
    private function mergeCustomer(array $row, string $branchId): string
    {
        $erpId = $row['id'] ?? null;
        $taxId = $this->text($row['tax_id'] ?? null);
        $name = $this->text($row['legal_name'] ?? null) ?? '—';

        $local = Customer::where('erp_customer_id', $erpId)->first();

        if (! $local && $taxId === null) {
            // Sin cédula no hay fusión posible: dos nombres iguales son dos
            // personas distintas (Q-04, B-11).
            $this->flag($branchId, ErpReconciliationEntry::KIND_CUSTOMER, null, $name, null,
                ErpReconciliationEntry::NO_KEY, ['erp_id' => $erpId]);

            return 'pending';
        }

        if (! $local && $taxId !== null) {
            // Una sola fila posible: `cmn_persons.national_id` es único y
            // `crm_customers.person_id` también, así que el POS no puede tener
            // dos clientes con la misma cédula. La ambigüedad que Q-04 teme la
            // impide el esquema, no una comprobación.
            $local = Customer::whereHas('person', fn ($q) => $q->where('national_id', $taxId))->first();
        }

        // La cédula apunta a alguien que ya está fusionado con **otro** cliente
        // del ERP. Pisarlo mezclaría dos historiales: queda para que una persona
        // decida cuál es cuál (§12.8).
        if ($local && $local->erp_customer_id !== null && $local->erp_customer_id !== $erpId) {
            $this->flag($branchId, ErpReconciliationEntry::KIND_CUSTOMER, $local->id, $name, $taxId,
                ErpReconciliationEntry::CONFLICT,
                ['erp_id' => $erpId, 'already_linked_to' => $local->erp_customer_id]);

            return 'pending';
        }

        $person = [
            'full_name' => $name,
            'national_id' => $taxId,
            'email' => $this->text($row['email'] ?? null),
            'phone' => $this->text($row['phone'] ?? null),
        ];

        $role = array_filter([
            'code' => $this->text($row['code'] ?? null),
            'is_tax_exempt' => $row['is_tax_exempt'] ?? null,
            'tax_exempt_reference' => $this->text($row['exemption_number'] ?? null),
            'erp_customer_id' => $erpId,
        ], fn ($value) => $value !== null);

        if ($local) {
            return DB::transaction(function () use ($local, $person, $role) {
                $local->update($role);
                $local->person?->update(array_filter($person, fn ($value) => $value !== null));

                return 'updated';
            });
        }

        // El cliente del ERP nace **con cuenta** cuando trae cédula: es la clase
        // que puede comprar a crédito (P-03). El límite viaja, el saldo no: lo
        // mueve la caja y el ERP lo reconstruye con los tickets (§12.5).
        Customer::createWithPerson(
            array_filter($person, fn ($value) => $value !== null),
            $role + ['kind' => $taxId !== null ? 'account' : 'cash', 'is_active' => true]
        );

        return 'created';
    }

    /**
     * Lo que el POS tiene y el ERP no.
     *
     * **No se manda al ERP**: el POS nunca escribe maestros (P2). Queda en la
     * bandeja para que alguien lo cargue allá o lo dé de baja acá, y mientras
     * tanto se sigue vendiendo (§12.8).
     */
    private function flagOrphanProducts(string $branchId): int
    {
        $orphans = Product::whereNull('erp_product_id')->where('is_active', true)->get();

        foreach ($orphans as $product) {
            $this->flag($branchId, ErpReconciliationEntry::KIND_PRODUCT, $product->id, $product->name,
                $product->sku, ErpReconciliationEntry::NO_MATCH, ['sku' => $product->sku]);
        }

        return $orphans->count();
    }

    private function flagOrphanCustomers(string $branchId): int
    {
        $orphans = Customer::with('person')
            ->whereNull('erp_customer_id')
            ->where('is_active', true)
            ->get();

        foreach ($orphans as $customer) {
            $this->flag($branchId, ErpReconciliationEntry::KIND_CUSTOMER, $customer->id, $customer->name,
                $customer->national_id, ErpReconciliationEntry::NO_MATCH,
                ['national_id' => $customer->national_id]);
        }

        return $orphans->count();
    }

    private function ensureBarcode(Product $product, ?string $code): void
    {
        if ($code === null) {
            return;
        }

        // El ERP guarda **uno**; el POS puede tener tres del mismo ítem, así que
        // se agrega sin tocar los demás (§12.1).
        Barcode::firstOrCreate(
            ['code' => $code],
            ['product_id' => $product->id, 'embedded' => 'none', 'is_primary' => true]
        );
    }

    /** @param array<string,mixed> $rowData */
    private function uomId(array $rowData): ?string
    {
        $code = $rowData['uom']['code'] ?? null;

        return $code ? Uom::where('code', $code)->value('id') : null;
    }

    /** @param array<string,mixed> $detail */
    private function flag(
        string $branchId,
        string $kind,
        ?string $entityId,
        string $label,
        ?string $naturalKey,
        string $reason,
        array $detail,
    ): void {
        ErpReconciliationEntry::updateOrCreate(
            ['branch_id' => $branchId, 'kind' => $kind, 'entity_id' => $entityId, 'reason' => $reason],
            ['label' => $label, 'natural_key' => $naturalKey, 'detail' => $detail, 'resolved_at' => null],
        );
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === '' ? null : $text;
    }
}
