<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\Shift;
use App\Models\Supplier;
use App\Services\Audit\AuditLogger;
use App\Services\Calc\Decimal;
use App\Services\Cash\CashMovementService;
use App\Services\Sales\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gastos categorizados (B-14).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad."* Está confirmado en la
 * Parte I con 21/25 y se había quedado sin hito: entra junto al turno de caja,
 * porque **un gasto pagado del cajón es un movimiento de caja**. Registrarlo
 * aparte obligaría a cuadrar dos veces la misma plata, y el arqueo no podría
 * explicar el faltante.
 *
 * Dos separaciones que parecen contables y son operativas:
 *
 *  - **El impuesto va aparte del monto.** El IVA de un gasto es crédito fiscal,
 *    no costo. Sumarlo al gasto infla el costo y desinfla el crédito.
 *  - **El proveedor es de tipo `expense`** (B-12). La luz y el alquiler no son
 *    compras de mercadería; mezclarlos hace que el reporte de compras incluya
 *    la factura del agua.
 */
class ExpenseService
{
    public function __construct(
        private CashMovementService $cash,
        private ExchangeRateService $rates,
        private AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $attributes */
    public function record(array $attributes, ?Shift $shift = null): Expense
    {
        $base = config('pos.base_currency');
        $currency = $attributes['currency_code'] ?? $base;

        $amount = (string) $attributes['amount'];
        $tax = (string) ($attributes['tax_amount'] ?? '0');
        $total = bcadd($amount, $tax, 2);

        $this->assertSupplierIsExpenseKind($attributes['supplier_id'] ?? null);

        $rate = null;
        $totalBase = $total;

        if ($currency !== $base) {
            $rate = $attributes['exchange_rate'] ?? $shift?->exchange_rate ?? $this->rates->current($currency);

            if ($rate === null) {
                throw ValidationException::withMessages([
                    'exchange_rate' => __('sales.missing_exchange_rate', ['currency' => $currency]),
                ]);
            }

            $totalBase = Decimal::format(
                Decimal::mulShift(
                    Decimal::parse($total, Decimal::MONEY),
                    Decimal::parse((string) $rate, Decimal::FX),
                    Decimal::FX
                ),
                Decimal::MONEY
            );
        }

        $method = $attributes['payment_method'] ?? 'cash';

        return DB::transaction(function () use ($attributes, $shift, $currency, $amount, $tax, $total, $totalBase, $rate, $method) {
            $movement = null;

            // Si salió del cajón, sale **como movimiento de caja**: es la misma
            // plata, y el arqueo tiene que poder explicarla.
            if ($method === 'cash') {
                if (! $shift) {
                    throw ValidationException::withMessages([
                        'shift' => __('expense.cash_needs_shift'),
                    ]);
                }

                $movement = $this->cash->record($shift, [
                    'direction' => 'out',
                    'reason' => $attributes['description'],
                    'amount' => $total,
                    'currency_code' => $currency,
                    'exchange_rate' => $rate,
                    'employee_id' => $attributes['employee_id'],
                    'authorized_by' => $attributes['authorized_by'] ?? null,
                ]);
            }

            $expense = Expense::create([
                'branch_id' => $attributes['branch_id'],
                'category_id' => $attributes['category_id'],
                'supplier_id' => $attributes['supplier_id'] ?? null,
                'employee_id' => $attributes['employee_id'],
                'shift_id' => $method === 'cash' ? $shift?->id : null,
                'cash_movement_id' => $movement?->id,
                'document_number' => $attributes['document_number'] ?? null,
                'document_date' => $attributes['document_date'] ?? now()->toDateString(),
                'description' => $attributes['description'],
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'amount' => $amount,
                'tax_amount' => $tax,
                'total' => $total,
                'total_base' => $totalBase,
                'payment_method' => $method,
                'status' => 'recorded',
                'occurred_at' => now(),
                'recorded_at' => now(),
            ]);

            $this->audit->record(
                event: 'expense.recorded',
                entityType: 'expense',
                entityId: $expense->id,
                context: [
                    'description' => $expense->description,
                    'total' => $total,
                    'currency' => $currency,
                    'method' => $method,
                    'paid_from_drawer' => $movement !== null,
                ],
                branchId: $expense->branch_id,
            );

            return $expense;
        });
    }

    /**
     * Anula un gasto.
     *
     * **No se borra ni se edita** (A-05): se marca anulado y, si había salido
     * del cajón, se compensa con una entrada. Es la misma regla que gobierna las
     * ventas y el kardex — el histórico cuenta lo que pasó, incluido el error.
     */
    public function void(Expense $expense, string $employeeId, string $reason, ?Shift $shift = null): Expense
    {
        if ($expense->isVoided()) {
            throw ValidationException::withMessages(['expense' => __('expense.already_voided')]);
        }

        return DB::transaction(function () use ($expense, $employeeId, $reason, $shift) {
            if ($expense->cash_movement_id !== null) {
                $target = $shift ?? Shift::find($expense->shift_id);

                if (! $target || ! $target->isOpen()) {
                    throw ValidationException::withMessages([
                        'shift' => __('expense.void_needs_open_shift'),
                    ]);
                }

                $this->cash->record($target, [
                    'direction' => 'in',
                    'reason' => __('expense.void_reason_prefix').': '.$reason,
                    'amount' => (string) $expense->total,
                    'currency_code' => $expense->currency_code,
                    'exchange_rate' => $expense->exchange_rate,
                    'employee_id' => $employeeId,
                ]);
            }

            $expense->forceFill([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => $employeeId,
            ])->save();

            $this->audit->record(
                event: 'expense.voided',
                entityType: 'expense',
                entityId: $expense->id,
                context: ['reason' => $reason, 'total' => (string) $expense->total],
                branchId: $expense->branch_id,
            );

            return $expense->fresh();
        });
    }

    private function assertSupplierIsExpenseKind(?string $supplierId): void
    {
        if ($supplierId === null) {
            return;
        }

        $supplier = Supplier::find($supplierId);

        if ($supplier && $supplier->kind !== Supplier::EXPENSE) {
            // B-12: mezclarlos hace que el reporte de compras incluya la
            // factura del agua, y entonces el margen deja de significar nada.
            throw ValidationException::withMessages([
                'supplier_id' => __('expense.supplier_must_be_expense_kind'),
            ]);
        }
    }
}
