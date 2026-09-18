<?php

namespace Tests\Feature\Erp;

use App\Models\ErpOutboxEntry;
use App\Models\ProductComponent;
use App\Models\Sale;
use App\Models\SupervisorNotification;
use App\Services\Erp\ErpOutboxService;
use App\Services\Erp\ErpTicketPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Cola hacia cherryERP (§2, §5 y §6 del contrato).
 *
 * `cherryB` implementó su lado antes de que cherryPOS escribiera una línea, y
 * sus comentarios referencian `docs/CONTRATO-CHERRYB.md` por nombre. Estas
 * pruebas son lo que impide que las dos implementaciones se separen sin que
 * nadie lo note.
 *
 * **Ante divergencia gana cherryB**: si una de estas pruebas falla contra el ERP
 * real, se corrige el POS y el contrato, no el ERP.
 */
class ErpOutboxTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        config([
            'pos.erp.base_url' => 'https://erp.local/api',
            'pos.erp.token' => 'token-de-prueba',
            'pos.erp.company_id' => 7,
        ]);

        $this->token = $this->signedIn();
    }

    private function closedSale(string $qty = '2', string $price = '100.00'): Sale
    {
        $product = $this->product('P-001', $price);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => $qty,
        ])->assertCreated();

        $total = Sale::find($sale)->total;

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash',
            'amount' => (string) $total,
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        return Sale::with('lines.taxes', 'terminal')->find($sale);
    }

    public function test_el_ticket_tiene_la_forma_que_valida_el_erp(): void
    {
        Http::fake();
        $sale = $this->closedSale();

        $payload = app(ErpTicketPayload::class)->build($sale);

        // Campos obligatorios de `PosSaleController::store`.
        $this->assertSame($sale->id, $payload['uuid']);
        $this->assertSame($sale->number, $payload['number']);
        $this->assertSame('CAJA-01', $payload['terminal_code']);
        $this->assertSame('NIO', $payload['currency_code']);
        $this->assertArrayHasKey('closed_at', $payload);

        $line = $payload['lines'][0];
        $this->assertSame('2.0000', $line['qty']);
        $this->assertSame('100.0000', $line['unit_price']);
        $this->assertSame('IVA', $line['tax_code']);
        $this->assertSame('product', $line['kind']);
        $this->assertLessThanOrEqual(200, mb_strlen($line['description']));

        // 2 × 100,00 con IVA dentro: base 173,91, impuesto 26,09.
        $this->assertSame('26.09', $payload['totals']['tax_total']);
        $this->assertSame('200.00', $payload['totals']['total']);
    }

    public function test_una_devolucion_va_con_lineas_en_positivo_y_totales_en_negativo(): void
    {
        Http::fake();

        // El signo fiscal lo pone el tipo de documento (`NCF`, sign −1), no el
        // cuerpo. Internamente el POS guarda la devolución con cantidades
        // negativas —así el motor la trata como el negativo exacto de su venta—
        // y el adaptador invierte solo la cantidad.
        $product = $this->product('P-002', '100.00', ['allow_negative_stock' => true]);

        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', ['sale_type' => 'refund'])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '-1',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash',
            'amount' => '-100.00',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $payload = app(ErpTicketPayload::class)->build(Sale::with('lines.taxes', 'terminal')->find($sale));

        $this->assertSame('1.0000', $payload['lines'][0]['qty']);
        $this->assertSame('-100.00', $payload['totals']['total']);
        $this->assertSame('-13.04', $payload['totals']['tax_total']);
    }

    public function test_un_combo_viaja_con_su_marca_de_grupo(): void
    {
        Http::fake();

        $burger = $this->product('HAMB', '120.00');
        $soda = $this->product('GASEOSA', '40.00');
        $combo = $this->product('COMBO', '140.00', ['is_composite' => true]);

        foreach ([$burger, $soda] as $part) {
            ProductComponent::create([
                'parent_id' => $combo->id,
                'component_id' => $part->id,
                'qty' => '1',
            ]);
        }

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $combo->id,
            'qty' => '1',
        ])->assertCreated();

        $payload = app(ErpTicketPayload::class)->build(Sale::with('lines.taxes', 'terminal')->find($sale));

        // El ERP no necesita saber qué es un combo: con `group_name` la factura
        // igual dice "Producto COMBO · Producto HAMB".
        $this->assertCount(2, $payload['lines']);
        $this->assertSame('Producto COMBO', $payload['lines'][0]['group_name']);
        $this->assertCount(1, array_unique(array_column($payload['lines'], 'group_ref')));
    }

    public function test_el_201_marca_el_ticket_como_emitido(): void
    {
        Http::fake(['*/pos/sales' => Http::response([
            'data' => [
                'ticket_uuid' => 'x',
                'billing_document_id' => 4321,
                'document_number' => 'CSI-000045',
                'status' => 'issued',
                'duplicate' => false,
                'tax_difference' => '0.00',
            ],
            'status' => 201,
        ], 201)]);

        $sale = $this->closedSale();
        $entry = ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail();

        app(ErpOutboxService::class)->dispatch($entry);

        $this->assertSame('sent', $entry->fresh()->status);
        $this->assertSame('issued', $sale->fresh()->erp_status);
        $this->assertSame('CSI-000045', $sale->fresh()->erp_document_number);
    }

    public function test_el_200_del_duplicado_es_exito_no_error(): void
    {
        // Para la cola tiene que ser indistinguible del éxito. Si fuera un
        // error, reintentaría para siempre algo ya hecho (§5 del contrato).
        Http::fake(['*/pos/sales' => Http::response([
            'data' => [
                'ticket_uuid' => 'x',
                'billing_document_id' => 4321,
                'document_number' => 'CSI-000045',
                'status' => 'issued',
                'duplicate' => true,
                'tax_difference' => '0.00',
            ],
            'status' => 200,
        ], 200)]);

        $sale = $this->closedSale();
        $entry = ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail();

        app(ErpOutboxService::class)->dispatch($entry);

        $this->assertSame('sent', $entry->fresh()->status);
        $this->assertTrue($entry->fresh()->duplicate);
    }

    public function test_una_diferencia_de_impuesto_es_alarma_no_dato(): void
    {
        Http::fake(['*/pos/sales' => Http::response([
            'data' => [
                'ticket_uuid' => 'x',
                'billing_document_id' => 4321,
                'document_number' => 'CSI-000045',
                'status' => 'issued',
                'duplicate' => false,
                'tax_difference' => '0.03',
            ],
            'status' => 201,
        ], 201)]);

        $sale = $this->closedSale();
        app(ErpOutboxService::class)->dispatch(ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail());

        // Una diferencia sistemática se repetiría cada día hasta que alguien
        // mire por qué, y para entonces habría cientos de documentos con ella
        // dentro. Es además el detector de que los dos motores divergen.
        $notice = SupervisorNotification::where('event', 'erp.tax_difference')->first();

        $this->assertNotNull($notice);
        $this->assertSame('critical', $notice->severity);
        $this->assertSame('0.03', $notice->context['difference']);
        $this->assertSame('0.03', (string) $sale->fresh()->erp_tax_difference);
    }

    public function test_un_422_no_se_reintenta(): void
    {
        Http::fake(['*/pos/sales' => Http::response([
            'error' => 'instrument_unsupported',
            'message' => 'El tratamiento contable de las tarjetas de regalo todavía no está definido.',
            'status' => 422,
        ], 422)]);

        $sale = $this->closedSale();
        $entry = ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail();

        app(ErpOutboxService::class)->dispatch($entry);

        $fresh = $entry->fresh();

        // Reintentar un 422 quema la cola contra un ticket que nunca va a pasar
        // y retrasa los que sí pasarían (§6 del contrato).
        $this->assertSame('exception', $fresh->status);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertSame('instrument_unsupported', $fresh->error_code);
        $this->assertSame('exception', $sale->fresh()->erp_status);
        $this->assertDatabaseHas('pos_supervisor_notifications', ['event' => 'erp.exception']);
    }

    public function test_un_500_se_reintenta_con_retroceso_exponencial(): void
    {
        Http::fake(['*/pos/sales' => Http::response(['message' => 'boom'], 500)]);

        $sale = $this->closedSale();
        $entry = ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail();

        app(ErpOutboxService::class)->dispatch($entry);
        $primero = $entry->fresh();

        $this->assertSame('pending', $primero->status);
        $this->assertNotNull($primero->next_attempt_at);
        $this->assertSame(1, $primero->attempts);

        $primera = $primero->next_attempt_at->diffInSeconds(now(), absolute: true);

        app(ErpOutboxService::class)->dispatch($primero);
        $segundo = $entry->fresh();

        // La segunda espera es mayor que la primera: reintentar cada quince
        // segundos toda la noche no acerca al ERP y sí llena el registro.
        $this->assertGreaterThan(
            $primera,
            $segundo->next_attempt_at->diffInSeconds(now(), absolute: true)
        );
    }

    public function test_el_issue_failed_deja_el_documento_anotado(): void
    {
        // El caso delicado: el documento **sí** se creó y quedó en borrador con
        // su uuid. Reintentar devolvería 200 "duplicado" y daríamos por
        // sincronizado algo que nadie emitió.
        Http::fake(['*/pos/sales' => Http::response([
            'error' => 'issue_failed',
            'message' => 'La serie no tiene rango disponible.',
            'billing_document_id' => 9911,
            'status' => 409,
        ], 409)]);

        $sale = $this->closedSale();
        $entry = ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail();

        app(ErpOutboxService::class)->dispatch($entry);
        $fresh = $entry->fresh();

        $this->assertSame('exception', $fresh->status);
        $this->assertSame('issue_failed', $fresh->error_code);
        $this->assertSame(9911, (int) $fresh->erp_document_id);
        $this->assertNull($fresh->next_attempt_at);
    }

    public function test_el_ticket_se_encola_al_cerrar_y_una_sola_vez(): void
    {
        Http::fake();

        $sale = $this->closedSale();

        $this->assertDatabaseCount('pos_erp_outbox', 1);

        // Reencolar el mismo ticket no crea una segunda fila: la idempotencia
        // empieza de este lado, no solo en el índice único del ERP.
        app(ErpOutboxService::class)->enqueue($sale);

        $this->assertDatabaseCount('pos_erp_outbox', 1);
    }

    public function test_el_envio_lleva_la_empresa_en_la_cabecera(): void
    {
        Http::fake(['*/pos/sales' => Http::response(['data' => [
            'ticket_uuid' => 'x', 'billing_document_id' => 1, 'document_number' => 'N',
            'status' => 'issued', 'duplicate' => false, 'tax_difference' => '0.00',
        ]], 201)]);

        $sale = $this->closedSale();
        app(ErpOutboxService::class)->dispatch(ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail());

        // Los permisos del ERP se resuelven **por empresa**: sin `X-Company-Id`
        // el token obtiene un conjunto vacío y recibe 403 (§3 del contrato).
        Http::assertSent(fn ($request) => $request->hasHeader('X-Company-Id', '7')
            && $request->hasHeader('Authorization', 'Bearer token-de-prueba'));
    }

    public function test_solo_se_envian_los_que_tocan(): void
    {
        Http::fake();

        $sale = $this->closedSale();
        $entry = ErpOutboxEntry::where('sale_id', $sale->id)->firstOrFail();

        $this->assertCount(1, app(ErpOutboxService::class)->due());

        DB::table('pos_erp_outbox')->where('id', $entry->id)
            ->update(['next_attempt_at' => now()->addHour()]);

        $this->assertCount(0, app(ErpOutboxService::class)->due());
    }
}
