<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Mesa del salón (F1-B, §10).
 *
 * **No guarda su estado.** Si una mesa está ocupada lo dice la venta suspendida
 * que la referencia, no una columna: dos verdades del mismo hecho se
 * desincronizan el día que una venta se cierre desde otra caja, y entonces el
 * mapa muestra ocupada una mesa vacía.
 *
 * Las mesas unidas apuntan a la que manda con `merged_into_id`. La unida deja de
 * aceptar cuenta propia: el grupo es **una sola unidad** y cobra una sola vez.
 */
class DiningTable extends Model
{
    use UsesUuid;

    protected $table = 'pos_dining_tables';

    protected $fillable = [
        'branch_id', 'area_id', 'code', 'name', 'seats',
        'pos_x', 'pos_y', 'shape', 'merged_into_id', 'is_active',
    ];

    protected $casts = [
        'seats' => 'integer',
        'pos_x' => 'integer',
        'pos_y' => 'integer',
        'is_active' => 'boolean',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(DiningArea::class, 'area_id');
    }

    /** La mesa que manda cuando esta quedó unida a otra. */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function merged(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /**
     * La cuenta abierta: una venta suspendida, no una tabla espejo (D-01).
     *
     * Ordenada en vez de `latestOfMany`: ese ayuda agrega `MAX(id)` como
     * desempate y PostgreSQL no sabe sacarle el máximo a un `uuid`. Por regla del
     * salón una mesa tiene una sola cuenta abierta, así que el orden solo
     * desempata lo que no debería empatar.
     */
    public function openSale(): HasOne
    {
        return $this->hasOne(Sale::class, 'dining_table_id')
            // **Suspendida o en curso.** Mientras el mesero edita la cuenta en la
            // caja la venta pasa a `draft`, y mirar solo las suspendidas dejaba
            // la mesa como libre justo en ese rato: otro mesero podía abrirle una
            // segunda cuenta al mismo grupo.
            ->whereIn('status', [Sale::STATUS_DRAFT, Sale::STATUS_SUSPENDED])
            ->orderByDesc('opened_at');
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }
}
