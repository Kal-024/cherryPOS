<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\Catalog\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * La lista 86: lo que se acabó hoy (F1-B).
 *
 * Del argot de cocina —*«86 the salmon»*— y es el mecanismo estándar de todo POS
 * de restaurante. Lo que estas pruebas fijan son las tres propiedades que lo
 * distinguen de dar de baja el producto, que era la única herramienta que había:
 *
 *  - **No toca el catálogo.** El producto sigue activo, con su precio y su
 *    histórico; solo no se puede vender hoy.
 *  - **El candado está en el servidor.** La pantalla atenúa, pero el lector de
 *    códigos, el modo degradado y dos meseros a la vez la esquivan.
 *  - **Se repone sola.** Si hubiera que acordarse de reponerla, el día que
 *    alguien se olvide el plato desaparece del menú sin que nadie sepa por qué.
 */
class AvailabilityTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        // Marcar agotado es de quien lleva el local: tiene permiso propio,
        // separado de la edición del catálogo.
        //
        // Una sola sesión de operador por terminal (D-05): entrar con otro PIN
        // cierra la anterior, así que el cajero se identifica **dentro** de la
        // prueba que lo necesita y no acá.
        $supervisor = $this->employee('SUP01', '4321', 'supervisor', '9876');

        $this->token = $this->signedIn($supervisor, '4321');
    }

    private function stocked(string $sku = 'P-001'): Product
    {
        return $this->product($sku, '100.00', ['allow_negative_stock' => true]);
    }

    private function markUnavailable(Product $product): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/catalog/products/{$product->id}/unavailable")
            ->assertOk();
    }

    /** Abre una venta y trata de agregarle el producto. */
    private function tryToSell(Product $product, ?string $token = null)
    {
        $token ??= $this->token;

        $sale = $this->actingAsTerminal($token)
            ->postJson('/api/sales', [])->assertCreated()->json('data.id');

        return $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ]);
    }

    public function test_marcar_agotado_no_da_de_baja_el_producto(): void
    {
        $product = $this->stocked();

        $this->markUnavailable($product);

        $product->refresh();

        // Desactivarlo lo sacaría de reportes e importaciones, y alguien tendría
        // que acordarse de reactivarlo.
        $this->assertTrue($product->is_active);
        $this->assertSame('100.0000', (string) $product->price);
        $this->assertTrue($product->isUnavailable());
    }

    public function test_lo_agotado_no_se_puede_vender(): void
    {
        $product = $this->stocked();

        $this->markUnavailable($product);

        $this->tryToSell($product)
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.product_id.0',
                __('catalog.product_unavailable', ['name' => $product->name])
            );
    }

    public function test_el_lector_tampoco_lo_vende(): void
    {
        $product = $this->stocked();

        DB::table('cat_barcodes')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $product->id,
            'code' => '7501234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->markUnavailable($product);

        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->json('data.id');

        // El código de barras es justo el camino que esquiva la pantalla donde
        // el producto sale atenuado.
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'scan', 'code' => '7501234567890',
        ])->assertStatus(422);
    }

    public function test_una_devolucion_no_se_bloquea_por_estar_agotado(): void
    {
        $product = $this->stocked();

        // Se vende y se cierra el ticket original.
        $sale = $this->actingAsTerminal($this->token)
            ->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash', 'amount' => '100.00',
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        $this->markUnavailable($product);

        $refund = $this->actingAsTerminal($this->token)->postJson('/api/sales', [
            'sale_type' => 'refund', 'reverses_sale_id' => $sale,
        ])->assertCreated()->json('data.id');

        // Que el plato se haya acabado después no tiene nada que ver con
        // devolverle la plata al cliente que lo compró esta mañana.
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$refund}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '-1',
        ])->assertCreated();
    }

    public function test_la_lista_viaja_como_identificadores(): void
    {
        $product = $this->stocked();
        $this->markUnavailable($product);

        // El terminal la sondea: bajar el catálogo entero para saber que se
        // acabó el pescado sería absurdo (D-04).
        $ids = $this->actingAsTerminal($this->token)
            ->getJson('/api/catalog/unavailable')->assertOk()->json('data');

        $this->assertSame([$product->id], $ids);
    }

    public function test_la_lista_dice_quien_marco_y_cuando(): void
    {
        $product = $this->stocked();
        $this->markUnavailable($product);

        $rows = $this->actingAsTerminal($this->token)
            ->getJson('/api/catalog/unavailable?detailed=1')->assertOk()->json('data');

        // En un reclamo —"me dijeron que no había y sí había"— es lo único que
        // dice a quién preguntarle.
        $this->assertSame('Empleado SUP01', $rows[0]['unavailable_by']);
        $this->assertNotNull($rows[0]['unavailable_since']);
    }

    public function test_volver_a_haber_lo_devuelve_a_la_venta(): void
    {
        $product = $this->stocked();
        $this->markUnavailable($product);

        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/catalog/products/{$product->id}/unavailable")
            ->assertOk();

        $this->tryToSell($product)->assertCreated();
    }

    public function test_marcar_dos_veces_no_es_un_error(): void
    {
        $product = $this->stocked();

        $this->markUnavailable($product);

        // Dos cocineros diciendo lo mismo no es un error: fallar ahí sería pedir
        // que alguien revise la pantalla antes de avisar que se acabó algo.
        $this->markUnavailable($product);

        $this->assertSame([$product->id], app(AvailabilityService::class)->unavailableIds());
    }

    public function test_el_cajero_no_marca_el_menu(): void
    {
        $product = $this->stocked();

        $cashier = $this->signedIn();

        // Marcar agotado le cuesta ventas al local y afecta a todas las
        // terminales: no es una decisión de caja.
        $this->actingAsTerminal($cashier)
            ->postJson("/api/catalog/products/{$product->id}/unavailable")
            ->assertStatus(403);
    }

    public function test_al_abrir_el_primer_turno_vuelve_todo(): void
    {
        $product = $this->stocked();
        $this->markUnavailable($product);

        // Se cierra el turno que `bootPosWorld` dejó abierto y se abre otro: es
        // el servicio siguiente, y lo que se acabó ayer vuelve a estar hoy.
        $this->actingAsTerminal($this->token)->postJson('/api/shifts/close', [
            'counts' => [['denomination_value' => '1000.00', 'count' => 1]],
        ])->assertOk();

        $this->actingAsTerminal($this->token)->postJson('/api/shifts', [
            'opening_float' => '1000.00',
        ])->assertCreated();

        $this->assertFalse($product->fresh()->isUnavailable());
    }

    public function test_abrir_una_segunda_caja_no_revive_el_menu(): void
    {
        $product = $this->stocked();
        $this->markUnavailable($product);

        // Otra caja del local, abierta desde temprano.
        $otra = Terminal::create([
            'branch_id' => $this->branch->id,
            'code' => 'CAJA-02',
            'name' => 'Caja 2',
            'secret_hash' => bcrypt('otro-secreto'),
            'layout_profile' => 'scan_first',
        ]);

        Shift::create([
            'branch_id' => $this->branch->id,
            'terminal_id' => $otra->id,
            'opened_by' => $this->cashier->id,
            'code' => 'T-OTRA',
            'status' => 'open',
            'opening_float' => '500.00',
            'opened_at' => now(),
        ]);

        // Con tres cajas, la segunda en abrir a media mañana reviviría todo lo
        // que la cocina marcó temprano. La reposición es del servicio, no de la
        // caja.
        $this->actingAsTerminal($this->token)->postJson('/api/shifts/close', [
            'counts' => [['denomination_value' => '1000.00', 'count' => 1]],
        ])->assertOk();

        $this->actingAsTerminal($this->token)->postJson('/api/shifts', [
            'opening_float' => '1000.00',
        ])->assertCreated();

        $this->assertTrue($product->fresh()->isUnavailable());
    }
}
