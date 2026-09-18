<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Una denominación contada en el arqueo (D-06).
 *
 * Es lo que convierte "falta plata" en "faltan tres billetes de 50", que es la
 * diferencia entre una sospecha y un dato.
 */
class CashCount extends Model
{
    use UsesUuid;

    protected $table = 'pos_cash_counts';

    protected $fillable = [
        'shift_id', 'moment', 'currency_code', 'denomination_id',
        'denomination_value', 'count', 'subtotal',
    ];

    protected $casts = ['count' => 'integer'];
}
