<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unidades de medida de arranque (G-08).
 *
 * `decimals` decide qué se puede fraccionar: "2,5 metros" sí, "2,5 unidades"
 * no. Es la validación que evita una venta de media caja registradora.
 */
class UomSeeder extends Seeder
{
    public function run(): void
    {
        $uoms = [
            ['code' => 'UND', 'name' => 'Unidad', 'decimals' => 0],
            ['code' => 'KG', 'name' => 'Kilogramo', 'decimals' => 3],
            ['code' => 'LB', 'name' => 'Libra', 'decimals' => 3],
            ['code' => 'LT', 'name' => 'Litro', 'decimals' => 3],
            ['code' => 'MT', 'name' => 'Metro', 'decimals' => 2],
            ['code' => 'CAJ', 'name' => 'Caja', 'decimals' => 0],
            ['code' => 'SRV', 'name' => 'Servicio', 'decimals' => 0],
        ];

        foreach ($uoms as $uom) {
            DB::table('cat_uoms')->updateOrInsert(
                ['code' => $uom['code']],
                $uom + [
                    'id' => (string) Str::uuid7(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
