<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Shift;
use App\Models\Uom;
use App\Services\Calc\Decimal;
use App\Services\Catalog\BarcodeService;
use App\Services\Catalog\UomConversionService;
use App\Services\Dining\ModifierService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El carrito (D-21, H2.5–H2.7).
 *
 * En OSPOS el carrito vivía en `$_SESSION`, con 1.727 líneas de lógica de
 * negocio atadas a la sesión HTTP. Es la peor decisión arquitectónica de aquel
 * proyecto y la que bloquea todo lo demás: ninguna venta podía continuarse en
 * otro dispositivo, ninguna app móvil podía consumir el flujo y ningún proceso
 * podía validarla.
 *
 * Aquí el carrito es una **venta en estado `draft` con identidad propia**, y el
 * terminal guarda además su copia local. El identificador lo genera el terminal,
 * no el servidor: de eso dependen el modo degradado y la idempotencia del ERP.
 *
 * Suspender es cambiar de estado, no copiar a cuatro tablas espejo (D-01).
 */
class CartService
{
    public function __construct(
        private SaleCalculationService $calculation,
        private UomConversionService $uoms,
        private BarcodeService $barcodes,
        private ModifierService $modifiers,
    ) {}

    /**
     * Abre un carrito.
     *
     * `id` llega del terminal (D-21, precondición 1). Si no viene se genera
     * aquí, pero el camino normal es que el terminal ya lo tenga: necesita poder
     * armar una venta sin consultar al servidor.
     *
     * @param  array<string,mixed>  $context
     */
    public function open(array $context): Sale
    {
        return Sale::create([
            'id' => $context['id'] ?? (string) Str::uuid7(),
            'branch_id' => $context['branch_id'],
            'terminal_id' => $context['terminal_id'],
            // El turno se resuelve solo: es del equipo, y la caja ya sabe cuál
            // tiene abierto. Pedírselo al terminal sería una oportunidad de
            // equivocarse sin ganancia.
            'shift_id' => $context['shift_id'] ?? $this->resolveShift($context['terminal_id']),
            'employee_id' => $context['employee_id'],
            'customer_id' => $context['customer_id'] ?? null,
            'sale_type' => $context['sale_type'] ?? 'counter',
            'status' => Sale::STATUS_DRAFT,
            'currency_code' => $context['currency_code'] ?? config('pos.base_currency'),
            'exchange_rate' => $context['exchange_rate'] ?? null,
            'opened_at' => now(),
        ]);
    }

    /**
     * Agrega un producto del catálogo.
     *
     * Un producto compuesto se **explota** en sus componentes (D-14): el ERP no
     * necesita saber qué es un combo, y así el POS puede definirlos aunque el
     * ERP nunca los soporte.
     *
     * @param  array<string,mixed>  $options
     * @return array<int,SaleLine>
     */
    public function addProduct(Sale $sale, Product $product, string $qty, array $options = []): array
    {
        $this->assertOpen($sale);

        if ($product->is_composite && ! $product->sells_as_pack) {
            return $this->addComposite($sale, $product, $qty, $options);
        }

        /*
         * Modificadores (B-06): se validan **antes** de crear la línea, porque
         * un obligatorio sin responder no puede llegar a cocina, y su precio se
         * dobla dentro del unitario para que el motor de cálculo vea un solo
         * precio, con el impuesto adentro, como cualquier otro.
         */
        $chosen = $this->modifiers->validate($product, $options['modifiers'] ?? []);
        $unitPrice = $options['unit_price'] ?? (string) $product->price;
        $unitPrice = bcadd($unitPrice, $this->modifiers->priceDelta($chosen), 2);

        $line = $this->addLine($sale, [
            'product_id' => $product->id,
            'item_code' => $product->sku,
            'description' => $product->name,
            'kind' => SaleLine::KIND_PRODUCT,
            'uom_id' => $options['uom_id'] ?? $product->uom_id,
            'qty' => $this->normalizeQty($product, $qty, $options),
            'unit_price' => $unitPrice,
            'unit_cost' => (string) $product->cost,
            'is_exempt' => $product->isExempt(),
            'discount_type' => $options['discount_type'] ?? null,
            'discount_value' => $options['discount_value'] ?? null,
            'lot_id' => $options['lot_id'] ?? null,
            'location_id' => $options['location_id'] ?? null,
            'notes' => $options['notes'] ?? null,
            'course' => $options['course'] ?? 1,
        ]);

        if ($chosen->isNotEmpty()) {
            $this->modifiers->attach($line, $chosen);
        }

        return [$line];
    }

