<?php

namespace Tests\Feature\Erp;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ErpOutboxEntry;
use App\Models\Sale;
use App\Services\Erp\ErpOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * La bandeja de excepciones del ERP (P4, §6 del contrato).
 *
 * Lo que se prueba es la decisión que la bandeja existe para sostener: hay
 * rechazos que **se reintentan** —el ERP estaba caído, faltaba el cliente— y
 * hay rechazos que **nunca** se van a aceptar, como una tarjeta de regalo
 * mientras A3 siga abierto. Reintentar los segundos es gastar la cola para
 * siempre; borrarlos es perder el ticket sin explicación.
 */
class ErpOutboxTrayTest extends TestCase
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
        ]);

        // Un solo encargado para toda la prueba: crearlo en cada venta chocaría
        // con la cédula, que es la clave natural de la persona (B-11).
        $this->manager = $this->employee('ENC01', '3333', 'manager');
        $this->token = $this->asManager();
    }

    private function asManager(): string
    {
        return $this->signedIn($this->manager, '3333');
    }

    public function test_la_bandeja_pone_las_excepciones_primero(): void
    {
        $this->rejectedSale('instrument_unsupported');
        $this->pendingSale();

        $response = $this->actingAsTerminal($this->token)
            ->getJson('/api/erp/outbox')
            ->assertOk();

        // La bandeja responde "¿qué hay que atender?", y lo que hay que atender
        // es lo que el ERP rechazó.
        $this->assertSame('exception', $response->json('data.0.status'));
        $this->assertSame('instrument_unsupported', $response->json('data.0.error_code'));
    }

    public function test_reintentar_devuelve_la_entrada_a_la_cola_y_reinicia_el_retroceso(): void
    {
        $entry = $this->rejectedSale('customer_required');
        $entry->forceFill(['attempts' => 9])->save();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/erp/outbox/{$entry->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $fresh = $entry->fresh();

        // El contador vuelve a cero: conservar el retroceso de ayer haría
        // esperar horas por un envío que ya puede salir.
        $this->assertSame(0, $fresh->attempts);
        $this->assertSame('pending', Sale::find($entry->sale_id)->erp_status);
    }

    public function test_cerrar_a_mano_no_borra_y_queda_en_la_bitacora(): void
    {
        $entry = $this->rejectedSale('instrument_unsupported');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/erp/outbox/{$entry->id}/resolve", [
                'reason' => 'Tarjeta de regalo: el ERP no las acepta todavía (A3).',
            ])
            ->assertOk();

        $fresh = $entry->fresh();

        // Sigue existiendo y sigue diciendo qué pasó: solo deja de ocupar la
        // bandeja.
        $this->assertNotNull($fresh);
        $this->assertSame('exception', $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame('instrument_unsupported', $fresh->error_code);

        $this->actingAsTerminal($this->token)
            ->getJson('/api/erp/outbox')
            ->assertNotFound();

        $log = AuditLog::where('event', 'erp.outbox_resolved')->firstOrFail();
        $this->assertStringContainsString('A3', $log->changes['reason']);
    }

    public function test_cerrar_a_mano_exige_motivo(): void
    {
        $entry = $this->rejectedSale('instrument_unsupported');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/erp/outbox/{$entry->id}/resolve", [])
            ->assertStatus(422);
    }

    public function test_lo_que_esta_pendiente_no_se_cierra_a_mano(): void
    {
        $entry = $this->pendingSale();

        // Cerrarlo sería declarar resuelto algo que todavía puede salir solo.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/erp/outbox/{$entry->id}/resolve", ['reason' => 'porque sí'])
            ->assertStatus(422);
    }

    public function test_el_cajero_no_ve_la_bandeja(): void
    {
        $this->rejectedSale('instrument_unsupported');
        $token = $this->signedIn();

        $this->actingAsTerminal($token)
            ->getJson('/api/erp/outbox')
            ->assertForbidden();
    }

    private function pendingSale(): ErpOutboxEntry
    {
        return ErpOutboxEntry::where('sale_id', $this->closedSale()->id)->firstOrFail();
    }

    /** Una venta cerrada cuyo envío el ERP rechazó sin apelación (422). */
    private function rejectedSale(string $code): ErpOutboxEntry
    {
        $sale = $this->closedSale();
        $entry = ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail();

        Http::fake([
            '*' => Http::response(['error' => $code, 'message' => 'rechazado'], 422),
        ]);

        app(ErpOutboxService::class)->dispatch($entry);

        return $entry->fresh();
    }

    private function closedSale(): Sale
    {
        $product = $this->product('P-'.uniqid(), '100.00');
        $token = $this->signedIn();

        $sale = $this->actingAsTerminal($token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '1',
        ])->assertCreated();

        $total = Sale::find($sale)->total;

        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash',
            'amount' => (string) $total,
        ])->assertCreated();

        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/close")->assertOk();

        // Se vuelve al encargado, que es quien mira la bandeja.
        $this->token = $this->asManager();

        return Sale::find($sale);
    }
}
