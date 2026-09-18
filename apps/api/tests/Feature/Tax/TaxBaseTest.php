<?php

namespace Tests\Feature\Tax;

use App\Models\Sale;
use App\Models\TaxCode;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Impuesto incluido o agregado, y exento contra no gravado (Q-07).
 *
 * **La inclusión es propiedad del código de impuesto, no de la instalación.**
 * Así lo modela `tax_codes.base` de cherryB, y copiarlo es lo que mantiene
 * `tax_difference` en cero: si el POS lo decidiera globalmente y el ERP por
 * código, cada ticket saldría con diferencia y la bandeja de excepciones se
 * llenaría de ruido desde el primer día.
 *
 * La segunda distinción es igual de importante y más fácil de pasar por alto:
 * **exento y tasa cero dan el mismo total y el libro de ventas los declara
 * distinto**.
 */
class TaxBaseTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
    }

    private function sell(string $sku, string $price, ?TaxCode $tax, array $attrs = []): Sale
    {
        $product = $this->product($sku, $price, array_merge(['tax_code_id' => $tax?->id], $attrs));

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '1',
        ])->assertCreated();

        return Sale::find($sale);
    }

    public function test_el_precio_de_gondola_es_lo_que_paga_el_cliente(): void
    {
        // `base = gross`, el defecto de Nicaragua: 115 es el precio, y dentro
        // van 100 de base y 15 de IVA.
        $sale = $this->sell('P-001', '115.00', $this->iva);

        $this->assertSame('100.00', (string) $sale->subtotal);
        $this->assertSame('15.00', (string) $sale->tax_total);
        $this->assertSame('115.00', (string) $sale->total);
    }

    public function test_un_impuesto_net_se_agrega_sobre_el_precio(): void
    {
        $net = TaxCode::create([
            'code' => 'IVA-N',
            'name' => 'IVA 15 % sobre neto',
            'rate' => '15.0000',
            'type' => 'vat',
            'base' => 'net',
        ]);

        // Mismo 15 %, otra base: aquí 115 se cobra encima de 100. Es el caso de
        // la venta entre empresas, donde el precio se cotiza sin impuesto.
        $sale = $this->sell('P-002', '100.00', $net);

        $this->assertSame('100.00', (string) $sale->subtotal);
        $this->assertSame('15.00', (string) $sale->tax_total);
        $this->assertSame('115.00', (string) $sale->total);
    }

    public function test_los_dos_codigos_conviven_en_el_mismo_catalogo(): void
    {
        // Es el sentido de que la base sea del impuesto: un mismo negocio puede
        // tener precios de mostrador con IVA dentro y precios de mayoreo sin él.
        $net = TaxCode::create([
            'code' => 'IVA-N', 'name' => 'IVA neto', 'rate' => '15.0000',
            'type' => 'vat', 'base' => 'net',
        ]);

        $mostrador = $this->product('MOS', '115.00', ['tax_code_id' => $this->iva->id]);
        $mayoreo = $this->product('MAY', '100.00', ['tax_code_id' => $net->id]);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        foreach ([$mostrador, $mayoreo] as $product) {
            $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $product->id,
                'qty' => '1',
            ])->assertCreated();
        }

        $updated = Sale::find($sale);

        $this->assertSame('200.00', (string) $updated->subtotal);
        $this->assertSame('30.00', (string) $updated->tax_total);
        $this->assertSame('230.00', (string) $updated->total);
    }

    public function test_exento_y_gravado_se_declaran_por_separado(): void
    {
        // Farmacia: el medicamento exento y el shampoo gravado en el mismo
        // ticket. El total sería el mismo si el medicamento fuera "tasa cero",
        // pero el libro de ventas los declara distinto.
        $exento = TaxCode::create([
            'code' => 'EXE', 'name' => 'Exento', 'rate' => '0.0000',
            'type' => 'exempt', 'base' => 'net',
        ]);

        $med = $this->product('MED', '250.00', ['tax_code_id' => $exento->id]);
        $sha = $this->product('SHA', '115.00', ['tax_code_id' => $this->iva->id]);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        foreach ([$med, $sha] as $product) {
            $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $product->id,
                'qty' => '1',
            ])->assertCreated();
        }

        $updated = Sale::find($sale);

        $this->assertSame('250.00', (string) $updated->exempt_total);
        $this->assertSame('100.00', (string) $updated->taxable_base);
        $this->assertSame('350.00', (string) $updated->subtotal);
        $this->assertSame('15.00', (string) $updated->tax_total);
        $this->assertSame('365.00', (string) $updated->total);
    }

    public function test_cuota_fija_no_es_exento_es_no_gravado(): void
    {
        // El negocio no traslada IVA, pero su venta no es una "venta exenta":
        // es una venta no gravada. Van a casillas distintas del libro.
        $this->app->make(SettingsRepository::class)
            ->set('tax.fixed_quota_regime', true, 'boolean');

        $sale = $this->sell('P-003', '115.00', $this->iva);

        $this->assertSame('0.00', (string) $sale->exempt_total);
        $this->assertSame('115.00', (string) $sale->taxable_base);
        $this->assertSame('0.00', (string) $sale->tax_total);
        $this->assertSame('115.00', (string) $sale->total);
    }

    public function test_un_producto_sin_codigo_de_impuesto_no_paga_ni_declara_exento(): void
    {
        $sale = $this->sell('P-004', '50.00', null);

        $this->assertSame('0.00', (string) $sale->exempt_total);
        $this->assertSame('50.00', (string) $sale->taxable_base);
        $this->assertSame('0.00', (string) $sale->tax_total);
    }

    public function test_la_base_y_el_exento_suman_el_subtotal(): void
    {
        $exento = TaxCode::create([
            'code' => 'EXE', 'name' => 'Exento', 'rate' => '0.0000',
            'type' => 'exempt', 'base' => 'net',
        ]);

        $sale = $this->sell('P-005', '333.33', $exento);

        // Invariante del motor, verificado además por los fixtures.
        $this->assertSame(
            (string) $sale->subtotal,
            bcadd((string) $sale->taxable_base, (string) $sale->exempt_total, 2)
        );
    }
}
