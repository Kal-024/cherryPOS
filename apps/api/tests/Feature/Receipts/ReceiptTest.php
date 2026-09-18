<?php

namespace Tests\Feature\Receipts;

use App\Models\DocumentDelivery;
use App\Models\ReceiptTemplate;
use App\Models\Sale;
use App\Models\TaxCode;
use App\Services\Delivery\DocumentDeliveryService;
use App\Services\Sales\ExchangeRateService;
use Database\Seeders\ReceiptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Comprobantes: plantilla, PDF y envío (D-11, P-04, H5).
 *
 * D-11 eligió **editor de plantillas** sobre las veinte banderas de OSPOS. Lo
 * que se prueba aquí es que la plantilla sea de verdad dato: que reordenarla
 * cambie el ticket, que el ancho del papel mande sobre el diseño y que una
 * plantilla rota no impida entregar el comprobante.
 */
class ReceiptTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    /** Configurar plantillas es del encargado, no del cajero. */
    private function signInAsManager(): string
    {
        return $this->token = $this->signedIn($this->employee('ENC01', '4321', 'manager'), '4321');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->seed(ReceiptTemplateSeeder::class);

        $this->branch->update([
            'legal_name' => 'Comercial El Ejemplo, S.A.',
            'tax_id' => 'J0310000000000',
            'address' => 'Km 8 Carretera a Masaya',
        ]);

        $this->token = $this->signedIn();
    }

    private function closedSale(string $price = '115.00', string $paid = '200.00'): Sale
    {
        $product = $this->product('P-001', $price);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => $paid,
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        return Sale::find($sale);
    }

    /** @return array<int,string> */
    private function textOf(array $lines): array
    {
        return array_values(array_filter(array_map(
            static fn (array $line) => $line['text'] ?? null,
            $lines
        )));
    }

    public function test_el_comprobante_lleva_la_identidad_del_negocio(): void
    {
        $sale = $this->closedSale();

        $lines = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale->id}/receipt")
            ->assertOk()
            ->json('data.lines');

        $text = implode("\n", $this->textOf($lines));

        // Q-08: nombre y RUC vienen de la instalación y se imprimen siempre. Es
        // la marca que hace que una copia delate al negocio original en sus
        // propias facturas.
        $this->assertStringContainsString('Comercial El Ejemplo, S.A.', $text);
        $this->assertStringContainsString('J0310000000000', $text);
        $this->assertStringContainsString($sale->number, $text);
    }

    public function test_los_importes_quedan_alineados_al_ancho_del_papel(): void
    {
        $sale = $this->closedSale();

        $response = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale->id}/receipt")->assertOk()->json('data');

        $this->assertSame('thermal_80', $response['paper']);
        $this->assertSame(48, $response['width']);

        // El renderizador justifica contando caracteres: ninguna línea puede
        // pasarse del ancho, o en la térmica se envuelve y descuadra la columna
        // de importes.
        foreach ($this->textOf($response['lines']) as $line) {
            $this->assertLessThanOrEqual(48, mb_strlen($line), "Línea más ancha que el papel: {$line}");
        }
    }

    public function test_un_papel_mas_angosto_reacomoda_el_mismo_ticket(): void
    {
        $sale = $this->closedSale();

        $template = ReceiptTemplate::where('document_type', 'counter')->firstOrFail();
        $narrow = $template->replicate(['is_system']);
        $narrow->code = 'ticket-58';
        $narrow->paper = 'thermal_58';
        $narrow->is_system = false;
        $narrow->save();

        $lines = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale->id}/receipt?template_id={$narrow->id}")
            ->assertOk()->json('data.lines');

        // Lo que entra en 48 caracteres no entra en 32: el ancho manda sobre
        // todo el diseño.
        foreach ($this->textOf($lines) as $line) {
            $this->assertLessThanOrEqual(32, mb_strlen($line));
        }
    }

    public function test_reordenar_la_plantilla_cambia_el_ticket(): void
    {
        $sale = $this->closedSale();

        $custom = ReceiptTemplate::create([
            'branch_id' => $this->branch->id,
            'code' => 'minimo',
            'name' => 'Mínimo',
            'document_type' => 'counter',
            'paper' => 'thermal_80',
            'is_default' => true,
            'content' => ['blocks' => [
                ['type' => 'text', 'content' => 'GRACIAS POR SU COMPRA', 'align' => 'center', 'bold' => true],
                ['type' => 'totals', 'show' => ['total']],
            ]],
        ]);

        $lines = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale->id}/receipt?template_id={$custom->id}")
            ->assertOk()->json('data.lines');

        $text = implode("\n", $this->textOf($lines));

        // Esto es lo que compra el editor: cambiar cómo se ve la factura de un
        // cliente sin un despliegue.
        $this->assertStringContainsString('GRACIAS POR SU COMPRA', $text);
        $this->assertStringNotContainsString('J0310000000000', $text);
    }

    public function test_un_dato_que_no_existe_no_deja_una_linea_vacia(): void
    {
        $sale = $this->closedSale();

        // La venta es anónima: el cliente no existe. Una línea "Cliente:" vacía
        // se lee como un error de la impresora.
        $lines = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale->id}/receipt")->assertOk()->json('data.lines');

        foreach ($this->textOf($lines) as $line) {
            $this->assertStringNotContainsString(__('receipt.customer'), $line);
        }
    }

    public function test_el_exento_y_el_gravado_se_imprimen_por_separado(): void
    {
        $exento = TaxCode::create([
            'code' => 'EXE', 'name' => 'Exento', 'rate' => '0.0000',
            'type' => 'exempt', 'base' => 'net',
        ]);

        $med = $this->product('MED', '250.00', ['tax_code_id' => $exento->id]);
        $sha = $this->product('SHA', '115.00', ['tax_code_id' => $this->iva->id]);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        foreach ([$med, $sha] as $product) {
            $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
            ])->assertCreated();
        }

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '365.00',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $text = implode("\n", $this->textOf(
            $this->actingAsTerminal($this->token)->getJson("/api/sales/{$sale}/receipt")->json('data.lines')
        ));

        // El libro de ventas los declara distinto, y el comprobante también.
        $this->assertStringContainsString(__('receipt.exempt_total'), $text);
        $this->assertStringContainsString('250.00', $text);
        $this->assertStringContainsString('IVA 15%', $text);
    }

    public function test_el_pago_en_dolares_muestra_lo_entregado_y_su_equivalente(): void
    {
        app(ExchangeRateService::class)->set('USD', '36.624300', $this->cashier->id);

        $product = $this->product('P-002', '500.00');
        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'currency_code' => 'USD', 'amount' => '20.00',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $text = implode("\n", $this->textOf(
            $this->actingAsTerminal($this->token)->getJson("/api/sales/{$sale}/receipt")->json('data.lines')
        ));

        // Q-06: mostrar lo que el cliente entregó **y** su equivalente es lo que
        // evita la discusión en el mostrador.
        $this->assertStringContainsString('USD 20.00', $text);
        $this->assertStringContainsString('732.49', $text);
        $this->assertStringContainsString(__('receipt.change'), $text);
    }

    public function test_el_comprobante_se_descarga_en_pdf(): void
    {
        $sale = $this->closedSale();

        $response = $this->actingAsTerminal($this->token)
            ->get("/api/sales/{$sale->id}/receipt/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Mientras el hardware siga pendiente (Q-05), el PDF es lo que se
        // entrega.
        // dompdf devuelve una respuesta normal con cabecera de PDF, no un
        // flujo: lo que importa es que el archivo sea un PDF de verdad.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_el_cajero_sabe_si_tiene_que_abrir_la_gaveta(): void
    {
        $sale = $this->closedSale();

        $data = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale->id}/receipt")->assertOk()->json('data');

        // Sin agente de impresión, esto es lo único que hay (Q-12): un aviso en
        // pantalla donde correspondería abrir el cajón.
        $this->assertTrue($data['open_drawer']);
    }

    public function test_la_vista_previa_no_necesita_una_venta(): void
    {
        $this->signInAsManager();

        $lines = $this->actingAsTerminal($this->token)
            ->postJson('/api/receipt-templates/preview', [
                'paper' => 'thermal_58',
                'content' => ['blocks' => [
                    ['type' => 'text', 'content' => '{{branch.legal_name}}', 'align' => 'center'],
                    ['type' => 'items'],
                    ['type' => 'totals', 'show' => ['total']],
                ]],
            ])
            ->assertOk()
            ->json('data.lines');

        $text = implode("\n", $this->textOf($lines));

        // Sin vista previa, configurar una plantilla es teclear a ciegas y
        // descubrir el resultado en el primer cliente.
        $this->assertStringContainsString('Comercial El Ejemplo', $text);
        $this->assertStringContainsString('Queso seco', $text);
    }

    public function test_una_plantilla_con_un_bloque_inventado_se_rechaza_al_guardar(): void
    {
        $this->signInAsManager();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/receipt-templates', [
                'code' => 'rota',
                'name' => 'Rota',
                'document_type' => 'counter',
                'paper' => 'thermal_80',
                'content' => ['blocks' => [['type' => 'baile_de_sanjuan']]],
            ])
            ->assertStatus(422);

        // Sin validación, el bloque inventado se descubre cuando el cajero
        // entrega un ticket en blanco.
        $this->assertSame(0, ReceiptTemplate::where('code', 'rota')->count());
    }

    public function test_las_plantillas_del_sistema_se_duplican_para_editarse(): void
    {
        $this->signInAsManager();

        $system = ReceiptTemplate::whereNull('branch_id')->where('is_system', true)->first();

        $this->actingAsTerminal($this->token)
            ->putJson("/api/receipt-templates/{$system->id}", ['name' => 'Modificada'])
            ->assertStatus(422);

        $copy = $this->actingAsTerminal($this->token)
            ->postJson("/api/receipt-templates/{$system->id}/duplicate")
            ->assertCreated()
            ->json('data');

        // La original queda intacta y sirve de punto de retorno cuando alguien
        // deja la suya irreconocible.
        $this->assertSame($this->branch->id, $copy['branch_id']);
        $this->assertFalse($copy['is_system']);

        $this->actingAsTerminal($this->token)
            ->putJson("/api/receipt-templates/{$copy['id']}", ['name' => 'Modificada'])
            ->assertOk();
    }

    public function test_un_cajero_no_edita_plantillas(): void
    {
        // Cambiar cómo se ve la factura no es una operación de mostrador.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/receipt-templates/preview', ['content' => ['blocks' => []]])
            ->assertStatus(403);
    }

    public function test_el_envio_se_encola_y_no_bloquea_la_venta(): void
    {
        $sale = $this->closedSale();

        $delivery = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale->id}/receipt/deliver", [
                'channel' => 'whatsapp',
                'destination' => '+505 8888-1111',
            ])
            ->assertCreated()
            ->json('data');

        // P-04: se encola, nunca bloquea, y el cajero ve el estado. El canal no
        // está configurado todavía —falta elegir proveedor, punto abierto A5— y
        // eso se dice en vez de reintentar para siempre.
        $this->assertSame('unconfigured', $delivery['status']);
        $this->assertSame('channel_unconfigured', $delivery['error_code']);
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_un_numero_mal_tecleado_se_rechaza_antes_de_encolar(): void
    {
        $sale = $this->closedSale();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale->id}/receipt/deliver", [
                'channel' => 'whatsapp',
                'destination' => 'no-es-un-numero',
            ])
            ->assertStatus(422);

        // Se corrige ahora, con el cliente enfrente. Encolarlo sería
        // descubrirlo cuando ya se fue.
        $this->assertSame(0, DocumentDelivery::count());
    }

    public function test_un_canal_sin_configurar_no_se_reintenta(): void
    {
        $sale = $this->closedSale();

        $delivery = app(DocumentDeliveryService::class)
            ->queue($sale, 'whatsapp', '+50588881111', $this->cashier->id);

        // Reintentar no consigue una cuenta: la cola no lo toma.
        $this->assertSame([], app(DocumentDeliveryService::class)->due());
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_el_cajero_consulta_el_estado_de_los_envios(): void
    {
        $sale = $this->closedSale();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale->id}/receipt/deliver", [
            'channel' => 'whatsapp', 'destination' => '+50588881111',
        ])->assertCreated();

        $items = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale->id}/receipt/deliveries")
            ->assertOk()->json('data');

        // "¿Le llegó?" tiene que poder responderse sin preguntarle a nadie.
        $this->assertCount(1, $items);
        $this->assertSame('+50588881111', $items[0]['destination']);
    }
}
