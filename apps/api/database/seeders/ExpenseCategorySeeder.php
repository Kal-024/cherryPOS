<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Categorías de gasto de arranque (B-14).
 *
 * Un negocio que abre no se pone a diseñar un plan de cuentas: necesita poder
 * anotar la luz el primer día. Estas son las que aparecen en cualquier local, y
 * son editables.
 *
 * `behaviour` separa lo fijo de lo variable, que es la distinción que pide
 * cualquier análisis de punto de equilibrio.
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'alquiler', 'name' => 'Alquiler', 'behaviour' => 'fixed'],
            ['code' => 'servicios', 'name' => 'Servicios básicos (luz, agua, internet)', 'behaviour' => 'fixed'],
            ['code' => 'planilla', 'name' => 'Planilla y prestaciones', 'behaviour' => 'fixed'],
            ['code' => 'transporte', 'name' => 'Transporte y combustible', 'behaviour' => 'variable'],
            ['code' => 'mantenimiento', 'name' => 'Mantenimiento y reparaciones', 'behaviour' => 'variable'],
            ['code' => 'limpieza', 'name' => 'Limpieza e insumos', 'behaviour' => 'variable'],
            ['code' => 'papeleria', 'name' => 'Papelería y empaque', 'behaviour' => 'variable'],
            ['code' => 'impuestos', 'name' => 'Impuestos y tasas municipales', 'behaviour' => 'fixed'],
            ['code' => 'comisiones', 'name' => 'Comisiones bancarias y de tarjeta', 'behaviour' => 'variable'],
            ['code' => 'otros', 'name' => 'Otros gastos', 'behaviour' => 'variable'],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
