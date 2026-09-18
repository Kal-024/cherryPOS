<?php

namespace Tests\Feature\Catalog;

use App\Models\ProductUom;
use App\Models\Uom;
use App\Services\Catalog\UomConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Unidades de medida y conversión (G-08, H1.2).
 *
 * Criterio de aceptación del hito, literal: **comprar por caja de 100 y vender
 * por unidad**. Es el caso normal en ferretería y en farmacia, no la excepción.
 */
class UomConversionTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private UomConversionService $uoms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->uoms = app(UomConversionService::class);
    }

    public function test_se_compra_por_caja_de_cien_y_se_vende_por_unidad(): void
    {
        $product = $this->product('P-100', '2.50');
        $box = Uom::create(['code' => 'CAJ', 'name' => 'Caja', 'decimals' => 0]);

        $presentation = ProductUom::create([
            'product_id' => $product->id,
            'uom_id' => $box->id,
            'conv_num' => 100,
            'conv_den' => 1,
            'is_purchase_default' => true,
        ]);

        // Tres cajas son trescientas unidades.
        $this->assertSame('300.0000', $this->uoms->toBase($presentation, '3'));
        // Y doscientas cincuenta unidades son dos cajas y media.
        $this->assertSame('2.5000', $this->uoms->fromBase($presentation, '250'));
        // La caja cuesta cien veces la unidad mientras nadie fije otro precio.
        $this->assertSame('250.0000', $this->uoms->priceFor($product, $presentation));
    }

    public function test_una_presentacion_fraccionaria_convierte_al_reves(): void
    {
        $product = $this->product('P-200', '400.00');
        $half = Uom::create(['code' => 'MED', 'name' => 'Media', 'decimals' => 2]);

        // Media docena: 1 presentación = 6/12 de la unidad base.
        $presentation = ProductUom::create([
            'product_id' => $product->id,
            'uom_id' => $half->id,
            'conv_num' => 6,
            'conv_den' => 12,
        ]);

        $this->assertSame('1.0000', $this->uoms->toBase($presentation, '2'));
        $this->assertSame('200.0000', $this->uoms->priceFor($product, $presentation));
    }

    public function test_una_unidad_sin_decimales_no_admite_media_unidad(): void
    {
        // "2,5 metros" sí; "2,5 unidades" no. Sin esto el kardex termina con
        // media caja registradora en existencia.
        $this->uoms->assertQtyAllowed('2.50', 2);

        $this->expectException(ValidationException::class);
        $this->uoms->assertQtyAllowed('2.5', 0);
    }

    public function test_los_ceros_a_la_derecha_no_cuentan_como_decimales(): void
    {
        // "3,00 unidades" son tres unidades: rechazarlo sería un fallo de la
        // interfaz, no del cajero.
        $this->uoms->assertQtyAllowed('3.00', 0);
        $this->assertTrue(true);
    }
}
