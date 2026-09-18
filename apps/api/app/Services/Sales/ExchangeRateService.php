<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tipo de cambio (Q-06).
 *
 * **La carga el supervisor y rige hasta que la cambie.** Puede mantenerse toda
 * la semana: mientras no se actualice, vale la última. No hay consulta en línea
 * — la red local puede no tener internet, y una caja que no puede cobrar en
 * dólares porque no alcanzó una API sería absurda.
 *
 * Con ERP vinculado sale de su tabla de tipos de cambio, que ya existe.
 *
 * El histórico se conserva porque el pago guarda la tasa que aplicó y hay que
 * poder releer un ticket de hace un mes.
 */
class ExchangeRateService
{
    public function current(string $currencyCode): ?string
    {
        $rate = DB::table('cmn_exchange_rates')
            ->where('currency_code', $currencyCode)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->value('rate');

        return $rate !== null ? (string) $rate : null;
    }

    public function set(string $currencyCode, string $rate, ?string $employeeId, string $source = 'manual'): void
    {
        DB::table('cmn_exchange_rates')->insert([
            'id' => (string) Str::uuid7(),
            'currency_code' => $currencyCode,
            'rate' => $rate,
            'effective_from' => now(),
            'created_by' => $employeeId,
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
