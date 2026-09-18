<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Sale;
use App\Services\Sales\DiscountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Descuentos con tope por cajero (D-02, H2.8).
 *
 * La decisión es específica y no es un permiso de sí o no: **varios cajeros
 * pueden tener habilitado el descuento, y cada uno con un tope distinto que fija
 * el supervisor**.
 *
 * Criterio de aceptación del hito, literal: **sobre el tope exige PIN de
 * supervisor, distinto al de sesión**.
 */
class DiscountTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private Employee $supervisor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        // Tope del 10 %: puede rebajar hasta ahí sin molestar a nadie.
        $this->cashier->forceFill(['discount_limit_percent' => '10.0000'])->save();

        // El de sesión es 4321; el de autorizar, 9876. Son distintos a
        // propósito (P-11): si fueran el mismo, en una semana lo conocería todo
        // el local.
        $this->supervisor = $this->employee('SUP01', '4321', 'supervisor', '9876');

        $this->token = $this->signedIn();
    }

    private function saleWithProduct(string $price = '1000.00'): string
    {
        $product = $this->product('P-001', $price);

        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '1',
        ])->assertCreated();

        return $sale;
    }

    public function test_dentro_del_tope_el_cajero_aplica_solo(): void
    {
        $sale = $this->saleWithProduct();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", ['type' => 'percent', 'value' => '8'])
            ->assertOk();

        $this->assertSame('80.00', Sale::find($sale)->sale_discount);
        // Sin interrupción, pero queda en auditoría.
        $this->assertDatabaseHas('sec_audit_log', ['event' => 'sale.discount']);
    }

    public function test_sobre_el_tope_se_rechaza_sin_autorizacion(): void
    {
        $sale = $this->saleWithProduct();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", ['type' => 'percent', 'value' => '25'])
            ->assertStatus(422)
            ->assertJsonPath('errors.discount.0', __('sales.discount_needs_authorization', ['limit' => '10.0000']));

        $this->assertSame('0.00', Sale::find($sale)->sale_discount);
    }

    public function test_sobre_el_tope_pasa_con_pin_de_supervisor(): void
    {
        $sale = $this->saleWithProduct();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", [
                'type' => 'percent',
                'value' => '25',
                'supervisor_code' => 'SUP01',
                'supervisor_pin' => '9876',
            ])
            ->assertOk();

        $updated = Sale::find($sale);

        $this->assertSame('250.00', $updated->sale_discount);
        // Queda registrado **quién** autorizó: ese es el punto de la auditoría.
        $this->assertSame($this->supervisor->id, $updated->discount_authorized_by);
        $this->assertDatabaseHas('sec_audit_log', [
            'event' => 'sale.discount_authorized',
            'authorized_by' => $this->supervisor->id,
        ]);
    }

    public function test_el_pin_de_sesion_del_supervisor_no_sirve_para_autorizar(): void
    {
        $sale = $this->saleWithProduct();

        // 4321 es con el que el supervisor abre su propia caja. Autorizar es
        // otra cosa y lleva otro PIN (P-11).
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", [
                'type' => 'percent',
                'value' => '25',
                'supervisor_code' => 'SUP01',
                'supervisor_pin' => '4321',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.supervisor_pin.0', __('auth.supervisor_pin_failed'));
    }

    public function test_un_cajero_no_puede_autorizarse_a_si_mismo(): void
    {
        $otro = $this->employee('CAJ02', '2222', 'cashier', '5555');
        $sale = $this->saleWithProduct();

        // Tiene PIN de supervisor cargado pero no el permiso de autorizar.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", [
                'type' => 'percent',
                'value' => '25',
                'supervisor_code' => $otro->code,
                'supervisor_pin' => '5555',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.supervisor_code.0', __('auth.supervisor_cannot_authorize'));
    }

    public function test_un_descuento_en_monto_se_mide_en_porcentaje_efectivo(): void
    {
        $policy = app(DiscountPolicy::class);

        // Sin esto, descontar "200 córdobas" sobre una venta de 210 esquivaría
        // cualquier tope expresado en porcentaje.
        $this->assertSame('95.2381', $policy->effectivePercent('amount', '200.00', '210.00'));
        $this->assertTrue($policy->requiresAuthorization($this->cashier, '95.2381', $this->branch->id));
        $this->assertFalse($policy->requiresAuthorization($this->cashier, '9.5000', $this->branch->id));
    }

    public function test_un_cajero_sin_tope_configurado_necesita_autorizacion_para_todo(): void
    {
        $policy = app(DiscountPolicy::class);
        $novato = $this->employee('CAJ03', '3333', 'cashier');

        // El defecto seguro es el restrictivo: un tope que nadie configuró no
        // significa "sin límite".
        $this->assertTrue($policy->requiresAuthorization($novato, '1.0000', $this->branch->id));
    }

    public function test_quitar_el_descuento_no_necesita_autorizacion(): void
    {
        $sale = $this->saleWithProduct();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", ['type' => 'percent', 'value' => '8'])
            ->assertOk();

        // Deshacer un favor no es un favor.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", [])
            ->assertOk();

        $this->assertSame('0.00', Sale::find($sale)->sale_discount);
        $this->assertNull(Sale::find($sale)->sale_discount_type);
    }

    public function test_el_descuento_se_reparte_entre_las_lineas_sin_perder_un_centavo(): void
    {
        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        foreach ([['A', '100.00'], ['B', '50.00'], ['C', '33.33']] as [$sku, $price]) {
            $product = $this->product($sku, $price);
            $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $product->id,
                'qty' => $sku === 'C' ? '3' : '1',
            ])->assertCreated();
        }

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", ['type' => 'percent', 'value' => '5'])
            ->assertOk();

        $updated = Sale::with('lines')->find($sale);
        $shares = $updated->lines->pluck('sale_discount_share')->map(fn ($v) => (string) $v)->all();

        // 100 + 50 + 3 × 33,33 = 249,99. El 5 % da 12,4995, que redondea a 12,50.
        $this->assertSame('12.50', (string) $updated->sale_discount);

        // El reparto lo hace el motor, verificado por los fixtures: las partes
        // suman **exactamente** el descuento, sin el centavo que se pierde
        // redondeando línea por línea.
        $this->assertSame('12.50', array_reduce($shares, fn ($c, $v) => bcadd($c, $v, 2), '0.00'));
    }

    public function test_la_auditoria_registra_el_tope_vigente_al_momento(): void
    {
        $sale = $this->saleWithProduct();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/discount", ['type' => 'percent', 'value' => '8'])
            ->assertOk();

        $log = AuditLog::where('event', 'sale.discount')->latest('occurred_at')->first();

        // Sin el tope registrado, en seis meses nadie podría decir si aquel
        // descuento estaba dentro de lo permitido: el tope pudo cambiar.
        $this->assertSame('10.0000', $log->context['limit']);
        $this->assertSame('8.0000', $log->context['effective_percent']);
    }
}
