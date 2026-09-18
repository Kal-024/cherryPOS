<?php

namespace App\Services\Catalog;

use App\Models\Barcode;
use App\Services\Calc\Decimal;

/**
 * Lectura de códigos de barras, incluidos los de balanza (B-04, H1.5).
 *
 * Un código de balanza **no identifica una unidad**: identifica un producto más
 * cuánto pesó o cuánto cuesta. La etiqueta se imprime en el momento de pesar, así
 * que el mismo producto genera un código distinto cada vez y una búsqueda exacta
 * nunca lo encuentra.
 *
 * La solución es guardar en el catálogo el código con la **región variable en
 * ceros** —la plantilla— y normalizar lo leído antes de buscar. El criterio de
 * aceptación del hito es literal: leer un código de balanza produce la línea
 * correcta.
 *
 * El dígito verificador también se pone en cero al normalizar: cambia con el
 * peso, así que no puede formar parte de la clave.
 */
class BarcodeService
{
    /**
     * Resuelve un código leído.
     *
     * `qty` viene informado cuando el código traía peso; `amount`, cuando traía
     * precio. Ambos nulos significa código fijo de catálogo.
     *
     * @return array{barcode: Barcode, qty: ?string, amount: ?string}|null
     */
    public function resolve(string $scanned): ?array
    {
        $scanned = trim($scanned);

        // Lo habitual: un código fijo de catálogo. Se prueba primero porque es
        // el 99 % de las lecturas y no tiene sentido pagar el análisis de
        // patrones en cada tecleo.
        $exact = Barcode::with(['product.uom', 'product.taxCode', 'productUom.uom'])
            ->where('code', $scanned)
            ->first();

        if ($exact) {
            return ['barcode' => $exact, 'qty' => null, 'amount' => null];
        }

        foreach ($this->patterns() as $pattern) {
            $parsed = $this->match($scanned, $pattern);

            if ($parsed === null) {
                continue;
            }

            $barcode = Barcode::with(['product.uom', 'product.taxCode', 'productUom.uom'])
                ->where('code', $parsed['template'])
                ->where('embedded', $pattern['kind'])
                ->first();

            if ($barcode) {
                return [
                    'barcode' => $barcode,
                    'qty' => $pattern['kind'] === 'weight' ? $parsed['value'] : null,
                    'amount' => $pattern['kind'] === 'price' ? $parsed['value'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * Plantilla que debe guardarse en el catálogo para un código de balanza.
     *
     * Se usa al dar de alta el código: el usuario pega una etiqueta real y el
     * sistema guarda su forma genérica.
     */
    public function template(string $sample, string $kind): ?string
    {
        foreach ($this->patterns() as $pattern) {
            if ($pattern['kind'] !== $kind) {
                continue;
            }

            $parsed = $this->match($sample, $pattern);

            if ($parsed !== null) {
                return $parsed['template'];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $pattern
     * @return array{template: string, value: string}|null
     */
    private function match(string $code, array $pattern): ?array
    {
        if (strlen($code) !== $pattern['length'] || ! ctype_digit($code)) {
            return null;
        }

        if (! str_starts_with($code, (string) $pattern['prefix'])) {
            return null;
        }

        $raw = substr($code, $pattern['value_from'], $pattern['value_length']);

        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        // La región variable y el dígito verificador van a cero: ambos cambian
        // con cada etiqueta y no pueden formar parte de la clave de búsqueda.
        $template = substr_replace(
            $code,
            str_repeat('0', $pattern['value_length']),
            $pattern['value_from'],
            $pattern['value_length']
        );
        $template = substr_replace($template, '0', -1, 1);

        $scaled = Decimal::divRoundHalfUp(
            bcmul($raw, Decimal::pow10($pattern['kind'] === 'weight' ? Decimal::QTY : Decimal::MONEY), 0),
            (string) $pattern['divisor']
        );

        return [
            'template' => $template,
            'value' => Decimal::format(
                $scaled,
                $pattern['kind'] === 'weight' ? Decimal::QTY : Decimal::MONEY
            ),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function patterns(): array
    {
        return config('pos.barcode_patterns', []);
    }
}
