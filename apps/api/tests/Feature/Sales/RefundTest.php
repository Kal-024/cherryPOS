<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Devoluciones contra el ticket original (H2.6, P-04).
 *
 * Una devolución **no es una entidad aparte**: es una venta con `sale_type`
 * `refund`, cantidades negativas y pago negativo, apuntando con
 * `reverses_sale_id` al documento que reversa.
 *
 * Dos reglas que el POS tiene que imponer **antes** de abrir el cajón, no
 * después:
 *
 *  - **El ticket original hace falta.** El contrato dice que el ERP rechaza la
 *    nota de crédito con `refund_original_missing`, pero esa respuesta viaja por
 *    la cola asíncrona y llega cuando el dinero ya salió.
 *  - **No se devuelve más de lo que se vendió.** Sin llevar la cuenta de lo ya
 *    devuelto, tres devoluciones parciales de una unidad vacían un ticket de
 *    dos: el mismo producto se paga dos veces y nada lo denuncia hasta el arqueo.
 */
class RefundTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        // Devolver es del supervisor: `pos_sale.refund` no está en el rol de
        // cajero. En la caja, el cajero arma la devolución y el supervisor la
        // autoriza con su PIN.
        $supervisor = $this->employee('SUP01', '4321', 'supervisor', '9876');
        $this->token = $this->signedIn($supervisor, '4321');
    }

    /** Un producto que tolera stock negativo: la prueba no trae mercadería. */
    private function stocked(string $sku = 'P-001', string $price = '100.00'): Product
    {
        return $this->product($sku, $price, ['allow_negative_stock' => true]);
    }

    /** Vende `qty` unidades y cierra: el ticket que el cliente trae en la mano. */
    private function soldTicket(string $qty = '3', string $price = '100.00'): array
    {
        $product = $this->stocked('P-001', $price);

        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->assertCreated()->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => $qty,
        ])->assertCreated();

        $total = bcmul($qty, $price, 2);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => $total,
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        return [$sale, $product];
    }

    /** Devuelve `qty` unidades del ticket y cierra la devolución. */
    private function refund(string $original, Product $product, string $qty, string $amount): array
    {
        $refund = $this->actingAsTerminal($this->token)->postJson('/api/sales', [
            'sale_type' => 'refund',
            'reverses_sale_id' => $original,
        ])->assertCreated()->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$refund}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '-'.$qty,
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$refund}/payments", [
            'method' => 'cash', 'amount' => '-'.$amount,
        ])->assertCreated();

        return [$refund, $this->actingAsTerminal($this->token)->postJson("/api/sales/{$refund}/close")];
    }

    public function test_sin_ticket_original_no_hay_devolucion(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', ['sale_type' => 'refund'])
            ->assertStatus(422)
            ->assertJsonPath('errors.reverses_sale_id.0', __('sales.refund_needs_original'));
    }

    public function test_el_ticket_tiene_que_existir_en_esta_sucursal(): void
    {
        $this->actingAsTerminal($this->token)->postJson('/api/sales', [
            'sale_type' => 'refund',
            'reverses_sale_id' => '0193ffff-ffff-7fff-8fff-ffffffffffff',
        ])->assertStatus(422);
    }

    public function test_no_se_devuelve_contra_una_venta_sin_cerrar(): void
    {
        $abierta = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->json('data.id');

        // Una venta que no se cerró no cobró nada: devolver sobre ella sería
        // regalar dinero.
        $this->actingAsTerminal($this->token)->postJson('/api/sales', [
            'sale_type' => 'refund',
            'reverses_sale_id' => $abierta,
        ])->assertStatus(422)
            ->assertJsonPath('errors.reverses_sale_id.0', __('sales.refund_original_not_closed'));
    }

    public function test_la_devolucion_parcial_sale_en_negativo_y_con_su_serie(): void
    {
        [$sale, $product] = $this->soldTicket('3');

        [, $response] = $this->refund($sale, $product, '1', '100.00');

        $closed = $response->assertOk()->json('data');

        $this->assertSame('refund', $closed['sale_type']);
        $this->assertSame('-100.00', $closed['total']);
        // Serie propia: una devolución no consume el correlativo de las ventas.
        $this->assertStringContainsString('REF', $closed['number']);
    }

    public function test_lo_devuelto_vuelve_al_estante(): void
    {
        [$sale, $product] = $this->soldTicket('3');

        $this->refund($sale, $product, '1', '100.00');

        $movements = \DB::table('inv_movements')
            ->where('product_id', $product->id)
            ->orderByDesc('recorded_at')
            ->first();

        $this->assertSame('refund', $movements->reason);
        $this->assertSame('1.0000', $movements->qty);
    }

    public function test_lo_que_queda_por_devolver_descuenta_lo_ya_devuelto(): void
    {
        [$sale, $product] = $this->soldTicket('3');

        $antes = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale}/refundable")->assertOk()->json('data');

        $this->assertSame('3.0000', $antes['lines'][0]['refundable']);
        $this->assertFalse($antes['fully_returned']);

        $this->refund($sale, $product, '1', '100.00');

        $despues = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale}/refundable")->assertOk()->json('data');

        $this->assertSame('1.0000', $despues['lines'][0]['returned']);
        $this->assertSame('2.0000', $despues['lines'][0]['refundable']);
    }

    public function test_no_se_devuelve_mas_de_lo_vendido(): void
    {
        [$sale, $product] = $this->soldTicket('2');

        $this->refund($sale, $product, '1', '100.00');
        $this->refund($sale, $product, '1', '100.00');

        // La tercera vaciaría un ticket de dos: el mismo producto se pagaría dos
        // veces y nada lo denunciaría hasta el arqueo.
        [, $response] = $this->refund($sale, $product, '1', '100.00');

        $response->assertStatus(422);
    }

    public function test_un_ticket_devuelto_entero_lo_dice(): void
    {
        [$sale, $product] = $this->soldTicket('2');

        $this->refund($sale, $product, '2', '200.00');

        $estado = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale}/refundable")->assertOk()->json('data');

        $this->assertTrue($estado['fully_returned']);
        $this->assertSame('0.0000', $estado['lines'][0]['refundable']);
    }

    public function test_no_se_devuelve_una_devolucion(): void
    {
        [$sale, $product] = $this->soldTicket('2');
        [$refund] = $this->refund($sale, $product, '1', '100.00');

        $this->actingAsTerminal($this->token)->postJson('/api/sales', [
            'sale_type' => 'refund',
            'reverses_sale_id' => $refund,
        ])->assertStatus(422)
            ->assertJsonPath('errors.reverses_sale_id.0', __('sales.refund_of_refund'));
    }

    public function test_un_cajero_no_devuelve_sin_autorizacion(): void
    {
        [$sale] = $this->soldTicket('1');

        $cashier = $this->employee('CAJ99', '1111', 'cashier');
        $token = $this->signedIn($cashier, '1111');

        // `pos_sale.refund` no está en el rol de cajero: devolver es sacar plata
        // del cajón y pide una segunda firma.
        $this->actingAsTerminal($token)->postJson('/api/sales', [
            'sale_type' => 'refund',
            'reverses_sale_id' => $sale,
        ])->assertStatus(422)
            ->assertJsonPath('errors.supervisor_pin.0', __('sales.refund_needs_authorization'));
    }

    public function test_con_el_pin_del_supervisor_el_cajero_devuelve(): void
    {
        [$sale] = $this->soldTicket('1');

        $cashier = $this->employee('CAJ99', '1111', 'cashier');
        $token = $this->signedIn($cashier, '1111');

        // El cajero arma la devolución y el supervisor la autoriza **en la misma
        // terminal**: mandar a buscar a alguien con el cliente esperando es lo
        // que hace que los controles se salten.
        $this->actingAsTerminal($token)->postJson('/api/sales', [
            'sale_type' => 'refund',
            'reverses_sale_id' => $sale,
            'supervisor_code' => 'SUP01',
            'supervisor_pin' => '9876',
        ])->assertCreated();
    }

    public function test_el_pin_de_sesion_no_sirve_para_autorizar(): void
    {
        [$sale] = $this->soldTicket('1');

        $cashier = $this->employee('CAJ99', '1111', 'cashier');
        $token = $this->signedIn($cashier, '1111');

        // El PIN de autorización es otro, por seguridad (P-11).
        $this->actingAsTerminal($token)->postJson('/api/sales', [
            'sale_type' => 'refund',
            'reverses_sale_id' => $sale,
            'supervisor_code' => 'SUP01',
            'supervisor_pin' => '4321',
        ])->assertStatus(422);
    }

    public function test_el_ticket_se_busca_por_numero(): void
    {
        [$sale] = $this->soldTicket('1');
        $number = Sale::find($sale)->number;

        // Es lo que el cliente trae impreso en la mano: buscarlo entre las del
        // día sería hacerlo esperar de pie.
        $found = $this->actingAsTerminal($this->token)
            ->getJson('/api/sales?number='.mb_substr($number, -6))
            ->assertOk()
            ->json('data');

        $this->assertSame($sale, $found[0]['id']);
    }
}
