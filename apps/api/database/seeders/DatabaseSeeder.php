<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Arranque de una instalación de cherryPOS.
 *
 * El orden importa: los roles necesitan los permisos, y la instalación necesita
 * los roles.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            TaxCodeSeeder::class,
            UomSeeder::class,
            BusinessProfileSeeder::class,
            ExpenseCategorySeeder::class,
            ReceiptTemplateSeeder::class,
            PermissionSeeder::class,
            InstallationSeeder::class,
        ]);
    }
}
