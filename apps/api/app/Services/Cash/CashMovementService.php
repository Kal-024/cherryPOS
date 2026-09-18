<?php

namespace App\Services\Cash;

use App\Models\CashMovement;
use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use App\Services\Calc\Decimal;
use App\Services\Sales\ExchangeRateService;
use Illuminate\Validation\ValidationException;

/**
 * Entradas y salidas de caja (H4.4).
 *
 * Todo lo que entra o sale del cajón sin ser una venta: un retiro parcial
 * cuando se acumula mucho efectivo, un fondo adicional para dar vuelto, el pago
 * de un gasto menor.
 *
 * **El motivo es obligatorio.** Un retiro sin motivo es exactamente la fila que
 * aparece cuando el arqueo no cuadra y nadie sabe explicar.
 */
class CashMovementService
{
    public function __construct(
        private ExchangeRateService $rates,
        private AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $attributes */
    public function record(Shift $shift, array $attributes): CashMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('shift.already_closed')]);
        }

        $base = config('pos.base_currency');
        $currency = $attributes['currency_code'] ?? $base;
        $amount = (string) $attributes['amount'];

        if (Decimal::cmp(Decimal::parse($amount, Decimal::MONEY), '0') <= 0) {
            throw ValidationException::withMessages(['amount' => __('cash.amount_must_be_positive')]);
        }

        $rate = null;
        $amountBase = $amount;

        if ($currency !== $base) {
            // La tasa del turno, no la de ahora: el turno ya la congeló al abrir.
            $rate = $attributes['exchange_rate'] ?? $shift->exchange_rate ?? $this->rates->current($currency);

            if ($rate === null) {
                throw ValidationException::withMessages([
                    'exchange_rate' => __('sales.missing_exchange_rate', ['currency' => $currency]),
                ]);
            }

            $amountBase = Decimal::format(
                Decimal::mulShift(
                    Decimal::parse($amount, Decimal::MONEY),
                    Decimal::parse((string) $rate, Decimal::FX),
                    Decimal::FX
                ),
                Decimal::MONEY
            );
        }

        $movement = CashMovement::create([
            'shift_id' => $shift->id,
            'branch_id' => $shift->branch_id,
            'employee_id' => $attributes['employee_id'],
            'authorized_by' => $attributes['authorized_by'] ?? null,
            'direction' => $attributes['direction'],
            'reason' => $attributes['reason'],
            'amount' => $amount,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'amount_base' => $amountBase,
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);

        $this->audit->record(
            event: 'cash.'.$attributes['direction'],
            entityType: 'cash_movement',
            entityId: $movement->id,
            context: [
                'shift_id' => $shift->id,
                'reason' => $attributes['reason'],
                'amount' => $amount,
                'currency' => $currency,
            ],
            authorizedBy: $attributes['authorized_by'] ?? null,
            branchId: $shift->branch_id,
        );

        return $movement;
    }
}
