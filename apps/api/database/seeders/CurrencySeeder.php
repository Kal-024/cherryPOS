<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monedas y denominaciones de caja.
 *
 * Nicaragua por defecto (D-06): billetes de 10, 20, 50, 100 y 1000 córdobas, y
 * monedas de 0,25, 0,50, 1, 5 y 10. Son **configurables por país** porque no hay
 * dos iguales, pero el sistema tiene que arrancar sabiendo contar la caja del
 * mercado inicial.
 *
 * El dólar entra desde F1 y no como brecha menor: pagar en dólares en caja es
 * rutina, y afecta al arqueo, a los pagos y al cierre de turno (Q-06).
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'NIO', 'name' => 'Córdoba nicaragüense', 'symbol' => 'C$', 'is_base' => true],
            ['code' => 'USD', 'name' => 'Dólar estadounidense', 'symbol' => '$', 'is_base' => false],
        ];

        foreach ($currencies as $currency) {
            DB::table('cmn_currencies')->updateOrInsert(
                ['code' => $currency['code']],
                $currency + ['decimals' => 2, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $denominations = [
            ['NIO', '1000.00', 'bill'],
            ['NIO', '500.00', 'bill'],
            ['NIO', '200.00', 'bill'],
            ['NIO', '100.00', 'bill'],
            ['NIO', '50.00', 'bill'],
            ['NIO', '20.00', 'bill'],
            ['NIO', '10.00', 'bill'],
            ['NIO', '10.00', 'coin'],
            ['NIO', '5.00', 'coin'],
            ['NIO', '1.00', 'coin'],
            ['NIO', '0.50', 'coin'],
            ['NIO', '0.25', 'coin'],
            ['USD', '100.00', 'bill'],
            ['USD', '50.00', 'bill'],
            ['USD', '20.00', 'bill'],
            ['USD', '10.00', 'bill'],
            ['USD', '5.00', 'bill'],
            ['USD', '1.00', 'bill'],
        ];

        foreach ($denominations as $index => [$currency, $value, $kind]) {
            DB::table('cmn_denominations')->updateOrInsert(
                ['currency_code' => $currency, 'value' => $value],
                [
                    'id' => (string) Str::uuid7(),
                    'kind' => $kind,
                    'sort_order' => $index,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
