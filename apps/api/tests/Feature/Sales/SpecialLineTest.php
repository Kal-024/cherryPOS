<?php

namespace Tests\Feature\Sales;

use App\Models\Role;
use App\Models\Sale;
use App\Models\SupervisorNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Ítem temporal y venta por monto (D-03, H2.9).
 *
 * Las dos válvulas de escape del catálogo: vender algo que no está cargado, y
 * cobrar un monto suelto. Indispensables —siempre hay algo que vender que no
 * está en el sistema— y a la vez el agujero por donde se escapa el control de
 * inventario y por donde un cajero puede facturar cualquier cosa.
 *
 * Criterio de aceptación del hito, literal: **cinco por cajero por día; el sexto
 * exige autorización**. Y el aviso al supervisor sale **siempre**, dentro o
 * fuera del límite.
 */
class SpecialLineTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        // El rol `cashier` no trae el permiso de vender fuera del catálogo: no
        // es una operación de todos los días. Aquí se le da para poder probar
        // el límite, que es lo que interesa.
        $this->cashier->roles()->detach();
        $this->cashier->roles()->attach(Role::where('code', 'supervisor')->value('id'));

        $this->employee('SUP01', '4321', 'supervisor', '9876');

        $this->token = $this->signedIn();
    }

    private function openSale(): string
    {
        return $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->json('data.id');
    }

    /** @param array<string,mixed> $extra */
    private function addTemporary(string $sale, string $description, array $extra = []): TestResponse
    {
        return $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", array_merge([
                'kind' => 'temporary',
                'description' => $description,
                'qty' => '1',
                'unit_price' => '250.00',
            ], $extra));
    }

    public function test_el_cajero_debe_nombrar_lo_que_vendio(): void
    {
        $sale = $this->openSale();

        // Sin nombre, el aviso al supervisor diría que se vendió "algo" por 250
        // córdobas, que no sirve de nada (D-03).
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'temporary',
                'qty' => '1',
                'unit_price' => '250.00',
            ])
            ->assertStatus(422);
    }

    public function test_un_item_temporal_entra_en_la_venta(): void
    {
        $sale = $this->openSale();

        $this->addTemporary($sale, 'Cambio de empaque a pedido')->assertCreated();

        $line = Sale::with('lines')->find($sale)->lines->first();

        $this->assertSame('temporary', $line->kind);
        $this->assertNull($line->product_id);
        $this->assertSame('250.00', (string) $line->gross);
    }

    public function test_una_venta_por_monto_cobra_sin_producto(): void
    {
        $sale = $this->openSale();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'amount',
                'description' => 'Mano de obra',
                'amount' => '500.00',
            ])
            ->assertCreated();

        $line = Sale::with('lines')->find($sale)->lines->first();

        $this->assertSame('amount', $line->kind);
        $this->assertSame('1.0000', (string) $line->qty);
        $this->assertSame('500.00', (string) $line->total);
    }

    public function test_siempre_avisa_al_supervisor_aunque_este_dentro_del_limite(): void
    {
        $sale = $this->openSale();

        $this->addTemporary($sale, 'Tornillos sueltos')->assertCreated();

        // "Sea cual sea la decisión, siempre notificar al supervisor de este
        // tipo de acción" (D-03).
        $notice = SupervisorNotification::where('event', 'sale.special_line')->first();

        $this->assertNotNull($notice);
        $this->assertSame('Tornillos sueltos', $notice->context['description']);
        $this->assertSame('250.00', $notice->context['total']);
        $this->assertSame(1, $notice->context['used_today']);
        $this->assertSame(5, $notice->context['daily_limit']);
    }

    public function test_el_sexto_del_dia_exige_autorizacion(): void
    {
        $sale = $this->openSale();

        for ($i = 1; $i <= 5; $i++) {
            $this->addTemporary($sale, "Suelto {$i}")->assertCreated();
        }

        $this->addTemporary($sale, 'Suelto 6')
            ->assertStatus(422)
            ->assertJsonPath('errors.kind.0', __('sales.special_line_limit_reached', ['limit' => '5']));

        // Con el PIN del supervisor sí pasa, y queda anotado quién autorizó.
        $this->addTemporary($sale, 'Suelto 6', [
            'supervisor_code' => 'SUP01',
            'supervisor_pin' => '9876',
        ])->assertCreated();

        $this->assertDatabaseHas('sec_audit_log', ['event' => 'sale.special_line']);
        $this->assertSame(6, Sale::with('lines')->find($sale)->lines->count());
    }

    public function test_pasado_el_limite_el_aviso_sube_a_critico(): void
    {
        $sale = $this->openSale();

        for ($i = 1; $i <= 5; $i++) {
            $this->addTemporary($sale, "Suelto {$i}")->assertCreated();
        }

        $this->addTemporary($sale, 'Suelto 6', [
            'supervisor_code' => 'SUP01',
            'supervisor_pin' => '9876',
        ])->assertCreated();

        $last = SupervisorNotification::where('event', 'sale.special_line')
            ->orderByDesc('occurred_at')->first();

        $this->assertSame('critical', $last->severity);
    }

    public function test_un_limite_en_cero_significa_ilimitado(): void
    {
        // "Permitir que siempre pueda hacerlo" (D-03). Cero no es "prohibido":
        // prohibirlo se hace quitando el permiso.
        $this->cashier->forceFill(['temp_item_daily_limit' => 0])->save();

        $sale = $this->openSale();

        for ($i = 1; $i <= 7; $i++) {
            $this->addTemporary($sale, "Suelto {$i}")->assertCreated();
        }

        $this->assertSame(7, Sale::with('lines')->find($sale)->lines->count());
    }

    public function test_sin_permiso_no_se_vende_fuera_del_catalogo(): void
    {
        $novato = $this->employee('CAJ09', '9999', 'cashier');
        $token = $this->signedIn($novato, '9999');

        $sale = $this->actingAsTerminal($token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'temporary',
                'description' => 'Algo',
                'qty' => '1',
                'unit_price' => '10.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.kind.0', __('sales.special_line_not_allowed'));
    }

    public function test_el_limite_es_por_cajero_no_por_terminal(): void
    {
        $sale = $this->openSale();

        for ($i = 1; $i <= 5; $i++) {
            $this->addTemporary($sale, "Suelto {$i}")->assertCreated();
        }

        // Otro cajero en la misma caja empieza con su propio contador: el
        // límite es de la persona, no del equipo.
        $otro = $this->employee('CAJ08', '8888', 'supervisor');
        $token = $this->signedIn($otro, '8888');

        $otraVenta = $this->actingAsTerminal($token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($token)
            ->postJson("/api/sales/{$otraVenta}/lines", [
                'kind' => 'temporary',
                'description' => 'Primero del otro cajero',
                'qty' => '1',
                'unit_price' => '10.00',
            ])
            ->assertCreated();
    }
}
