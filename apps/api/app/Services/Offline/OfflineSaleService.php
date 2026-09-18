<?php

namespace App\Services\Offline;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\Audit\AuditLogger;
use App\Services\Calc\Decimal;
use App\Services\Calc\SaleCalculator;
use App\Services\Erp\ErpOutboxService;
use App\Services\Inventory\LotAllocationService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Sales\DocumentNumberService;
use App\Services\Settings\SettingsRepository;
use App\Services\Supervision\SupervisorNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recepción de tickets cerrados sin conexión (H6.2).
 *
 * El terminal vendió con el servidor apagado: armó el ticket completo, lo numeró
 * con su bloque reservado (H6.3) y lo calculó con el motor de
 * `packages/calc`. Cuando la red vuelve, lo manda acá.
 *
 * **Tres reglas, y las tres vienen de decisiones ya tomadas:**
 *
 *  1. **Es un hecho consumado, no una propuesta.** La venta se cobró y el dinero
 *     está en el cajón. El servidor la registra; no la aprueba. Es exactamente
 *     la relación que el POS tiene con el ERP (§2.A del contrato), un nivel más
 *     abajo.
 *  2. **La idempotencia es del UUID.** El terminal reintenta por diseño, así que
 *     recibir dos veces el mismo ticket devuelve lo mismo y no duplica nada.
 *  3. **Los totales se recalculan y se comparan.** Si el motor de TypeScript y
 *     el de PHP no coinciden, es la misma alarma que `tax_difference` con el
 *     ERP: se registra, se avisa y **mandan los del servidor**. Nunca se corrige
 *     en silencio.
 */
