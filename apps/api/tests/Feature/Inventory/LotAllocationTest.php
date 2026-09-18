<?php

namespace Tests\Feature\Inventory;

use App\Models\Lot;
use App\Services\Inventory\LotAllocationService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Lotes y vencimientos (G-07, H1.4).
 *
 * Criterio de aceptación: **salida por primero en vencer**. No es una
 * preferencia contable — en farmacia y en alimentos, sacar el lote de atrás es
 * cómo se llega a vender mercadería vencida, y eso no se arregla con un ajuste
 * después.
 */
class LotAllocationTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private LotAllocationService $lots;

    private StockLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->lots = app(LotAllocationService::class);
        $this->ledger = app(StockLedgerService::class);
    }

    private function stockLot(string $productId, string $code, ?string $expires, string $qty): Lot
    {
        $lot = Lot::create([
            'product_id' => $productId,
            'code' => $code,
            'expires_on' => $expires,
        ]);

        $this->ledger->record([
            'branch_id' => $this->branch->id,
            'location_id' => $this->location->id,
            'product_id' => $productId,
            'lot_id' => $lot->id,
            'reason' => 'receipt',
            'qty' => $qty,
            'occurred_at' => now(),
        ]);

        return $lot;
    }

    public function test_sale_primero_el_lote_que_vence_antes(): void
    {
        $product = $this->product('MED-001', '35.00', ['tracks_lots' => true]);

        // Cargado en desorden a propósito: el orden de recepción no es el orden
        // de salida.
        $tarde = $this->stockLot($product->id, 'L-TARDE', now()->addYear()->toDateString(), '40');
        $pronto = $this->stockLot($product->id, 'L-PRONTO', now()->addDays(10)->toDateString(), '25');

        $allocation = $this->lots->allocate($product->id, $this->location->id, '30');

        $this->assertCount(2, $allocation);
        $this->assertSame($pronto->id, $allocation[0]['lot_id']);
        $this->assertSame('25.0000', $allocation[0]['qty']);
        $this->assertSame($tarde->id, $allocation[1]['lot_id']);
        $this->assertSame('5.0000', $allocation[1]['qty']);
    }

    public function test_el_lote_sin_vencimiento_va_al_final(): void
    {
        $product = $this->product('MED-002', '20.00', ['tracks_lots' => true]);

        $sinFecha = $this->stockLot($product->id, 'L-SIN', null, '50');
        $conFecha = $this->stockLot($product->id, 'L-CON', now()->addMonths(3)->toDateString(), '10');

        $allocation = $this->lots->allocate($product->id, $this->location->id, '12');

        // Se prefiere mover lo que caduca antes que lo que no caduca nunca.
        $this->assertSame($conFecha->id, $allocation[0]['lot_id']);
        $this->assertSame($sinFecha->id, $allocation[1]['lot_id']);
    }

    public function test_no_alcanzan_los_lotes(): void
    {
        $product = $this->product('MED-003', '20.00', ['tracks_lots' => true]);
        $this->stockLot($product->id, 'L-1', now()->addMonth()->toDateString(), '5');

        $this->expectException(ValidationException::class);

        $this->lots->allocate($product->id, $this->location->id, '9');
    }

    public function test_los_lotes_vencidos_con_existencia_son_una_alerta(): void
    {
        $product = $this->product('MED-004', '20.00', ['tracks_lots' => true]);

        $vencido = $this->stockLot($product->id, 'L-VENCIDO', now()->subDay()->toDateString(), '7');
        $this->stockLot($product->id, 'L-VIGENTE', now()->addMonth()->toDateString(), '7');

        $expired = $this->lots->expired($this->location->id);

        // Es una alerta, no un reporte que alguien tenga que acordarse de abrir
        // (D-20): lo único que produce acción operativa directa.
        $this->assertCount(1, $expired);
        $this->assertSame($vencido->id, $expired->first()->id);
    }
}
