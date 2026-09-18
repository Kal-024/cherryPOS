<?php

namespace App\Services\Catalog;

use App\Models\BusinessProfile;
use Illuminate\Validation\ValidationException;

/**
 * Validación de los atributos dinámicos de un producto (B-08, D-24, H1.1).
 *
 * **Híbrido: JSON por defecto, columna cuando haga falta.** La columna
 * `cat_products.attributes` acepta lo que el perfil de negocio declare en su
 * `attribute_schema`, de modo que una farmacia agrega "principio activo" sin
 * migración. Cuando un campo resulte muy consultado, se promueve a columna real
 * con su propia migración.
 *
 * El EAV de OSPOS —tres tablas genéricas, un modelo de 1.235 líneas— quedó
 * descartado: máximamente flexible y notoriamente difícil de consultar e
 * indexar.
 *
 * Que exista un esquema es lo que separa esto de un campo de texto libre: sin
 * validación, el JSON se convierte en el basurero donde cada sucursal escribe
 * "vencimiento", "vto" y "fecha_venc" para lo mismo.
 */
class ProductAttributeValidator
{
    private const TYPES = ['string', 'integer', 'decimal', 'boolean', 'date', 'enum'];

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed> los atributos normalizados al tipo declarado
     *
     * @throws ValidationException
     */
    public function validate(array $attributes, ?BusinessProfile $profile): array
    {
        $fields = $this->fields($profile);

        // Sin perfil configurado no hay esquema contra el cual validar. Se
        // rechaza en vez de aceptar cualquier cosa: aceptar es como quedarse sin
        // esquema para siempre, porque nadie vuelve a limpiar ese JSON.
        if ($fields === []) {
            if ($attributes === []) {
                return [];
            }

            throw ValidationException::withMessages([
                'attributes' => __('catalog.attributes_without_schema'),
            ]);
        }

        $known = array_column($fields, null, 'key');
        $unknown = array_diff(array_keys($attributes), array_keys($known));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'attributes' => __('catalog.attribute_unknown', [
                    'keys' => implode(', ', $unknown),
                ]),
            ]);
        }

        $normalized = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $present = array_key_exists($key, $attributes);
            $value = $present ? $attributes[$key] : null;

            if (($field['required'] ?? false) && ($value === null || $value === '')) {
                throw ValidationException::withMessages([
                    "attributes.{$key}" => __('catalog.attribute_required', [
                        'label' => $field['label'] ?? $key,
                    ]),
                ]);
            }

            if (! $present || $value === null || $value === '') {
                continue;
            }

            $normalized[$key] = $this->cast($field, $value);
        }

        return $normalized;
    }

    /** @return array<int,array<string,mixed>> */
    public function fields(?BusinessProfile $profile): array
    {
        $schema = $profile?->attribute_schema ?? [];

        return array_values(array_filter(
            $schema['fields'] ?? [],
            static fn ($field) => is_array($field) && isset($field['key'])
        ));
    }

    /** @param array<string,mixed> $field */
    private function cast(array $field, mixed $value): mixed
    {
        $type = $field['type'] ?? 'string';

        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages([
                'attributes' => __('catalog.attribute_type_unknown', ['type' => $type]),
            ]);
        }

        $key = $field['key'];
        $label = $field['label'] ?? $key;

        return match ($type) {
            'integer' => $this->castInteger($value, $key, $label),
            // Decimal queda como **cadena**, no como float: el mismo motivo por
            // el que el motor de cálculo no toca coma flotante.
            'decimal' => $this->castDecimal($value, $key, $label),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->fail($key, $label),
            'date' => $this->castDate($value, $key, $label),
            'enum' => $this->castEnum($value, $field, $key, $label),
            default => (string) $value,
        };
    }

    private function castInteger(mixed $value, string $key, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->fail($key, $label);
        }

        return (int) $value;
    }

    private function castDecimal(mixed $value, string $key, string $label): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            $this->fail($key, $label);
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', (string) $value) !== 1) {
            $this->fail($key, $label);
        }

        return (string) $value;
    }

    private function castDate(mixed $value, string $key, string $label): string
    {
        if (! is_string($value) || strtotime($value) === false) {
            $this->fail($key, $label);
        }

        return date('Y-m-d', strtotime((string) $value));
    }

    /** @param array<string,mixed> $field */
    private function castEnum(mixed $value, array $field, string $key, string $label): string
    {
        $options = $field['options'] ?? [];

        if (! in_array($value, $options, true)) {
            throw ValidationException::withMessages([
                "attributes.{$key}" => __('catalog.attribute_not_in_options', [
                    'label' => $label,
                    'options' => implode(', ', $options),
                ]),
            ]);
        }

        return (string) $value;
    }

    private function fail(string $key, string $label): never
    {
        throw ValidationException::withMessages([
            "attributes.{$key}" => __('catalog.attribute_invalid', ['label' => $label]),
        ]);
    }
}
