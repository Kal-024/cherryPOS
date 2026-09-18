<?php

namespace Tests\Feature\Sales;

use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Sale;
use App\Services\Inventory\StockLedgerService;
use App\Services\Sales\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Cierre de la venta: el momento en que un carrito se convierte en documento.
 *
 * A partir de aquí la venta es **inmutable** (P1, D-17): un error se corrige con
 * un documento de reverso, no editando este. El disparador de la base lo impone,
 * y `ImmutabilityTest` lo prueba desde abajo; aquí se prueba el flujo completo.
 */
class CheckoutTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private StockLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
        $this->ledger = app(StockLedgerService::class);
    }

    private function saleWith(string $sku, string $price, string $qty = '1', array $productAttrs = []): string
    {
        $product = $this->product($sku, $price, $productAttrs);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => $qty,
        ])->assertCreated();

        return $sale;
    }

    private function pay(string $sale, array $payment): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/payments", $payment)
            ->assertCreated();
    }

    public function test_una_venta_pagada_se_cierra_y_recibe_numero(): void
    {
        $sale = $this->saleWith('P-001', '100.00');
        $this->pay($sale, ['method' => 'cash', 'amount' => '100.00']);

        $closed = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/close")
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $closed['status']);
        // Plantilla `{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}` (B-05).
        $this->assertSame('001-COU-'.now()->format('Y').'-000001', $closed['number']);
        $this->assertNotNull($closed['closed_at']);
    }

    public function test_no_se_cierra_una_venta_con_saldo_pendiente(): void
    {
        $sale = $this->saleWith('P-002', '100.00');
        $this->pay($sale, ['method' => 'cash', 'amount' => '50.00']);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/close")
            ->assertStatus(422)
            ->assertJsonPath('errors.payments.0', __('sales.balance_pending', ['balance' => '50.00']));
    }

    public function test_el_correlativo_avanza_de_uno_en_uno(): void
    {
        foreach (range(1, 3) as $i) {
            $sale = $this->saleWith("P-10{$i}", '10.00');
            $this->pay($sale, ['method' => 'cash', 'amount' => '10.00']);
            $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();
        }

        $numbers = Sale::whereNotNull('number')->orderBy('number')->pluck('number')->all();
        $year = now()->format('Y');

        $this->assertSame([
            "001-COU-{$year}-000001",
            "001-COU-{$year}-000002",
            "001-COU-{$year}-000003",
        ], $numbers);
    }

    public function test_cerrar_descuenta_existencias(): void
    {
        $sale = $this->saleWith('P-003', '100.00', '3', ['allow_negative_stock' => false]);
        $product = Sale::with('lines')->find($sale)->lines->first()->product;

        $this->ledger->record([
            'branch_id' => $this->branch->id,
            'location_id' => $this->location->id,
            'product_id' => $product->id,
            'reason' => 'receipt',
            'qty' => '10',
            'occurred_at' => now(),
        ]);

        $this->pay($sale, ['method' => 'cash', 'amount' => '300.00']);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->assertSame('7.0000', $this->ledger->available($product->id, $this->location->id));

        // El movimiento apunta a la venta: el kardex explica de dónde salió cada
        // unidad.
        $this->assertDatabaseHas('inv_movements', [
            'source_type' => 'sale',
            'source_id' => $sale,
            'reason' => 'sale',
        ]);
    }

    public function test_un_servicio_no_toca_el_inventario(): void
    {
        $sale = $this->saleWith('SRV-001', '500.00', '1', ['tracks_stock' => false]);
        $this->pay($sale, ['method' => 'cash', 'amount' => '500.00']);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->assertDatabaseCount('inv_movements', 0);
    }

    public function test_una_venta_cerrada_ya_no_admite_lineas(): void
    {
        $sale = $this->saleWith('P-004', '100.00');
        $this->pay($sale, ['method' => 'cash', 'amount' => '100.00']);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $otro = $this->product('P-005', '20.00');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $otro->id,
                'qty' => '1',
            ])
            ->assertStatus(422);
    }

    public function test_se_cobra_en_dolares_y_el_vuelto_sale_en_cordobas(): void
    {
        // Q-06: operación cotidiana de caja en Nicaragua. La tasa la carga el
        // supervisor y rige hasta que la cambie.
        app(ExchangeRateService::class)->set('USD', '36.624300', $this->cashier->id);

        $sale = $this->saleWith('P-006', '500.00');
        $this->pay($sale, ['method' => 'cash', 'currency_code' => 'USD', 'amount' => '20.00']);

        $updated = Sale::with('payments')->find($sale);
        $payment = $updated->payments->first();

        // 20 × 36,6243 = 732,486 → 732,49
        $this->assertSame('732.49', (string) $payment->amount_base);
        $this->assertSame('36.624300', (string) $payment->exchange_rate);
        // Total 500; el resto es vuelto, y sale en moneda base.
        $this->assertSame('0.00', (string) $updated->balance);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();
    }

    public function test_sin_tipo_de_cambio_cargado_no_se_cobra_en_dolares(): void
    {
        $sale = $this->saleWith('P-007', '500.00');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/payments", [
                'method' => 'cash',
                'currency_code' => 'USD',
                'amount' => '20.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.exchange_rate.0', __('sales.missing_exchange_rate', ['currency' => 'USD']));
    }

    public function test_una_venta_a_credito_carga_la_cuenta_del_cliente(): void
    {
        // Cliente con cuenta: la cédula es obligatoria y es su código (G-10).
        $customer = $this->customer('Don Julio', 'account', '001-010180-0001A');

        $account = CreditAccount::create([
            'customer_id' => $customer->id,
            'credit_limit' => '5000.00',
            'balance' => '0.00',
        ]);

        $sale = $this->saleWith('P-008', '1000.00');
        DB::table('pos_sales')->where('id', $sale)->update(['customer_id' => $customer->id]);

        $this->pay($sale, ['method' => 'credit', 'amount' => '1000.00']);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->assertSame('1000.00', (string) $account->fresh()->balance);
        $this->assertDatabaseHas('crm_credit_entries', [
            'account_id' => $account->id,
            'kind' => 'charge',
            'sale_id' => $sale,
        ]);
        $this->assertSame('4000.00', $account->fresh()->available());
    }

    public function test_el_limite_de_credito_bloquea_la_venta(): void
    {
        // Q-10: el límite **bloquea**, no advierte.
        $customer = $this->customer('Doña Rosa', 'account');
        CreditAccount::create([
            'customer_id' => $customer->id,
            'credit_limit' => '500.00',
            'balance' => '400.00',
        ]);

        $sale = $this->saleWith('P-009', '1000.00');
        DB::table('pos_sales')->where('id', $sale)->update(['customer_id' => $customer->id]);

        $this->pay($sale, ['method' => 'credit', 'amount' => '1000.00']);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/close")
            ->assertStatus(422)
            ->assertJsonPath('errors.payments.0', __('sales.credit_limit_exceeded', ['available' => '100.00']));

        // Nada quedó a medias: ni cargo, ni cierre.
        $this->assertDatabaseCount('crm_credit_entries', 0);
        $this->assertSame('draft', Sale::find($sale)->status);
    }

    public function test_una_cuenta_bloqueada_no_admite_consumo(): void
    {
        $customer = $this->customer('Moroso', 'account');
        $account = CreditAccount::create([
            'customer_id' => $customer->id,
            'credit_limit' => '10000.00',
            'balance' => '0.00',
            'is_blocked' => true,
            'blocked_reason' => 'Mora de dos meses',
        ]);

        $sale = $this->saleWith('P-010', '100.00');
        DB::table('pos_sales')->where('id', $sale)->update(['customer_id' => $customer->id]);

        $this->pay($sale, ['method' => 'credit', 'amount' => '100.00']);

        // El bloqueo manual del supervisor se suma al límite: una cuenta puede
        // estar dentro del límite y aun así bloqueada por mora (Q-10).
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/close")
            ->assertStatus(422)
            ->assertJsonPath('errors.payments.0', __('sales.credit_account_blocked'));

        $this->assertSame('0.00', (string) $account->fresh()->balance);
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_un_pago_mixto_salda_la_venta(): void
    {
        $sale = $this->saleWith('P-011', '1000.00');

        $this->pay($sale, ['method' => 'cash', 'amount' => '500.00']);
        $this->pay($sale, ['method' => 'card', 'amount' => '500.00', 'reference' => '4821']);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->assertSame('0.00', (string) Sale::find($sale)->balance);
        $this->assertDatabaseCount('pos_payments', 2);
    }

    public function test_un_presupuesto_se_cierra_sin_cobrar(): void
    {
        // Un presupuesto es una cotización: exigirle pagos sería impedir que
        // exista.
        $product = $this->product('P-012', '100.00');

        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', ['sale_type' => 'quote'])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '1',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/close")
            ->assertOk();
    }

    public function test_el_cierre_queda_en_la_bitacora(): void
    {
        $sale = $this->saleWith('P-013', '100.00');
        $this->pay($sale, ['method' => 'cash', 'amount' => '100.00']);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->assertDatabaseHas('sec_audit_log', [
            'event' => 'sale.closed',
            'entity_id' => $sale,
            'employee_id' => $this->cashier->id,
        ]);
    }

    public function test_sin_erp_configurado_no_se_encola_nada(): void
    {
        // P-01: en F1 el POS es autónomo y dueño de todo su dato. La cola
        // existiría para nadie.
        $sale = $this->saleWith('P-014', '100.00');
        $this->pay($sale, ['method' => 'cash', 'amount' => '100.00']);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->assertDatabaseCount('pos_erp_outbox', 0);
    }
}
