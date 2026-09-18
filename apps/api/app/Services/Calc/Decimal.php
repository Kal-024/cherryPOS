<?php

namespace App\Services\Calc;

use InvalidArgumentException;
use RuntimeException;

/**
 * Aritmética decimal exacta sobre enteros escalados.
 *
 * Espejo exacto de `packages/calc/src/decimal.ts`. El motor de cálculo existe
 * dos veces y la única forma de que no diverjan es que ninguno de los dos use
 * coma flotante en ningún punto: un `0.1 + 0.2` de PHP contra el de JavaScript
 * produce el centavo que después aparece cuadrando una caja.
 *
 * Aquí se usa BCMath —no `int`— porque `cantidad × precio` a escala 8 supera
 * `PHP_INT_MAX` con valores todavía plausibles de ferretería. El lado
 * TypeScript usa `bigint` por el mismo motivo.
 *
 * Todos los valores son cadenas de dígitos que representan **enteros**; la
 * escala vive fuera, en quien llama.
 */
final class Decimal
{
    /** Importes de dinero: al centavo. */
    public const MONEY = 2;

    /** Cantidades: 4 decimales, como `decimal:4` de cherryB. */
    public const QTY = 4;

    /** Precio unitario: 4 decimales. */
    public const PRICE = 4;

    /** Tasas de impuesto y porcentajes de descuento: 4 decimales. */
    public const RATE = 4;

    /** Tipo de cambio: 6 decimales. */
    public const FX = 6;

    /**
     * Convierte una cadena decimal a entero escalado.
     *
     * Lanza si el valor trae más decimales de los que la escala admite, en vez
     * de redondear en silencio: un fixture con un decimal de más es un error
     * del fixture, y descubrirlo aquí cuesta un segundo — descubrirlo en un
     * arqueo, semanas.
     */
    public static function parse(string|int|float $value, int $scale): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException(
                'El motor de cálculo no acepta float: usar cadena decimal.'
            );
        }

        $raw = trim((string) $value);

        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $raw) !== 1) {
            throw new InvalidArgumentException("Valor decimal inválido: {$raw}");
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = ltrim($raw, '+-');
        [$intPart, $fracPart] = array_pad(explode('.', $unsigned, 2), 2, '');

        if (strlen($fracPart) > $scale) {
            throw new InvalidArgumentException(
                sprintf(
                    '"%s" tiene %d decimales y la escala admite %d. Redondear en la '
                    .'frontera es responsabilidad de quien produce el dato.',
                    $raw,
                    strlen($fracPart),
                    $scale
                )
            );
        }

        $magnitude = ltrim($intPart.str_pad($fracPart, $scale, '0'), '0');
        $magnitude = $magnitude === '' ? '0' : $magnitude;

        return $negative && $magnitude !== '0' ? '-'.$magnitude : $magnitude;
    }

    /** Entero escalado a cadena decimal con exactamente `$scale` decimales. */
    public static function format(string $value, int $scale): string
    {
        $negative = self::isNegative($value);
        $digits = str_pad(ltrim($value, '-'), $scale + 1, '0', STR_PAD_LEFT);
        $cut = strlen($digits) - $scale;
        $body = $scale === 0
            ? substr($digits, 0, $cut)
            : substr($digits, 0, $cut).'.'.substr($digits, $cut);

        return $negative ? '-'.$body : $body;
    }

    /**
     * División con redondeo de medio hacia arriba **alejándose del cero**
     * (`half-up`).
     *
     * Es la única regla de redondeo del sistema. Que sea simétrica importa: sin
     * eso una devolución no sería el negativo exacto de su venta, y la nota de
     * crédito dejaría un centavo colgando.
     */
    public static function divRoundHalfUp(string $numerator, string $denominator): string
    {
        if (bccomp($denominator, '0', 0) === 0) {
            throw new RuntimeException('División por cero en el motor de cálculo');
        }

        $negative = self::isNegative($numerator) !== self::isNegative($denominator);
        $absNum = self::abs($numerator);
        $absDen = self::abs($denominator);

        $quotient = bcdiv($absNum, $absDen, 0);
        $remainder = bcmod($absNum, $absDen, 0);

        if (bccomp(bcmul($remainder, '2', 0), $absDen, 0) >= 0) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return $negative ? self::negate($quotient) : $quotient;
    }

    /** `a × b / 10^shift`, redondeado half-up. El caballo de batalla del motor. */
    public static function mulShift(string $a, string $b, int $shift): string
    {
        return self::divRoundHalfUp(bcmul($a, $b, 0), self::pow10($shift));
    }

    public static function pow10(int $exponent): string
    {
        return bcpow('10', (string) $exponent, 0);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 0);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 0);
    }

    /** @param array<int,string> $values */
    public static function sum(array $values): string
    {
        return array_reduce($values, static fn ($acc, $v) => bcadd($acc, $v, 0), '0');
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, 0);
    }

    public static function abs(string $value): string
    {
        return ltrim($value, '-');
    }

    public static function isNegative(string $value): bool
    {
        return str_starts_with($value, '-') && ltrim($value, '-0') !== '';
    }

    public static function negate(string $value): string
    {
        if (bccomp($value, '0', 0) === 0) {
            return '0';
        }

        return self::isNegative($value) ? ltrim($value, '-') : '-'.$value;
    }

    /**
     * Reparte `$total` entre `$weights` de forma proporcional, en enteros y
     * **sin perder ni un centavo**: el método del resto mayor.
     *
     * Se usa para bajar el descuento de venta a las líneas. Repartir con
     * redondeo independiente por línea deja un descuadre de céntimos entre la
     * suma de las partes y el descuento que el cajero tecleó; el resto mayor
     * garantiza que `sum(resultado) === total`.
     *
     * El desempate es por **orden de línea**, nunca por valor: es lo que hace
     * que PHP y TypeScript produzcan la misma asignación ante dos líneas
     * idénticas.
     *
     * @param  array<int,string>  $weights
     * @return array<int,string>
     */
    public static function distribute(string $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $weightSum = self::sum($weights);

        // Sin peso donde apoyarse (todas las líneas en cero) el reparto
        // proporcional no está definido: se carga todo a la primera línea, que
        // es el único resultado estable y reproducible.
        if (bccomp($weightSum, '0', 0) === 0) {
            $result = array_fill(0, $count, '0');
            $result[0] = $total;

            return $result;
        }

        $negative = self::isNegative($total);
        $magnitude = self::abs($total);

        $base = [];
        $remainders = [];
        $assigned = '0';

        for ($i = 0; $i < $count; $i++) {
            $product = bcmul($magnitude, $weights[$i], 0);
            $share = bcdiv($product, $weightSum, 0);
            $base[$i] = $share;
            $remainders[] = ['index' => $i, 'value' => bcmod($product, $weightSum, 0)];
            $assigned = bcadd($assigned, $share, 0);
        }

        $leftover = bcsub($magnitude, $assigned, 0);

        usort($remainders, static function (array $a, array $b): int {
            $comparison = bccomp($b['value'], $a['value'], 0);

            return $comparison !== 0 ? $comparison : $a['index'] <=> $b['index'];
        });

        foreach ($remainders as $remainder) {
            if (bccomp($leftover, '0', 0) <= 0) {
                break;
            }
            $base[$remainder['index']] = bcadd($base[$remainder['index']], '1', 0);
            $leftover = bcsub($leftover, '1', 0);
        }

        return $negative ? array_map([self::class, 'negate'], $base) : $base;
    }
}
