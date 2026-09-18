<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada o salida de caja que no es una venta: un retiro parcial, un fondo
 * adicional, el pago de un gasto menor.
 *
 * El motivo es obligatorio. Un retiro sin motivo es exactamente lo que después
 * nadie sabe explicar, y es la fila que aparece cuando el arqueo no cuadra.
 */
class CashMovement extends Model
{
    use UsesUuid;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'pos_cash_movements';

    protected $fillable = [
        'shift_id', 'branch_id', 'employee_id', 'authorized_by', 'direction',
        'reason', 'amount', 'currency_code', 'exchange_rate', 'amount_base',
        'occurred_at', 'recorded_at',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'recorded_at' => 'datetime'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Con signo: entra positivo, sale negativo. El saldo es la suma. */
    public function signedAmount(): string
    {
        return $this->direction === 'in'
            ? (string) $this->amount
            : bcmul((string) $this->amount, '-1', 2);
    }
}
