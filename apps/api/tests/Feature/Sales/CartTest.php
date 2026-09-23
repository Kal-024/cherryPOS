<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Sale;
use App\Models\Terminal;
use App\Services\Sales\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * El carrito y los estados de la venta (D-21, D-01, H2.5–H2.7).
 *
 * En OSPOS el carrito vivía en `$_SESSION`: ninguna venta podía continuarse en
 * otro dispositivo, ninguna app podía consumir el flujo y ningún proceso podía
 * validarla. Aquí es una venta en estado `draft` con identidad propia, y el
 * identificador lo genera **el terminal**.
 *
 * Criterio de aceptación de H2.5, literal: **cerrar el navegador y recuperar el
 * carrito**.
 */
class CartTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
    }

    /** @param array<string,mixed> $payload */
    private function openSale(array $payload = []): string
    {
        return $this->withToken($this->token)
            ->postJson('/api/sales', $payload)
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function addProduct(string $saleId, Product $product, string $qty = '1', array $extra = []): array
    {
        return $this->withToken($this->token)
            ->postJson("/api/sales/{$saleId}/lines", array_merge([
                'kind' => 'product',
                'product_id' => $product->id,
                'qty' => $qty,
            ], $extra))
            ->assertCreated()
            ->json('data');
    }

    public function test_el_terminal_genera_el_identificador_de_la_venta(): void
    {
        // De esto dependen el modo degradado y la idempotencia del ERP: el
        // terminal tiene que poder armar una venta sin consultar al servidor.
        $id = (string) Str::uuid7();

        $this->withToken($this->token)
            ->postJson('/api/sales', ['id' => $id])
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('pos_sales', ['id' => $id, 'status' => 'draft']);
    }

    public function test_el_carrito_sobrevive_al_cierre_del_navegador(): void
    {
        $product = $this->product('P-001', '100.00');
        $sale = $this->openSale();
        $this->addProduct($sale, $product, '2');

        // "Cerrar el navegador": el terminal vuelve a autenticarse y el cajero
        // reingresa su PIN. El carrito sigue donde estaba.
        $this->token = $this->signedIn();

        $recovered = $this->withToken($this->token)
            ->getJson("/api/sales/{$sale}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $recovered['lines']);
        // El precio de góndola es lo que paga el cliente: 2 × 100,00. El IVA va
        // dentro y se extrae al facturar.
        $this->assertSame('200.00', $recovered['total']);
    }

    public function test_los_totales_los_calcula_el_servidor_no_el_terminal(): void
    {
        $product = $this->product('P-002', '87.50');
        $sale = $this->openSale();

        $result = $this->addProduct($sale, $product, '1');

        // 87,50 con IVA dentro: base 76,09 e impuesto 11,41. El terminal no
        // manda totales, los recibe.
        $this->assertSame('76.09', $result['sale']['subtotal']);
        $this->assertSame('11.41', $result['sale']['tax_total']);
        $this->assertSame('87.50', $result['sale']['total']);
    }

    /** Una venta cerrada de verdad: es lo que una devolución necesita detrás. */
    private function closedSale(string $price = '100.00'): string
    {
        $product = $this->product('P-REF', $price);
        $sale = $this->openSale();
        $this->addProduct($sale, $product, '1');

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/payments", ['method' => 'cash', 'amount' => $price])
            ->assertCreated();

        $this->withToken($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        return $sale;
    }

    public function test_los_cinco_tipos_de_venta_viven_en_un_solo_modelo(): void
    {
        // B-01: mostrador, factura, presupuesto, orden de trabajo y devolución
        // sobre la misma tabla, distinguidos por `sale_type`.
        foreach (['counter', 'invoice', 'quote', 'work_order'] as $type) {
            $id = $this->openSale(['sale_type' => $type]);
            $this->assertSame($type, Sale::find($id)->sale_type);
        }

        // La devolución es el quinto tipo y vive en la misma tabla, pero **no se
        // abre en el aire**: necesita el ticket que reversa. La regla la impone
        // el POS antes de devolver nada, no el ERP cuando ya salió el dinero.
        $original = $this->closedSale();

        // Y con la firma del supervisor: devolver es sacar plata del cajón, así
        // que el rol de cajero no lo trae.
        $this->employee('SUPDEV', '4321', 'supervisor', '9876');

        $refund = $this->openSale([
            'sale_type' => 'refund',
            'reverses_sale_id' => $original,
            'supervisor_code' => 'SUPDEV',
            'supervisor_pin' => '9876',
        ]);

        $this->assertSame('refund', Sale::find($refund)->sale_type);
        $this->assertSame($original, Sale::find($refund)->reverses_sale_id);

        $this->withToken($this->token)
            ->postJson('/api/sales', [
                'sale_type' => 'refund',
                'supervisor_code' => 'SUPDEV',
                'supervisor_pin' => '9876',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.reverses_sale_id.0', __('sales.refund_needs_original'));
    }

    public function test_suspender_es_un_estado_no_una_tabla_espejo(): void
    {
        $product = $this->product('P-003', '50.00');
        $sale = $this->openSale();
        $this->addProduct($sale, $product, '3');

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/suspend", ['label' => 'Mesa 4'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.label', 'Mesa 4');

        // OSPOS duplicaba cuatro tablas para sostener esto. Aquí la venta es la
        // misma fila con otro estado.
        $this->assertSame(1, Sale::count());
        $this->assertDatabaseCount('pos_sale_lines', 1);
    }

    public function test_una_venta_suspendida_se_retoma_en_otra_terminal(): void
    {
        $product = $this->product('P-004', '40.00');
        $sale = $this->openSale();
        $this->addProduct($sale, $product, '1');

        $this->withToken($this->token)->postJson("/api/sales/{$sale}/suspend")->assertOk();

        // Otra caja de la misma sucursal. Es lo que el carrito en sesión HTTP
        // hacía imposible.
        $otra = Terminal::create([
            'branch_id' => $this->branch->id,
            'code' => 'CAJA-02',
            'name' => 'Caja 2',
            'secret_hash' => Hash::make('otro-secreto'),
        ]);

        $this->app['auth']->forgetGuards();

        $otroToken = $this->postJson('/api/terminal/login', [
            'branch_code' => '001',
            'terminal_code' => 'CAJA-02',
            'secret' => 'otro-secreto',
        ])->json('data.token');

        $this->actingAsTerminal($otroToken)->postJson('/api/operator/session', [
            'employee_code' => 'CAJ01',
            'pin' => '1234',
        ])->assertCreated();

        $this->actingAsTerminal($otroToken)
            ->postJson("/api/sales/{$sale}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.terminal_id', $otra->id);
    }

    public function test_no_se_suspende_un_carrito_vacio(): void
    {
        $sale = $this->openSale();

        $this->withToken($this->token)
            ->postJson("/api/sales/{$sale}/suspend")
            ->assertStatus(422);
    }

    public function test_quitar_una_linea_renumera_las_demas(): void
    {
        $sale = $this->openSale();
        $a = $this->product('P-A', '10.00');
        $b = $this->product('P-B', '20.00');
        $c = $this->product('P-C', '30.00');

        $this->addProduct($sale, $a);
        $middle = $this->addProduct($sale, $b)['lines'][0]['id'];
        $this->addProduct($sale, $c);

        $this->withToken($this->token)
            ->deleteJson("/api/sales/{$sale}/lines/{$middle}")
            ->assertOk();

        $sequences = Sale::find($sale)->lines->pluck('sequence')->all();

        // Dejar huecos en la numeración confundiría al cajero, que lee la línea
        // por su número al cancelarla.
        $this->assertSame([1, 2], $sequences);
    }

    public function test_un_combo_se_explota_en_sus_componentes(): void
    {
        // D-14 y §4 del contrato: el ERP no necesita saber qué es un combo. El
        // POS manda los componentes sueltos con el descuento repartido, y
        // `group_ref` es lo único que los relaciona.
        $burger = $this->product('HAMB', '120.00');
        $fries = $this->product('PAPAS', '60.00');
        $soda = $this->product('GASEOSA', '40.00');

        $combo = $this->product('COMBO', '180.00', ['is_composite' => true]);

        foreach ([$burger, $fries, $soda] as $part) {
            ProductComponent::create([
                'parent_id' => $combo->id,
                'component_id' => $part->id,
                'qty' => '1',
            ]);
        }

        $sale = $this->openSale();
        $lines = $this->addProduct($sale, $combo, '1')['lines'];

        $this->assertCount(3, $lines);

        // Las tres comparten la marca del combo.
        $this->assertCount(1, array_unique(array_column($lines, 'group_ref')));
        $this->assertSame('Producto COMBO', $lines[0]['group_name']);

        // Lista 220, combo 180: se reparten 40 de descuento sin perder un
        // centavo. La factura muestra qué se llevó y cuánto se ahorró.
        $this->assertSame('180.00', Sale::find($sale)->total);
        $this->assertSame('40.00', Sale::find($sale)->line_discount_total);
    }

    public function test_un_cliente_exonerado_quita_el_impuesto_de_la_venta_en_curso(): void
    {
        $product = $this->product('P-005', '1000.00');
        $sale = $this->openSale();
        $this->addProduct($sale, $product, '1');

        // 1.000 con IVA dentro: 869,57 de base y 130,43 de impuesto.
        $this->assertSame('130.43', Sale::find($sale)->tax_total);

        $exempt = $this->customer('Organismo exonerado');
        $exempt->update(['is_tax_exempt' => true]);

        // No alcanza con guardar el cliente: cambia el impuesto, así que hay que
        // recalcular (Q-07).
        app(CartService::class)->setCustomer(Sale::find($sale), $exempt->id);

        $updated = Sale::find($sale);

        $this->assertSame('0.00', $updated->tax_total);
        $this->assertSame('1000.00', $updated->exempt_total);

        // **El cliente paga lo mismo.** Con precio IVA incluido, exonerar no
        // abarata: el importe deja de declararse como base gravada y pasa a
        // exento, que es exactamente lo que hace `TaxCalculationService` del
        // ERP. Si el negocio quiere que el exonerado pague menos, eso es una
        // lista de precios distinta, no una bandera fiscal.
        $this->assertSame('1000.00', $updated->total);
    }
}
