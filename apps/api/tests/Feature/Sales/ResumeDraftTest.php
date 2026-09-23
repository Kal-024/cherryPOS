<?php

namespace Tests\Feature\Sales;

use App\Models\DiningArea;
use App\Models\DiningTable;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Retomar una cuenta abierta, esté como esté (F1-B).
 *
 * Había dos reglas que decían cosas distintas sobre la misma mesa:
 * `DiningTable::openSale()` la daba por ocupada con la venta en `draft` **o** en
 * `suspended`, y `resume()` solo toleraba `suspended`. Como nada vuelve a
 * suspender una cuenta al salir de la caja, bastaba con abrirla y marcharse para
 * dejar esa mesa ocupada y **sin forma de volver a entrar**: tocarla respondía
 * "esta venta no está suspendida", que a un mesero no le dice nada.
 */
class ResumeDraftTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
    }

    private function tableWithOpenSale(): array
    {
        $area = DiningArea::create([
            'branch_id' => $this->branch->id,
            'code' => 'SALON',
            'name' => 'Salón',
            'sort_order' => 1,
        ]);

        $table = DiningTable::create([
            'branch_id' => $this->branch->id,
            'area_id' => $area->id,
            'code' => 'M1',
            'name' => 'Mesa 1',
            'seats' => 4,
            'pos_x' => 40,
            'pos_y' => 40,
            'shape' => 'square',
        ]);

        // La cuenta de mesa se abre por su propia puerta: `POST /sales` no sabe
        // de mesas, y es `openTable()` quien las ata.
        $sale = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open", ['guests' => 2])
            ->assertCreated()
            ->json('data.sale.id');

        $product = $this->product('P-001', '115.00');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        return [$table, $sale];
    }

    public function test_la_cuenta_ya_retomada_se_puede_volver_a_abrir(): void
    {
        [, $sale] = $this->tableWithOpenSale();

        // La cuenta de mesa nace suspendida. El primer toque la retoma y la
        // deja en `draft`, que es como queda cuando el mesero entra a la caja.
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/resume")->assertOk();
        $this->assertSame(Sale::STATUS_DRAFT, Sale::find($sale)->status);

        // **Acá estaba el fallo.** Nada re-suspende la cuenta al salir de la
        // caja, así que el segundo toque —volver al salón y tocar la misma
        // mesa— respondía "esta venta no está suspendida" y dejaba la mesa
        // ocupada y sin forma de entrar.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/resume")
            ->assertOk();

        $this->assertSame(Sale::STATUS_DRAFT, Sale::find($sale)->status);
    }

    public function test_la_mesa_ocupada_siempre_se_puede_abrir(): void
    {
        [$table, $sale] = $this->tableWithOpenSale();

        // La regla que importa: si el mapa la pinta ocupada, tocarla tiene que
        // llevar a su cuenta. Las dos reglas ahora dicen lo mismo.
        $map = $this->actingAsTerminal($this->token)
            ->getJson('/api/dining/map')->assertOk()->json('data');

        $found = collect($map['tables'])->firstWhere('id', $table->id);

        $this->assertSame('occupied', $found['state']);
        $this->assertSame($sale, $found['sale']['id']);

        // La misma comprobación con la cuenta en cada uno de los dos estados
        // que el mapa da por ocupada: las dos reglas dicen lo mismo o la mesa
        // se vuelve intocable.
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/resume")->assertOk();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/resume")->assertOk();
    }

    public function test_una_cuenta_suspendida_se_sigue_retomando(): void
    {
        [, $sale] = $this->tableWithOpenSale();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', Sale::STATUS_DRAFT);
    }

    public function test_una_venta_cerrada_no_se_retoma(): void
    {
        [, $sale] = $this->tableWithOpenSale();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '115.00',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        // El mensaje ya no habla de suspensión: dice lo que de verdad pasa.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/resume")
            ->assertStatus(422)
            ->assertJsonPath('errors.sale.0', __('sales.not_resumable'));
    }
}
