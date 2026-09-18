<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\Erp\ErpTicketPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * La propina (G-16).
 *
 * Todo lo que se prueba acá cuelga de una sola regla: **la propina se cobra y no
 * se factura**. Es dinero que pasa por el cajón camino a otra mano, así que
 * entra en lo que hay que cobrar y en el arqueo, y no entra en la base gravada,
 * ni en el impuesto, ni en el total del documento, ni en lo que viaja al ERP.
 *
 * Si alguna vez entrara en el total, la alarma que sonaría no sería "hay una
 * propina mal puesta" sino `tax_difference` distinto de cero en cada cuenta de
 * restaurante — el peor lugar posible para descubrirlo.
 */
class TipTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
    }

    /** Una cuenta de 345,00 con IVA dentro: base 300,00 e impuesto 45,00. */
    private function saleOf(string $price = '345.00'): string
    {
        $product = $this->product('PLATO-'.substr(md5($price), 0, 6), $price);

        $id = $this->withToken($this->token)
            ->postJson('/api/sales')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($this->token)
            ->postJson("/api/sales/{$id}/lines", [
                'kind' => 'product',
                'product_id' => $product->id,
                'qty' => '1',
            ])
            ->assertCreated();

        return $id;
    }

    public function test_la_propina_no_toca_ningun_importe_fiscal(): void
    {
        $sale = $this->saleOf();

        $data = $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/tip", ['amount' => '34.50'])
            ->assertOk()
            ->json('data');

        $this->assertSame('34.50', $data['tip_amount']);

        // El documento sigue diciendo lo mismo que antes de la propina.
        $this->assertSame('300.00', $data['subtotal']);
        $this->assertSame('300.00', $data['taxable_base']);
        $this->assertSame('45.00', $data['tax_total']);
        $this->assertSame('345.00', $data['total']);

        // Y el saldo sí la incluye: lo que falta cobrar son 379,50.
        $this->assertSame('379.50', $data['balance']);
    }

    public function test_la_propina_no_viaja_al_erp(): void
    {
        $sale = $this->saleOf();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/tip", ['amount' => '34.50'])
            ->assertOk();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/payments", ['method' => 'cash', 'amount' => '379.50'])
            ->assertCreated();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/close")
            ->assertOk();

        $payload = app(ErpTicketPayload::class)->build(Sale::find($sale));

        // El total que viaja es el fiscal, sin propina: es lo que el ERP
        // contabiliza y lo que mantiene `tax_difference` en cero.
        $this->assertSame('345.00', (string) $payload['totals']['total']);
        $this->assertStringNotContainsString('tip', json_encode($payload));
    }

    public function test_la_propina_no_puede_superar_el_total(): void
    {
        $sale = $this->saleOf();

        // 3.500 donde iban 35: el error de tecleo que se descubriría al cuadrar
        // el cajón, cuando el cliente ya se fue.
        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/tip", ['amount' => '3500.00'])
            ->assertStatus(422);

        $this->assertSame('0.00', (string) Sale::find($sale)->tip_amount);
    }

    public function test_en_una_venta_la_propina_no_va_en_negativo(): void
    {
        $sale = $this->saleOf();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/tip", ['amount' => '-10.00'])
            ->assertStatus(422);
    }

    public function test_la_devolucion_entrega_la_propina(): void
    {
        $product = $this->product('PLATO-DEV', '115.00');

        $id = $this->withToken($this->token)
            ->postJson('/api/sales', ['sale_type' => 'refund'])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($this->token)
            ->postJson("/api/sales/{$id}/lines", [
                'kind' => 'product',
                'product_id' => $product->id,
                'qty' => '-1',
            ])
            ->assertCreated();

        // El ticket entero está en negativo y la propina también: se entrega, no
        // se cobra.
        $data = $this->withToken($this->token)
            ->postJson("/api/sales/{$id}/tip", ['amount' => '-10.00'])
            ->assertOk()
            ->json('data');

        $this->assertSame('-10.00', $data['tip_amount']);
        $this->assertSame('-125.00', $data['balance']);
    }

    public function test_el_arqueo_separa_la_propina_sin_descuadrar(): void
    {
        $terminal = Terminal::first();
        $shift = Shift::where('terminal_id', $terminal->id)->where('status', 'open')->first();

        $sale = $this->saleOf();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/tip", ['amount' => '34.50'])
            ->assertOk();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/payments", ['method' => 'cash', 'amount' => '400.00'])
            ->assertCreated();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/close")
            ->assertOk();

        $summary = $this->withToken($this->token)
            ->getJson("/api/shifts/{$shift->id}/summary")
            ->assertOk()
            ->json('data');

        $base = collect($summary['by_currency'])->firstWhere('currency_code', 'NIO');

        // Fondo 1.000 más 400 recibidos menos 20,50 de vuelto: la propina ya está
        // dentro porque es efectivo que entró, y el cajón cuadra sin tocarla.
        $this->assertSame('1379.50', $base['expected']);

        // Y aparece aparte, que es lo que hace falta para poder pagarla.
        $this->assertSame('34.50', $summary['tips']['total']);
    }
}
