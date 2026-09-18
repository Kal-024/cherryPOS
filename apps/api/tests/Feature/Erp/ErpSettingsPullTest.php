<?php

namespace Tests\Feature\Erp;

use App\Models\Employee;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * La configuración que baja del ERP (F1-C, §7 y §8 del contrato).
 *
 * **Se lee, no se escribe**: con ERP presente la configuración de caja es suya y
 * el POS la adopta (P2, P3). Lo que se prueba es que lo que baja **rija de
 * verdad** —no que se guarde en una tabla que nadie mira— y que un ERP caído no
 * detenga la caja (P4).
 */
class ErpSettingsPullTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        config([
            'pos.erp.base_url' => 'https://erp.local/api',
            'pos.erp.token' => 'token-de-prueba',
            'pos.erp.company_id' => 7,
        ]);

        $this->manager = $this->employee('ENC01', '3333', 'manager');
        $this->token = $this->signedIn($this->manager, '3333');
    }

    public function test_lo_que_baja_del_erp_rige_de_verdad(): void
    {
        $this->fakeErp(['pos_require_shift' => false, 'pos_offline_max_hours' => 24]);

        $this->actingAsTerminal($this->token)
            ->postJson('/api/erp/settings/pull')
            ->assertOk();

        app(SettingsRepository::class)->flush();

        // No alcanza con guardarlo: el turno deja de ser obligatorio de verdad,
        // que es lo que el ERP quiso decir.
        $this->assertFalse((bool) app(SettingsRepository::class)->get('pos.require_shift'));
        $this->assertSame(24, (int) app(SettingsRepository::class)->get('pos.offline_max_hours'));
    }

    public function test_el_perfil_de_pantalla_llega_a_la_terminal(): void
    {
        // La terminal de prueba declara el suyo; se lo quita para que rija el de
        // la empresa, que es el caso del contrato (§8).
        $this->terminal->forceFill(['layout_profile' => null])->save();

        $this->fakeErp([], ['pos_layout_profile' => 'restaurant']);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/settings/pull')->assertOk();

        app(SettingsRepository::class)->flush();

        // La terminal no declara perfil propio, así que rige el de la empresa
        // (§8): sin esto la caja abriría siempre en `scan_first`.
        $this->assertSame('restaurant', $this->terminal->fresh()->layoutProfile());
    }

    public function test_un_perfil_desconocido_se_ignora(): void
    {
        $this->terminal->forceFill(['layout_profile' => null])->save();

        $this->fakeErp([], ['pos_layout_profile' => 'kiosco_lunar']);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/settings/pull')->assertOk();

        app(SettingsRepository::class)->flush();

        // Dejar la caja sin pantalla que dibujar es peor que seguir con la
        // anterior.
        $this->assertSame('scan_first', $this->terminal->fresh()->layoutProfile());
    }

    public function test_un_erp_caido_no_detiene_la_caja(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->actingAsTerminal($this->token)
            ->postJson('/api/erp/settings/pull')
            ->assertStatus(503);

        // La venta sigue funcionando: el enlace caído no impide vender (P4).
        $product = $this->product('SKU-1', '115.00');
        $token = $this->signedIn();

        $sale = $this->actingAsTerminal($token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($token)
            ->postJson("/api/sales/{$sale}/lines", ['kind' => 'product', 'product_id' => $product->id, 'qty' => '1'])
            ->assertCreated();
    }

    public function test_sin_erp_configurado_rige_la_configuracion_local(): void
    {
        config(['pos.erp.base_url' => null, 'pos.erp.token' => null]);

        $this->actingAsTerminal($this->token)
            ->postJson('/api/erp/settings/pull')
            ->assertStatus(422);
    }

    public function test_el_estado_dice_que_falta_mandar(): void
    {
        $response = $this->actingAsTerminal($this->token)
            ->getJson('/api/erp/status')
            ->assertOk();

        $this->assertTrue($response->json('data.configured'));
        $this->assertSame(0, $response->json('data.queue.pending'));
        // Lo que rige hoy, venga de donde venga: es lo que hay que mirar cuando
        // la caja se comporta distinto de lo que alguien esperaba.
        $this->assertSame('scan_first', $response->json('data.effective.layout_profile'));
    }

    public function test_el_cajero_no_baja_configuracion(): void
    {
        $token = $this->signedIn();

        $this->actingAsTerminal($token)
            ->postJson('/api/erp/settings/pull')
            ->assertForbidden();
    }

    /**
     * @param  array<string,mixed>  $settings
     * @param  array<string,mixed>  $layout
     */
    private function fakeErp(array $settings, array $layout = []): void
    {
        Http::fake([
            '*/pos/settings' => Http::response(['data' => $settings, 'status' => 200], 200),
            '*/pos/layout' => Http::response(['data' => $layout, 'status' => 200], 200),
        ]);
    }
}
