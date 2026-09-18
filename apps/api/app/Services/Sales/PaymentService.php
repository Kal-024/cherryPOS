<?php

namespace App\Services\Sales;

use App\Models\Payment;
use App\Models\Sale;
use App\Services\Calc\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pagos de una venta (B-02, H3.1–H3.2).
 *
 * Tabla aparte del encabezado: varios pagos por venta, con medio, referencia y
 * saldo pendiente calculado. Mitad efectivo y mitad tarjeta es lo normal, no la
 * excepción.
 *
 * **Doble moneda (Q-06).** En Nicaragua el cliente entrega dólares, se cobra a
 * la tasa del día y el vuelto sale en córdobas. Cada pago guarda la moneda
 * recibida, la tasa aplicada y el equivalente en moneda base — sin la tasa
 * persistida, releer el ticket de hace un mes daría otro número.
 */
class PaymentService
{
    public function __construct(
        private SaleCalculationService $calculation,
        private ExchangeRateService $rates,
    ) {}

    /** @param array<string,mixed> $attributes */
    public function register(Sale $sale, array $attributes): Payment
    {
        if ($sale->isClosed()) {
            throw ValidationException::withMessages(['sale' => __('sales.already_closed')]);
        }

        $baseCurrency = config('pos.base_currency');
        $currency = $attributes['currency_code'] ?? $baseCurrency;
        $amount = (string) $attributes['amount'];

        $rate = null;
        $amountBase = $amount;

        if ($currency !== $baseCurrency) {
            // La tasa del pago la fija el turno, no el momento: un turno que
            // abrió con 36,62 cobra todo el día a 36,62, aunque el supervisor
            // cargue otra a media tarde.
            $rate = $attributes['exchange_rate']
                ?? $sale->exchange_rate
                ?? $this->rates->current($currency);

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

        $payment = DB::transaction(fn () => Payment::create([
            'sale_id' => $sale->id,
            'branch_id' => $sale->branch_id,
            'shift_id' => $sale->shift_id,
            'method' => $attributes['method'],
            'currency_code' => $currency,
            'amount' => $amount,
            'exchange_rate' => $rate,
            'amount_base' => $amountBase,
            'is_change' => $attributes['is_change'] ?? false,
            'reference' => $attributes['reference'] ?? null,
            'card_brand' => $attributes['card_brand'] ?? null,
            'authorization_code' => $attributes['authorization_code'] ?? null,
            'paid_at' => now(),
        ]));

        $this->calculation->recalculate($sale->fresh());

        return $payment->fresh();
    }

    public function remove(Payment $payment): void
    {
        $sale = $payment->sale;

        if ($sale->isClosed()) {
            throw ValidationException::withMessages(['sale' => __('sales.already_closed')]);
        }

        $payment->delete();
        $this->calculation->recalculate($sale->fresh());
    }
}
