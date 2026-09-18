<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Referencias del catálogo: categorías, unidades e impuestos.
 *
 * Existen para que el formulario de producto **ofrezca opciones** en lugar de
 * pedir un UUID escrito a mano. Lo que estas pruebas fijan no es el listado
 * sino sus bordes: quién puede escribir, qué pasa cuando no hay nada cargado y
 * que dar de baja no borre.
 */
class CatalogReferencesTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
    }

    public function test_el_cajero_lee_unidades_e_impuestos(): void
    {
        $token = $this->signedIn();

        $uoms = $this->actingAsTerminal($token)->getJson('/api/catalog/uoms');
        $uoms->assertOk()->assertJsonPath('data.0.code', 'UND');

        $taxes = $this->actingAsTerminal($token)->getJson('/api/catalog/tax-codes');
        $taxes->assertOk()->assertJsonPath('data.0.code', 'IVA');
    }

    /**
     * Sin categorías cargadas la respuesta es 404, no una lista vacía.
     *
     * Es el mismo contrato que `cherryF` y el que el cliente HTTP del terminal
     * ya traduce a "no hay nada": romperlo aquí obligaría a la pantalla a
     * distinguir dos formas de vacío.
     */
    public function test_sin_categorias_la_respuesta_es_404(): void
    {
        $token = $this->signedIn();

        $this->actingAsTerminal($token)
            ->getJson('/api/catalog/categories')
            ->assertNotFound();
    }

    public function test_el_supervisor_crea_una_categoria_y_el_cajero_la_ve(): void
    {
        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        $this->actingAsTerminal($token)
            ->postJson('/api/catalog/categories', [
                'code' => 'BEB',
                'name' => 'Bebidas',
                'color' => '#22C55E',
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bebidas');

        $this->actingAsTerminal($token)
            ->getJson('/api/catalog/categories')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'BEB');
    }

    public function test_el_cajero_no_puede_crear_categorias(): void
    {
        $token = $this->signedIn();

        // El cajero lee el catálogo para vender; mantenerlo es del supervisor
        // (Q-03 aplicado al catálogo: crear es trastienda).
        $this->actingAsTerminal($token)
            ->postJson('/api/catalog/categories', ['code' => 'BEB', 'name' => 'Bebidas'])
            ->assertForbidden();
    }

    public function test_el_codigo_de_categoria_no_se_repite(): void
    {
        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        Category::create(['code' => 'BEB', 'name' => 'Bebidas']);

        $this->actingAsTerminal($token)
            ->postJson('/api/catalog/categories', ['code' => 'BEB', 'name' => 'Otras bebidas'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', fn ($message) => is_string($message));
    }

    public function test_el_color_tiene_que_ser_hexadecimal(): void
    {
        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        // El terminal pinta la pestaña con este valor tal cual: aceptar "verde"
        // dejaría la cuadrícula sin color y sin explicación.
        $this->actingAsTerminal($token)
            ->postJson('/api/catalog/categories', [
                'code' => 'BEB',
                'name' => 'Bebidas',
                'color' => 'verde',
            ])
            ->assertStatus(422);
    }

    public function test_una_categoria_no_puede_ser_su_propia_madre(): void
    {
        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        $category = Category::create(['code' => 'BEB', 'name' => 'Bebidas']);

        $this->actingAsTerminal($token)
            ->putJson("/api/catalog/categories/{$category->id}", ['parent_id' => $category->id])
            ->assertStatus(422);
    }

    /**
     * Dar de baja no borra.
     *
     * Las líneas de venta ya emitidas la referencian y son inmutables (P1):
     * borrarla dejaría el histórico sin explicación.
     */
    public function test_dar_de_baja_una_categoria_la_desactiva_sin_borrarla(): void
    {
        $admin = $this->employee('ADM01', '2222', 'admin');
        $token = $this->signedIn($admin, '2222');

        $category = Category::create(['code' => 'BEB', 'name' => 'Bebidas']);

        $this->actingAsTerminal($token)
            ->deleteJson("/api/catalog/categories/{$category->id}")
            ->assertOk();

        $this->assertFalse($category->fresh()->is_active);

        // Ya no aparece en el listado normal, pero sigue existiendo para quien
        // la pida explícitamente.
        $this->actingAsTerminal($token)
            ->getJson('/api/catalog/categories')
            ->assertNotFound();

        $this->actingAsTerminal($token)
            ->getJson('/api/catalog/categories?include_inactive=1')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'BEB');
    }
}
