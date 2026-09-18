<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryMovement;
use App\Models\StockBalance;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Libro mayor de existencias (B-03, H1.3).
 *
 * Criterio de aceptación del hito, literal: **el stock nunca se escribe directo;
 * solo se suma**. No hay una operación "poner el stock en 40": hay un movimiento
 * de ajuste con su motivo, y el saldo es la suma.
 */
class StockLedgerTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private StockLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->ledger = app(StockLedgerService::class);
    }

    private function move(string $productId, string $qty, string $reason = 'receipt', array $extra = []): InventoryMovement
    {
        return $this->ledger->record(array_merge([
            'branch_id' => $this->branch->id,
            'location_id' => $this->location->id,
            'product_id' => $productId,
            'reason' => $reason,
            'qty' => $qty,
            'occurred_at' => now(),
        ], $extra));
    }

    public function test_el_saldo_es_la_suma_de_los_movimientos(): void
    {
        $product = $this->product('P-001', '10.00', ['allow_negative_stock' => false]);

        $this->move($product->id, '100');
        $this->move($product->id, '-30', 'sale');
        $this->move($product->id, '8', 'adjustment');

        $this->assertSame('78.0000', $this->ledger->available($product->id, $this->location->id));
        $this->assertSame(3, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_la_cache_de_saldo_se_reconstruye_desde_el_libro(): void
    {
        $product = $this->product('P-002', '10.00');

        $this->move($product->id, '50');
        $this->move($product->id, '-20', 'sale');

        // La caché puede estar mal —un despliegue a medias, una restauración— y
        // el libro no. Por eso la verdad vive en `inv_movements`.
        StockBalance::where('product_id', $product->id)->update(['qty' => '999']);
        $this->assertSame('999.0000', $this->ledger->available($product->id, $this->location->id));

        $this->ledger->recalculate($product->id);

        $this->assertSame('30.0000', $this->ledger->available($product->id, $this->location->id));
    }

    public function test_no_se_vende_lo_que_no_hay(): void
    {
        $product = $this->product('P-003', '10.00', ['allow_negative_stock' => false]);
        $this->move($product->id, '5');

        try {
            $this->move($product->id, '-6', 'sale');
            $this->fail('Se permitió una salida mayor que la existencia.');
        } catch (ValidationException $e) {
            // Error con acción, no callejón sin salida: el mensaje dice cuánto
            // hay, que es lo que el cajero necesita para decidir.
            $this->assertStringContainsString('5.0000', $e->errors()['qty'][0]);
        }
    }

    public function test_un_producto_puede_admitir_existencia_negativa(): void
    {
        // Hay negocios que venden antes de recibir. Es una decisión del producto,
        // no del sistema.
        $product = $this->product('P-004', '10.00', ['allow_negative_stock' => true]);

        $this->move($product->id, '-3', 'sale');

        $this->assertSame('-3.0000', $this->ledger->available($product->id, $this->location->id));
    }

    public function test_un_servicio_no_lleva_existencias(): void
    {
        $service = $this->product('SRV-001', '500.00', ['tracks_stock' => false]);

        $this->expectException(ValidationException::class);

        // Registrarle movimientos llenaría el kardex de filas sin significado.
        $this->move($service->id, '1');
    }

    public function test_un_movimiento_de_cero_no_es_un_movimiento(): void
    {
        $product = $this->product('P-005', '10.00');

        $this->expectException(ValidationException::class);

        $this->move($product->id, '0');
    }

    public function test_el_costo_promedio_ponderado_se_recalcula_en_cada_entrada(): void
    {
        $product = $this->product('P-006', '30.00');

        // 100 a 10 = 1000. Después 100 a 20 = 2000. Promedio: 3000/200 = 15.
        $this->move($product->id, '100', 'receipt', ['unit_cost' => '10.0000']);
        $this->assertSame('10.0000', $product->fresh()->cost);

        $this->move($product->id, '100', 'receipt', ['unit_cost' => '20.0000']);
        $this->assertSame('15.0000', $product->fresh()->cost);

        // Una salida consume al costo vigente y no lo altera.
        $this->move($product->id, '-50', 'sale', ['unit_cost' => '15.0000']);
        $this->assertSame('15.0000', $product->fresh()->cost);
    }

    public function test_el_endpoint_de_ajuste_exige_motivo(): void
    {
        $product = $this->product('P-007', '10.00');
        $token = $this->signedIn($this->employee('SUP01', '4321', 'supervisor'), '4321');

        $this->withToken($token)->postJson('/api/inventory/adjustments', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'qty' => '8',
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/inventory/adjustments', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'qty' => '8',
            'comment' => 'Conteo físico de agosto',
        ])->assertCreated();

        $this->assertSame('8.0000', $this->ledger->available($product->id, $this->location->id));
    }
}
