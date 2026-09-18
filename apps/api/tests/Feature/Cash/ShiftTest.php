<?php

namespace Tests\Feature\Cash;

use App\Models\Product;
use App\Models\Shift;
use App\Services\Sales\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Turno de caja y arqueo (G-04, D-06, H4).
 *
 * **El turno es del equipo, con corte por cajero.** El cajón de dinero es físico
 * y el arqueo cuenta ese cajón; dentro del turno cada venta queda atribuida a su
 * cajero. Un turno por persona obligaría a contar la caja en cada relevo, que es
 * justo lo que la doble credencial de D-05 quiso evitar.
 *
 * El arqueo de OSPOS no estaba atado a un turno, y por eso no podía responder
 * *"¿qué vendió la caja 2 entre las 14:00 y las 22:00 del martes?"*.
 */
class ShiftTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
    }

    private function signIn(): string
    {
        return $this->token = $this->signedIn();
    }

    /**
     * Entra como supervisor.
     *
     * Mover plata del cajón no es una operación de cajero: un retiro al banco o
     * un fondo adicional los decide quien responde por la caja. El rol `cashier`
     * no trae `pos_cash.movement` a propósito.
     */
    private function signInAsSupervisor(): string
    {
        $supervisor = $this->employee('SUP01', '4321', 'supervisor', '9876');

        return $this->token = $this->signedIn($supervisor, '4321');
    }

    /** Terminal y cajero listos, pero **sin** turno abierto. */
    private function signInWithoutShift(): string
    {
        $token = $this->terminalToken();

        $this->actingAsTerminal($token)->postJson('/api/operator/session', [
            'employee_code' => $this->cashier->code,
            'pin' => '1234',
        ])->assertCreated();

        return $token;
    }

    private function sell(string $price, string $payment, array $extra = []): string
    {
        $product = Product::where('sku', 'P-001')->first() ?? $this->product('P-001', $price);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/payments", array_merge([
                'method' => 'cash', 'amount' => $payment,
            ], $extra))
            ->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        return $sale;
    }

    public function test_no_se_vende_sin_turno_abierto(): void
    {
        $token = $this->signInWithoutShift();

        // H4.1: nada se registra fuera de un turno abierto. Sin esto el arqueo
        // no tiene a qué compararse.
        $this->actingAsTerminal($token)
            ->postJson('/api/sales', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.shift.0', __('shift.none_open'));
    }

    public function test_se_abre_el_turno_con_su_fondo_inicial(): void
    {
        $token = $this->signInWithoutShift();

        $shift = $this->actingAsTerminal($token)
            ->postJson('/api/shifts', [
                'opening_float' => '1000.00',
                'counts' => [
                    ['denomination_value' => '500.00', 'count' => 1],
                    ['denomination_value' => '100.00', 'count' => 5],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('open', $shift['status']);
        $this->assertSame('1000.00', $shift['opening_float']);
        $this->assertDatabaseCount('pos_cash_counts', 2);
    }

    public function test_una_terminal_no_tiene_dos_turnos_abiertos(): void
    {
        $token = $this->signIn();

        // Lo impide el servicio y, por debajo, un índice parcial en la base: el
        // cajón es uno solo.
        $this->actingAsTerminal($token)
            ->postJson('/api/shifts', ['opening_float' => '500.00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.terminal.0', __('shift.already_open'));
    }

    public function test_el_esperado_suma_fondo_ventas_y_descuenta_el_vuelto(): void
    {
        $this->signIn();
        // Precio 100 con IVA dentro. Paga con 200, se lleva 100 de vuelto.
        $this->sell('100.00', '200.00');

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        $nio = collect($summary['by_currency'])->firstWhere('currency_code', 'NIO');

        // 1.000 de fondo + 200 recibidos − 100 de vuelto = 1.100 en el cajón.
        // Sin registrar el vuelto, el esperado diría 1.200 y el arqueo siempre
        // parecería faltante.
        $this->assertSame('1100.00', $nio['expected']);
    }

    public function test_el_arqueo_cuadra_por_denominacion(): void
    {
        $this->signIn();
        $this->sell('100.00', '200.00');

        $summary = $this->actingAsTerminal($this->token)
            ->postJson('/api/shifts/close', [
                'counts' => [
                    ['denomination_value' => '1000.00', 'count' => 1],
                    ['denomination_value' => '100.00', 'count' => 1],
                ],
            ])
            ->assertOk()
            ->json('data');

        $nio = collect($summary['by_currency'])->firstWhere('currency_code', 'NIO');

        $this->assertSame('1100.00', $nio['expected']);
        $this->assertSame('1100.00', $nio['counted']);
        $this->assertSame('0.00', $nio['difference']);
        $this->assertSame('closed', Shift::first()->status);
    }

    public function test_el_faltante_se_explica_por_denominacion(): void
    {
        $this->signIn();
        $this->sell('100.00', '200.00');

        $summary = $this->actingAsTerminal($this->token)
            ->postJson('/api/shifts/close', [
                'counts' => [
                    ['denomination_value' => '1000.00', 'count' => 1],
                    ['denomination_value' => '50.00', 'count' => 1],
                ],
            ])
            ->assertOk()
            ->json('data');

        $nio = collect($summary['by_currency'])->firstWhere('currency_code', 'NIO');

        // "Falta plata" se convierte en "faltan 50": eso es lo que compra el
        // desglose por denominación (D-06).
        $this->assertSame('-50.00', $nio['difference']);
        $this->assertSame(
            [['currency_code' => 'NIO', 'denomination' => '1000.00', 'count' => 1, 'subtotal' => '1000.00'],
                ['currency_code' => 'NIO', 'denomination' => '50.00', 'count' => 1, 'subtotal' => '50.00']],
            $summary['denominations']
        );
    }

    public function test_el_sobrante_se_distingue_del_faltante(): void
    {
        $this->signIn();

        $summary = $this->actingAsTerminal($this->token)
            ->postJson('/api/shifts/close', [
                'counts' => [['denomination_value' => '1000.00', 'count' => 1],
                    ['denomination_value' => '20.00', 'count' => 1]],
            ])
            ->assertOk()->json('data');

        $nio = collect($summary['by_currency'])->firstWhere('currency_code', 'NIO');

        // Sobrar no es lo mismo que faltar, aunque las dos descuadren.
        $this->assertSame('20.00', $nio['difference']);
    }

    public function test_el_arqueo_desglosa_cordobas_y_dolares_por_separado(): void
    {
        app(ExchangeRateService::class)->set('USD', '36.624300', $this->cashier->id);
        $this->signIn();

        // Q-06: pagar en dólares en caja es rutina en Nicaragua.
        $this->sell('100.00', '10.00', ['currency_code' => 'USD']);

        $summary = $this->actingAsTerminal($this->token)
            ->postJson('/api/shifts/close', [
                'counts' => [
                    ['denomination_value' => '1000.00', 'count' => 1],
                    ['denomination_value' => '10.00', 'count' => 1, 'currency_code' => 'USD'],
                ],
            ])
            ->assertOk()->json('data');

        $byCurrency = collect($summary['by_currency'])->keyBy('currency_code');

        // 10 USD = 366,24 córdobas: sobra vuelto, que sale en moneda base.
        $this->assertSame('10.00', $byCurrency['USD']['expected']);
        $this->assertSame('10.00', $byCurrency['USD']['counted']);
        $this->assertSame('0.00', $byCurrency['USD']['difference']);

        // Un total consolidado escondería que sobran córdobas y faltan dólares.
        $this->assertNotSame($byCurrency['NIO']['expected'], $byCurrency['USD']['expected']);
    }

    public function test_el_corte_desglosa_las_ventas_por_cajero(): void
    {
        $this->signIn();
        $this->sell('100.00', '100.00');

        // El relevo de D-05: otro cajero entra en la misma caja, mismo turno.
        $otro = $this->employee('CAJ02', '2222', 'cashier');
        $this->token = $this->signedIn($otro, '2222');
        $this->sell('100.00', '100.00');

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        $byCashier = collect($summary['by_cashier'])->keyBy('employee_code');

        // El cajón se cuenta una vez, pero se sabe quién vendió qué. Es la
        // mitad que hace útil el turno del equipo.
        $this->assertCount(2, $byCashier);
        $this->assertSame(1, $byCashier['CAJ01']['sales']);
        $this->assertSame(1, $byCashier['CAJ02']['sales']);
        $this->assertSame('100.00', $byCashier['CAJ02']['total']);
        $this->assertSame(1, Shift::count());
    }

    public function test_un_retiro_de_caja_exige_motivo(): void
    {
        $this->signInAsSupervisor();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/cash/movements', ['direction' => 'out', 'amount' => '500.00'])
            ->assertStatus(422);

        $this->actingAsTerminal($this->token)
            ->postJson('/api/cash/movements', [
                'direction' => 'out',
                'amount' => '500.00',
                'reason' => 'Retiro parcial al banco',
            ])
            ->assertCreated();

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        $nio = collect($summary['by_currency'])->firstWhere('currency_code', 'NIO');

        // Un retiro sin motivo es la fila que aparece cuando el arqueo no cuadra
        // y nadie sabe explicar.
        $this->assertSame('500.00', $nio['expected']);
        $this->assertDatabaseHas('sec_audit_log', ['event' => 'cash.out']);
    }

    public function test_una_entrada_de_caja_suma_al_esperado(): void
    {
        $this->signInAsSupervisor();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/cash/movements', [
                'direction' => 'in',
                'amount' => '300.00',
                'reason' => 'Fondo adicional para vuelto',
            ])
            ->assertCreated();

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        $this->assertSame(
            '1300.00',
            collect($summary['by_currency'])->firstWhere('currency_code', 'NIO')['expected']
        );
    }

    public function test_no_se_mueve_caja_con_el_turno_cerrado(): void
    {
        $this->signInAsSupervisor();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/shifts/close', [
                'counts' => [['denomination_value' => '1000.00', 'count' => 1]],
            ])->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/cash/movements', [
                'direction' => 'out', 'amount' => '10.00', 'reason' => 'Tarde',
            ])
            ->assertStatus(404);
    }

    public function test_el_corte_informa_lo_vendido_y_como_se_cobro(): void
    {
        $this->signIn();
        $this->sell('100.00', '100.00');

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        // Mínimo operativo de F1 (P-07): sin esto el negocio no cierra el día.
        $this->assertSame(1, $summary['sales']['count']);
        $this->assertSame('100.00', $summary['sales']['total']);
        $this->assertSame('13.04', $summary['sales']['tax_total']);
        $this->assertSame('100.00', $summary['sales']['by_method']['cash']);
    }

    public function test_el_cierre_queda_en_la_bitacora_con_las_diferencias(): void
    {
        $this->signIn();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/shifts/close', [
                'counts' => [['denomination_value' => '500.00', 'count' => 1]],
            ])->assertOk();

        $log = DB::table('sec_audit_log')->where('event', 'shift.closed')->first();
        $context = json_decode($log->context, true);

        $this->assertSame('-500.00', $context['differences'][0]['difference']);
    }

    public function test_un_cajero_no_mueve_el_cajon_por_su_cuenta(): void
    {
        $this->signIn();

        // Un retiro al banco lo decide quien responde por la caja, no quien la
        // opera. El rol `cashier` no trae `pos_cash.movement`.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/cash/movements', [
                'direction' => 'out', 'amount' => '500.00', 'reason' => 'Retiro',
            ])
            ->assertStatus(403);
    }

    public function test_el_turno_congela_la_tasa_del_dia(): void
    {
        app(ExchangeRateService::class)->set('USD', '36.500000', $this->cashier->id);
        $this->signIn();

        $shift = Shift::first();
        $this->assertSame('36.500000', (string) $shift->exchange_rate);

        // El supervisor carga otra tasa a media tarde: el turno ya abierto sigue
        // con la suya. Releerlo mañana con la tasa de mañana daría otro número.
        app(ExchangeRateService::class)->set('USD', '36.900000', $this->cashier->id);

        $this->assertSame('36.500000', (string) $shift->fresh()->exchange_rate);
    }
}
