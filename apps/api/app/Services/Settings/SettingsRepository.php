<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Configuración tipada y jerárquica (D-12).
 *
 * Dos correcciones sobre el patrón de OSPOS, que guardaba ~140 claves de texto
 * plano sin tipo ni validación y todas globales:
 *
 *  1. **Tipada** — `value_type` convierte y valida. Una clave booleana mal
 *     escrita falla al guardarse, no al leerse en medio de una venta.
 *  2. **Jerárquica** (P-05) — `negocio → sucursal → terminal`. Gana el valor más
 *     específico. Una cadena con tres locales necesita impresora distinta por
 *     local y política fiscal común.
 *
 * Cacheada en memoria, como en OSPOS: leerla por consulta en cada tecla del
 * buscador sería absurdo. La caché se invalida al escribir, no por expiración —
 * un cambio de configuración tiene que verse ya.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'pos.settings';

    private const TTL = 3600;

    /** @var array<string,mixed>|null */
    private ?array $rows = null;

    public function get(
        string $key,
        mixed $default = null,
        ?string $branchId = null,
        ?string $terminalId = null,
    ): mixed {
        $rows = $this->rows();

        // Del más específico al más general: la terminal gana sobre la
        // sucursal, y la sucursal sobre el negocio.
        foreach ([
            $terminalId ? "terminal:{$terminalId}:{$key}" : null,
            $branchId ? "branch:{$branchId}:{$key}" : null,
            "business::{$key}",
        ] as $candidate) {
            if ($candidate !== null && array_key_exists($candidate, $rows)) {
                return $rows[$candidate];
            }
        }

        return $default;
    }

    public function set(
        string $key,
        mixed $value,
        string $type = 'string',
        string $scope = 'business',
        ?string $scopeId = null,
        string $group = 'general',
    ): void {
        DB::table('cmn_settings')->updateOrInsert(
            ['scope' => $scope, 'scope_id' => $scopeId, 'key' => $key],
            [
                'id' => (string) Str::uuid7(),
                'value' => $this->encode($value, $type),
                'value_type' => $type,
                'group' => $group,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->flush();
    }

    public function flush(): void
    {
        $this->rows = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string,mixed> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $this->rows = Cache::remember(self::CACHE_KEY, self::TTL, function (): array {
            $map = [];

            foreach (DB::table('cmn_settings')->get() as $row) {
                $map["{$row->scope}:{$row->scope_id}:{$row->key}"] = $this->decode($row->value, $row->value_type);
            }

            return $map;
        });

        return $this->rows;
    }

    private function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            // Decimal viaja como cadena, nunca como float: el mismo motivo por
            // el que el motor de cálculo no toca coma flotante.
            default => (string) $value,
        };
    }

    private function decode(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === '1' || $value === 'true',
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
