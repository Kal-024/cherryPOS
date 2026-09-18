<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presentación alternativa de un producto: `1 caja = 100 unidades`.
 *
 * Mismo modelo de conversión que `ProductUom` de cherryB (Q-02):
 * `1 uom = (conv_num / conv_den)` unidades base, con la fila de la unidad base
 * existiendo siempre con 1/1.
 */
class ProductUom extends Model
{
    use UsesUuid;

    protected $table = 'cat_product_uoms';

    protected $fillable = [
        'product_id', 'uom_id', 'conv_num', 'conv_den',
        'is_sales_default', 'is_purchase_default', 'is_active',
    ];

    protected $casts = [
        'conv_num' => 'integer',
        'conv_den' => 'integer',
        'is_sales_default' => 'boolean',
        'is_purchase_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
