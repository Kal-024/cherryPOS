<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Terminal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * La instalación mínima operable: una sucursal, una terminal y un administrador.
 *
 * Una instalación pertenece a un solo negocio y a una sola sucursal (P-05). En
 * un cliente de un solo local, ese mismo servidor cumple los dos roles —casa
 * matriz y sucursal— con el mismo código, sin ramas especiales.
 *
 * Los secretos y PIN de desarrollo salen por consola y **no** sirven para
 * producción: allí los emite el instalador junto con la licencia firmada (Q-08).
 */
class InstallationSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::updateOrCreate(
            ['code' => config('pos.branch_code')],
            [
                'name' => config('pos.branch_name'),
                'timezone' => config('app.timezone'),
                'is_headquarters' => (bool) config('pos.is_headquarters'),
                'is_active' => true,
            ]
        );

        $terminalSecret = app()->environment('local') ? 'terminal-dev' : Str::random(24);

        $terminal = Terminal::updateOrCreate(
            ['branch_id' => $branch->id, 'code' => 'CAJA-01'],
            [
                'name' => 'Caja 1',
                'secret_hash' => Hash::make($terminalSecret),
                'layout_profile' => 'scan_first',
                'is_active' => true,
            ]
        );

        $adminPin = app()->environment('local') ? '1234' : (string) random_int(1000, 9999);
        $supervisorPin = app()->environment('local') ? '9876' : (string) random_int(1000, 9999);

        // La identidad va a `cmn_persons` (B-11): el administrador que además
        // compre en su propio negocio es una persona con dos roles.
        $admin = Employee::where('branch_id', $branch->id)->where('code', 'ADMIN')->first()
            ?? Employee::createWithPerson(
                ['full_name' => 'Administrador', 'kind' => 'natural'],
                ['branch_id' => $branch->id, 'code' => 'ADMIN', 'is_active' => true]
            );

        $admin->setPin($adminPin);
        // El PIN de autorizar es otro, distinto del de sesión (P-11).
        $admin->setSupervisorPin($supervisorPin);
        $admin->save();

        $adminRole = Role::where('code', 'admin')->first();
        if ($adminRole) {
            DB::table('sec_employee_role')->updateOrInsert(
                ['employee_id' => $admin->id, 'role_id' => $adminRole->id],
                ['branch_id' => null]
            );
        }

        // Ubicación de existencias por defecto: de aquí sale la mercadería de un
        // ticket mientras nadie configure otra cosa.
        DB::table('inv_locations')->updateOrInsert(
            ['branch_id' => $branch->id, 'code' => 'PRINCIPAL'],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'Bodega principal',
                'is_sales_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Serie de numeración del año en curso, por sucursal (B-05,
        // precondición 3). Dos locales nunca comparten correlativo.
        foreach (['counter', 'invoice', 'quote', 'work_order', 'refund'] as $type) {
            DB::table('pos_document_series')->updateOrInsert(
                ['branch_id' => $branch->id, 'document_type' => $type, 'year' => (int) now()->format('Y')],
                [
                    'id' => (string) Str::uuid7(),
                    'template' => '{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}',
                    'next_number' => 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (app()->environment('local')) {
            $this->command?->info("Terminal {$terminal->code} · secreto: {$terminalSecret}");
            $this->command?->info("Empleado ADMIN · PIN: {$adminPin} · PIN de supervisor: {$supervisorPin}");
        }
    }
}
