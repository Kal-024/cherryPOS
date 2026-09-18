<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Línea de venta.
 *
 * `kind` distingue el producto del catálogo de las dos válvulas de escape
 * (D-03): el **ítem temporal** —algo que no está cargado— y la **venta por
 * monto** —"cobro 500 de mano de obra"—. Ambas llevan límite diario y aviso al
 * supervisor, porque son también el agujero por donde se escapa el control de
 * inventario.
 *
 * Los impuestos se persisten en `pos_sale_line_taxes` y **nunca se recalculan**
 * (D-16): si la tasa cambia el año que viene, las ventas viejas siguen cuadrando.
 */
class SaleLine extends Model
{
    use UsesUuid;

    public const KIND_PRODUCT = 'product';

    public const KIND_TEMPORARY = 'temporary';

    public const KIND_AMOUNT = 'amount';

    protected $table = 'pos_sale_lines';

    protected $fillable = [
        'sale_id', 'branch_id', 'sequence', 'product_id', 'item_code',
        'description', 'kind', 'uom_id', 'qty', 'unit_price', 'unit_cost',
        'discount_type', 'discount_value', 'line_discount', 'sale_discount_share',
        'gross', 'taxable_base', 'tax_total', 'total', 'is_exempt',
        'lot_id', 'location_id', 'group_ref', 'group_name',
        // "Sin sal", "término medio": lo que no entra en ningún modificador y
        // cocina igual necesita leer (F1-B).
        'notes',
        // Curso: entrada, fuerte, postre (G-16). Es de la línea porque el pedido
        // se toma entero de una vez y lo que cambia es cuándo sale cada cosa.
        'course',
    ];

    protected $casts = ['is_exempt' => 'boolean', 'sequence' => 'integer', 'course' => 'integer'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(SaleLineTax::class, 'sale_line_id');
    }

    /**
     * Lo que se eligió al pedir el plato (B-06).
     *
     * Copiado, no referenciado: si mañana sube el "doble queso", el ticket de
     * ayer tiene que seguir explicándose solo (P1).
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(SaleLineModifier::class, 'sale_line_id');
    }

    /** Las válvulas de escape del catálogo, que llevan control aparte (D-03). */
    public function isSpecial(): bool
    {
        return in_array($this->kind, [self::KIND_TEMPORARY, self::KIND_AMOUNT], true);
    }
}
