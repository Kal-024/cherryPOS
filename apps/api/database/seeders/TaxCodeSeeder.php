<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impuestos de arranque: IVA de Nicaragua (P-09).
 *
 * Motor **simple** en F1; el completo con categorías y jurisdicciones llega
 * cuando se internacionalice, y por eso el cálculo vive detrás de
 * `SaleCalculator` — la costura está puesta desde ahora.
 *
 * `EXE` no es "sin impuesto": es **gravado a tasa cero por naturaleza del
 * bien**, que es lo que corresponde a los medicamentos. Sin ese código el
 * vertical farmacia no puede declarar bien sus ventas (Q-07).
 */
class TaxCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            // `gross`: el precio de góndola es lo que paga el cliente y el motor
            // extrae el IVA al facturar. Es lo habitual en Nicaragua para
            // consumidor final.
            ['code' => 'IVA', 'name' => 'IVA 15 %', 'rate' => '15.0000', 'type' => 'vat', 'base' => 'gross'],
            ['code' => 'EXE', 'name' => 'Exento', 'rate' => '0.0000', 'type' => 'exempt', 'base' => 'net'],
        ];

        foreach ($codes as $code) {
            DB::table('cat_tax_codes')->updateOrInsert(
                ['code' => $code['code']],
                $code + [
                    'id' => (string) Str::uuid7(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
