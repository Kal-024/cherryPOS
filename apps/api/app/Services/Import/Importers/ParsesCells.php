<?php

namespace App\Services\Import\Importers;

/**
 * Lectura tolerante de celdas de Excel.
 *
 * El archivo lo arma una persona, no un sistema: los números vienen con coma
 * decimal, con espacios y a veces como texto; los booleanos vienen como "sí",
 * "x" o "1". Rechazar la fila por eso sería hacerle perder la tarde a quien está
 * cargando tres mil productos.
 *
 * Lo que **no** se tolera es la ambigüedad: si algo no se entiende, la fila se
 * marca con su error y el usuario lo ve en la previsualización, antes de que se
 * escriba nada.
 */
trait ParsesCells
{
    protected function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * Devuelve `null` cuando no es un número reconocible.
     *
     * El separador decimal es **el que aparece más a la derecha**: en
     * `1.234,56` la coma, en `1,234.56` el punto. Es la única regla que acierta
     * con los dos formatos sin preguntar de qué máquina salió el archivo.
     *
     * Con un solo separador queda una ambigüedad real —`1,234` puede ser mil
     * doscientos treinta y cuatro o uno con doscientos treinta y cuatro— y se
     * resuelve a favor del mercado: en Nicaragua la coma es decimal. La
     * previsualización muestra el valor ya interpretado junto al original,
     * justamente para que esa lectura se pueda verificar antes de aplicar.
     */
    protected function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
        }

        $clean = preg_replace('/[\s\x{00A0}]/u', '', (string) $value) ?? '';

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $separator = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false) {
            $separator = ',';
        } else {
            $separator = '.';
        }

        // Fuera todo lo que no sea el separador decidido; después, ese pasa a
        // punto, que es lo que entiende el motor de cálculo.
        $thousands = $separator === ',' ? '.' : ',';
        $clean = str_replace($thousands, '', $clean);
        $clean = str_replace($separator, '.', $clean);

        return preg_match('/^-?\d+(\.\d+)?$/', $clean) === 1 ? $clean : null;
    }

    protected function integer(mixed $value): ?int
    {
        $decimal = $this->decimal($value);

        return $decimal === null ? null : (int) $decimal;
    }

    protected function boolean(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'si', 'sí', 'true', 'yes', 'x', 'verdadero', 'v'],
            true
        );
    }

    protected function date(mixed $value): ?string
    {
        $raw = $this->text($value);

        if ($raw === '') {
            return null;
        }

        // Excel guarda las fechas como número de serie desde 1900.
        if (ctype_digit($raw) && (int) $raw > 20000) {
            return date('Y-m-d', ((int) $raw - 25569) * 86400);
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
