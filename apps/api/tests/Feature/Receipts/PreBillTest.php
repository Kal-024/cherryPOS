<?php

namespace Tests\Feature\Receipts;

use App\Models\DiningTable;
use App\Models\Sale;
use App\Services\Receipts\ReceiptService;
use Database\Seeders\ReceiptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * La precuenta del salón (F1-B).
 *
 * En un restaurante el cliente pide ver lo consumido **antes** de pagar. Ese
 * papel no es el comprobante y no puede parecerlo: si no dice que no es
 * comprobante de pago, alguien se va creyendo que ya tiene su factura y el ticket
 * real queda sin entregar.
 *
 * Y tiene un efecto que el mapa necesita: **imprimirla marca la mesa como
 * "cuenta pedida"**. Deducirlo del gesto que ya existe evita un segundo botón que
 * alguien olvidaría, con el mapa mintiendo justo cuando hay gente esperando mesa.
 */
class PreBillTest extends TestCase
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

    /** Una cuenta de mesa con un plato servido. */
    private function openTab(): Sale
    {
        $table = DiningTable::create([
            'branch_id' => $this->branch->id,
            'code' => 'M1',
            'name' => 'Mesa 1',
            'seats' => 4,
        ]);

        $product = $this->product('P-001', '250.00', ['allow_negative_stock' => true]);

        $saleId = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open", ['guests' => 2])
            ->assertCreated()->json('data.sale.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$saleId}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '2',
        ])->assertCreated();

        return Sale::findOrFail($saleId);
    }

    /** El papel entero, como texto. */
    private function paper(Sale $sale): string
    {
        $rendered = app(ReceiptService::class)->forPreBill($sale);

        return implode("\n", array_column($rendered['lines'], 'text'));
    }

    public function test_la_cuenta_muestra_lo_consumido_y_su_total(): void
    {
        $paper = $this->paper($this->openTab());

        $this->assertStringContainsString(__('receipt.pre_bill'), $paper);
        $this->assertStringContainsString('500.00', $paper);
    }

    public function test_la_cuenta_dice_que_no_es_comprobante_de_pago(): void
    {
        $paper = $this->paper($this->openTab());

        // Sin este aviso, el cliente se va con la precuenta creyendo que ya tiene
        // su factura y el ticket real queda sin entregar.
        $this->assertStringContainsString(__('receipt.pre_bill_notice'), $paper);
    }

    public function test_la_cuenta_no_lleva_pagos_porque_no_se_cobro_nada(): void
    {
        $rendered = app(ReceiptService::class)->forPreBill($this->openTab());

        $kinds = array_column($rendered['lines'], 'kind');

        $this->assertNotContains('payment', $kinds);
        $this->assertNotContains('change', $kinds);
    }

    public function test_imprimirla_marca_la_mesa_como_cuenta_pedida(): void
    {
        $sale = $this->openTab();

        $this->assertNull($sale->bill_requested_at);

        $this->actingAsTerminal($this->token)
            ->get("/api/sales/{$sale->id}/pre-bill/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Es el estado que el mapa necesita: de "están comiendo" a "están por
        // irse". Un botón aparte sería un paso que alguien olvida.
        $this->assertNotNull($sale->fresh()->bill_requested_at);
    }

    public function test_reimprimirla_conserva_la_hora_del_primer_pedido(): void
    {
        $sale = $this->openTab();

        $this->actingAsTerminal($this->token)->get("/api/sales/{$sale->id}/pre-bill/pdf")->assertOk();

        $primera = $sale->fresh()->bill_requested_at;

        $this->travel(5)->minutes();

        $this->actingAsTerminal($this->token)->get("/api/sales/{$sale->id}/pre-bill/pdf")->assertOk();

        // El cliente la pide, la mira, sigue pidiendo postre y la vuelve a pedir.
        // Lo que el mapa muestra es cuánto lleva esperando pagar, así que la hora
        // que importa es la primera.
        $this->assertEquals($primera, $sale->fresh()->bill_requested_at);
    }

    public function test_el_mapa_lleva_la_marca_para_pintar_la_mesa(): void
    {
        $sale = $this->openTab();

        $this->actingAsTerminal($this->token)->get("/api/sales/{$sale->id}/pre-bill/pdf")->assertOk();

        $map = $this->actingAsTerminal($this->token)->getJson('/api/dining/map')->assertOk();

        $this->assertNotNull($map->json('data.tables.0.sale.bill_requested_at'));
    }

    public function test_una_cuenta_ya_cobrada_no_tiene_precuenta(): void
    {
        $sale = $this->openTab();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale->id}/resume")->assertOk();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => '500.00',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale->id}/close")->assertOk();

        // Ya tiene su comprobante de verdad: dar otro papel parecido es la forma
        // de que el cliente se quede con el equivocado.
        $this->actingAsTerminal($this->token)
            ->get("/api/sales/{$sale->id}/pre-bill/pdf")
            ->assertStatus(422);
    }
}
