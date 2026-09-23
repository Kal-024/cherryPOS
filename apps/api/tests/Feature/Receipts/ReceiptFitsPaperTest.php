<?php

namespace Tests\Feature\Receipts;

use App\Models\ReceiptTemplate;
use App\Services\Receipts\ReceiptPdf;
use Database\Seeders\ReceiptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * El ticket entra en el papel (H5.3).
 *
 * El renderizador justifica **contando caracteres**: 32 en un rollo de 58 mm y
 * 48 en uno de 80. El PDF llevaba un cuerpo de letra fijo que no respetaba esa
 * cuenta —a 9 pt, 48 caracteres ocupan unos 91 mm en un papel de 80— así que
 * todo lo alineado a la derecha **se salía de la hoja**: el número de
 * comprobante, la fecha, el cajero y la columna entera de importes, total
 * incluido. Un comprobante sin importes no es un comprobante.
 *
 * Lo que se fija acá es la relación entre las dos medidas. Es una invariante
 * aritmética, no una opinión sobre el diseño: si alguien sube el cuerpo o mete
 * más caracteres por línea, esta prueba lo dice antes que un cliente.
 */
class ReceiptFitsPaperTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    /** Lo que avanza un carácter en DejaVu Sans Mono, en proporción al cuerpo. */
    private const ADVANCE = 0.602;

    private const MM_TO_PT = 2.83465;

    private const PAPER_MM = ['thermal_58' => 58.0, 'thermal_80' => 80.0];

    private const PADDING_MM = 2.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->seed(ReceiptTemplateSeeder::class);
    }

    private function fontSizeOf(string $html): float
    {
        $this->assertSame(1, preg_match('/font-size:\s*([\d.]+)pt/', $html, $matches));

        return (float) $matches[1];
    }

    /** @return array<int,array<string,mixed>> */
    private function widestLines(int $width): array
    {
        // La línea más ancha que el renderizador puede producir: el ancho entero
        // del papel, que es justo lo que pasa con un importe alineado a la
        // derecha y una descripción larga a la izquierda.
        return [
            ['kind' => 'text', 'text' => str_repeat('W', $width), 'align' => 'left'],
            ['kind' => 'pair', 'text' => str_repeat('M', $width)],
            ['kind' => 'text', 'text' => str_repeat('X', $width), 'bold' => true, 'size' => 'lg'],
        ];
    }

    public static function papers(): array
    {
        return [
            'rollo de 58 mm' => ['thermal_58'],
            'rollo de 80 mm' => ['thermal_80'],
        ];
    }

    #[DataProvider('papers')]
    public function test_la_linea_mas_ancha_entra_en_el_rollo(string $paper): void
    {
        $width = ReceiptTemplate::WIDTHS[$paper];
        $template = new ReceiptTemplate(['paper' => $paper, 'content' => ['blocks' => []]]);

        $html = app(ReceiptPdf::class)->html($template, $this->widestLines($width), []);

        $fontSize = $this->fontSizeOf($html);
        $usableMm = self::PAPER_MM[$paper] - 2 * self::PADDING_MM;
        $lineMm = ($width * self::ADVANCE * $fontSize) / self::MM_TO_PT;

        $this->assertLessThanOrEqual(
            $usableMm,
            $lineMm,
            "Con {$fontSize}pt, {$width} caracteres ocupan {$lineMm}mm y solo hay {$usableMm}mm"
        );
    }

    #[DataProvider('papers')]
    public function test_el_cuerpo_aprovecha_el_ancho_disponible(string $paper): void
    {
        $width = ReceiptTemplate::WIDTHS[$paper];
        $template = new ReceiptTemplate(['paper' => $paper, 'content' => ['blocks' => []]]);

        $html = app(ReceiptPdf::class)->html($template, $this->widestLines($width), []);

        $fontSize = $this->fontSizeOf($html);
        $usableMm = self::PAPER_MM[$paper] - 2 * self::PADDING_MM;
        $lineMm = ($width * self::ADVANCE * $fontSize) / self::MM_TO_PT;

        // Y no se pasa de prudente: un ticket con letra diminuta en medio rollo
        // es ilegible a la distancia a la que se lee un comprobante.
        $this->assertGreaterThan($usableMm * 0.9, $lineMm);
    }

    public function test_el_enfasis_no_ensancha_la_linea(): void
    {
        $template = new ReceiptTemplate(['paper' => 'thermal_80', 'content' => ['blocks' => []]]);

        $html = app(ReceiptPdf::class)->html($template, $this->widestLines(48), []);

        // `lg` marcaba el total y lo agrandaba un 25 %: esa línea se salía del
        // rollo y se llevaba el importe. El énfasis a doble alto es cosa de la
        // térmica, que no gasta ancho en ello.
        $this->assertStringContainsString('.lg { font-weight: bold; }', $html);
        $this->assertStringNotContainsString('.lg { font-size', $html);
    }

    public function test_el_comprobante_real_conserva_sus_importes(): void
    {
        $template = ReceiptTemplate::where('document_type', 'counter')->firstOrFail();

        $html = app(ReceiptPdf::class)->html($template, [
            ['kind' => 'pair', 'text' => 'TOTAL                                    1,234.56'],
        ], []);

        // El caso que lo destapó: el total estaba, pero fuera de la hoja.
        $this->assertStringContainsString('1,234.56', $html);
        $this->assertLessThanOrEqual(8.5, $this->fontSizeOf($html));
    }
}
