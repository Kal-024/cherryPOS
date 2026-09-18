<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Saldo cacheado por producto, ubicación y lote.
 *
 * **No es la fuente de verdad** — lo es `inv_movements`. Existe porque sumar el
 * libro entero en cada tecla del buscador no escala. Que sea derivable es
 * justamente lo que lo hace seguro: ante la duda se recalcula.
 */
class StockBalance extends Model
{
    use UsesUuid;

    protected $table = 'inv_stock_balances';

    protected $fillable = ['location_id', 'product_id', 'lot_id', 'qty', 'recalculated_at'];

    protected $casts = ['recalculated_at' => 'datetime'];
}
