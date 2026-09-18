<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta de crédito del cliente, en su versión reducida del POS (P-02).
 *
 * Límite, saldo, consumo, pago en caja. Intereses, planes de pago y cobranza son
 * del módulo de crédito del ERP. Si el cliente solo tiene el POS, esto le
 * alcanza para operar.
 */
class CreditAccount extends Model
{
    use UsesUuid;

    protected $table = 'crm_credit_accounts';

    protected $fillable = [
        'customer_id', 'credit_limit', 'balance', 'cut_off_day',
        'is_blocked', 'blocked_reason', 'blocked_by', 'blocked_at',
    ];

    protected $casts = [
        'is_blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'cut_off_day' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CreditEntry::class, 'account_id');
    }

    /** Crédito disponible. Nunca negativo: si está pasado, es cero. */
    public function available(): string
    {
        $available = bcsub((string) $this->credit_limit, (string) $this->balance, 2);

        return bccomp($available, '0', 2) > 0 ? $available : '0.00';
    }

    /**
     * El límite **bloquea la venta**, no solo advierte (Q-10).
     *
     * El bloqueo manual del supervisor es otra cosa y se suma a este: una cuenta
     * puede estar dentro del límite y aun así bloqueada por mora.
     */
    public function canCharge(string $amount): bool
    {
        if ($this->is_blocked) {
            return false;
        }

        return bccomp($amount, $this->available(), 2) <= 0;
    }
}
