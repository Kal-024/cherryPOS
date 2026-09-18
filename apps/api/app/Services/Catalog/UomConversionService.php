<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductUom;
use App\Services\Calc\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Conversión entre presentaciones de un producto (G-08, H1.2).
 *
 * `1 uom = (conv_num / conv_den)` unidades base, mismo modelo que `ProductUom`
 * de cherryB (Q-02). El criterio de aceptación del hito es literal: **comprar
 * por caja de 100 y vender por unidad**.
 *
 * Necesario en ferretería y también en farmacia — vender unidades sueltas de una
 * caja o de un bote es el caso normal, no la excepción.
 *
 * Usa `Decimal` y no coma flotante por la misma razón que el motor de cálculo:
 * convertir 3 cajas de 7 unidades con `float` y volver atrás no siempre devuelve
 * 3, y esa diferencia termina en el kardex.
 */
class UomConversionService
{
    /** Cantidad expresada en `$productUom`, llevada a unidades base. */
    public function toBase(ProductUom $productUom, string $qty): string
    {
        $this->assertUsable($productUom);

        $scaled = Decimal::parse($qty, Decimal::QTY);
        $converted = Decimal::divRoundHalfUp(
            bcmul($scaled, (string) $productUom->conv_num, 0),
            (string) $productUom->conv_den
        );

        return Decimal::format($converted, Decimal::QTY);
    }

    /** Cantidad en unidades base, expresada en `$productUom`. */
    public function fromBase(ProductUom $productUom, string $qty): string
    {
        $this->assertUsable($productUom);

        $scaled = Decimal::parse($qty, Decimal::QTY);
        $converted = Decimal::divRoundHalfUp(
            bcmul($scaled, (string) $productUom->conv_den, 0),
            (string) $productUom->conv_num
        );

        return Decimal::format($converted, Decimal::QTY);
    }

    /**
     * Precio unitario de una presentación, derivado del precio base.
     *
     * Una caja de 100 cuesta 100 veces la unidad mientras nadie fije otro
     * precio. El precio propio de la presentación, cuando exista, gana sobre
     * este cálculo — vender la caja más barata que la suma de sus unidades es
     * justamente el sentido de vender cajas.
     */
    public function priceFor(Product $product, ProductUom $productUom): string
    {
        $this->assertUsable($productUom);

        $base = Decimal::parse((string) $product->price, Decimal::PRICE);
        $price = Decimal::divRoundHalfUp(
            bcmul($base, (string) $productUom->conv_num, 0),
            (string) $productUom->conv_den
        );

        return Decimal::format($price, Decimal::PRICE);
    }

    /**
     * ¿Admite esta unidad una cantidad fraccionada?
     *
     * "2,5 metros" sí, "2,5 unidades" no. Sin esta comprobación el kardex
     * termina con media caja registradora en existencia.
     */
    public function assertQtyAllowed(string $qty, int $decimals): void
    {
        $fraction = explode('.', ltrim($qty, '-'))[1] ?? '';
        $significant = rtrim($fraction, '0');

        if (strlen($significant) > $decimals) {
            throw ValidationException::withMessages([
                'qty' => __('catalog.qty_decimals_not_allowed', ['decimals' => $decimals]),
            ]);
        }
    }

    private function assertUsable(ProductUom $productUom): void
    {
        if ($productUom->conv_num < 1 || $productUom->conv_den < 1) {
            throw ValidationException::withMessages([
                'uom' => __('catalog.uom_conversion_invalid'),
            ]);
        }
    }
}
