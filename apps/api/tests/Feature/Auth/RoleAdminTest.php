<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Administración de roles (D-13, H7).
 *
 * Lo que se prueba son las tres barandas, no el CRUD: que `admin` quede fuera
 * del alcance de cualquiera —es quien puede devolver un permiso que alguien se
 * quitó por error, y en una instalación sin internet no hay a quién llamar—,
 * que un rol del sistema no se renombre porque hay código que lo nombra, y que
 * un rol con gente no se borre en medio de un turno.
 *
 * El cuarto caso es el que hace auditable todo lo anterior: cambiar quién puede
 * qué **queda registrado**.
 */
class RoleAdminTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->token = $this->signedIn($this->employee('ADM01', '2222', 'admin'), '2222');
    }

    public function test_el_supervisor_no_administra_roles(): void
    {
        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        // Administrar permisos no es parte de llevar el local: es configurar el
        // sistema.
        $this->actingAsTerminal($token)
            ->getJson('/api/security/roles')
            ->assertForbidden();
    }

    public function test_se_crea_un_rol_con_sus_permisos(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson('/api/security/roles', [
                'code' => 'bodeguero',
                'name' => 'Bodeguero',
                'permissions' => ['inventory.read', 'inventory.receive'],
            ])
            ->assertCreated();

        $role = Role::where('code', 'bodeguero')->first();

        $this->assertNotNull($role);
        $this->assertFalse($role->is_system);
        $this->assertEqualsCanonicalizing(
            ['inventory.read', 'inventory.receive'],
            $role->permissions->pluck('code')->all()
        );
    }

    public function test_el_rol_de_administrador_no_se_toca(): void
    {
        $admin = Role::where('code', 'admin')->firstOrFail();

        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/roles/{$admin->id}", ['permissions' => ['inventory.read']])
            ->assertStatus(422);

        // Sigue teniendo todo: si esto fallara, el negocio se quedaría sin nadie
        // capaz de devolver un permiso.
        $this->assertGreaterThan(10, $admin->fresh()->permissions()->count());
    }

    public function test_un_rol_del_sistema_ajusta_permisos_pero_no_se_renombra(): void
    {
        $cashier = Role::where('code', 'cashier')->firstOrFail();

        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/roles/{$cashier->id}", ['name' => 'Cajera'])
            ->assertStatus(422);

        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/roles/{$cashier->id}", [
                'permissions' => ['pos_sale.create', 'catalog.product.read'],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['pos_sale.create', 'catalog.product.read'],
            $cashier->fresh()->permissions->pluck('code')->all()
        );
    }

    public function test_un_rol_con_empleados_no_se_borra(): void
    {
        $cashier = Role::where('code', 'cashier')->firstOrFail();
        $custom = Role::create(['code' => 'temporal', 'name' => 'Temporal', 'is_system' => false]);

        // El del sistema, ni aunque estuviera vacío.
        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/security/roles/{$cashier->id}")
            ->assertStatus(422);

        $custom->employees()->attach($this->cashier->id, ['branch_id' => null]);

        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/security/roles/{$custom->id}")
            ->assertStatus(422);

        $custom->employees()->detach($this->cashier->id);

        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/security/roles/{$custom->id}")
            ->assertOk();

        $this->assertNull(Role::find($custom->id));
    }

    public function test_cambiar_permisos_queda_en_la_bitacora(): void
    {
        $cashier = Role::where('code', 'cashier')->firstOrFail();
        $before = $cashier->permissions->pluck('code')->all();

        $this->actingAsTerminal($this->token)
            ->putJson("/api/security/roles/{$cashier->id}", ['permissions' => ['pos_sale.create']])
            ->assertOk();

        $entry = AuditLog::where('event', 'role.permissions_changed')->latest('occurred_at')->first();

        $this->assertNotNull($entry);
        $this->assertSame('sec_roles', $entry->entity_type);
        $this->assertEqualsCanonicalizing($before, $entry->changes['before']);
        $this->assertSame(['pos_sale.create'], $entry->changes['after']);
    }

    public function test_el_catalogo_de_permisos_viene_agrupado_por_modulo(): void
    {
        $response = $this->actingAsTerminal($this->token)
            ->getJson('/api/security/permissions')
            ->assertOk();

        // La pantalla los muestra por módulo: mandarlos planos obligaría al
        // terminal a saber a qué módulo pertenece cada código.
        $this->assertArrayHasKey('venta', $response->json('data'));
    }
}
