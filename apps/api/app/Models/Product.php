<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ítem del catálogo: productos y servicios en la misma tabla (D-07).
 *
 * `tracks_stock` es toda la diferencia. Un gimnasio vende planes y toallas desde
 * la misma pantalla; sin esa bandera cada rubro de servicios necesitaría su
 * propio modelo.
 */
class Product extends Model
{
    use UsesUuid;

    protected $table = 'cat_products';

    protected $fillable = [
        'sku', 'name', 'description', 'category_id', 'uom_id', 'tax_code_id',
        'price', 'cost', 'is_exempt', 'tracks_stock', 'tracks_lots',
        'allow_negative_stock', 'min_stock', 'is_composite', 'sells_as_pack',
        // Dónde se prepara (F1-B): la cerveza sale de la barra y el lomo de la
        // cocina. `null` = no se prepara y no va a ninguna comanda.
        'prep_station',
        'attributes', 'image_path', 'is_active', 'erp_product_id',
    ];

    protected $casts = [
        'attributes' => 'array',
        'is_exempt' => 'boolean',
        'tracks_stock' => 'boolean',
        'tracks_lots' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'is_composite' => 'boolean',
        'sells_as_pack' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    /** Grupos de modificadores que este producto pregunta al venderse (B-06). */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'cat_product_modifier_group', 'product_id', 'group_id')
            ->withPivot('sort_order')
            ->orderBy('cat_product_modifier_group.sort_order');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }

    public function uoms(): HasMany
    {
        return $this->hasMany(ProductUom::class);
    }

    /** Componentes de un kit, combo o paquete (D-14). */
    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'parent_id');
    }

    /**
     * ¿Se vendió alguna vez?
     *
     * Decide si un producto cargado por error se puede borrar o solo dar de
     * baja: sus líneas de venta son inmutables, y borrarlo dejaría el histórico
     * sin explicación.
     */
    public function saleLinesExist(): bool
    {
        return SaleLine::where('product_id', $this->id)->exists();
    }

    /**
     * Exento por el bien, no por el comprador (Q-07).
     *
     * La bandera propia manda sobre el código de impuesto: un producto marcado
     * exento no traslada IVA aunque su `tax_code` diga otra cosa, que es lo que
     * pasa cuando alguien reutiliza una ficha vieja para un medicamento.
     */
    public function isExempt(): bool
    {
        return $this->is_exempt || ($this->taxCode?->is_exempt ?? false);
    }
}
