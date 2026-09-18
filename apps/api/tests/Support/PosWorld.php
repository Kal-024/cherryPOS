<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\TaxCode;
use App\Models\Terminal;
use App\Models\Uom;
use App\Services\Cash\ShiftService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * El mundo mínimo de una prueba: una sucursal, una caja y un cajero.
 *
 * Existe para que cada prueba hable de lo suyo. Montar a mano una sucursal, una
 * terminal, un empleado con rol, una bodega y un impuesto en cada archivo
 * convierte la intención de la prueba en ruido, y el día que cambie el esquema
 * hay que tocar veinte sitios.
 */
trait PosWorld
{
    protected Branch $branch;

    protected Terminal $terminal;

    protected Employee $cashier;

    protected Location $location;

    protected TaxCode $iva;

    protected Uom $unit;

    protected function bootPosWorld(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::create([
            'code' => '001',
            'name' => 'Sucursal de prueba',
            'timezone' => 'America/Managua',
            'is_headquarters' => true,
        ]);

        $this->terminal = Terminal::create([
            'branch_id' => $this->branch->id,
            'code' => 'CAJA-01',
            'name' => 'Caja 1',
            'secret_hash' => Hash::make('secreto-de-prueba'),
            'layout_profile' => 'scan_first',
        ]);

        $this->location = Location::create([
            'branch_id' => $this->branch->id,
            'code' => 'PRINCIPAL',
            'name' => 'Bodega principal',
            'is_sales_default' => true,
        ]);

        $this->iva = TaxCode::create([
            'code' => 'IVA',
            'name' => 'IVA 15 %',
            'rate' => '15.0000',
        ]);

        $this->unit = Uom::create(['code' => 'UND', 'name' => 'Unidad', 'decimals' => 0]);

        $this->cashier = $this->employee('CAJ01', '1234', 'cashier');

        DB::table('cmn_currencies')->insert([
            ['code' => 'NIO', 'name' => 'Córdoba', 'symbol' => 'C$', 'decimals' => 2, 'is_base' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'Dólar', 'symbol' => '$', 'decimals' => 2, 'is_base' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('pos_document_series')->insert([
            'id' => (string) Str::uuid7(),
            'branch_id' => $this->branch->id,
            'document_type' => 'counter',
            'year' => (int) now()->format('Y'),
            'template' => '{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}',
            'next_number' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function employee(string $code, string $pin, string $role, ?string $supervisorPin = null): Employee
    {
        $employee = Employee::createWithPerson(
            ['full_name' => "Empleado {$code}"],
            ['branch_id' => $this->branch->id, 'code' => $code]
        );

        $employee->setPin($pin);

        if ($supervisorPin !== null) {
            // El de autorizar es **otro**, distinto del de sesión (P-11).
            $employee->setSupervisorPin($supervisorPin);
        }

        $employee->save();

        DB::table('sec_employee_role')->insert([
            'employee_id' => $employee->id,
            'role_id' => Role::where('code', $role)->value('id'),
            'branch_id' => null,
        ]);

        return $employee;
    }

    /** @param array<string,mixed> $attributes */
    protected function product(string $sku, string $price, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => $sku,
            'name' => "Producto {$sku}",
            'uom_id' => $this->unit->id,
            'tax_code_id' => $this->iva->id,
            'price' => $price,
            'cost' => '0',
            'tracks_stock' => true,
            'allow_negative_stock' => true,
        ], $attributes));
    }

    protected function customer(string $name, string $kind = 'cash', ?string $nationalId = null): Customer
    {
        return Customer::createWithPerson(
            array_filter(['full_name' => $name, 'national_id' => $nationalId]),
            ['kind' => $kind]
        );
    }

    /**
     * Actúa como una terminal concreta.
     *
     * `Auth::forgetGuards()` es obligatorio al cambiar de token dentro de un
     * mismo test: el guard de Sanctum cachea el usuario resuelto y el
     * contenedor no se reinicia entre llamadas HTTP de una prueba, así que la
     * segunda petición seguiría viendo la terminal de la primera. En producción
     * no ocurre —cada petición levanta su propio contenedor— pero en pruebas
     * produce un fallo desconcertante: la venta se retoma en la caja
     * equivocada.
     */
    protected function actingAsTerminal(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** Token de terminal ya autenticada. */
    protected function terminalToken(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'CAJA-01',
            'secret' => 'secreto-de-prueba',
        ])->json('data.token');
    }

    /**
     * Terminal autenticada, cajero identificado **y turno abierto**: lista para
     * facturar.
     *
     * El turno va incluido porque es lo que pasa en la realidad —H4.1: nada se
     * registra fuera de un turno abierto— y porque una prueba que lo omitiera
     * estaría probando un camino que en producción no existe.
     */
    protected function signedIn(?Employee $employee = null, string $pin = '1234'): string
    {
        $token = $this->terminalToken();
        $operator = $employee ?? $this->cashier;

        $this->actingAsTerminal($token)->postJson('/api/operator/session', [
            'employee_code' => $operator->code,
            'pin' => $pin,
        ])->assertCreated();

        $this->openShiftFor($this->terminal, $operator);

        return $token;
    }

    /** Abre el turno de una terminal si no tiene uno. El turno es del equipo. */
    protected function openShiftFor(Terminal $terminal, Employee $employee, string $float = '1000.00'): Shift
    {
        return Shift::where('terminal_id', $terminal->id)->where('status', 'open')->first()
            ?? app(ShiftService::class)->open($terminal, $employee, $float);
    }
}
