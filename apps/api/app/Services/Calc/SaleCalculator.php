<?php

namespace App\Services\Calc;

use InvalidArgumentException;

/**
 * Motor de cálculo de una venta.
 *
 * El orden de las operaciones **es** el activo: descuento de línea, descuento
 * de venta repartido, base imponible, impuesto, y solo al final el redondeo de
 * efectivo. Cualquier permutación produce otro total, y es donde casi todo POS
 * nuevo se equivoca (D-02).
 *
 * Espejo exacto de `packages/calc/src/engine.ts`. Ningún cambio entra aquí sin
 * su caso en `packages/calc/fixtures/`, y el fixture se escribe antes.
 *
 * **Por qué arrays y no objetos de dominio.** La entrada y la salida son
 * exactamente la forma de los fixtures compartidos. Un DTO intermedio sería una
 * traducción más donde las dos implementaciones podrían separarse sin que
 * ninguna prueba lo note.
 */
final class SaleCalculator
{
    /** `qty × price` está a escala 8; el dinero a 2. */
    private const SHIFT_QTY_PRICE = Decimal::QTY + Decimal::PRICE - Decimal::MONEY;

    /** Un porcentaje divide entre 100 y desescala la tasa. */
    private const SHIFT_RATE = Decimal::RATE + 2;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function calculate(array $input): array
    {
        $config = $input['config'] ?? throw new InvalidArgumentException('Falta `config`.');
        $fixedQuota = (bool) ($config['fixedQuotaRegime'] ?? false);
        $customerExempt = (bool) ($input['customer']['taxExempt'] ?? false);
        $sourceLines = $input['lines'] ?? [];

        // ── 1. Bruto y descuento de línea ────────────────────────────────────
        $lines = [];
        foreach ($sourceLines as $source) {
            $qty = Decimal::parse($source['qty'], Decimal::QTY);
            $unitPrice = Decimal::parse($source['unitPrice'], Decimal::PRICE);
            $gross = Decimal::mulShift($qty, $unitPrice, self::SHIFT_QTY_PRICE);

            $lines[] = [
                'id' => (string) $source['id'],
                'gross' => $gross,
                'lineDiscount' => $this->resolveDiscount($source['discount'] ?? null, $gross),
                'saleDiscountShare' => '0',
                'taxableBase' => '0',
                'taxes' => [],
                'taxTotal' => '0',
                'total' => '0',
                // Exenta por naturaleza del bien o por exoneración del
                // comprador. **No** lo es una venta bajo cuota fija: esa es no
                // gravada, que fiscalmente es otra cosa aunque el total
                // coincida.
                'exempt' => false,
            ];
        }

        // ── 2. Descuento de venta, repartido proporcionalmente ───────────────
        // El peso es el importe ya neto de descuento de línea: una línea que ya
        // tuvo su rebaja no debe absorber además la parte mayor del descuento
        // general.
        $weights = array_map(
            static fn (array $l): string => Decimal::sub($l['gross'], $l['lineDiscount']),
            $lines
        );
        $netAfterLineDiscounts = Decimal::sum($weights);
        $saleDiscount = $this->resolveDiscount($input['saleDiscount'] ?? null, $netAfterLineDiscounts);
        $shares = Decimal::distribute($saleDiscount, $weights);

        foreach ($lines as $index => $_) {
            $lines[$index]['saleDiscountShare'] = $shares[$index] ?? '0';
        }

        // ── 3. Impuestos, línea por línea ────────────────────────────────────
        foreach ($sourceLines as $index => $source) {
            $line = $lines[$index];
            $amount = Decimal::sub(
                Decimal::sub($line['gross'], $line['lineDiscount']),
                $line['saleDiscountShare']
            );

            $rates = $source['taxes'] ?? [];

            $line['exempt'] = ($source['exempt'] ?? false) === true || $customerExempt;

            $untaxed = $fixedQuota || $line['exempt'] || count($rates) === 0;

            $includesTax = array_reduce(
                $rates,
                static fn (bool $carry, array $tax): bool => $carry || ($tax['base'] ?? 'net') === 'gross',
                false
            );

            if ($untaxed) {
                // Sin impuesto el precio es el precio, venga incluido o
                // excluido: no hay nada que extraer ni que agregar.
                $line['taxableBase'] = $amount;
                $line['taxTotal'] = '0';
                $line['total'] = $amount;
            } elseif ($includesTax) {
                // Basta con que un impuesto de la línea se declare `gross`:
                // mezclarlos en una misma línea es un error de configuración,
                // no un caso a soportar.
                $line = $this->applyIncludedTaxes($line, $amount, $rates);
            } else {
                $line = $this->applyExcludedTaxes($line, $amount, $rates);
            }

            $lines[$index] = $line;
        }

        // ── 4. Totales del ticket ────────────────────────────────────────────
        $gross = Decimal::sum(array_column($lines, 'gross'));
        $lineDiscountTotal = Decimal::sum(array_column($lines, 'lineDiscount'));
        $subtotal = Decimal::sum(array_column($lines, 'taxableBase'));
        $exemptTotal = Decimal::sum(array_column(
            array_filter($lines, static fn (array $l): bool => $l['exempt']),
            'taxableBase'
        ));
        $taxableBase = Decimal::sub($subtotal, $exemptTotal);
        $taxTotal = Decimal::sum(array_column($lines, 'taxTotal'));
        $total = Decimal::add($subtotal, $taxTotal);

        // ── 5. Redondeo de efectivo y cobro ──────────────────────────────────
        $cashTotal = $this->applyCashRounding($total, $config['cashRounding'] ?? null);
        $payments = $input['payments'] ?? [];

        // "Total en efectivo redondeado; total en otros medios sin redondear"
        // (B-09). El redondeo solo rige cuando el ticket entero se salda en
        // efectivo: en cuanto entra una tarjeta, el importe exacto es cobrable y
        // redondear sería regalar o cobrar de más sin motivo.
        $allCash = count($payments) === 0;
        if (! $allCash) {
            $allCash = array_reduce(
                $payments,
                static fn (bool $carry, array $p): bool => $carry && ($p['method'] ?? '') === 'cash',
                true
            );
        }
        // La propina se suma **después** del redondeo y fuera de todo importe
        // fiscal (G-16). No es venta: no la cobra el negocio para sí, no lleva
        // impuesto y no viaja al ERP. Meterla en `total` la volvería base
        // gravada y `tax_difference` dejaría de ser cero en cada cuenta de
        // restaurante.
        $tip = Decimal::parse((string) ($input['tip'] ?? '0'), Decimal::MONEY);
        $due = Decimal::add($allCash ? $cashTotal : $total, $tip);

        $baseCurrency = (string) ($config['currency'] ?? '');
        $paid = Decimal::sum(array_map(
            fn (array $payment): string => $this->toBaseCurrency($payment, $baseCurrency),
            $payments
        ));

        // Venta y devolución no se leen igual. En una venta el saldo es lo que
        // falta cobrar y el sobrante es vuelto; en una devolución el importe es
        // negativo y lo pendiente es **entregar** dinero, que sale como saldo
        // negativo. Sin esa distinción una devolución sin pago registrado
        // mostraría un vuelto enorme en la pantalla del cajero.
        $signed = Decimal::sub($due, $paid);
        $isRefund = Decimal::isNegative($due);
        $change = ($isRefund || Decimal::cmp($signed, '0') >= 0) ? '0' : Decimal::negate($signed);
        $balance = $isRefund ? $signed : (Decimal::cmp($signed, '0') > 0 ? $signed : '0');

        return [
            'lines' => array_map([$this, 'renderLine'], $lines),
            'gross' => Decimal::format($gross, Decimal::MONEY),
            'lineDiscountTotal' => Decimal::format($lineDiscountTotal, Decimal::MONEY),
            'saleDiscount' => Decimal::format($saleDiscount, Decimal::MONEY),
            'discountTotal' => Decimal::format(Decimal::add($lineDiscountTotal, $saleDiscount), Decimal::MONEY),
            'subtotal' => Decimal::format($subtotal, Decimal::MONEY),
            'taxableBase' => Decimal::format($taxableBase, Decimal::MONEY),
            'exemptTotal' => Decimal::format($exemptTotal, Decimal::MONEY),
            'taxes' => $this->aggregateTaxes($lines),
            'taxTotal' => Decimal::format($taxTotal, Decimal::MONEY),
            'total' => Decimal::format($total, Decimal::MONEY),
            'cashRounding' => Decimal::format(Decimal::sub($cashTotal, $total), Decimal::MONEY),
            'cashTotal' => Decimal::format($cashTotal, Decimal::MONEY),
            'tip' => Decimal::format($tip, Decimal::MONEY),
            'due' => Decimal::format($due, Decimal::MONEY),
            'paid' => Decimal::format($paid, Decimal::MONEY),
            'change' => Decimal::format($change, Decimal::MONEY),
            'balance' => Decimal::format($balance, Decimal::MONEY),
        ];
    }

