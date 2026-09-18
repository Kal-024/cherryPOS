<?php

namespace Tests\Feature\Dining;

use App\Models\DiningTable;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * El salón (F1-B, D-01, §10 del plan).
 *
 * Lo que se prueba son las tres reglas que hacen que el mapa sirva:
 *
 *  - **El estado de la mesa se deduce**, no se guarda: ocupada es "tiene una
 *    venta suspendida". Una columna de estado se desincronizaría el día que una
 *    cuenta se cobre desde otra caja, y el mapa mostraría ocupada una mesa vacía.
 *  - **La cuenta abierta es una venta suspendida**, no una tabla espejo (D-01).
 *  - **Las mesas unidas son una sola unidad**: la absorbida no acepta cuenta
 *    propia, y unir una mesa que ya está sirviendo se rechaza en vez de mover
 *    importes en silencio.
 */
class DiningRoomTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->token = $this->signedIn();
    }

    public function test_una_mesa_recien_creada_esta_libre(): void
    {
        $this->table('M1');

        $response = $this->actingAsTerminal($this->token)
            ->getJson('/api/dining/map')
            ->assertOk();

        $this->assertSame('free', $response->json('data.tables.0.state'));
        $this->assertNull($response->json('data.tables.0.sale'));
    }

    public function test_abrir_la_cuenta_deja_la_mesa_ocupada(): void
    {
        $table = $this->table('M1');

        $response = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open", ['guests' => 4])
            ->assertCreated();

        $sale = Sale::find($response->json('data.sale.id'));

        // La cuenta es la venta: mismo modelo, mismo total, misma inmutabilidad.
        $this->assertSame(Sale::STATUS_SUSPENDED, $sale->status);
        $this->assertSame($table->id, $sale->dining_table_id);
        $this->assertSame(4, $sale->guests);

        $map = $this->actingAsTerminal($this->token)->getJson('/api/dining/map')->assertOk();

        $this->assertSame('occupied', $map->json('data.tables.0.state'));
        $this->assertSame(4, $map->json('data.tables.0.sale.guests'));
    }

    public function test_la_cuenta_nace_aunque_no_hayan_pedido_todavia(): void
    {
        $table = $this->table('M1');

        // Sentarse y pedir cinco minutos después es lo normal: la mesa tiene que
        // verse ocupada desde que el grupo se sienta.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->assertCreated();

        $this->assertSame(0, Sale::where('dining_table_id', $table->id)->firstOrFail()->lines()->count());
    }

    public function test_una_mesa_ocupada_no_abre_una_segunda_cuenta(): void
    {
        $table = $this->table('M1');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->assertCreated();

        // Dos cuentas en la misma mesa es cobrar dos veces al mismo grupo.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->assertStatus(422);
    }

    public function test_cerrar_la_cuenta_libera_la_mesa_sin_tocar_la_mesa(): void
    {
        $table = $this->table('M1');
        $product = $this->product('SKU-1', '115.00');

        $sale = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->json('data.sale.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/resume")->assertOk();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '1',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash',
            'amount' => '115.00',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $map = $this->actingAsTerminal($this->token)->getJson('/api/dining/map')->assertOk();

        // Nadie tocó la mesa y quedó libre: el estado se deduce de la venta.
        $this->assertSame('free', $map->json('data.tables.0.state'));
    }

    public function test_la_mesa_sigue_ocupada_mientras_el_mesero_edita_la_cuenta(): void
    {
        $table = $this->table('M1');

        $sale = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->json('data.sale.id');

        // Retomar la cuenta la pasa a `draft`: si el mapa mirara solo las
        // suspendidas, la mesa se vería libre justo mientras se le agrega el
        // postre, y otro mesero podría abrirle una segunda cuenta.
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/resume")->assertOk();

        $map = $this->actingAsTerminal($this->token)->getJson('/api/dining/map')->assertOk();

        $this->assertSame('occupied', $map->json('data.tables.0.state'));

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->assertStatus(422);
    }

    public function test_unir_mesas_deja_una_sola_unidad(): void
    {
        $main = $this->table('M1');
        $other = $this->table('M2');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$main->id}/merge", ['tables' => [$other->id]])
            ->assertOk();

        $this->assertSame($main->id, $other->fresh()->merged_into_id);

        // La absorbida no acepta cuenta propia: el grupo cobra una sola vez.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$other->id}/open")
            ->assertStatus(422);
    }

    public function test_no_se_une_una_mesa_que_ya_esta_sirviendo(): void
    {
        $main = $this->table('M1');
        $other = $this->table('M2');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$other->id}/open")
            ->assertCreated();

        // Mover las líneas en silencio cambiaría el importe de dos cuentas a la
        // vez: se rechaza y quien ya sirvió decide.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$main->id}/merge", ['tables' => [$other->id]])
            ->assertStatus(422);
    }

    public function test_separar_devuelve_las_mesas(): void
    {
        $main = $this->table('M1');
        $other = $this->table('M2');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$main->id}/merge", ['tables' => [$other->id]])
            ->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$main->id}/split")
            ->assertOk();

        $this->assertNull($other->fresh()->merged_into_id);
    }

    public function test_el_mesero_no_configura_el_salon(): void
    {
        // Mover una mesa en el plano no es una operación de turno.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/dining/tables', ['code' => 'M9'])
            ->assertForbidden();
    }

    private function table(string $code): DiningTable
    {
        return DiningTable::create([
            'branch_id' => $this->branch->id,
            'code' => $code,
            'seats' => 4,
            'pos_x' => 0,
            'pos_y' => 0,
        ]);
    }
}
