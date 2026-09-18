<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago, en tabla aparte del encabezado (B-02).
 *
 * Guarda **moneda recibida, tasa aplicada y equivalente en moneda base** (Q-06).
 * Sin la tasa persistida, releer un ticket de hace un mes daría otro número.
 */
class Payment extends Model
{
    use UsesUuid;

    protected $table = 'pos_payments';

    protected $fillable = [
        'sale_id', 'branch_id', 'shift_id', 'method', 'currency_code',
        'amount', 'exchange_rate', 'amount_base', 'is_change',
        'reference', 'card_brand', 'authorization_code', 'paid_at',
    ];

    protected $casts = ['is_change' => 'boolean', 'paid_at' => 'datetime'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
