<?php

namespace Tests\Feature\Inventory;

use App\Services\Inventory\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * La vista de existencias de la trastienda.
 *
 * El saldo que muestra sale de `inv_stock_balances`, que es caché: la fuente de
 * verdad es el libro de movimientos. Lo que estas pruebas fijan es que la caché
 * diga lo mismo que el libro, que el filtro de reposición solo mire a los
 * productos que **tienen** mínimo, y que los servicios no aparezcan — no hay
 * nada que contar de un servicio.
 */
class StockOverviewTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private StockLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->ledger = app(StockLedgerService::class);
    }

    public function test_las_existencias_son_la_suma_de_los_movimientos(): void
    {
        $product = $this->product('SKU-1', '100.00', ['min_stock' => '5']);
        $token = $this->signedIn($this->employee('SUP01', '1111', 'supervisor'), '1111');

        $this->receive($product->id, '10');
        $this->receive($product->id, '4');

        $response = $this->actingAsTerminal($token)
            ->getJson("/api/inventory/balances?location_id={$this->location->id}")
            ->assertOk();

        $this->assertSame('SKU-1', $response->json('data.0.sku'));
        $this->assertSame(14.0, (float) $response->json('data.0.qty'));
    }

    public function test_el_filtro_de_reposicion_solo_mira_lo_que_tiene_minimo(): void
    {
        $low = $this->product('SKU-LOW', '100.00', ['min_stock' => '10']);
        $noMinimum = $this->product('SKU-SIN-MIN', '100.00');

        $this->receive($low->id, '2');
        $this->receive($noMinimum->id, '1');

        $token = $this->signedIn($this->employee('SUP01', '1111', 'supervisor'), '1111');

        $response = $this->actingAsTerminal($token)
            ->getJson("/api/inventory/balances?location_id={$this->location->id}&below_min=1")
            ->assertOk();

        // Un producto sin umbral no está "por debajo" de nada: incluirlo llenaría
        // la lista de reposición con todo el catálogo.
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('SKU-LOW', $response->json('data.0.sku'));
    }

    public function test_un_servicio_no_aparece_en_existencias(): void
    {
        $service = $this->product('SERV-1', '300.00', ['tracks_stock' => false]);
        $product = $this->product('SKU-1', '100.00');

        $this->receive($product->id, '3');
        $token = $this->signedIn($this->employee('SUP01', '1111', 'supervisor'), '1111');

        $response = $this->actingAsTerminal($token)
            ->getJson("/api/inventory/balances?location_id={$this->location->id}")
            ->assertOk();

        $skus = array_column($response->json('data'), 'sku');

        $this->assertContains('SKU-1', $skus);
        $this->assertNotContains($service->sku, $skus);
    }

    public function test_las_ubicaciones_son_las_de_la_sucursal_de_la_terminal(): void
    {
        // El cajero no lee inventario: vender no exige saber cuánto hay en
        // bodega, y el permiso es de quien repone.
        $token = $this->signedIn($this->employee('SUP01', '1111', 'supervisor'), '1111');

        $this->actingAsTerminal($token)
            ->getJson('/api/inventory/locations')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'PRINCIPAL');
    }

    private function receive(string $productId, string $qty): void
    {
        $this->ledger->record([
            'branch_id' => $this->branch->id,
            'location_id' => $this->location->id,
            'product_id' => $productId,
            'qty' => $qty,
            'unit_cost' => '50.00',
            'reason' => 'receipt',
            'employee_id' => $this->cashier->id,
            'occurred_at' => now(),
        ]);
    }
}
