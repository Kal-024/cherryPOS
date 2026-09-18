<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de barras (B-04).
 *
 * `embedded` declara si el código trae **peso o precio adentro**, que es como
 * funcionan las balanzas de cualquier abarrotería: la etiqueta no identifica una
 * unidad, identifica un producto más cuánto pesó.
 */
class Barcode extends Model
{
    use UsesUuid;

    protected $table = 'cat_barcodes';

    protected $fillable = ['product_id', 'product_uom_id', 'code', 'embedded', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class);
    }
}
