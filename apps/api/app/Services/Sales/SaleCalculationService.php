<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleLineTax;
use App\Services\Calc\Decimal;
use App\Services\Calc\SaleCalculator;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Puente entre la venta persistida y el motor de cálculo.
 *
 * El motor (`SaleCalculator`) no sabe nada de Eloquent ni de configuración: toma
 * arrays y devuelve arrays, exactamente la forma de los fixtures compartidos.
 * Esa frontera es a propósito — es lo que permite que PHP y TypeScript verifiquen
 * las mismas fórmulas sin que ninguno arrastre el mundo del otro.
 *
 * Este servicio hace las dos traducciones: arma la entrada desde la venta y su
 * configuración, y **congela** el resultado en las filas. Los impuestos quedan
 * persistidos por línea y nunca se recalculan después del cierre (D-16).
 */
class SaleCalculationService
{
    public function __construct(
        private SaleCalculator $calculator,
        private SettingsRepository $settings,
    ) {}

    /**
     * Recalcula la venta abierta y guarda el resultado.
     *
     * @return array<string,mixed> el resultado completo del motor
     */
    public function recalculate(Sale $sale): array
    {
        $sale->loadMissing('lines.product.taxCode', 'customer', 'payments');

        $result = $this->calculator->calculate($this->buildInput($sale));

        DB::transaction(function () use ($sale, $result) {
            $byId = collect($result['lines'])->keyBy('id');

            foreach ($sale->lines as $line) {
                $computed = $byId->get($line->id);

                if ($computed === null) {
                    continue;
                }

                $line->forceFill([
                    'gross' => $computed['gross'],
                    'line_discount' => $computed['lineDiscount'],
                    'sale_discount_share' => $computed['saleDiscountShare'],
                    'taxable_base' => $computed['taxableBase'],
                    'tax_total' => $computed['taxTotal'],
                    'total' => $computed['total'],
                ])->save();

                // Se reescriben enteros en vez de reconciliar: mientras la venta
                // está abierta el detalle es desechable, y comparar impuesto por
                // impuesto costaría más que rehacerlos.
                SaleLineTax::where('sale_line_id', $line->id)->delete();

                foreach ($computed['taxes'] as $tax) {
                    SaleLineTax::create([
                        'sale_line_id' => $line->id,
                        'tax_code_id' => $this->taxCodeId($line),
                        'code' => $tax['code'],
                        'rate' => $tax['rate'],
                        'base' => $tax['base'],
                        'amount' => $tax['amount'],
                    ]);
                }
            }

            $sale->forceFill([
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
                'paid' => $result['paid'],
                'balance' => $result['balance'],
            ])->save();
        });

        return $result;
    }

    /** @return array<string,mixed> */
    public function buildInput(Sale $sale): array
    {
        $branchId = $sale->branch_id;
        $terminalId = $sale->terminal_id;

        return [
            'config' => [
                'currency' => $sale->currency_code,
                // Régimen de cuota fija: apaga el traslado de IVA en todo el
                // sistema, no producto por producto (Q-07).
                'fixedQuotaRegime' => (bool) $this->settings->get('tax.fixed_quota_regime', false, $branchId, $terminalId),
                'cashRounding' => [
                    'mode' => (string) $this->settings->get(
                        'cash.rounding_mode',
                        config('pos.cash_rounding_mode'),
                        $branchId,
                        $terminalId
                    ),
                    'increment' => (string) $this->settings->get(
                        'cash.rounding_increment',
                        config('pos.cash_rounding_increment'),
                        $branchId,
                        $terminalId
                    ),
                ],
            ],
            'customer' => [
                // Exoneración del comprador, distinta de la exención del bien.
                'taxExempt' => (bool) ($sale->customer?->is_tax_exempt ?? false),
            ],
            'lines' => $sale->lines->map(fn ($line) => array_filter([
                'id' => $line->id,
                'qty' => (string) $line->qty,
                'unitPrice' => (string) $line->unit_price,
                'discount' => $line->discount_type !== null ? [
                    'type' => $line->discount_type,
                    'value' => $this->discountValue($line->discount_type, $line->discount_value),
                ] : null,
                'taxes' => $this->taxesFor($line),
                'exempt' => $this->isExempt($line),
            ], static fn ($value) => $value !== null))->all(),
            'saleDiscount' => $sale->sale_discount_type !== null ? [
                'type' => $sale->sale_discount_type,
                'value' => $this->discountValue($sale->sale_discount_type, $sale->sale_discount_value),
            ] : null,
            // La propina viaja al motor pero no vuelve a las columnas fiscales:
            // el motor la suma a `due` y la echa fuera de `total` (G-16).
            'tip' => Decimal::format(
                Decimal::parse((string) ($sale->tip_amount ?? '0'), Decimal::MONEY),
                Decimal::MONEY
            ),
            'payments' => $sale->payments->map(fn ($payment) => array_filter([
                'method' => $payment->method,
                'currency' => $payment->currency_code,
                'amount' => (string) $payment->amount,
                'rate' => $payment->exchange_rate !== null ? (string) $payment->exchange_rate : null,
            ], static fn ($value) => $value !== null))->all(),
        ];
    }

    /**
     * Lleva el valor del descuento a la escala que el motor espera.
     *
     * La columna guarda cuatro decimales porque un porcentaje los usa, pero un
     * descuento **en monto** es dinero y el motor lo exige al centavo: leer
     * `21.8200` y pasárselo tal cual lo hace fallar, con razón. Redondear en la
     * frontera es responsabilidad de quien produce el dato, y la frontera es
     * exactamente este método.
     */
    private function discountValue(string $type, mixed $value): string
    {
        $scale = $type === 'percent' ? Decimal::RATE : Decimal::MONEY;

        return Decimal::format(
            Decimal::divRoundHalfUp(
                Decimal::parse((string) $value, Decimal::RATE),
                Decimal::pow10(Decimal::RATE - $scale)
            ),
            $scale
        );
    }

    /**
     * Impuestos de la línea, con su base.
     *
     * `base` viaja al motor porque **es propiedad del impuesto, no de la
     * instalación**: así lo modela `tax_codes` de cherryB, y copiarlo es lo que
     * mantiene `tax_difference` en cero.
     *
     * Un código exento no produce impuesto pero **sí** marca la línea como
     * exenta, que es otra cosa que no tener impuesto configurado.
     *
     * @return array<int,array{code:string,rate:string,base:string}>
     */
    private function taxesFor($line): array
    {
        $taxCode = $line->product?->taxCode;

        if ($taxCode === null || $taxCode->is_exempt) {
            return [];
        }

        return [[
            'code' => $taxCode->code,
            'rate' => (string) $taxCode->rate,
            'base' => $taxCode->base ?? 'net',
        ]];
    }

    private function isExempt($line): bool
    {
        return (bool) $line->is_exempt || (bool) ($line->product?->isExempt() ?? false);
    }

    private function taxCodeId($line): ?string
    {
        return $line->product?->tax_code_id;
    }
}
