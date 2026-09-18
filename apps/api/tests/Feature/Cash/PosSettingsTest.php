<?php

namespace Tests\Feature\Cash;

use App\Models\AuditLog;
use App\Models\Denomination;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Configuración operativa del POS (D-12, D-06, B-09, Q-07).
 *
 * Lo que se prueba es lo que la configuración puede romper: que el redondeo solo
 * acepte el vocabulario que entienden **los dos** motores de cálculo, que
 * encenderlo cambie de verdad lo que cobra la caja, que el cambio quede en la
 * bitácora —dos tickets del mismo día que cierran distinto tienen que poder
 * explicarse— y que una denominación se dé de baja sin borrarse, porque los
 * arqueos cerrados la referencian.
 */
class PosSettingsTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->token = $this->signedIn($this->employee('ENC01', '3333', 'manager'), '3333');
    }

    public function test_el_redondeo_solo_acepta_el_vocabulario_de_los_motores(): void
    {
        // Cualquier otro valor haría que PHP y TypeScript tomaran caminos
        // distintos, que es la falla que el proyecto más teme.
        $this->actingAsTerminal($this->token)
            ->putJson('/api/settings', ['settings' => ['cash.rounding_mode' => 'hacia_arriba']])
            ->assertStatus(422);

        $this->actingAsTerminal($this->token)
            ->putJson('/api/settings', ['settings' => ['cash.rounding_mode' => 'up', 'cash.rounding_increment' => '0.25']])
            ->assertOk();

        $settings = app(SettingsRepository::class);
        $settings->flush();

        $this->assertSame('up', $settings->get('cash.rounding_mode'));
    }

    public function test_el_redondeo_encendido_cambia_lo_que_cobra_la_caja(): void
    {
        $product = $this->product('SKU-1', '100.10');

        $this->actingAsTerminal($this->token)
            ->putJson('/api/settings', ['settings' => ['cash.rounding_mode' => 'up', 'cash.rounding_increment' => '0.25']])
            ->assertOk();

        app(SettingsRepository::class)->flush();

        $token = $this->signedIn();
        $sale = $this->actingAsTerminal($token)->postJson('/api/sales', ['sale_type' => 'counter'])->json('data.id');

        $response = $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '1',
        ])->assertCreated();

        // 100,10 sube al siguiente cuarto de córdoba: se cobran 100,25 porque la
        // moneda más chica del país es de 0,25 y no hay vuelto exacto posible.
        //
        // **El total fiscal no se toca**: el redondeo es del cobro, no del
        // documento. Tocarlo haría que el libro de ventas declarara una cifra
        // que ninguna línea justifica.
        $this->assertSame('100.10', $response->json('data.sale.total'));
        $this->assertSame('0.15', $response->json('data.sale.cash_rounding'));
    }

    public function test_cambiar_la_configuracion_queda_en_la_bitacora(): void
    {
        $this->actingAsTerminal($this->token)
            ->putJson('/api/settings', ['settings' => ['tax.fixed_quota_regime' => true]])
            ->assertOk();

        $entry = AuditLog::where('event', 'settings.changed')->latest('occurred_at')->firstOrFail();

        // Cuota fija desactiva el traslado de IVA en todo el sistema: no es una
        // preferencia de pantalla.
        $this->assertTrue($entry->changes['tax.fixed_quota_regime']['after']);
    }

    public function test_el_cajero_no_cambia_la_configuracion(): void
    {
        $token = $this->signedIn();

        $this->actingAsTerminal($token)
            ->putJson('/api/settings', ['settings' => ['cash.rounding_mode' => 'up']])
            ->assertForbidden();
    }

    public function test_una_denominacion_no_se_repite_y_se_da_de_baja_sin_borrarse(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson('/api/cash/denominations', [
                'currency_code' => 'NIO',
                'value' => '200',
                'kind' => 'bill',
            ])
            ->assertCreated();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/cash/denominations', [
                'currency_code' => 'NIO',
                'value' => '200',
                'kind' => 'bill',
            ])
            ->assertStatus(422);

        $denomination = Denomination::where('value', '200')->firstOrFail();

        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/cash/denominations/{$denomination->id}")
            ->assertOk();

        // Los arqueos ya cerrados la referencian: borrarla dejaría el histórico
        // sin poder cuadrar.
        $this->assertFalse($denomination->fresh()->is_active);
        $this->assertNotNull(Denomination::find($denomination->id));
    }
}
