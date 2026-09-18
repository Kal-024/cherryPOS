<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Ficha de empleado (D-05, D-02, D-13, P-11, H7).
 *
 * Lo que se prueba es lo que separa esta pantalla de un CRUD cualquiera: que el
 * PIN de supervisor sea **otro** —si coincidiera con el de sesión, quien viera
 * teclearlo podría autorizarse a sí mismo un descuento—, que un override
 * individual pueda **revocar** y no solo conceder, y que ningún hash salga
 * nunca en una respuesta.
 */
class EmployeeAdminTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->token = $this->signedIn($this->employee('ADM01', '2222', 'admin'), '2222');
    }

    public function test_la_respuesta_nunca_lleva_hashes(): void
    {
        $response = $this->actingAsTerminal($this->token)
            ->getJson("/api/security/employees/{$this->cashier->id}")
            ->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('pin_hash', $body);
        $this->assertStringNotContainsString('password_hash', $body);
        // Lo único que la pantalla necesita es si hay PIN puesto.
        $this->assertTrue($response->json('data.has_pin'));
    }

    public function test_se_crea_un_cajero_con_su_rol_y_su_tope_de_descuento(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson('/api/security/employees', [
                'name' => 'Marta Solís',
                'code' => 'CAJ09',
                'national_id' => '001-010190-0001M',
                'roles' => ['cashier'],
                // D-02: el tope es de la persona, no del rol.
                'discount_limit_percent' => '5',
            ])
            ->assertCreated()
            ->assertJsonPath('data.roles.0', 'cashier');

        $employee = Employee::where('code', 'CAJ09')->firstOrFail();

        $this->assertSame('Marta Solís', $employee->full_name);
        $this->assertSame('5.0000', (string) $employee->discount_limit_percent);
        // Nace sin PIN: ponerlo es un paso aparte y auditado.
        $this->assertNull($employee->pin_hash);
    }

    public function test_el_pin_de_supervisor_no_puede_ser_el_de_sesion(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/security/employees/{$this->cashier->id}/pin", [
                'pin' => '1234',
                'kind' => 'supervisor',
            ])
            ->assertStatus(422);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/security/employees/{$this->cashier->id}/pin", [
                'pin' => '9876',
                'kind' => 'supervisor',
            ])
            ->assertOk();

        $this->assertTrue($this->cashier->fresh()->checkSupervisorPin('9876'));
    }

    public function test_el_pin_no_queda_escrito_en_la_bitacora(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/security/employees/{$this->cashier->id}/pin", [
                'pin' => '4321',
                'kind' => 'session',
            ])
            ->assertOk();

        $entry = AuditLog::where('event', 'employee.pin_changed')->latest('occurred_at')->firstOrFail();

        $this->assertStringNotContainsString('4321', json_encode($entry->changes));
        $this->assertSame('session', $entry->changes['kind']);
    }

    public function test_un_override_revoca_un_permiso_que_el_rol_concede(): void
    {
        $this->assertContains('pos_sale.discount', $this->cashier->permissionCodes());

        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/employees/{$this->cashier->id}/overrides", [
                'overrides' => [['code' => 'pos_sale.discount', 'effect' => 'deny']],
            ])
            ->assertOk();

        // Sin la revocación habría que inventarle un rol propio a esta persona,
        // que es el modelo que D-13 dejó atrás.
        $this->assertNotContains('pos_sale.discount', $this->cashier->fresh()->permissionCodes());
    }

    public function test_un_override_concede_un_permiso_que_el_rol_no_tiene(): void
    {
        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/employees/{$this->cashier->id}/overrides", [
                'overrides' => [['code' => 'inventory.adjust', 'effect' => 'grant']],
            ])
            ->assertOk();

        $this->assertContains('inventory.adjust', $this->cashier->fresh()->permissionCodes());
    }

    public function test_guardar_overrides_reemplaza_la_lista_entera(): void
    {
        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/employees/{$this->cashier->id}/overrides", [
                'overrides' => [['code' => 'pos_sale.discount', 'effect' => 'deny']],
            ])
            ->assertOk();

        // Mandar la lista final y no lo agregado: si no, una revocación vieja
        // quedaría en pie sin que nadie la vea en pantalla.
        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/employees/{$this->cashier->id}/overrides", ['overrides' => []])
            ->assertOk();

        $this->assertSame(0, DB::table('sec_employee_permission')
            ->where('employee_id', $this->cashier->id)->count());
        $this->assertContains('pos_sale.discount', $this->cashier->fresh()->permissionCodes());
    }

    public function test_el_supervisor_desbloquea_un_pin_bloqueado(): void
    {
        $this->cashier->forceFill([
            'failed_pin_attempts' => 5,
            'pin_locked_until' => now()->addMinutes(15),
        ])->save();

        $this->assertTrue($this->cashier->fresh()->isPinLocked());

        $this->actingAsTerminal($this->token)
            ->postJson("/api/security/employees/{$this->cashier->id}/unlock")
            ->assertOk();

        $this->assertFalse($this->cashier->fresh()->isPinLocked());
    }

    public function test_el_cajero_no_administra_empleados(): void
    {
        $token = $this->signedIn();

        $this->actingAsTerminal($token)
            ->getJson('/api/security/employees')
            ->assertForbidden();
    }
}
