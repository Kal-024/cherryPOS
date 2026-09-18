<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento de la cuenta corriente. Solo inserción, como todo lo que toca
 * dinero: un consumo mal cargado se corrige con un ajuste, no editándolo.
 */
class CreditEntry extends Model
{
    use UsesUuid;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'crm_credit_entries';

    protected $fillable = [
        'account_id', 'branch_id', 'kind', 'amount', 'sale_id',
        'authorized_id', 'employee_id', 'comment', 'occurred_at', 'recorded_at',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
}
