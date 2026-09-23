<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\CreditEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Services\Audit\AuditLogger;
use App\Services\Calc\Decimal;
use App\Services\Erp\ErpOutboxService;
use App\Services\Inventory\LotAllocationService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierre de la venta.
 *
 * El momento en que un carrito se convierte en documento. A partir de aquí la
 * venta es **inmutable** (P1): si hay un error, se corrige con un documento de
 * reverso, no editando este. El disparador de la base lo impone.
 *
 * El orden importa y no es arbitrario:
 *
 *  1. Recalcular con los pagos puestos — el total manda, no lo que diga el
 *     terminal.
 *  2. Comprobar que el ticket está saldado.
 *  3. Cargar el crédito, si lo hubo.
 *  4. Asignar número.
 *  5. Descontar existencias.
 *  6. Cerrar.
 *  7. Encolar hacia el ERP.
 *
 * El envío al ERP va **al final y fuera de la transacción de negocio**: P4 dice
 * que el POS nunca depende del ERP para cerrar una venta. Si la cola falla, el
 * ticket está cobrado igual y el dinero está en el cajón.
 */
class SaleCloser
{
    public function __construct(
        private SaleCalculationService $calculation,
        private DocumentNumberService $numbers,
        private StockLedgerService $stock,
        private LotAllocationService $lots,
        private ErpOutboxService $outbox,
        private AuditLogger $audit,
        private RefundService $refunds,
    ) {}

    public function close(Sale $sale): Sale
    {
        if ($sale->isClosed()) {
            throw ValidationException::withMessages(['sale' => __('sales.already_closed')]);
        }

        $sale->loadMissing('lines.product', 'payments', 'customer.creditAccount');

        if ($sale->lines->isEmpty()) {
            throw ValidationException::withMessages(['sale' => __('sales.cannot_close_empty')]);
        }

        $result = $this->calculation->recalculate($sale);
        $sale->refresh();

        $this->assertSettled($sale, $result);
        $this->assertRefundFitsOriginal($sale);

        DB::transaction(function () use ($sale, $result) {
            $this->recordChange($sale, $result['change']);
            $this->chargeCredit($sale);

            $branch = Branch::findOrFail($sale->branch_id);
            $number = $this->numbers->next($sale->branch_id, $branch->code, $sale->sale_type);

            $this->moveStock($sale);

            $sale->forceFill([
                'number' => $number,
                'status' => Sale::STATUS_COMPLETED,
                'closed_at' => now(),
            ])->save();
        });

        $sale->refresh();

        $this->audit->record(
            event: 'sale.closed',
            entityType: 'sale',
            entityId: $sale->id,
            context: [
                'number' => $sale->number,
                'total' => (string) $sale->total,
                'currency' => $sale->currency_code,
                'lines' => $sale->lines->count(),
            ],
            branchId: $sale->branch_id,
        );

        // Fuera de la transacción: encolar es una consecuencia del cierre, no
        // una condición de él (P4).
        $this->outbox->enqueue($sale);

        return $sale;
    }

