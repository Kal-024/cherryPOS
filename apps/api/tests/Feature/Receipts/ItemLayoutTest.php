<?php

namespace Tests\Feature\Receipts;

use App\Models\ReceiptTemplate;
use App\Services\Receipts\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * El detalle de productos, en una línea o en dos (D-11, H5.2).
 *
 * Cada producto ocupaba **dos renglones**: la descripción en uno y "cantidad ×
 * precio" en el siguiente. En un rollo eso es el doble de papel y el doble de
 * tiempo de impresión; con veinte productos, veinte centímetros de más en cada
 * ticket del día.
 *
 * La forma compacta usa tres columnas —descripción, cantidad, importe— y es la
 * de fábrica. La detallada se conserva para cuando el precio unitario tiene que
 * quedar impreso: ferretería, venta por peso.
 */
class ItemLayoutTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        return [
            'items' => [
                [
                    'description' => 'Gaseosa de naranja 1.5 L retornable',
                    'qty' => '2',
                    'uom' => 'UND',
                    'unit_price' => '45.00',
                    'total' => '90.00',
                    'discount' => '0.00',
                ],
                [
                    'description' => 'Queso seco',
                    'qty' => '0.847',
                    'uom' => 'KG',
                    'unit_price' => '180.00',
                    'total' => '152.46',
                    'discount' => '7.50',
                ],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function render(array $block, string $paper = 'thermal_80'): array
    {
        $template = new ReceiptTemplate([
            'paper' => $paper,
            'content' => ['blocks' => [$block]],
        ]);

        return app(ReceiptRenderer::class)->render($template, $this->context());
    }

    public function test_de_fabrica_cada_producto_ocupa_una_sola_linea(): void
    {
        $lines = $this->render(['type' => 'items']);

        $items = array_values(array_filter(
            $lines,
            static fn (array $line) => ($line['kind'] ?? '') === 'item'
        ));

        // Dos productos, dos líneas. Antes eran cuatro.
        $this->assertCount(2, $items);
        $this->assertStringContainsString('Gaseosa', $items[0]['text']);
        $this->assertStringContainsString('90.00', $items[0]['text']);
        $this->assertStringContainsString('2', $items[0]['text']);
    }

    public function test_las_columnas_llevan_su_rotulo(): void
    {
        $lines = $this->render(['type' => 'items']);

        // Una raya sola no dice qué es cada número. El cliente que revisa su
        // ticket tiene que poder saber cuál es la cantidad y cuál el importe sin
        // preguntar. Cuesta un renglón por ticket, no uno por producto.
        $header = $lines[0];

        $this->assertSame('item_header', $header['kind']);
        $this->assertTrue($header['bold']);
        $this->assertStringContainsString(__('receipt.item'), $header['text']);
        $this->assertStringContainsString(__('receipt.qty'), $header['text']);
        $this->assertStringContainsString(__('receipt.amount'), $header['text']);
    }

    public function test_el_rotulo_usa_las_mismas_columnas_que_las_filas(): void
    {
        $lines = $this->render(['type' => 'items']);

        // Si el encabezado no cae sobre sus columnas, confunde más que la raya
        // sola: el rótulo diría una cosa y el número estaría debajo de otra.
        $header = $lines[0]['text'];
        $first = $lines[1]['text'];

        $this->assertSame(mb_strlen($header), mb_strlen($first));
        $this->assertSame(
            mb_strpos($header, __('receipt.amount')) + mb_strlen(__('receipt.amount')),
            mb_strlen($first)
        );
    }

    public function test_sin_productos_no_hay_rotulo_huerfano(): void
    {
        $template = new ReceiptTemplate([
            'paper' => 'thermal_80',
            'content' => ['blocks' => [['type' => 'items']]],
        ]);

        // Un encabezado sobre una lista vacía es un hueco que parece un error.
        $this->assertSame([], app(ReceiptRenderer::class)->render($template, ['items' => []]));
    }

    public function test_el_rotulo_se_puede_apagar(): void
    {
        $lines = $this->render(['type' => 'items', 'show_header' => false]);

        $this->assertSame('item', $lines[0]['kind']);
    }

    public function test_las_columnas_quedan_alineadas_al_ancho_del_papel(): void
    {
        $lines = $this->render(['type' => 'items']);

        foreach ($lines as $line) {
            if (($line['kind'] ?? '') !== 'item') {
                continue;
            }

            // Ni una línea se pasa del rollo: pasarse es perder el importe, que
            // es la columna que el cliente revisa.
            $this->assertSame(48, mb_strlen($line['text']));
            // Y el importe termina pegado al borde derecho, que es lo que hace
            // legible una columna de números.
            $this->assertMatchesRegularExpression('/\d$/', $line['text']);
        }
    }

    public function test_la_descripcion_larga_cede_espacio_y_el_importe_no(): void
    {
        $lines = $this->render(['type' => 'items'], 'thermal_58');

        $first = array_values(array_filter(
            $lines,
            static fn (array $line) => ($line['kind'] ?? '') === 'item'
        ))[0];

        // En 32 caracteres la descripción se recorta. Un nombre a medias se
        // entiende; un importe a medias no sirve de nada.
        $this->assertSame(32, mb_strlen($first['text']));
        $this->assertStringContainsString('90.00', $first['text']);
        $this->assertStringNotContainsString('retornable', $first['text']);
    }

    public function test_el_descuento_de_linea_sigue_teniendo_su_renglon(): void
    {
        $lines = $this->render(['type' => 'items']);

        $discounts = array_filter(
            $lines,
            static fn (array $line) => ($line['kind'] ?? '') === 'item_discount'
        );

        // Es la excepción y el cliente la revisa: esconderla en una columna
        // estrecha sería esconder justo lo que se mira.
        $this->assertCount(1, $discounts);
        $this->assertStringContainsString('-7.50', reset($discounts)['text']);
    }

    public function test_la_forma_detallada_conserva_el_precio_unitario(): void
    {
        $lines = $this->render(['type' => 'items', 'layout' => 'detailed', 'show_unit_price' => true]);

        $texts = implode("\n", array_column($lines, 'text'));

        // Ferretería y venta por peso necesitan el precio unitario impreso.
        $this->assertStringContainsString('2 UND x 45.00', $texts);
        $this->assertStringContainsString('0.847 KG x 180.00', $texts);
    }

    public function test_los_acentos_no_desalinean_las_columnas(): void
    {
        $template = new ReceiptTemplate(['paper' => 'thermal_80', 'content' => ['blocks' => [['type' => 'items']]]]);

        $lines = app(ReceiptRenderer::class)->render($template, [
            'items' => [[
                'description' => 'Acetaminofén 500 mg añejo',
                'qty' => '1',
                'uom' => 'UND',
                'unit_price' => '250.00',
                'total' => '250.00',
                'discount' => '0.00',
            ]],
        ]);

        // `str_pad` mide bytes y una "ñ" ocupa dos: con acentos las columnas se
        // corrían justo en los productos de nombre español.
        // La primera línea es el rótulo; la segunda, el producto.
        $this->assertSame(48, mb_strlen($lines[1]['text']));
    }
}
