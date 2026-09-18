<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lote con vencimiento (G-07). La salida por defecto es primero-en-vencer. */
class Lot extends Model
{
    use UsesUuid;

    protected $table = 'inv_lots';

    protected $fillable = ['product_id', 'code', 'expires_on', 'manufactured_on'];

    protected $casts = ['expires_on' => 'date', 'manufactured_on' => 'date'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }
}