    /**
     * Agrega lo que salga de un lector.
     *
     * Un código de balanza trae el peso o el precio dentro (B-04): la etiqueta
     * no dice "una unidad de queso", dice "queso, 0,847 kg".
     *
     * @return array<int,SaleLine>
     */
    public function addScanned(Sale $sale, string $code, ?string $qty = null): array
    {
        $this->assertOpen($sale);

        $resolved = $this->barcodes->resolve($code);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'code' => __('catalog.barcode_not_found', ['code' => $code]),
            ]);
        }

        $product = $resolved['barcode']->product;
        $productUom = $resolved['barcode']->productUom;

        // Código con precio embebido: la balanza ya decidió cuánto cobrar, así
        // que la línea es de una unidad a ese precio.
        if ($resolved['amount'] !== null) {
            return [$this->addLine($sale, [
                'product_id' => $product->id,
                'item_code' => $product->sku,
                'description' => $product->name,
                'kind' => SaleLine::KIND_PRODUCT,
                'uom_id' => $product->uom_id,
                'qty' => '1',
                'unit_price' => $resolved['amount'],
                'unit_cost' => (string) $product->cost,
                'is_exempt' => $product->isExempt(),
            ])];
        }

        // La cantidad tecleada en la caja (`3*` y el lector) manda sobre el 1
        // por defecto, pero **nunca** sobre el peso que trae la etiqueta: la
        // balanza ya pesó y multiplicar eso por tres cobraría 2,5 kg de queso
        // como si fueran siete y medio.
        $qty = $resolved['qty'] ?? $qty ?? '1';

        $options = [];
        if ($productUom !== null) {
            $options['uom_id'] = $productUom->uom_id;
            $options['unit_price'] = $this->uoms->priceFor($product, $productUom);
        }

        return $this->addProduct($sale, $product, $qty, $options);
    }

    /**
     * Ítem temporal: algo que no está en el catálogo (D-03).
     *
     * El cajero **debe** darle nombre y descripción; sin eso el aviso al
     * supervisor diría que se vendió "algo" por 500 córdobas, que no sirve de
     * nada. El control del límite diario vive en `SpecialLineGuard`.
     */
    public function addTemporary(Sale $sale, string $description, string $qty, string $unitPrice): SaleLine
    {
        $this->assertOpen($sale);

        return $this->addLine($sale, [
            'product_id' => null,
            'description' => $description,
            'kind' => SaleLine::KIND_TEMPORARY,
            'qty' => $qty,
            'unit_price' => $unitPrice,
        ]);
    }

    /** Venta por monto: "cobro 500 de mano de obra" (D-03). */
    public function addAmount(Sale $sale, string $description, string $amount): SaleLine
    {
        $this->assertOpen($sale);

        return $this->addLine($sale, [
            'product_id' => null,
            'description' => $description,
            'kind' => SaleLine::KIND_AMOUNT,
            'qty' => '1',
            'unit_price' => $amount,
        ]);
    }

    /** @param array<string,mixed> $changes */
    public function updateLine(SaleLine $line, array $changes): SaleLine
    {
        $sale = $line->sale;
        $this->assertOpen($sale);

        $line->fill(array_intersect_key($changes, array_flip([
            'qty', 'unit_price', 'description', 'discount_type', 'discount_value',
            'lot_id', 'location_id', 'uom_id',
            // El curso se corrige hasta que la línea sale a cocina: "el postre
            // va después" se dice en la mesa, no al tomar la orden.
            'course', 'notes',
        ])))->save();

        $this->calculation->recalculate($sale);

        return $line->fresh();
    }

    public function removeLine(SaleLine $line): void
    {
        $sale = $line->sale;
        $this->assertOpen($sale);

        DB::transaction(function () use ($line, $sale) {
            $line->delete();
            $this->resequence($sale);
        });

        $this->calculation->recalculate($sale->fresh());
    }

    /**
     * Descuento sobre el total de la venta.
     *
     * El reparto proporcional entre líneas lo hace el motor, no este servicio:
     * es aritmética verificada por los fixtures, y duplicarla aquí sería crear
     * un tercer motor que también podría divergir.
     */
    public function setSaleDiscount(Sale $sale, ?string $type, ?string $value, ?string $authorizedBy = null): Sale
    {
        $this->assertOpen($sale);

        $sale->forceFill([
            'sale_discount_type' => $type,
            'sale_discount_value' => $value,
            'discount_authorized_by' => $authorizedBy,
        ])->save();

        $this->calculation->recalculate($sale);

        return $sale->fresh();
    }

    /**
     * Fija la propina de la cuenta (G-16).
     *
     * Se guarda en la venta y **no toca ningún importe fiscal**: el motor la
     * suma a lo que hay que cobrar y la deja fuera del total. Recalcular sí hace
     * falta, porque el saldo y el vuelto cambian.
     *
     * El signo lo manda el ticket: en una devolución todas las cifras son
     * negativas y la propina también, porque se entrega en vez de cobrarse. Y se
     * exige un tope —el propio total— porque la propina se teclea a mano en la
     * hora pico: 3.500 donde iban 35 es un error que se descubre al cuadrar el
     * cajón, cuando el cliente ya se fue.
     */
    public function setTip(Sale $sale, string $amount, ?string $employeeId = null): Sale
    {
        $this->assertOpen($sale);

        $tip = Decimal::parse($amount, Decimal::MONEY);
        $total = Decimal::parse((string) $sale->total, Decimal::MONEY);

        // El signo lo manda el ticket: una devolución tiene todas sus cifras en
        // negativo y su propina también, porque se entrega, no se cobra. Pedirlo
        // al revés es siempre un error de quien llama.
        $wrongSign = Decimal::cmp($tip, '0') !== 0
            && Decimal::isNegative($tip) !== Decimal::isNegative($total);

        if ($wrongSign) {
            throw ValidationException::withMessages([
                'amount' => __('sales.tip_wrong_sign'),
            ]);
        }

        if (Decimal::cmp(Decimal::abs($tip), Decimal::abs($total)) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('sales.tip_over_total'),
            ]);
        }

        $sale->forceFill([
            'tip_amount' => Decimal::format($tip, Decimal::MONEY),
            'tip_employee_id' => $employeeId ?? $sale->waiter_employee_id,
        ])->save();

        $this->calculation->recalculate($sale);

        return $sale->fresh();
    }

    public function setCustomer(Sale $sale, ?string $customerId): Sale
    {
        $this->assertOpen($sale);

        $sale->forceFill(['customer_id' => $customerId])->save();

        // El cliente cambia el impuesto cuando está exonerado (Q-07), así que
        // no alcanza con guardarlo: hay que recalcular.
        $this->calculation->recalculate($sale);

        return $sale->fresh();
    }

    /**
     * Suspende la venta (D-01).
     *
     * Es un cambio de estado, no una copia a tablas espejo. En perfil
     * restaurante esto mismo se llama "abrir cuenta", y `label` lleva la mesa o
     * el nombre del cliente.
     */
    public function suspend(Sale $sale, ?string $label = null): Sale
    {
        $this->assertOpen($sale);

        // Una venta vacía no se suspende… salvo que sea la cuenta de una mesa.
        // En el salón, sentarse y pedir cinco minutos después es lo normal, y la
        // mesa tiene que verse ocupada desde que el grupo se sienta (F1-B).
        if ($sale->lines()->count() === 0 && $sale->dining_table_id === null) {
            throw ValidationException::withMessages([
                'sale' => __('sales.cannot_suspend_empty'),
            ]);
        }

        $sale->forceFill([
            'status' => Sale::STATUS_SUSPENDED,
            'label' => $label ?? $sale->label,
        ])->save();

        return $sale->fresh();
    }

    /** Retoma una venta suspendida en **cualquier** terminal de la sucursal. */
    public function resume(Sale $sale, string $terminalId, ?string $employeeId = null): Sale
    {
        if ($sale->status !== Sale::STATUS_SUSPENDED) {
            throw ValidationException::withMessages([
                'sale' => __('sales.not_suspended'),
            ]);
        }

        $sale->forceFill([
            'status' => Sale::STATUS_DRAFT,
            // La venta se continúa donde esté el cliente, no donde empezó: eso
            // es lo que el carrito en sesión HTTP hacía imposible.
            'terminal_id' => $terminalId,
            'employee_id' => $employeeId ?? $sale->employee_id,
        ])->save();

        return $sale->fresh();
    }

    /**
     * Explota un combo en sus componentes (D-14, §4 del contrato).
     *
     * Las líneas llevan el precio de lista de cada parte y la diferencia hasta
     * el precio del combo baja como descuento repartido. Así la factura muestra
     * qué se llevó **y** cuánto se ahorró, en vez de un precio inventado por
     * componente que nadie sabría explicar.
     *
     * @param  array<string,mixed>  $options
     * @return array<int,SaleLine>
     */
    private function addComposite(Sale $sale, Product $product, string $qty, array $options): array
    {
        $product->loadMissing('components.component.taxCode');

        if ($product->components->isEmpty()) {
            throw ValidationException::withMessages([
                'product_id' => __('catalog.composite_without_components', ['name' => $product->name]),
            ]);
        }

        $groupRef = (string) Str::uuid7();
        $parentQty = Decimal::parse($qty, Decimal::QTY);

        $drafts = [];
        $weights = [];

        foreach ($product->components as $component) {
            $part = $component->component;
            $lineQty = Decimal::divRoundHalfUp(
                bcmul($parentQty, Decimal::parse((string) $component->qty, Decimal::QTY), 0),
                Decimal::pow10(Decimal::QTY)
            );

            $unitPrice = Decimal::parse((string) $part->price, Decimal::PRICE);
            $gross = Decimal::mulShift($lineQty, $unitPrice, Decimal::QTY + Decimal::PRICE - Decimal::MONEY);

            $drafts[] = [
                'product' => $part,
                'qty' => Decimal::format($lineQty, Decimal::QTY),
                'unit_price' => Decimal::format($unitPrice, Decimal::PRICE),
            ];
            $weights[] = $gross;
        }

        $listTotal = Decimal::sum($weights);
        $packTotal = Decimal::mulShift(
            $parentQty,
            Decimal::parse((string) $product->price, Decimal::PRICE),
            Decimal::QTY + Decimal::PRICE - Decimal::MONEY
        );

        $difference = Decimal::sub($listTotal, $packTotal);

        // Un combo más caro que sus partes no es un combo: se deja sin
        // descuento en vez de inventar un recargo que nadie pidió.
        $shares = Decimal::cmp($difference, '0') > 0
            ? Decimal::distribute($difference, $weights)
            : array_fill(0, count($drafts), '0');

        $lines = [];

        foreach ($drafts as $index => $draft) {
            $part = $draft['product'];
            $share = $shares[$index] ?? '0';

            $lines[] = $this->addLine($sale, [
                'product_id' => $part->id,
                'item_code' => $part->sku,
                'description' => $part->name,
                'kind' => SaleLine::KIND_PRODUCT,
                'uom_id' => $part->uom_id,
                'qty' => $draft['qty'],
                'unit_price' => $draft['unit_price'],
                'unit_cost' => (string) $part->cost,
                'is_exempt' => $part->isExempt(),
                'discount_type' => Decimal::cmp($share, '0') > 0 ? 'amount' : null,
                'discount_value' => Decimal::cmp($share, '0') > 0
                    ? Decimal::format($share, Decimal::MONEY)
                    : null,
                'location_id' => $options['location_id'] ?? null,
                'group_ref' => $groupRef,
                'group_name' => $product->name,
            ]);
        }

        return $lines;
    }

    /**
     * Turno abierto de la terminal.
     *
     * H4.1: **nada se registra fuera de un turno abierto**. Se puede apagar por
     * configuración (`pos_require_shift` del ERP) porque hay negocios chicos que
     * no llevan turno, pero el defecto es exigirlo — sin turno, el arqueo no
     * tiene a qué compararse.
     */
    private function resolveShift(string $terminalId): ?string
    {
        $shiftId = Shift::where('terminal_id', $terminalId)
            ->where('status', 'open')
            ->value('id');

        // La configuración del ERP gana sobre la local cuando existe (P3): es el
        // ERP quien decide si este negocio lleva turno.
        $requireShift = (bool) app(SettingsRepository::class)
            ->get('pos.require_shift', config('pos.require_shift'));

        if ($shiftId === null && $requireShift) {
            throw ValidationException::withMessages([
                'shift' => __('shift.none_open'),
            ]);
        }

        return $shiftId;
    }

    /** @param array<string,mixed> $attributes */
    private function addLine(Sale $sale, array $attributes): SaleLine
    {
        $line = DB::transaction(function () use ($sale, $attributes) {
            $sequence = (int) SaleLine::where('sale_id', $sale->id)->max('sequence') + 1;

            return SaleLine::create(array_merge([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'sequence' => $sequence,
            ], $attributes));
        });

        $this->calculation->recalculate($sale->fresh());

        return $line->fresh();
    }

    /** @param array<string,mixed> $options */
    private function normalizeQty(Product $product, string $qty, array $options): string
    {
        $uomId = $options['uom_id'] ?? $product->uom_id;
        $decimals = $product->uom_id === $uomId
            ? ($product->uom?->decimals ?? 0)
            : (Uom::find($uomId)?->decimals ?? 0);

        $this->uoms->assertQtyAllowed($qty, $decimals);

        return $qty;
    }

    /** Las líneas quedan numeradas 1..n tras borrar una del medio. */
    private function resequence(Sale $sale): void
    {
        $sequence = 1;

        foreach (SaleLine::where('sale_id', $sale->id)->orderBy('sequence')->get() as $line) {
            $line->forceFill(['sequence' => $sequence++])->save();
        }
    }

    private function assertOpen(Sale $sale): void
    {
        if ($sale->isClosed()) {
            throw ValidationException::withMessages([
                'sale' => __('sales.already_closed'),
            ]);
        }
    }
}
