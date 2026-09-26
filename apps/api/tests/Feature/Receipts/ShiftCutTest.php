<?php

namespace Tests\Feature\Receipts;

use App\Models\ReceiptTemplate;
use App\Models\Shift;
use App\Services\Cash\ShiftService;
use App\Services\Receipts\ReceiptRenderer;
use App\Services\Receipts\ReceiptService;
use Database\Seeders\ReceiptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * El corte de turno, en papel (H4, H5.2).
 *
 * El desglose se calculaba entero —`ShiftService::summary()` devuelve monedas,
 * cajeros, denominaciones, ventas y propinas— y **no tenía por dónde salir al
 * papel**: ningún tipo de bloque sabía recorrer una lista, así que la plantilla
 * eran cuatro campos y un separador. Con veinte ventas el corte salía igual de
 * vacío que con ninguna, y eso no se ve hasta que alguien cierra la caja y tiene
 * que archivar el comprobante.
 *
 * Lo que estas pruebas fijan es que el número llegue al papel. El arqueo en sí
 * —lo esperado, lo contado, la diferencia— ya lo cubren las pruebas de turno.
 */
class ShiftCutTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->seed(ReceiptTemplateSeeder::class);

        $this->token = $this->signedIn();
    }

    /** El corte completo de un turno cerrado, ya renderizado. */
    private function cutOf(Shift $shift): array
    {
        $shifts = app(ShiftService::class);

        return app(ReceiptService::class)->forShift($shift, $shifts->summary($shift));
    }

    /** Todo el papel en un solo texto: es cómo se lee un corte. */
    private function paper(Shift $shift): string
    {
        return implode("\n", array_column($this->cutOf($shift)['lines'], 'text'));
    }

    /**
     * Vende y cierra el turno contando el cajón.
     *
     * Se cuenta de menos a propósito: el faltante es el renglón que obliga a
     * alguien a hacer algo, y es justo el que tiene que quedar impreso.
     */
    private function closedShift(): Shift
    {
        $product = $this->product('P-001', '100.00', ['allow_negative_stock' => true]);

        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->assertCreated()->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '2',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '200.00',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->actingAsTerminal($this->token)->postJson('/api/shifts/close', [
            'counts' => [
                ['denomination_value' => '1000.00', 'count' => 1],
                ['denomination_value' => '100.00', 'count' => 1],
            ],
        ])->assertOk();

        return Shift::where('branch_id', $this->branch->id)->latest('closed_at')->firstOrFail();
    }

    /** Una plantilla de un solo bloque, para mirarlo aislado. */
    private function render(array $block, array $context, string $paper = 'thermal_80'): array
    {
        $template = new ReceiptTemplate(['paper' => $paper, 'content' => ['blocks' => [$block]]]);

        return app(ReceiptRenderer::class)->render($template, $context);
    }

    public function test_el_corte_imprime_lo_vendido_y_no_solo_el_esquema(): void
    {
        $paper = $this->paper($this->closedShift());

        // Era el síntoma reportado: el papel traía los rótulos y ni un número.
        $this->assertStringContainsString(__('receipt.sales_summary'), $paper);
        $this->assertStringContainsString('200.00', $paper);
        $this->assertStringContainsString(__('receipt.payment_method.cash'), $paper);
    }

    public function test_el_corte_imprime_el_arqueo_con_su_diferencia(): void
    {
        $paper = $this->paper($this->closedShift());

        // Fondo 1.000 más 200 vendidos son 1.200 esperados; se contaron 1.100.
        $this->assertStringContainsString(__('receipt.cash_count'), $paper);
        $this->assertStringContainsString('1200.00', $paper);
        $this->assertStringContainsString('1100.00', $paper);
        $this->assertStringContainsString('-100.00', $paper);
    }

    public function test_el_corte_imprime_el_conteo_por_denominacion(): void
    {
        $paper = $this->paper($this->closedShift());

        // Es lo que convierte "falta plata" en "falta un billete de cien".
        $this->assertStringContainsString(__('receipt.denominations'), $paper);
        $this->assertMatchesRegularExpression('/1000\s+1\s+1000\.00/', $paper);
    }

    public function test_el_corte_atribuye_las_ventas_a_su_cajero(): void
    {
        $paper = $this->paper($this->closedShift());

        // El turno es del equipo sobre un cajón físico, pero cada venta es de
        // alguien: es lo que permite el relevo sin contar el cajón (D-05).
        $this->assertStringContainsString(__('receipt.by_cashier'), $paper);
        $this->assertStringContainsString($this->cashier->full_name, $paper);
    }

    public function test_un_turno_sin_ventas_imprime_cero_y_no_nada(): void
    {
        // Se cuenta el fondo y nada más: el turno abrió y no vendió.
        $this->actingAsTerminal($this->token)->postJson('/api/shifts/close', [
            'counts' => [['denomination_value' => '1000.00', 'count' => 1]],
        ])->assertOk();

        $shift = Shift::where('branch_id', $this->branch->id)->latest('closed_at')->firstOrFail();
        $paper = $this->paper($shift);

        // Un corte en blanco no se distingue de una impresora que falló, y el
        // papel se archiva igual.
        $this->assertStringContainsString(__('receipt.tickets'), $paper);
        $this->assertStringContainsString('0.00', $paper);
    }

    public function test_la_diferencia_a_favor_lleva_su_signo(): void
    {
        $lines = $this->render(['type' => 'shift_currencies'], [
            'currencies' => [
                ['currency_code' => 'NIO', 'expected' => '100.00', 'counted' => '120.00', 'difference' => '20.00'],
            ],
        ]);

        // Sobrar no es faltar, aunque las dos descuadren: sin el signo, quien
        // archiva el papel no sabe si el cajero debe plata o si le sobró.
        $this->assertStringContainsString('+20.00', $lines[4]['text']);
        $this->assertTrue($lines[4]['bold']);
    }

    public function test_un_arqueo_cuadrado_no_grita(): void
    {
        $lines = $this->render(['type' => 'shift_currencies'], [
            'currencies' => [
                ['currency_code' => 'NIO', 'expected' => '100.00', 'counted' => '100.00', 'difference' => '0.00'],
            ],
        ]);

        // La negrita es del descuadre: ponerla siempre la vacía de significado.
        $this->assertFalse($lines[4]['bold']);
    }

    public function test_el_conteo_se_agrupa_por_moneda_solo_cuando_hay_dos(): void
    {
        $unaMoneda = $this->render(['type' => 'shift_denominations'], [
            'denominations' => [
                ['currency_code' => 'NIO', 'denomination' => '100.00', 'count' => 2, 'subtotal' => '200.00'],
            ],
        ]);

        // El rótulo "NIO" sobre la única moneda del local es ruido.
        $this->assertSame([], array_filter(
            $unaMoneda,
            static fn (array $line) => ($line['kind'] ?? '') === 'shift_group'
        ));

        $dosMonedas = $this->render(['type' => 'shift_denominations'], [
            'denominations' => [
                ['currency_code' => 'NIO', 'denomination' => '100.00', 'count' => 2, 'subtotal' => '200.00'],
                ['currency_code' => 'USD', 'denomination' => '20.00', 'count' => 1, 'subtotal' => '20.00'],
            ],
        ]);

        $this->assertCount(2, array_filter(
            $dosMonedas,
            static fn (array $line) => ($line['kind'] ?? '') === 'shift_group'
        ));
    }

    public function test_sin_propinas_no_hay_seccion_de_propinas(): void
    {
        // Un local sin propinas imprimiría "Propinas: 0.00" en cada corte del
        // año: un renglón que nunca dice nada enseña a no leer el papel.
        $this->assertSame([], $this->render(['type' => 'shift_tips'], [
            'tips' => ['total' => '0.00', 'by_employee' => []],
        ]));
    }

    public function test_la_propina_sin_dueno_se_imprime_igual(): void
    {
        $lines = $this->render(['type' => 'shift_tips'], [
            'tips' => [
                'total' => '500.00',
                'by_employee' => [['employee_id' => null, 'employee_name' => null, 'total' => '500.00']],
            ],
        ]);

        // Hay plata que sacar del cajón: esconderla por no saber de quién es
        // dejaría el arqueo cuadrando con dinero que ya no está.
        $this->assertStringContainsString(__('receipt.tip_unassigned'), $lines[2]['text']);
    }

    public function test_las_listas_vacias_no_dejan_rotulos_huerfanos(): void
    {
        foreach (['shift_currencies', 'shift_cashiers', 'shift_denominations'] as $type) {
            // Un encabezado sobre una lista vacía es un hueco que parece un
            // error de la impresora.
            $this->assertSame([], $this->render(['type' => $type], []), $type);
        }
    }

    public function test_el_rotulo_de_seccion_se_puede_apagar(): void
    {
        $lines = $this->render(['type' => 'shift_cashiers', 'show_title' => false], [
            'cashiers' => [['employee_name' => 'Ana', 'sales' => 3, 'total' => '300.00']],
        ]);

        $this->assertSame('shift_header', $lines[0]['kind']);
    }

    public function test_el_bloque_del_corte_en_un_ticket_de_venta_no_inventa_ceros(): void
    {
        // La misma plantilla la puede editar cualquiera: un bloque de turno
        // puesto en un ticket de mostrador tiene que callar, no imprimir un
        // resumen de ventas en blanco al pie de la factura del cliente.
        $this->assertSame([], $this->render(['type' => 'shift_sales'], [
            'sale' => ['total' => '100.00'],
        ]));
    }

    public function test_las_columnas_del_corte_caben_en_el_rollo_angosto(): void
    {
        $lines = $this->render(['type' => 'shift_cashiers'], [
            'cashiers' => [
                ['employee_name' => 'María de los Ángeles Rodríguez', 'sales' => 24, 'total' => '31980.50'],
            ],
        ], 'thermal_58');

        foreach ($lines as $line) {
            if (($line['kind'] ?? '') === 'section') {
                continue;
            }

            // Pasarse del rollo es perder el importe, que es la columna que se
            // revisa. Y "ñ" ocupa dos bytes: se cuentan caracteres.
            $this->assertSame(32, mb_strlen($line['text']));
        }
    }

    public function test_un_bloque_vacio_no_deja_dos_rayas_seguidas(): void
    {
        $template = new ReceiptTemplate([
            'paper' => 'thermal_80',
            'content' => ['blocks' => [
                ['type' => 'text', 'content' => 'Gracias'],
                ['type' => 'separator'],
                ['type' => 'shift_tips'],
                ['type' => 'separator'],
                ['type' => 'text', 'content' => 'Vuelva pronto'],
            ]],
        ]);

        $lines = app(ReceiptRenderer::class)->render($template, []);

        // Dos rayas juntas se leen como una impresora que repitió una línea, y la
        // plantilla no puede preverlo: solo el renderizador sabe qué bloque quedó
        // vacío.
        $this->assertCount(3, $lines);
        $this->assertCount(1, array_filter(
            $lines,
            static fn (array $line) => ($line['kind'] ?? '') === 'separator'
        ));
    }

    public function test_la_vista_previa_del_corte_muestra_numeros(): void
    {
        $template = ReceiptTemplate::where('code', 'corte-turno')->firstOrFail();

        // Ajustar el ticket es de quien lleva el local, no del cajero ni del
        // dueño: `pos_settings.read` vive en el rol de encargado.
        $manager = $this->signedIn($this->employee('ENC01', '4321', 'manager'), '4321');

        $lines = $this->actingAsTerminal($manager)->postJson('/api/receipt-templates/preview', [
            'content' => $template->content,
            'paper' => $template->paper,
        ])->assertOk()->json('data.lines');

        $paper = implode("\n", array_column($lines, 'text'));

        // Sin datos de ejemplo de turno, el editor mostraba el esquema y ni un
        // número: configurar el corte era teclear a ciegas.
        $this->assertStringContainsString(__('receipt.cash_count'), $paper);
        $this->assertStringContainsString(__('receipt.by_cashier'), $paper);
        $this->assertMatchesRegularExpression('/\d+\.\d{2}/', $paper);
    }
}
