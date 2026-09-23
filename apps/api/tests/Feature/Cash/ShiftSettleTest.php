<?php

namespace Tests\Feature\Cash;

use App\Models\CashMovement;
use App\Models\Shift;
use Database\Seeders\ReceiptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Cuadrar una caja ya cerrada (H4.3).
 *
 * Al cerrar aparecía el faltante o el sobrante y no había nada que hacer con él:
 * el turno quedaba descuadrado para siempre. No es un detalle de orden — el ERP
 * no emite el comprobante contable del día si la caja no cuadra.
 *
 * La corrección **no reescribe el arqueo** (P1): asienta un movimiento de caja
 * con motivo y autorización, y como lo esperado en el cajón ya suma los
 * movimientos, la diferencia se va a cero por aritmética. El conteo original
 * queda intacto, que es lo que permite auditar quién contó qué.
 */
class ShiftSettleTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    /** Cuadrar es del supervisor: su PIN de autorización es otro (P-11). */
    private const SUPERVISOR = ['code' => 'SUP01', 'pin' => '4321', 'authPin' => '9876'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        // El corte se imprime con la plantilla `shift_cut`, que trae la
        // instalación.
        $this->seed(ReceiptTemplateSeeder::class);

        $supervisor = $this->employee(
            self::SUPERVISOR['code'],
            self::SUPERVISOR['pin'],
            'supervisor',
            self::SUPERVISOR['authPin']
        );

        $this->token = $this->signedIn($supervisor, self::SUPERVISOR['pin']);
    }

    /**
     * Cierra el turno con el cajón descuadrado.
     *
     * Se abre con mil de fondo y se cuenta de más o de menos: la diferencia es
     * exactamente lo que el corte tiene que denunciar.
     */
    private function closedWith(array $counts): Shift
    {
        // El turno ya viene abierto con mil de fondo: entrar con el PIN lo abre,
        // porque nada se registra fuera de un turno (H4.1).
        $this->actingAsTerminal($this->token)
            ->postJson('/api/shifts/close', ['counts' => $counts])
            ->assertOk();

        return Shift::where('branch_id', $this->branch->id)->latest('closed_at')->firstOrFail();
    }

    /** Falta un billete de cien: se contaron 900 donde debía haber 1.000. */
    private function closedWithShortage(): Shift
    {
        return $this->closedWith([['denomination_value' => '100.00', 'count' => 9]]);
    }

    public function test_el_corte_dice_si_el_turno_quedo_cuadrado(): void
    {
        $shift = $this->closedWithShortage();

        $summary = $this->actingAsTerminal($this->token)
            ->getJson("/api/shifts/{$shift->id}/summary")->assertOk()->json('data');

        $this->assertFalse($summary['settled']);
        $this->assertSame('-100.00', $summary['by_currency'][0]['difference']);
    }

    public function test_cuadrar_deja_la_diferencia_en_cero_sin_tocar_el_conteo(): void
    {
        $shift = $this->closedWithShortage();

        $contadoAntes = $this->actingAsTerminal($this->token)
            ->getJson("/api/shifts/{$shift->id}/summary")->json('data.by_currency.0.counted');

        $summary = $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", [
            'reason' => 'Faltante del turno de la mañana',
            'supervisor_code' => self::SUPERVISOR['code'],
            'supervisor_pin' => self::SUPERVISOR['authPin'],
        ])->assertOk()->json('data');

        $this->assertTrue($summary['settled']);
        $this->assertSame('0.00', $summary['by_currency'][0]['difference']);

        // El arqueo no se reescribe: lo contado sigue siendo lo que se contó.
        $this->assertSame($contadoAntes, $summary['by_currency'][0]['counted']);
    }

    public function test_el_faltante_sale_del_cajon_y_el_sobrante_entra(): void
    {
        $shift = $this->closedWithShortage();

        $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", [
            'reason' => 'Faltante',
            'supervisor_code' => self::SUPERVISOR['code'],
            'supervisor_pin' => self::SUPERVISOR['authPin'],
        ])->assertOk();

        $movement = CashMovement::where('shift_id', $shift->id)->where('is_settlement', true)->firstOrFail();

        // Faltó: sale del cajón lo que no estaba. El signo distingue el faltante
        // del sobrante, y el asiento los distingue de un retiro normal.
        $this->assertSame('out', $movement->direction);
        $this->assertSame('100.00', $movement->amount);
        $this->assertTrue($movement->is_settlement);
        $this->assertNotNull($movement->authorized_by);
    }

    public function test_el_sobrante_tambien_cuadra(): void
    {
        // Sobra un billete de cien: aparecieron 1.100.
        $shift = $this->closedWith([['denomination_value' => '100.00', 'count' => 11]]);

        $summary = $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", [
            'reason' => 'Sobrante sin identificar',
            'supervisor_code' => self::SUPERVISOR['code'],
            'supervisor_pin' => self::SUPERVISOR['authPin'],
        ])->assertOk()->json('data');

        $this->assertTrue($summary['settled']);
        $this->assertSame('in', CashMovement::where('shift_id', $shift->id)
            ->where('is_settlement', true)->firstOrFail()->direction);
    }

    public function test_sin_pin_de_supervisor_no_se_cuadra(): void
    {
        $shift = $this->closedWithShortage();

        // El PIN de autorización es distinto del de sesión (P-11): mover plata
        // de un arqueo cerrado es donde conviene el segundo freno.
        $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", [
            'reason' => 'Faltante',
            'supervisor_code' => self::SUPERVISOR['code'],
            'supervisor_pin' => self::SUPERVISOR['pin'],
        ])->assertStatus(422);

        $this->assertSame(0, CashMovement::where('shift_id', $shift->id)->where('is_settlement', true)->count());
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $shift = $this->closedWithShortage();

        // Un ajuste sin motivo es un descuadre escondido.
        $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", [
            'supervisor_code' => self::SUPERVISOR['code'],
            'supervisor_pin' => self::SUPERVISOR['authPin'],
        ])->assertStatus(422);
    }

    public function test_un_turno_ya_cuadrado_no_se_vuelve_a_cuadrar(): void
    {
        $shift = $this->closedWithShortage();

        $payload = [
            'reason' => 'Faltante',
            'supervisor_code' => self::SUPERVISOR['code'],
            'supervisor_pin' => self::SUPERVISOR['authPin'],
        ];

        $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", $payload)->assertOk();
        $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", $payload)->assertStatus(422);
    }

    public function test_el_corte_se_emite_en_pdf(): void
    {
        $shift = $this->closedWithShortage();

        // El corte se entrega con el efectivo y se archiva: es el respaldo de lo
        // que había en el cajón cuando alguien lo contó.
        $response = $this->actingAsTerminal($this->token)
            ->get("/api/shifts/{$shift->id}/cut/pdf")
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_la_bandeja_lista_lo_que_falta_cuadrar(): void
    {
        $shift = $this->closedWithShortage();

        $pending = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts?pending_only=1')->assertOk()->json('data');

        $this->assertCount(1, $pending);
        $this->assertSame($shift->id, $pending[0]['id']);

        $this->actingAsTerminal($this->token)->postJson("/api/shifts/{$shift->id}/settle", [
            'reason' => 'Faltante',
            'supervisor_code' => self::SUPERVISOR['code'],
            'supervisor_pin' => self::SUPERVISOR['authPin'],
        ])->assertOk();

        // Vaciar esta bandeja es la condición para que el ERP cierre el día.
        $this->assertSame([], $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts?pending_only=1')->json('data'));
    }
}