    /**
     * Impuesto excluido: se agrega sobre la base. Cada impuesto se redondea por
     * su cuenta — son tributos distintos y cada uno se declara por separado.
     *
     * @param  array<string,mixed>  $line
     * @param  array<int,array<string,string>>  $rates
     * @return array<string,mixed>
     */
    private function applyExcludedTaxes(array $line, string $amount, array $rates): array
    {
        $line['taxableBase'] = $amount;
        $line['taxes'] = [];

        foreach ($rates as $tax) {
            $rate = Decimal::parse($tax['rate'], Decimal::RATE);
            $line['taxes'][] = [
                'code' => (string) $tax['code'],
                'rate' => $rate,
                'base' => $amount,
                'amount' => Decimal::mulShift($amount, $rate, self::SHIFT_RATE),
            ];
        }

        $line['taxTotal'] = Decimal::sum(array_column($line['taxes'], 'amount'));
        $line['total'] = Decimal::add($amount, $line['taxTotal']);

        return $line;
    }

    /**
     * Impuesto incluido: hay que extraerlo del precio.
     *
     * Con varias tasas se extrae **una sola vez** contra la suma de tasas y
     * después se reparte por resto mayor. Extraer una por una dejaría un residuo
     * que no pertenece a ningún impuesto y que descuadra el libro de ventas.
     *
     * @param  array<string,mixed>  $line
     * @param  array<int,array<string,string>>  $rates
     * @return array<string,mixed>
     */
    private function applyIncludedTaxes(array $line, string $amount, array $rates): array
    {
        $rateOne = Decimal::pow10(self::SHIFT_RATE);
        $parsed = array_map(
            static fn (array $tax): array => [
                'code' => (string) $tax['code'],
                'rate' => Decimal::parse($tax['rate'], Decimal::RATE),
            ],
            $rates
        );

        $rateSum = Decimal::sum(array_column($parsed, 'rate'));
        $base = Decimal::divRoundHalfUp(
            bcmul($amount, $rateOne, 0),
            Decimal::add($rateOne, $rateSum)
        );
        $taxTotal = Decimal::sub($amount, $base);
        $split = Decimal::distribute($taxTotal, array_column($parsed, 'rate'));

        $line['taxableBase'] = $base;
        $line['taxes'] = [];
        foreach ($parsed as $index => $tax) {
            $line['taxes'][] = [
                'code' => $tax['code'],
                'rate' => $tax['rate'],
                'base' => $base,
                'amount' => $split[$index] ?? '0',
            ];
        }
        $line['taxTotal'] = $taxTotal;
        $line['total'] = $amount;

        return $line;
    }