class OfflineSaleService
{
    public function __construct(
        private SaleCalculator $calculator,
        private DocumentNumberService $numbers,
        private StockLedgerService $stock,
        private LotAllocationService $lots,
        private ErpOutboxService $outbox,
        private SettingsRepository $settings,
        private SupervisorNotifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $ticket
     * @return array{sale: Sale, duplicate: bool, difference: string}
     */
    public function receive(array $ticket, Terminal $terminal): array
    {
        $existing = Sale::find($ticket['id']);

        if ($existing) {
            // Reintento: se responde lo mismo que la primera vez. Si fuera un
            // error, el terminal reintentaría para siempre algo ya hecho.
            return ['sale' => $existing, 'duplicate' => true, 'difference' => '0.00'];
        }

        $this->assertWithinOfflineWindow($ticket, $terminal);

        $saleType = $ticket['sale_type'] ?? 'counter';
        $this->numbers->consumeReserved($terminal, $saleType, $ticket['number']);

        $result = $this->calculator->calculate($this->calculatorInput($ticket, $terminal));
        $difference = $this->difference($ticket, $result);

        $sale = DB::transaction(function () use ($ticket, $terminal, $saleType, $result, $difference) {
            $sale = $this->persist($ticket, $terminal, $saleType, $result, $difference);

            $this->moveStock($sale);

            return $sale;
        });

        if (bccomp($difference, '0', 2) !== 0) {
            $this->reportDifference($sale, $ticket, $result, $difference);
        }

        $this->audit->record(
            event: 'sale.offline_received',
            entityType: 'sale',
            entityId: $sale->id,
            context: [
                'number' => $sale->number,
                'closed_at' => $ticket['closed_at'],
                'received_at' => now()->toIso8601String(),
                'total_difference' => $difference,
            ],
            branchId: $terminal->branch_id,
        );

        // Fuera de la transacción: encolar es consecuencia del registro, no
        // condición de él (P4).
        $this->outbox->enqueue($sale);

        return ['sale' => $sale, 'duplicate' => false, 'difference' => $difference];
    }

    /**
     * La ventana de operación sin conexión (H6.5).
     *
     * Pasado `pos_offline_max_hours` el ticket **se rechaza**: choca con el
     * cierre de período y con precios e impuestos que ya cambiaron. Es el mismo
     * límite que el ERP declara en `cmn_company_settings` (§7 del contrato).
     *
     * @param  array<string,mixed>  $ticket
     */
    private function assertWithinOfflineWindow(array $ticket, Terminal $terminal): void
    {
        $maxHours = (int) $this->settings->get(
            'pos.offline_max_hours',
            config('pos.offline_max_hours'),
            $terminal->branch_id,
            $terminal->id
        );

        $closedAt = Carbon::parse($ticket['closed_at']);

        if ($closedAt->diffInHours(now()) > $maxHours) {
            throw ValidationException::withMessages([
                'closed_at' => __('offline.window_expired', ['hours' => (string) $maxHours]),
            ]);
        }

        if ($closedAt->isFuture()) {
            // El reloj del terminal se adelantó. Aceptarlo metería una venta con
            // fecha futura en el libro, que nadie va a poder explicar.
            throw ValidationException::withMessages([
                'closed_at' => __('offline.closed_in_the_future'),
            ]);
        }
    }

    /**
     * Arma la entrada del motor desde el ticket, **resolviendo los impuestos
     * contra el catálogo del servidor**.
     *
     * No se confía en las tasas que mandó el terminal: su catálogo cacheado
     * puede estar viejo. Si difieren, la diferencia sale en la comparación de
     * totales, que es justo lo que interesa detectar.
     *
     * @param  array<string,mixed>  $ticket
     * @return array<string,mixed>
     */
    private function calculatorInput(array $ticket, Terminal $terminal): array
    {
        $products = Product::with('taxCode')
            ->whereIn('id', collect($ticket['lines'])->pluck('product_id')->filter())
            ->get()
            ->keyBy('id');

        return [
            'config' => [
                'currency' => $ticket['currency_code'] ?? config('pos.base_currency'),
                'fixedQuotaRegime' => (bool) $this->settings->get(
                    'tax.fixed_quota_regime', false, $terminal->branch_id, $terminal->id
                ),
                'cashRounding' => [
                    'mode' => (string) $this->settings->get(
                        'cash.rounding_mode', config('pos.cash_rounding_mode'), $terminal->branch_id
                    ),
                    'increment' => (string) $this->settings->get(
                        'cash.rounding_increment', config('pos.cash_rounding_increment'), $terminal->branch_id
                    ),
                ],
            ],
            'customer' => ['taxExempt' => (bool) ($ticket['customer_tax_exempt'] ?? false)],
            'lines' => collect($ticket['lines'])->map(function (array $line, int $index) use ($products) {
                $product = $line['product_id'] ? $products->get($line['product_id']) : null;
                $taxCode = $product?->taxCode;

                return array_filter([
                    'id' => (string) $index,
                    'qty' => (string) $line['qty'],
                    'unitPrice' => (string) $line['unit_price'],
                    'discount' => isset($line['discount_type']) && $line['discount_type'] !== null
                        ? ['type' => $line['discount_type'], 'value' => (string) $line['discount_value']]
                        : null,
                    'taxes' => $taxCode === null || $taxCode->is_exempt
                        ? []
                        : [['code' => $taxCode->code, 'rate' => (string) $taxCode->rate, 'base' => $taxCode->base ?? 'net']],
                    'exempt' => (bool) ($product?->isExempt() ?? ($line['is_exempt'] ?? false)),
                ], static fn ($value) => $value !== null);
            })->values()->all(),
            'saleDiscount' => isset($ticket['sale_discount_type']) && $ticket['sale_discount_type'] !== null
                ? ['type' => $ticket['sale_discount_type'], 'value' => (string) $ticket['sale_discount_value']]
                : null,
            // La propina viajó con el ticket y el servidor la recalcula igual
            // que el resto: si el terminal la sumó al total en vez de dejarla
            // fuera, `difference` lo delata (G-16).
            'tip' => (string) ($ticket['tip_amount'] ?? '0'),
            'payments' => collect($ticket['payments'] ?? [])->map(fn (array $payment) => array_filter([
                'method' => $payment['method'],
                'currency' => $payment['currency_code'] ?? null,
                'amount' => (string) $payment['amount'],
                'rate' => isset($payment['exchange_rate']) ? (string) $payment['exchange_rate'] : null,
            ], static fn ($value) => $value !== null))->values()->all(),
        ];
    }

    /**
     * Diferencia entre lo que calculó el terminal y lo que calcula el servidor.
     *
     * **Distinta de cero es alarma.** Significa que los dos motores están
     * divergiendo, y una diferencia sistemática se repetirá en cada ticket
     * offline hasta que alguien mire por qué.
     *
     * @param  array<string,mixed>  $ticket
     * @param  array<string,mixed>  $result
     */
    private function difference(array $ticket, array $result): string
    {
        $declared = (string) ($ticket['totals']['total'] ?? $result['total']);

        return bcsub($result['total'], $declared, 2);
    }

    /**
     * @param  array<string,mixed>  $ticket
     * @param  array<string,mixed>  $result
     */
    private function persist(array $ticket, Terminal $terminal, string $saleType, array $result, string $difference): Sale
    {
        $branch = Branch::findOrFail($terminal->branch_id);

        // El turno de la terminal en el momento del cierre. Sin turno abierto la
        // venta se registra igual: el ticket es un hecho, y negarlo dejaría la
        // plata sin explicación en el cajón.
        $shiftId = Shift::where('terminal_id', $terminal->id)
            ->where('status', 'open')
            ->value('id');

        $sale = new Sale;
        $sale->forceFill([
            'id' => $ticket['id'],
            'branch_id' => $branch->id,
            'terminal_id' => $terminal->id,
            'shift_id' => $shiftId,
            'employee_id' => $ticket['employee_id'],
            'customer_id' => $ticket['customer_id'] ?? null,
            'sale_type' => $saleType,
            'status' => Sale::STATUS_COMPLETED,
            'number' => $ticket['number'],
            'currency_code' => $ticket['currency_code'] ?? config('pos.base_currency'),
            'exchange_rate' => $ticket['exchange_rate'] ?? null,
            'gross' => $result['gross'],
            'line_discount_total' => $result['lineDiscountTotal'],
            'sale_discount' => $result['saleDiscount'],
            'discount_total' => $result['discountTotal'],
            'subtotal' => $result['subtotal'],
            'taxable_base' => $result['taxableBase'],
            'exempt_total' => $result['exemptTotal'],
            'tax_total' => $result['taxTotal'],
            'total' => $result['total'],
            'cash_rounding' => $result['cashRounding'],
            'tip_amount' => $result['tip'],
            'tip_employee_id' => $ticket['tip_employee_id'] ?? null,
            'paid' => $result['paid'],
            'balance' => $result['balance'],
            'sale_discount_type' => $ticket['sale_discount_type'] ?? null,
            'sale_discount_value' => $ticket['sale_discount_value'] ?? null,
            'opened_at' => $ticket['opened_at'] ?? $ticket['closed_at'],
            // El momento del hecho y el de su registro son distintos, y los dos
            // importan (precondición 4).
            'closed_at' => $ticket['closed_at'],
            'recorded_at' => now(),
        ])->save();

        foreach ($result['lines'] as $index => $computed) {
            $source = $ticket['lines'][$index];

            $line = SaleLine::create([
                'sale_id' => $sale->id,
                'branch_id' => $branch->id,
                'sequence' => $index + 1,
                'product_id' => $source['product_id'] ?? null,
                'item_code' => $source['item_code'] ?? null,
                'description' => $source['description'],
                'kind' => $source['kind'] ?? 'product',
                'uom_id' => $source['uom_id'] ?? null,
                'qty' => $source['qty'],
                'unit_price' => $source['unit_price'],
                'discount_type' => $source['discount_type'] ?? null,
                'discount_value' => $source['discount_value'] ?? null,
                'line_discount' => $computed['lineDiscount'],
                'sale_discount_share' => $computed['saleDiscountShare'],
                'gross' => $computed['gross'],
                'taxable_base' => $computed['taxableBase'],
                'tax_total' => $computed['taxTotal'],
                'total' => $computed['total'],
                'is_exempt' => $source['is_exempt'] ?? false,
                'location_id' => $source['location_id'] ?? null,
                'group_ref' => $source['group_ref'] ?? null,
                'group_name' => $source['group_name'] ?? null,
            ]);

            foreach ($computed['taxes'] as $tax) {
                $line->taxes()->create([
                    'code' => $tax['code'],
                    'rate' => $tax['rate'],
                    'base' => $tax['base'],
                    'amount' => $tax['amount'],
                ]);
            }
        }

        foreach ($ticket['payments'] ?? [] as $payment) {
            $sale->payments()->create([
                'branch_id' => $branch->id,
                'shift_id' => $shiftId,
                'method' => $payment['method'],
                'currency_code' => $payment['currency_code'] ?? $sale->currency_code,
                'amount' => $payment['amount'],
                'exchange_rate' => $payment['exchange_rate'] ?? null,
                'amount_base' => $payment['amount_base'] ?? $payment['amount'],
                'is_change' => false,
                'reference' => $payment['reference'] ?? null,
                'paid_at' => $payment['paid_at'] ?? $ticket['closed_at'],
            ]);
        }

        // El vuelto, como pago negativo: sin esa fila el arqueo esperaría
        // encontrar en el cajón todo el efectivo recibido.
        if (bccomp($result['change'], '0', 2) > 0) {
            $sale->payments()->create([
                'id' => (string) Str::uuid7(),
                'branch_id' => $branch->id,
                'shift_id' => $shiftId,
                'method' => 'cash',
                'currency_code' => config('pos.base_currency'),
                'amount' => bcmul($result['change'], '-1', 2),
                'amount_base' => bcmul($result['change'], '-1', 2),
                'is_change' => true,
                'paid_at' => $ticket['closed_at'],
            ]);
        }

        $sale->forceFill(['erp_tax_difference' => $difference])->save();

        return $sale->fresh();
    }

    /** @param array<string,mixed> $sale */
    private function moveStock(Sale $sale): void
    {
        $sale->loadMissing('lines.product');
        $sign = $sale->sale_type === 'refund' ? '1' : '-1';

        foreach ($sale->lines as $line) {
            $product = $line->product;

            if ($product === null || ! $product->tracks_stock || $line->kind !== SaleLine::KIND_PRODUCT) {
                continue;
            }

            $locationId = $line->location_id ?? DB::table('inv_locations')
                ->where('branch_id', $sale->branch_id)
                ->where('is_sales_default', true)
                ->value('id');

            if ($locationId === null) {
                continue;
            }

            $qty = Decimal::abs(Decimal::parse((string) $line->qty, Decimal::QTY));

            $allocations = $product->tracks_lots && $line->lot_id === null && $sign === '-1'
                ? $this->lots->allocate($product->id, $locationId, Decimal::format($qty, Decimal::QTY))
                : [['lot_id' => $line->lot_id, 'qty' => Decimal::format($qty, Decimal::QTY)]];

            foreach ($allocations as $allocation) {
                $this->stock->record([
                    'branch_id' => $sale->branch_id,
                    'location_id' => $locationId,
                    'product_id' => $product->id,
                    'lot_id' => $allocation['lot_id'],
                    'reason' => $sale->sale_type === 'refund' ? 'refund' : 'sale',
                    'qty' => bcmul($allocation['qty'], $sign, 4),
                    'unit_cost' => $line->unit_cost,
                    'source_type' => 'sale',
                    'source_id' => $sale->id,
                    'employee_id' => $sale->employee_id,
                    // El movimiento lleva la fecha del hecho, no la del registro:
                    // el kardex tiene que contar cuándo salió la mercadería.
                    'occurred_at' => $sale->closed_at,
                ]);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $ticket
     * @param  array<string,mixed>  $result
     */
    private function reportDifference(Sale $sale, array $ticket, array $result, string $difference): void
    {
        $this->notifier->notify(
            event: 'offline.total_difference',
            title: __('offline.difference_title'),
            body: __('offline.difference_body', [
                'number' => (string) $sale->number,
                'difference' => $difference,
            ]),
            context: [
                'sale_id' => $sale->id,
                'terminal_total' => (string) ($ticket['totals']['total'] ?? ''),
                'server_total' => $result['total'],
                'difference' => $difference,
            ],
            severity: 'critical',
            entityType: 'sale',
            entityId: $sale->id,
        );
    }
}
