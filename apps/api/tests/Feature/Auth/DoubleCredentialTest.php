<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Terminal;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Doble credencial: terminal autenticada + PIN de operador (D-05, H0.6).
 *
 * El criterio de aceptación del hito es literal: **cambiar de cajero sin
 * reautenticar la terminal**. En hora pico el operador cambia cada treinta
 * segundos; pedir usuario y contraseña en cada relevo es inviable, y es la
 * razón por la que existe todo este mecanismo.
 */
class DoubleCredentialTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    private function cashier(string $code, string $pin): Employee
    {
        $employee = Employee::createWithPerson(
            ['full_name' => "Cajero {$code}"],
            ['branch_id' => $this->branch->id, 'code' => $code]
        );

        $employee->setPin($pin);
        $employee->save();

        DB::table('sec_employee_role')->insert([
            'employee_id' => $employee->id,
            'role_id' => Role::where('code', 'cashier')->value('id'),
            'branch_id' => null,
        ]);

        return $employee;
    }

    private function terminalToken(): string
    {
        return $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'CAJA-01',
            'secret' => 'secreto-de-prueba',
        ])->json('data.token');
    }

    public function test_la_terminal_se_autentica_con_credenciales_reales(): void
    {
        $response = $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'CAJA-01',
            'secret' => 'secreto-de-prueba',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.terminal.code', 'CAJA-01')
            ->assertJsonPath('data.branch.code', '001')
            ->assertJsonStructure(['message', 'data' => ['token', 'expires_at'], 'status']);
    }

    public function test_el_secreto_incorrecto_no_distingue_terminal_inexistente(): void
    {
        $conSecretoMalo = $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'CAJA-01',
            'secret' => 'incorrecto',
        ]);

        $terminalInventada = $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'CAJA-99',
            'secret' => 'incorrecto',
        ]);

        $conSecretoMalo->assertStatus(422);
        $terminalInventada->assertStatus(422);

        // Enumerar terminales válidas le ahorraría trabajo a quien esté
        // probando secretos.
        $this->assertSame(
            $conSecretoMalo->json('errors'),
            $terminalInventada->json('errors')
        );
    }

    public function test_una_terminal_autenticada_todavia_no_puede_facturar(): void
    {
        $token = $this->terminalToken();

        // 423: la terminal es válida, pero no hay nadie identificado. Es el
        // estado "calculadora cerrada" de D-05.
        $this->withToken($token)
            ->getJson('/api/operator/me')
            ->assertStatus(423);
    }

    public function test_el_cajero_abre_su_sesion_con_pin(): void
    {
        $this->cashier('CAJ01', '1234');
        $token = $this->terminalToken();

        $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => 'CAJ01', 'pin' => '1234'])
            ->assertCreated()
            ->assertJsonPath('data.employee.code', 'CAJ01')
            ->assertJsonFragment(['pos_sale.create']);
    }

    /** El criterio de aceptación de H0.6, tal cual está escrito en el plan. */
    public function test_se_cambia_de_cajero_sin_reautenticar_la_terminal(): void
    {
        $this->cashier('CAJ01', '1111');
        $this->cashier('CAJ02', '2222');

        $token = $this->terminalToken();

        $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => 'CAJ01', 'pin' => '1111'])
            ->assertCreated();

        $this->withToken($token)
            ->getJson('/api/operator/session')
            ->assertOk()
            ->assertJsonPath('data.employee.code', 'CAJ01');

        // El relevo: mismo token de terminal, otro PIN.
        $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => 'CAJ02', 'pin' => '2222'])
            ->assertCreated()
            ->assertJsonPath('data.employee.code', 'CAJ02');

        $this->withToken($token)
            ->getJson('/api/operator/session')
            ->assertOk()
            ->assertJsonPath('data.employee.code', 'CAJ02');

        // La sesión del primero quedó cerrada, no abierta en paralelo: una
        // terminal tiene un operador a la vez.
        $this->assertSame(1, DB::table('sec_operator_sessions')->whereNull('closed_at')->count());
    }

    public function test_el_pin_se_bloquea_tras_cinco_intentos_fallidos(): void
    {
        $employee = $this->cashier('CAJ01', '1234');
        $token = $this->terminalToken();

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)
                ->postJson('/api/operator/session', ['employee_code' => 'CAJ01', 'pin' => '0000'])
                ->assertStatus(422);
        }

        // Es lo que hace aceptable un secreto de cuatro dígitos: sin bloqueo,
        // diez mil intentos son minutos.
        $this->assertTrue($employee->fresh()->isPinLocked());

        $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => 'CAJ01', 'pin' => '1234'])
            ->assertStatus(422);
    }

    public function test_los_codigos_no_distinguen_mayusculas_ni_espacios(): void
    {
        $this->cashier('CAJ01', '1234');

        // Nadie frente a una caja distingue `CAJA-01` de `caja-01`, y exigir la
        // forma exacta convierte un error de tecleo en "PIN incorrecto": el
        // mensaje culpa al PIN, el cajero lo repite, y a los cinco intentos se
        // bloquea a sí mismo por haber escrito bien el PIN.
        $token = $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'caja-01',
            'secret' => 'secreto-de-prueba',
        ])->assertOk()->json('data.token');

        $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => '  caj01 ', 'pin' => '1234'])
            ->assertCreated();
    }

    public function test_un_codigo_de_cajero_que_no_existe_nombra_los_dos_campos(): void
    {
        $this->cashier('CAJ01', '1234');
        $token = $this->terminalToken();

        $response = $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => 'NO-EXISTE', 'pin' => '1234'])
            ->assertStatus(422);

        // No se dice **cuál** de los dos falló —eso sería decir qué códigos
        // existen— pero se nombran los dos: con "PIN incorrecto" a secas, nadie
        // vuelve a mirar el código.
        // El idioma lo pone la terminal; lo que se fija es que el texto sea el
        // combinado y no el que culpa solo al PIN.
        $this->assertSame(__('auth.operator_failed'), $response->json('errors.employee_code.0'));
        $this->assertNotSame(__('auth.pin_failed'), $response->json('errors.employee_code.0'));
    }

    public function test_el_limite_de_intentos_explica_la_espera_en_vez_de_decir_too_many_attempts(): void
    {
        // La protección existía; su respuesta era la de fábrica, en inglés y sin
        // decir qué hacer. En una caja eso se lee como "las credenciales no
        // sirven": el cajero se equivoca unas cuantas veces y, a partir de ahí,
        // **el dato correcto también falla** sin ninguna pista de que hay que
        // esperar un minuto.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/terminal/login', [
                'branch_code' => '001',
                'terminal_code' => 'CAJA-01',
                'secret' => 'incorrecto',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'CAJA-01',
            'secret' => 'secreto-de-prueba',
        ])->assertStatus(429);

        $response->assertJsonPath('error', 'too_many_attempts');
        // El idioma lo pone la terminal; lo que se fija acá es que el mensaje
        // sea **del sistema** y diga cuánto falta, en vez del texto de fábrica.
        $this->assertStringNotContainsString('Too Many Attempts', $response->json('message'));
        $this->assertStringContainsString('60', $response->json('message'));
    }

    public function test_el_cajero_cierra_su_sesion_y_la_terminal_sigue_autenticada(): void
    {
        $this->cashier('CAJ01', '1234');
        $token = $this->terminalToken();

        $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => 'CAJ01', 'pin' => '1234'])
            ->assertCreated();

        $this->withToken($token)->deleteJson('/api/operator/session')->assertOk();

        // Sin cajero: 423. Pero el token de terminal sigue sirviendo, que es lo
        // que evita reautenticar el equipo en cada relevo.
        $this->withToken($token)->getJson('/api/operator/session')->assertStatus(404);
        $this->withToken($token)
            ->postJson('/api/operator/session', ['employee_code' => 'CAJ01', 'pin' => '1234'])
            ->assertCreated();
    }

    public function test_los_permisos_del_cajero_se_resuelven_por_sucursal(): void
    {
        $employee = $this->cashier('CAJ01', '1234');

        $codes = $employee->permissionCodes($this->branch->id);

        $this->assertContains('pos_sale.create', $codes);
        // Un cajero no autoriza descuentos sobre el tope: para eso está el PIN
        // de supervisor (D-02, P-11).
        $this->assertNotContains('pos_sale.discount_authorize', $codes);
    }

    public function test_un_override_individual_revoca_un_permiso_del_rol(): void
    {
        $employee = $this->cashier('CAJ01', '1234');

        DB::table('sec_employee_permission')->insert([
            'employee_id' => $employee->id,
            'permission_id' => DB::table('sec_permissions')->where('code', 'pos_sale.discount')->value('id'),
            'effect' => 'deny',
            'branch_id' => null,
        ]);

        // Sin la revocación habría que inventarle un rol propio a esta persona.
        $this->assertNotContains('pos_sale.discount', $employee->permissionCodes($this->branch->id));
        $this->assertContains('pos_sale.create', $employee->permissionCodes($this->branch->id));
    }
}
