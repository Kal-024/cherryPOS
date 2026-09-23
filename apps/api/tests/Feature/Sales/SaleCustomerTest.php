<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * A quién se le vende, y cuánto hay que devolverle.
 *
 * Dos huecos que solo se ven usando la caja:
 *
 *  - **El cliente no se podía asignar.** `CartService::setCustomer()` existía
 *    desde el principio y no tenía ruta: el cliente solo se fijaba al abrir la
 *    venta, así que cobrar a crédito era imposible desde la pantalla y el error
 *    aparecía recién al cerrar.
 *  - **El vuelto no existía hasta cerrar.** La fila de vuelto la crea
 *    `SaleCloser`, así que mientras el cajero cobraba valía cero. Ahora la venta
 *    lo expone en todo momento.
 */
class SaleCustomerTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
    }

    private function openSaleWithLine(string $price = '115.00'): string
    {
        $product = $this->product('P-001', $price);

        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->assertCreated()->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        return $sale;
    }

    public function test_se_le_asigna_un_cliente_a_la_venta_abierta(): void
    {
        $sale = $this->openSaleWithLine();
        $customer = $this->customer('Rosa Mendoza', 'account', '001-010180-0001X');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/customer", ['customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customer->id);

        $this->assertSame($customer->id, Sale::find($sale)->customer_id);
    }

    public function test_el_cliente_se_puede_quitar(): void
    {
        $sale = $this->openSaleWithLine();
        $customer = $this->customer('Rosa Mendoza', 'account', '001-010180-0001X');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/customer", ['customer_id' => $customer->id])
            ->assertOk();

        // Equivocarse de cliente delante del cliente es lo más fácil del mundo.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/customer", ['customer_id' => null])
            ->assertOk()
            ->assertJsonPath('data.customer_id', null);
    }

    public function test_no_se_le_cambia_el_cliente_a_una_venta_cerrada(): void
    {
        $sale = $this->openSaleWithLine();
        $customer = $this->customer('Rosa Mendoza', 'account', '001-010180-0001X');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '115.00',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        // P1: una venta cerrada no se edita. Se corrige con un reverso.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/customer", ['customer_id' => $customer->id])
            ->assertStatus(422);
    }

    public function test_un_cliente_inexistente_no_pasa(): void
    {
        $sale = $this->openSaleWithLine();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/customer", [
                'customer_id' => '0193ffff-ffff-7fff-8fff-ffffffffffff',
            ])
            ->assertStatus(422);
    }

    public function test_el_vuelto_se_ve_antes_de_cerrar(): void
    {
        $sale = $this->openSaleWithLine();

        // Un billete de 500 sobre 115: el cajero tiene que ver 385 **mientras**
        // cobra, no después de cerrar.
        $response = $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '500.00',
        ])->assertCreated()->json('data.sale');

        $this->assertSame('385.00', $response['change']);
        $this->assertSame('0.00', $response['balance']);
    }

    public function test_sin_sobrepago_el_vuelto_es_cero(): void
    {
        $sale = $this->openSaleWithLine();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '100.00',
        ])->assertCreated();

        $shown = $this->actingAsTerminal($this->token)
            ->getJson("/api/sales/{$sale}")->assertOk()->json('data');

        $this->assertSame('0.00', $shown['change']);
        $this->assertSame('15.00', $shown['balance']);
    }
}
