<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modificador elegido en una línea concreta (B-06).
 *
 * Guarda **copia** del nombre y del precio: la línea de venta es inmutable una
 * vez cerrada (P1) y el catálogo no lo es. Referenciar el catálogo haría que un
 * ticket de hace tres meses cambiara de importe al corregir un precio.
 */
class SaleLineModifier extends Model
{
    use UsesUuid;

    protected $table = 'pos_sale_line_modifiers';

    protected $fillable = ['sale_line_id', 'modifier_id', 'name', 'price_delta'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class, 'sale_line_id');
    }
}
