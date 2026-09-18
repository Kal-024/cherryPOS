<?php

namespace Tests\Feature\Offline;

use App\Models\DocumentSeries;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SequenceReservation;
use App\Models\Terminal;
use App\Services\Inventory\StockLedgerService;
use App\Services\Sales\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Modo degradado (H6).
 *
 * El paso 7 del guion de cierre de F1-A, literal: **apagar el servidor, vender
 * tres tickets en efectivo, encenderlo y verificar que se envían solos y sin
 * duplicados**.
 *
 * Es también donde el motor de cálculo en TypeScript deja de ser un espejo: sin
 * servidor, el terminal calcula, y el servidor **recalcula al recibir**. Una
 * diferencia entre los dos es la misma alarma que `tax_difference` con el ERP.
 */
class OfflineSaleTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
    }

    private function reserve(int $size = 10): array
    {
        return $this->actingAsTerminal($this->token)
            ->postJson('/api/terminal/sequence/reserve', ['document_type' => 'counter', 'size' => $size])
            ->assertCreated()
            ->json('data');
    }

    /** @param array<string,mixed> $overrides */
    private function ticket(string $number, array $overrides = []): array
    {
        $product = Product::where('sku', 'P-001')->first()
            ?? $this->product('P-001', '115.00');

        return array_merge([
            // Lo genera el terminal, sin servidor: es toda la premisa.
            'id' => (string) Str::uuid7(),
            'number' => $number,
            'sale_type' => 'counter',
            'employee_id' => $this->cashier->id,
            'currency_code' => 'NIO',
            'opened_at' => now()->subMinutes(5)->toIso8601String(),
            'closed_at' => now()->subMinutes(4)->toIso8601String(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'kind' => 'product',
                'qty' => '1',
                'unit_price' => '115.00',
            ]],
            'payments' => [['method' => 'cash', 'amount' => '115.00']],
            'totals' => ['total' => '115.00'],
        ], $overrides);
    }

    public function test_la_terminal_reserva_un_bloque_de_correlativos(): void
    {
        $reservation = $this->reserve(50);

        $this->assertSame(1, $reservation['range_from']);
        $this->assertSame(50, $reservation['range_to']);

        // El bloque sale de la misma serie: la numeración en línea sigue desde
        // el 51 y no se pisa con la reservada.
        $this->assertSame(51, (int) DocumentSeries::first()->next_number);
    }

    public function test_dos_cajas_sin_conexion_no_emiten_el_mismo_numero(): void
    {
        $primera = $this->reserve(10);

        $otra = Terminal::create([
            'branch_id' => $this->branch->id,
            'code' => 'CAJA-02',
            'name' => 'Caja 2',
            'secret_hash' => Hash::make('otro-secreto'),
        ]);

        $segunda = app(DocumentNumberService::class)->reserve($otra, 'counter', 10);

        // Es el problema que hace difícil el modo degradado, y la razón de que
        // los bloques existan.
        $this->assertGreaterThan($primera['range_to'], $segunda->range_from);
    }

    public function test_reservar_de_nuevo_cierra_el_bloque_anterior(): void
    {
        $this->reserve(10);
        $this->reserve(10);

        // Los números sin usar del primero se pierden. Un hueco en la
        // numeración se explica; dos facturas con el mismo número no.
        $this->assertSame(1, SequenceReservation::where('is_active', true)->count());
        $this->assertSame(2, SequenceReservation::count());
    }

    public function test_un_ticket_cerrado_sin_servidor_se_registra_al_volver(): void
    {
        $this->reserve();

        $response = $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001'))
            ->assertCreated()
            ->json('data');

        $sale = Sale::find($response['sale_id']);

        $this->assertSame('completed', $sale->status);
        $this->assertSame('001-COU-2026-000001', $sale->number);
        // 115 con IVA dentro: base 100, impuesto 15.
        $this->assertSame('115.00', (string) $sale->total);
        $this->assertSame('15.00', (string) $sale->tax_total);
    }

    public function test_el_momento_del_hecho_y_el_del_registro_son_distintos(): void
    {
        $this->reserve();

        $closedAt = now()->subHours(3);

        $response = $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001', [
                'closed_at' => $closedAt->toIso8601String(),
            ]))
            ->assertCreated()->json('data');

        $sale = Sale::find($response['sale_id']);

        // Precondición 4: el consolidado no puede depender de cuándo llegó la
        // información, sino de cuándo ocurrió el hecho.
        $this->assertSame($closedAt->format('Y-m-d H:i'), $sale->closed_at->format('Y-m-d H:i'));
        $this->assertTrue($sale->recorded_at->greaterThan($sale->closed_at));
    }

    public function test_el_mismo_ticket_dos_veces_no_duplica_nada(): void
    {
        $this->reserve();
        $ticket = $this->ticket('001-COU-2026-000001');

        $this->actingAsTerminal($this->token)->postJson('/api/offline-sales', $ticket)->assertCreated();

        // El terminal reintenta por diseño: si esto fuera un error, reintentaría
        // para siempre algo ya hecho.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $ticket)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, Sale::count());
        $this->assertDatabaseCount('pos_sale_lines', 1);
    }

    public function test_tres_tickets_seguidos_entran_sin_pisarse(): void
    {
        $this->reserve();

        // El paso 7 del guion, tal cual: tres tickets en efectivo.
        foreach (['000001', '000002', '000003'] as $sequence) {
            $this->actingAsTerminal($this->token)
                ->postJson('/api/offline-sales', $this->ticket("001-COU-2026-{$sequence}"))
                ->assertCreated();
        }

        $this->assertSame(3, Sale::where('status', 'completed')->count());
        $this->assertSame(4, SequenceReservation::first()->next_number);
    }

    public function test_un_numero_fuera_del_bloque_se_rechaza(): void
    {
        $this->reserve(5);

        // Sin esta verificación, un terminal con un error de programación podría
        // inventar números y duplicar facturas.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000999'))
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', __('sequence.outside_reservation', [
                'number' => '001-COU-2026-000999', 'from' => '1', 'to' => '5',
            ]));

        $this->assertSame(0, Sale::count());
    }

    public function test_sin_reserva_no_se_reciben_tickets(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001'))
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', __('sequence.no_reservation'));
    }

    public function test_el_servidor_recalcula_y_avisa_si_los_motores_divergen(): void
    {
        $this->reserve();

        // El terminal declara 999: o su catálogo estaba viejo, o su motor
        // difiere del de PHP. En los dos casos hay que mirarlo.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001', [
                'totals' => ['total' => '999.00'],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.total', '115.00')
            ->assertJsonPath('data.total_difference', '-884.00');

        // Nunca se corrige en silencio: manda el servidor y queda el aviso.
        $this->assertDatabaseHas('pos_supervisor_notifications', [
            'event' => 'offline.total_difference',
            'severity' => 'critical',
        ]);
    }

    public function test_un_ticket_muy_viejo_no_entra_solo(): void
    {
        $this->reserve();

        // H6.5: pasado `pos_offline_max_hours` choca con el cierre de período y
        // con precios e impuestos ya cambiados.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001', [
                'closed_at' => now()->subHours(100)->toIso8601String(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.closed_at.0', __('offline.window_expired', ['hours' => '72']));
    }

    public function test_un_reloj_adelantado_se_detecta(): void
    {
        $this->reserve();

        // Aceptarlo metería una venta con fecha futura en el libro, y nadie va a
        // poder explicarla.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001', [
                'closed_at' => now()->addHours(2)->toIso8601String(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.closed_at.0', __('offline.closed_in_the_future'));
    }

    public function test_sin_conexion_no_se_vende_a_credito(): void
    {
        $this->reserve();

        // Alcance de H6: el límite y el bloqueo de la cuenta solo los sabe el
        // servidor. Prometer paridad sería prometer lo que no se sostiene.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001', [
                'payments' => [['method' => 'credit', 'amount' => '115.00']],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.payments.0', __('offline.credit_not_allowed'));
    }

    public function test_el_ticket_recibido_descuenta_existencias_con_su_fecha(): void
    {
        $this->reserve();

        $product = $this->product('P-001', '115.00', ['allow_negative_stock' => false]);
        $ledger = app(StockLedgerService::class);

        $ledger->record([
            'branch_id' => $this->branch->id,
            'location_id' => $this->location->id,
            'product_id' => $product->id,
            'reason' => 'receipt',
            'qty' => '10',
            'occurred_at' => now()->subDay(),
        ]);

        $closedAt = now()->subHours(2);

        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001', [
                'closed_at' => $closedAt->toIso8601String(),
            ]))
            ->assertCreated();

        $this->assertSame('9.0000', $ledger->available($product->id, $this->location->id));

        // El kardex cuenta cuándo salió la mercadería, no cuándo se enteró el
        // servidor.
        $movement = InventoryMovement::where('reason', 'sale')->firstOrFail();
        $this->assertSame($closedAt->format('Y-m-d H:i'), $movement->occurred_at->format('Y-m-d H:i'));
    }

    public function test_el_vuelto_queda_asentado_para_que_el_arqueo_cuadre(): void
    {
        $this->reserve();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001', [
                'payments' => [['method' => 'cash', 'amount' => '200.00']],
                'totals' => ['total' => '115.00'],
            ]))
            ->assertCreated();

        // Sin la fila negativa, el arqueo esperaría encontrar en el cajón los
        // 200 completos y el cierre parecería sobrante.
        $this->assertDatabaseHas('pos_payments', ['is_change' => true, 'amount' => '-85.00']);
    }

    public function test_el_bootstrap_entrega_lo_que_hace_falta_para_operar_sin_red(): void
    {
        $this->reserve(25);

        $data = $this->actingAsTerminal($this->token)
            ->getJson('/api/terminal/bootstrap')
            ->assertOk()
            ->json('data');

        $this->assertSame(72, $data['settings']['offline_max_hours']);
        $this->assertSame(25, $data['reservations']['counter']['remaining']);

        // `base` viaja con el impuesto porque el motor del terminal lo necesita:
        // decide si el precio trae el IVA dentro.
        $this->assertSame('gross', collect($data['tax_codes'])->firstWhere('code', 'IVA')['base']);
    }

    public function test_el_ticket_recibido_se_encola_hacia_el_erp(): void
    {
        config(['pos.erp.base_url' => 'https://erp.local/api', 'pos.erp.token' => 't']);
        Http::fake();

        $this->reserve();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001'))
            ->assertCreated();

        // El ticket que nació sin servidor termina igual en la cola del ERP: la
        // venta es la misma, haya pasado o no por la red al cobrarse.
        $this->assertDatabaseCount('pos_erp_outbox', 1);
    }

    public function test_el_registro_queda_en_la_bitacora(): void
    {
        $this->reserve();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/offline-sales', $this->ticket('001-COU-2026-000001'))
            ->assertCreated();

        $this->assertDatabaseHas('sec_audit_log', ['event' => 'sale.offline_received']);
    }
}
