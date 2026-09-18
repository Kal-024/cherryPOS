<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opción de un grupo de modificadores (B-06).
 *
 * `price_delta` va con signo y **con el impuesto adentro**, igual que el precio
 * de catálogo: se suma al precio de góndola antes de calcular, así que mezclar
 * criterios haría que "doble queso" cobrara distinto que el plato.
 */
class Modifier extends Model
{
    use UsesUuid;

    protected $table = 'cat_modifiers';

    protected $fillable = [
        'group_id', 'code', 'name', 'price_delta', 'is_default', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'group_id');
    }
}
