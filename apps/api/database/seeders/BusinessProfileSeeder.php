<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Perfiles de negocio (A-04).
 *
 * El mecanismo de extensión de los verticales. No son banderas sueltas como en
 * OSPOS: cada perfil **preconfigura** qué módulos se encienden, qué atributos
 * tiene un producto, cómo se ve la pantalla de venta y cómo se llaman las cosas.
 *
 * `vocabulary` es lo que resuelve D-01: en restaurante, "suspender una venta"
 * se llama **cuenta abierta**, porque lo natural allí es comer y pagar después.
 * Mismo estado, otro nombre — no otro mecanismo ni cuatro tablas espejo.
 *
 * Los cinco perfiles existen desde ahora aunque solo dos estén implementados en
 * F1: el núcleo se diseña con al menos dos verticales en mente para que el
 * segundo rubro no termine incrustado a la fuerza (A-03).
 */
class BusinessProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            [
                'code' => 'retail',
                'name' => 'Retail genérico',
                'layout_profile' => 'scan_first',
                'modules' => ['sales', 'inventory', 'customers', 'credit', 'shifts'],
                'attribute_schema' => ['fields' => []],
                'vocabulary' => [],
            ],
            [
                'code' => 'restaurant',
                'name' => 'Restaurante',
                'layout_profile' => 'restaurant',
                'modules' => ['sales', 'inventory', 'customers', 'shifts', 'tables', 'kds', 'modifiers'],
                'attribute_schema' => ['fields' => [
                    ['key' => 'preparation_area', 'label' => 'Área de preparación', 'type' => 'string'],
                    ['key' => 'preparation_minutes', 'label' => 'Minutos de preparación', 'type' => 'integer'],
                ]],
                'vocabulary' => [
                    'sale.suspend' => 'sale.open_tab',
                    'sale.suspended' => 'sale.tab_open',
                    'sale.resume' => 'sale.reopen_tab',
                ],
            ],
            [
                'code' => 'gym',
                'name' => 'Gimnasio',
                'layout_profile' => 'touch_grid',
                'modules' => ['sales', 'inventory', 'customers', 'shifts', 'memberships'],
                'attribute_schema' => ['fields' => [
                    ['key' => 'membership_months', 'label' => 'Meses de membresía', 'type' => 'integer'],
                ]],
                'vocabulary' => [],
            ],
            [
                'code' => 'pharmacy',
                'name' => 'Farmacia',
                'layout_profile' => 'scan_first',
                'modules' => ['sales', 'inventory', 'customers', 'credit', 'shifts', 'lots', 'prescriptions'],
                // Una farmacia agrega "principio activo" sin migración: es toda
                // la promesa de los atributos híbridos (D-24).
                'attribute_schema' => ['fields' => [
                    ['key' => 'active_ingredient', 'label' => 'Principio activo', 'type' => 'string'],
                    ['key' => 'concentration', 'label' => 'Concentración', 'type' => 'string'],
                    ['key' => 'requires_prescription', 'label' => 'Requiere receta', 'type' => 'boolean'],
                ]],
                'vocabulary' => [],
            ],
            [
                'code' => 'hardware',
                'name' => 'Ferretería',
                'layout_profile' => 'scan_first',
                'modules' => ['sales', 'inventory', 'customers', 'credit', 'shifts', 'cut_to_size', 'rentals'],
                'attribute_schema' => ['fields' => [
                    ['key' => 'material', 'label' => 'Material', 'type' => 'string'],
                    ['key' => 'gauge', 'label' => 'Calibre', 'type' => 'string'],
                ]],
                'vocabulary' => [],
            ],
        ];

        foreach ($profiles as $profile) {
            DB::table('cat_business_profiles')->updateOrInsert(
                ['code' => $profile['code']],
                [
                    'id' => (string) Str::uuid7(),
                    'name' => $profile['name'],
                    'layout_profile' => $profile['layout_profile'],
                    'modules' => json_encode($profile['modules']),
                    'attribute_schema' => json_encode($profile['attribute_schema']),
                    'vocabulary' => json_encode($profile['vocabulary']),
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
