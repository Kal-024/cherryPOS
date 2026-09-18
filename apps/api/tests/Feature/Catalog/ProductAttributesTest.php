<?php

namespace Tests\Feature\Catalog;

use App\Models\BusinessProfile;
use App\Services\Catalog\ProductAttributeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Atributos dinámicos de producto (B-08, D-24, H1.1).
 *
 * El criterio de aceptación del hito es literal: **una farmacia agrega
 * "principio activo" sin migración**. Eso es lo que la columna JSON compra.
 *
 * Lo que un campo de texto libre no compraría es el resto: que el atributo esté
 * declarado, tenga tipo y se valide. Sin esquema, el JSON se convierte en el
 * basurero donde cada sucursal escribe "vencimiento", "vto" y "fecha_venc" para
 * lo mismo, y después nadie puede consultarlo.
 */
class ProductAttributesTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private ProductAttributeValidator $validator;

    private BusinessProfile $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->validator = app(ProductAttributeValidator::class);

        $this->pharmacy = BusinessProfile::create([
            'code' => 'pharmacy',
            'name' => 'Farmacia',
            'layout_profile' => 'scan_first',
            'modules' => ['sales', 'lots'],
            'attribute_schema' => ['fields' => [
                ['key' => 'active_ingredient', 'label' => 'Principio activo', 'type' => 'string', 'required' => true],
                ['key' => 'concentration', 'label' => 'Concentración', 'type' => 'string'],
                ['key' => 'requires_prescription', 'label' => 'Requiere receta', 'type' => 'boolean'],
                ['key' => 'units_per_box', 'label' => 'Unidades por caja', 'type' => 'integer'],
                ['key' => 'shelf', 'label' => 'Estante', 'type' => 'enum', 'options' => ['A', 'B', 'C']],
            ]],
        ]);
    }

    public function test_una_farmacia_agrega_principio_activo_sin_migracion(): void
    {
        $attributes = $this->validator->validate([
            'active_ingredient' => 'Ibuprofeno',
            'concentration' => '400 mg',
            'requires_prescription' => false,
            'units_per_box' => 20,
        ], $this->pharmacy);

        $product = $this->product('MED-001', '35.00', ['attributes' => $attributes]);

        $this->assertSame('Ibuprofeno', $product->fresh()->attributes['active_ingredient']);
        $this->assertSame(20, $product->fresh()->attributes['units_per_box']);
    }

    public function test_los_atributos_no_declarados_se_rechazan(): void
    {
        $this->expectException(ValidationException::class);

        // Aceptar lo que venga es quedarse sin esquema para siempre: nadie
        // vuelve a limpiar ese JSON.
        $this->validator->validate(['color_de_la_caja' => 'azul'], $this->pharmacy);
    }

    public function test_un_atributo_obligatorio_falta(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate(['concentration' => '400 mg'], $this->pharmacy);
    }

    public function test_el_tipo_se_normaliza_no_se_guarda_como_llego(): void
    {
        $attributes = $this->validator->validate([
            'active_ingredient' => 'Paracetamol',
            'units_per_box' => '30',
            'requires_prescription' => 'true',
        ], $this->pharmacy);

        // La interfaz manda cadenas; lo que queda guardado son tipos.
        $this->assertSame(30, $attributes['units_per_box']);
        $this->assertTrue($attributes['requires_prescription']);
    }

    public function test_un_enum_solo_admite_sus_opciones(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate([
            'active_ingredient' => 'Paracetamol',
            'shelf' => 'Z',
        ], $this->pharmacy);
    }

    public function test_sin_esquema_no_se_aceptan_atributos(): void
    {
        $retail = BusinessProfile::create([
            'code' => 'retail',
            'name' => 'Retail',
            'layout_profile' => 'scan_first',
            'attribute_schema' => ['fields' => []],
        ]);

        $this->assertSame([], $this->validator->validate([], $retail));

        $this->expectException(ValidationException::class);
        $this->validator->validate(['lo_que_sea' => 1], $retail);
    }

    public function test_el_endpoint_de_catalogo_valida_contra_el_perfil(): void
    {
        config(['pos.business_profile' => 'pharmacy']);

        $token = $this->signedIn($this->employee('SUP01', '4321', 'supervisor'), '4321');

        $this->withToken($token)->postJson('/api/catalog/products', [
            'sku' => 'MED-002',
            'name' => 'Amoxicilina 500 mg',
            'uom_id' => $this->unit->id,
            'price' => '120.00',
            'attributes' => ['principio' => 'Amoxicilina'],
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/catalog/products', [
            'sku' => 'MED-002',
            'name' => 'Amoxicilina 500 mg',
            'uom_id' => $this->unit->id,
            'price' => '120.00',
            'attributes' => ['active_ingredient' => 'Amoxicilina'],
        ])->assertCreated();
    }
}
