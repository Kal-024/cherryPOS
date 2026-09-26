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

    public function test_unir_un_grupo_a_otra_mesa_forma_un_solo_grupo(): void
    {
        $tres = $this->table('M3');
        $cuatro = $this->table('M4');
        $dos = $this->table('M2');

        // La 4 se une a la 3, y después la 3 —que manda— se arrastra sobre la 2.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$tres->id}/merge", ['tables' => [$cuatro->id]])
            ->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$dos->id}/merge", ['tables' => [$tres->id]])
            ->assertOk();

        // Las tres son una sola unidad. Antes quedaba la cadena 4 → 3 → 2, y como
        // todo el salón mira un solo salto, la 4 se quedaba fuera del grupo y sin
        // forma de soltarse.
        $this->assertSame($dos->id, $tres->fresh()->merged_into_id);
        $this->assertSame($dos->id, $cuatro->fresh()->merged_into_id);
    }

    public function test_separar_una_mesa_no_deshace_el_grupo(): void
    {
        $main = $this->table('M1');
        $dos = $this->table('M2');
        $tres = $this->table('M3');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$main->id}/merge", ['tables' => [$dos->id, $tres->id]])
            ->assertOk();

        // Se suelta la 2 y nada más: con tres mesas empujadas contra una, devolver
        // una a su sitio no debería obligar a rearmar el resto a mano.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$dos->id}/split")
            ->assertOk();

        $this->assertNull($dos->fresh()->merged_into_id);
        $this->assertSame($main->id, $tres->fresh()->merged_into_id);
    }

    public function test_la_mesa_soltada_vuelve_a_aceptar_cuenta(): void
    {
        $main = $this->table('M1');
        $other = $this->table('M2');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$main->id}/merge", ['tables' => [$other->id]])
            ->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$other->id}/split")
            ->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$other->id}/open")
            ->assertCreated();
    }

    public function test_la_nota_se_guarda_sobre_la_cuenta_abierta(): void
    {
        $table = $this->table('M1');

        $sale = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->assertCreated()->json('data.sale.id');

        $this->actingAsTerminal($this->token)->putJson("/api/dining/tables/{$table->id}/note", [
            'notes' => 'Cumpleaños, traer el postre con vela',
        ])->assertOk();

        $this->assertSame(
            'Cumpleaños, traer el postre con vela',
            Sale::find($sale)->notes
        );
    }

    public function test_la_nota_viaja_con_el_mapa(): void
    {
        $table = $this->table('M1');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")->assertCreated();

        $this->actingAsTerminal($this->token)->putJson("/api/dining/tables/{$table->id}/note", [
            'notes' => 'Apurados',
        ])->assertOk();

        // Una nota que nadie ve no sirve de nada: la ficha del salón la marca, y
        // el mapa llega de una sola consulta por sondeo (G-13).
        $map = $this->actingAsTerminal($this->token)->getJson('/api/dining/map')->assertOk();

        $this->assertSame('Apurados', $map->json('data.tables.0.sale.notes'));
    }

    public function test_vaciar_la_nota_la_borra(): void
    {
        $table = $this->table('M1');

        $sale = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->assertCreated()->json('data.sale.id');

        $this->actingAsTerminal($this->token)
            ->putJson("/api/dining/tables/{$table->id}/note", ['notes' => 'Algo'])->assertOk();

        // Sin esto haría falta un segundo botón para borrar, y con el cliente
        // esperando nadie lo busca.
        $this->actingAsTerminal($this->token)
            ->putJson("/api/dining/tables/{$table->id}/note", ['notes' => '  '])->assertOk();

        $this->assertNull(Sale::find($sale)->notes);
    }

    public function test_sin_cuenta_abierta_no_hay_donde_anotar(): void
    {
        $table = $this->table('M1');

        // Guardar la nota en la mesa para cubrir este caso la haría sobrevivir al
        // cliente, y entonces alguien tendría que acordarse de borrarla.
        $this->actingAsTerminal($this->token)
            ->putJson("/api/dining/tables/{$table->id}/note", ['notes' => 'Mesa coja'])
            ->assertStatus(422)
            ->assertJsonPath('errors.table.0', __('dining.note_needs_tab'));
    }

    public function test_la_nota_se_va_con_la_cuenta_cobrada(): void
    {
        $table = $this->table('M1');

        $sale = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->assertCreated()->json('data.sale.id');

        $this->actingAsTerminal($this->token)
            ->putJson("/api/dining/tables/{$table->id}/note", ['notes' => 'Cumpleaños'])->assertOk();

        // Se cobra de verdad: una cuenta vacía no cierra, y sin cierre la prueba
        // no probaría nada.
        $product = $this->product('P-NOTA', '100.00', ['allow_negative_stock' => true]);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '100.00',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        // Cobrada la cuenta, la mesa vuelve libre y no hay nota que arrastrar: la
        // vida útil de la nota es la del servicio, no la de la mesa.
        $this->actingAsTerminal($this->token)
            ->putJson("/api/dining/tables/{$table->id}/note", ['notes' => 'Otra cosa'])
            ->assertStatus(422);
    }

    public function test_la_mesa_lleva_su_forma_y_su_tamano(): void
    {
        $table = $this->table('M1');

        // El plano lo configura quien lleva el local (`dining.manage`), no el
        // mesero: una sola sesión de operador por terminal (D-05), así que se
        // entra con el suyo para esta prueba.
        $token = $this->signedIn($this->employee('SUP01', '4321', 'supervisor', '9876'), '4321');

        // `rect` existía en la base desde el primer día y la pantalla lo dibujaba
        // igual que el cuadrado: sin ancho y alto propios no hay forma de que uno
        // se vea distinto del otro.
        $this->actingAsTerminal($token)->putJson("/api/dining/tables/{$table->id}", [
            'shape' => 'rect',
            'width' => 240,
            'height' => 120,
        ])->assertOk();

        $map = $this->actingAsTerminal($token)->getJson('/api/dining/map')->assertOk();

        $this->assertSame('rect', $map->json('data.tables.0.shape'));
        $this->assertSame(240, $map->json('data.tables.0.width'));
        $this->assertSame(120, $map->json('data.tables.0.height'));
    }

    public function test_una_mesa_no_puede_medir_cualquier_cosa(): void
    {
        $table = $this->table('M1');

        $token = $this->signedIn($this->employee('SUP01', '4321', 'supervisor', '9876'), '4321');

        // Fuera del rango no es una mesa: es un dedo que resbaló arrastrando la
        // esquina, y una mesa de mil píxeles tapa el plano entero.
        $this->actingAsTerminal($token)
            ->putJson("/api/dining/tables/{$table->id}", ['width' => 5000])
            ->assertStatus(422);

        $this->actingAsTerminal($token)
            ->putJson("/api/dining/tables/{$table->id}", ['height' => 10])
            ->assertStatus(422);
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