    /**
     * Registra el vuelto como un pago **negativo** en efectivo.
     *
     * Es plata que sale del cajón, y si no queda asentada el arqueo esperaría
     * encontrar el efectivo recibido completo. Con la fila negativa, el
     * esperado es la simple suma de los pagos en efectivo — que es lo que el
     * cajón realmente tiene.
     */
    /**
     * Una devolución no puede exceder lo que se vendió.
     *
     * Se comprueba **al cerrar**, que es cuando el dinero sale del cajón: hasta
     * ahí el cajero puede corregir el carrito. Sin esta cuenta, tres
     * devoluciones parciales de una unidad vacían un ticket de dos y el mismo
     * producto se paga dos veces sin que nada lo denuncie hasta el arqueo.
     */
    private function assertRefundFitsOriginal(Sale $sale): void
    {
        if ($sale->sale_type !== 'refund' || $sale->reverses_sale_id === null) {
            return;
        }

        $original = Sale::with('lines')->find($sale->reverses_sale_id);

        if (! $original) {
            return;
        }

        $this->refunds->assertWithinOriginal($original, $sale->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'qty' => (string) $line->qty,
        ])->all());
    }

    private function recordChange(Sale $sale, string $change): void
    {
        if (bccomp($change, '0', 2) <= 0) {
            return;
        }

        Payment::create([
            'sale_id' => $sale->id,
            'branch_id' => $sale->branch_id,
            'shift_id' => $sale->shift_id,
            'method' => 'cash',
            // El vuelto sale siempre en moneda base, aunque hayan pagado en
            // dólares (Q-06).
            'currency_code' => config('pos.base_currency'),
            'amount' => bcmul($change, '-1', 2),
            'exchange_rate' => null,
            'amount_base' => bcmul($change, '-1', 2),
            'is_change' => true,
            'paid_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function assertSettled(Sale $sale, array $result): void
    {
        $balance = Decimal::parse($result['balance'], Decimal::MONEY);

        if (Decimal::cmp($balance, '0') === 0) {
            return;
        }

        // Un presupuesto no se cobra: es una cotización, y exigirle pagos sería
        // impedir que exista.
        if ($sale->sale_type === 'quote') {
            return;
        }

        throw ValidationException::withMessages([
            'payments' => __('sales.balance_pending', [
                'balance' => $result['balance'],
            ]),
        ]);
    }

    /**
     * Carga la venta a la cuenta del cliente (P-02, G-10).
     *
     * **El límite bloquea la venta**, no solo advierte (Q-10). Y el bloqueo
     * manual del supervisor se suma: una cuenta puede estar dentro del límite y
     * aun así bloqueada por mora.
     */
    private function chargeCredit(Sale $sale): void
    {
        $credit = $sale->payments->firstWhere('method', 'credit');

        if ($credit === null) {
            return;
        }

        $customer = $sale->customer;
        $account = $customer?->creditAccount;

        if ($account === null) {
            throw ValidationException::withMessages([
                'payments' => __('sales.credit_without_account'),
            ]);
        }

        $amount = (string) $credit->amount_base;

        if (! $account->canCharge($amount)) {
            throw ValidationException::withMessages([
                'payments' => $account->is_blocked
                    ? __('sales.credit_account_blocked')
                    : __('sales.credit_limit_exceeded', ['available' => $account->available()]),
            ]);
        }

        CreditEntry::create([
            'account_id' => $account->id,
            'branch_id' => $sale->branch_id,
            'kind' => 'charge',
            'amount' => $amount,
            'sale_id' => $sale->id,
            'employee_id' => $sale->employee_id,
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);

        $account->balance = bcadd((string) $account->balance, $amount, 2);
        $account->save();
    }

    /**
     * Descuenta existencias.
     *
     * Solo lo que lleva stock: los servicios no (D-07), y el ítem temporal
     * tampoco —no está en el catálogo, por definición—.
     *
     * Con lotes y sin uno elegido, sale **primero el que vence antes** (G-07):
     * en farmacia y alimentos, sacar el lote de atrás es cómo se llega a vender
     * mercadería vencida.
     */
    private function moveStock(Sale $sale): void
    {
        $sign = $sale->sale_type === 'refund' ? '1' : '-1';

        foreach ($sale->lines as $line) {
            $product = $line->product;

            if ($product === null || ! $product->tracks_stock || $line->kind !== SaleLine::KIND_PRODUCT) {
                continue;
            }

            $locationId = $line->location_id ?? $this->defaultLocation($sale->branch_id);
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
                    'occurred_at' => now(),
                ]);
            }
        }
    }

    private function defaultLocation(string $branchId): string
    {
        $location = DB::table('inv_locations')
            ->where('branch_id', $branchId)
            ->where('is_sales_default', true)
            ->value('id');

        if ($location === null) {
            throw ValidationException::withMessages([
                'location' => __('inventory.no_default_location'),
            ]);
        }

        return $location;
    }
}