    /**
     * Porcentaje sobre una base, o monto fijo. El monto nunca supera la base.
     *
     * @param  array<string,string>|null  $discount
     */
    private function resolveDiscount(?array $discount, string $base): string
    {
        if ($discount === null) {
            return '0';
        }

        if (($discount['type'] ?? null) === 'percent') {
            return Decimal::mulShift(
                $base,
                Decimal::parse($discount['value'], Decimal::RATE),
                self::SHIFT_RATE
            );
        }

        $amount = Decimal::parse($discount['value'], Decimal::MONEY);

        // Un descuento mayor que el importe convertiría la venta en un pago al
        // cliente. Se acota en vez de fallar: en caja, un tope silencioso es
        // mejor que un error que detiene la fila.
        return Decimal::cmp(Decimal::abs($amount), Decimal::abs($base)) > 0 ? $base : $amount;
    }

    /** @param array<string,string>|null $rounding */
    private function applyCashRounding(string $total, ?array $rounding): string
    {
        if ($rounding === null || ($rounding['mode'] ?? 'none') === 'none') {
            return $total;
        }

        $increment = Decimal::parse($rounding['increment'], Decimal::MONEY);
        if (Decimal::cmp($increment, '0') <= 0) {
            return $total;
        }

        $negative = Decimal::isNegative($total);
        $magnitude = Decimal::abs($total);
        $remainder = bcmod($magnitude, $increment, 0);
        $mode = $rounding['mode'];

        if (Decimal::cmp($remainder, '0') === 0) {
            $rounded = $magnitude;
        } elseif ($mode === 'up') {
            $rounded = Decimal::add(Decimal::sub($magnitude, $remainder), $increment);
        } elseif ($mode === 'down') {
            $rounded = Decimal::sub($magnitude, $remainder);
        } else {
            $rounded = Decimal::cmp(bcmul($remainder, '2', 0), $increment) >= 0
                ? Decimal::add(Decimal::sub($magnitude, $remainder), $increment)
                : Decimal::sub($magnitude, $remainder);
        }

        return $negative ? Decimal::negate($rounded) : $rounded;
    }

