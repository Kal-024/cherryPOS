<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asiento del libro mayor de existencias (B-03).
 *
 * **El stock es la suma de movimientos, no un número editable.** No existe una
 * columna `cantidad_actual` que alguien pueda corregir a mano: un ajuste es un
 * movimiento más, con su empleado, su fecha y su comentario.
 *
 * El modelo no tiene `updated_at` porque la tabla es de solo inserción y un
 * disparador lo impone: `UPDATE` y `DELETE` fallan en la base.
 */
class InventoryMovement extends Model
{
    use UsesUuid;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'inv_movements';

    protected $fillable = [
        'branch_id', 'location_id', 'product_id', 'lot_id', 'reason',
        'qty', 'unit_cost', 'source_type', 'source_id', 'employee_id',
        'comment', 'occurred_at', 'recorded_at',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'recorded_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
