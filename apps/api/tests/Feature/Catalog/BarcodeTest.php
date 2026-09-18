<?php

namespace Tests\Feature\Catalog;

use App\Models\Barcode;
use App\Models\Sale;
use App\Models\Uom;
use App\Services\Catalog\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Códigos de barras, incluidos los de balanza (B-04, H1.5).
 *
 * Criterio de aceptación del hito, literal: **leer un código de balanza produce
 * la línea correcta**.
 *
 * Un código de balanza no identifica una unidad: identifica un producto más
 * cuánto pesó. La etiqueta se imprime al pesar, así que el mismo queso genera un
 * código distinto cada vez y una búsqueda exacta nunca lo encuentra.
 */
class BarcodeTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private BarcodeService $barcodes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->barcodes = app(BarcodeService::class);
    }

    public function test_un_codigo_fijo_se_resuelve_exacto(): void
    {
        $product = $this->product('P-001', '25.00');
        Barcode::create(['product_id' => $product->id, 'code' => '7501234567890', 'is_primary' => true]);

        $resolved = $this->barcodes->resolve('7501234567890');

        $this->assertSame($product->id, $resolved['barcode']->product_id);
        // Sin peso ni precio embebidos: es una unidad y su precio de catálogo.
        $this->assertNull($resolved['qty']);
        $this->assertNull($resolved['amount']);
    }

    public function test_un_codigo_de_balanza_con_peso_produce_la_cantidad(): void
    {
        $kilo = Uom::create(['code' => 'KG', 'name' => 'Kilogramo', 'decimals' => 3]);
        $cheese = $this->product('QUESO', '180.00', ['uom_id' => $kilo->id]);

        // La plantilla: el código con la región variable y el verificador en
        // ceros. Es lo que se guarda en el catálogo.
        Barcode::create([
            'product_id' => $cheese->id,
            'code' => '2001234000000',
            'embedded' => 'weight',
        ]);

        // Etiqueta real: 847 gramos, con su dígito verificador.
        $resolved = $this->barcodes->resolve('2001234008475');

        $this->assertSame($cheese->id, $resolved['barcode']->product_id);
        $this->assertSame('0.8470', $resolved['qty']);
        $this->assertNull($resolved['amount']);
    }

    public function test_un_codigo_de_balanza_con_precio_produce_el_importe(): void
    {
        $ham = $this->product('JAMON', '0.00');

        Barcode::create([
            'product_id' => $ham->id,
            'code' => '2009999000000',
            'embedded' => 'price',
        ]);

        // La balanza ya decidió cuánto cobrar: 12,50.
        $resolved = $this->barcodes->resolve('2009999012503');

        $this->assertSame($ham->id, $resolved['barcode']->product_id);
        $this->assertSame('12.50', $resolved['amount']);
        $this->assertNull($resolved['qty']);
    }

    public function test_dos_etiquetas_del_mismo_producto_resuelven_al_mismo_producto(): void
    {
        $kilo = Uom::create(['code' => 'KG', 'name' => 'Kilogramo', 'decimals' => 3]);
        $cheese = $this->product('QUESO', '180.00', ['uom_id' => $kilo->id]);

        Barcode::create(['product_id' => $cheese->id, 'code' => '2001234000000', 'embedded' => 'weight']);

        // El mismo queso pesado dos veces da dos códigos distintos. Ese es
        // exactamente el problema que la plantilla resuelve.
        $primera = $this->barcodes->resolve('2001234008475');
        $segunda = $this->barcodes->resolve('2001234012309');

        $this->assertSame($cheese->id, $primera['barcode']->product_id);
        $this->assertSame($cheese->id, $segunda['barcode']->product_id);
        $this->assertSame('0.8470', $primera['qty']);
        $this->assertSame('1.2300', $segunda['qty']);
    }

    public function test_la_plantilla_se_deriva_de_una_etiqueta_de_muestra(): void
    {
        // Al dar de alta el código, el usuario pega una etiqueta real y el
        // sistema guarda su forma genérica.
        $this->assertSame('2001234000000', $this->barcodes->template('2001234008475', 'weight'));
    }

    public function test_un_codigo_desconocido_no_resuelve(): void
    {
        $this->assertNull($this->barcodes->resolve('9999999999999'));
    }

    public function test_leer_una_etiqueta_de_balanza_arma_la_linea_de_venta(): void
    {
        $kilo = Uom::create(['code' => 'KG', 'name' => 'Kilogramo', 'decimals' => 3]);
        $cheese = $this->product('QUESO', '180.00', ['uom_id' => $kilo->id, 'allow_negative_stock' => true]);
        Barcode::create(['product_id' => $cheese->id, 'code' => '2001234000000', 'embedded' => 'weight']);

        $token = $this->signedIn();

        $sale = $this->withToken($token)->postJson('/api/sales', [])->json('data.id');

        $response = $this->withToken($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'scan',
            'code' => '2001234008475',
        ])->assertCreated();

        $line = $response->json('data.lines.0');

        $this->assertSame('0.8470', $line['qty']);
        $this->assertSame('180.0000', $line['unit_price']);
        // 0,847 × 180 = 152,46
        $this->assertSame('152.46', $line['gross']);
        $this->assertSame('152.46', Sale::find($sale)->total);
    }

    public function test_el_catalogo_cacheado_lleva_los_codigos_de_barras(): void
    {
        $soda = $this->product('GASEOSA', '45.00');
        Barcode::create(['product_id' => $soda->id, 'code' => '7501234567890']);

        $token = $this->signedIn();

        $catalogo = $this->withToken($token)
            ->getJson('/api/catalog/products')
            ->assertOk()
            ->json('data');

        // Sin los códigos en el catálogo cacheado, **la caja sin servidor no
        // puede vender con el lector**: la búsqueda local no tiene contra qué
        // comparar lo que llega de la pistola, y el perfil `scan_first` no tiene
        // otra forma de agregar.
        $producto = collect($catalogo)->firstWhere('sku', 'GASEOSA');

        $this->assertSame(
            ['7501234567890'],
            collect($producto['barcodes'])->pluck('code')->all()
        );
    }

    public function test_la_cantidad_tecleada_manda_sobre_el_uno_pero_no_sobre_la_balanza(): void
    {
        $kilo = Uom::create(['code' => 'KG', 'name' => 'Kilogramo', 'decimals' => 3]);
        $cheese = $this->product('QUESO', '180.00', ['uom_id' => $kilo->id, 'allow_negative_stock' => true]);
        Barcode::create(['product_id' => $cheese->id, 'code' => '2001234000000', 'embedded' => 'weight']);

        $soda = $this->product('GASEOSA', '45.00');
        Barcode::create(['product_id' => $soda->id, 'code' => '7501234567890']);

        $token = $this->signedIn();
        $sale = $this->withToken($token)->postJson('/api/sales', [])->json('data.id');

        // "3*" y el lector: tres gaseosas en una línea.
        $soda = $this->withToken($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'scan',
            'code' => '7501234567890',
            'qty' => '3',
        ])->assertCreated()->json('data.lines.0');

        $this->assertSame('3.0000', $soda['qty']);

        // La misma cantidad contra una etiqueta de balanza **no** se aplica: la
        // balanza ya pesó, y multiplicar eso por tres cobraría 2,5 kg de queso
        // como si fueran siete y medio.
        $weighed = $this->withToken($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'scan',
            'code' => '2001234008475',
            'qty' => '3',
        ])->assertCreated()->json('data.lines.0');

        $this->assertSame('0.8470', $weighed['qty']);
    }
}