    /**
     * Convierte un pago a moneda base (Q-06).
     *
     * El dólar en caja es rutina en Nicaragua: se recibe en dólares, se cobra a
     * la tasa del día y el vuelto sale en córdobas. La tasa viaja con el pago
     * porque se persiste con él.
     *
     * @param  array<string,mixed>  $payment
     */
    private function toBaseCurrency(array $payment, string $baseCurrency): string
    {
        $amount = Decimal::parse($payment['amount'], Decimal::MONEY);
        $currency = (string) ($payment['currency'] ?? $baseCurrency);

        if ($currency === $baseCurrency) {
            return $amount;
        }

        if (! isset($payment['rate'])) {
            throw new InvalidArgumentException(
                "El pago en {$currency} no trae tipo de cambio y la moneda base es {$baseCurrency}."
            );
        }

        return Decimal::mulShift($amount, Decimal::parse($payment['rate'], Decimal::FX), Decimal::FX);
    }

    /**
     * Desglose de impuestos del ticket, agrupado por código y en orden de
     * aparición.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array<string,string>>
     */
    private function aggregateTaxes(array $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            foreach ($line['taxes'] as $tax) {
                $code = $tax['code'];
                if (isset($totals[$code])) {
                    $totals[$code]['base'] = Decimal::add($totals[$code]['base'], $tax['base']);
                    $totals[$code]['amount'] = Decimal::add($totals[$code]['amount'], $tax['amount']);
                } else {
                    $totals[$code] = [
                        'rate' => $tax['rate'],
                        'base' => $tax['base'],
                        'amount' => $tax['amount'],
                    ];
                }
            }
        }

        $result = [];
        foreach ($totals as $code => $entry) {
            $result[] = [
                'code' => (string) $code,
                'rate' => Decimal::format($entry['rate'], Decimal::RATE),
                'base' => Decimal::format($entry['base'], Decimal::MONEY),
                'amount' => Decimal::format($entry['amount'], Decimal::MONEY),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $line
     * @return array<string,mixed>
     */
    private function renderLine(array $line): array
    {
        return [
            'id' => $line['id'],
            'gross' => Decimal::format($line['gross'], Decimal::MONEY),
            'lineDiscount' => Decimal::format($line['lineDiscount'], Decimal::MONEY),
            'saleDiscountShare' => Decimal::format($line['saleDiscountShare'], Decimal::MONEY),
            'discountTotal' => Decimal::format(
                Decimal::add($line['lineDiscount'], $line['saleDiscountShare']),
                Decimal::MONEY
            ),
            'taxableBase' => Decimal::format($line['taxableBase'], Decimal::MONEY),
            'taxes' => array_map(static fn (array $tax): array => [
                'code' => $tax['code'],
                'rate' => Decimal::format($tax['rate'], Decimal::RATE),
                'base' => Decimal::format($tax['base'], Decimal::MONEY),
                'amount' => Decimal::format($tax['amount'], Decimal::MONEY),
            ], $line['taxes']),
            'taxTotal' => Decimal::format($line['taxTotal'], Decimal::MONEY),
            'total' => Decimal::format($line['total'], Decimal::MONEY),
        ];
    }
}
