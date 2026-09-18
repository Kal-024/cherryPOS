<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Componente de un kit, combo o paquete (D-14). */
class ProductComponent extends Model
{
    use UsesUuid;

    protected $table = 'cat_product_components';

    protected $fillable = [
        'parent_id', 'component_id', 'qty',
        'deducts_component_stock', 'print_expanded', 'sort_order',
    ];

    protected $casts = [
        'deducts_component_stock' => 'boolean',
        'print_expanded' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_id');
    }
}
