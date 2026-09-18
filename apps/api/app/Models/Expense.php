<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gasto (B-14).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad."*
 *
 * `tax_amount` va aparte del monto porque el IVA de un gasto es **crédito
 * fiscal, no costo**: sumarlo al gasto infla el costo y desinfla el crédito, y
 * las dos cifras quedan mal.
 *
 * Un gasto pagado del cajón lleva `cash_movement_id`: el arqueo tiene que poder
 * explicar esa plata que ya no está.
 */
class Expense extends Model
{
    use UsesUuid;

    protected $table = 'exp_expenses';

    protected $fillable = [
        'branch_id', 'category_id', 'supplier_id', 'employee_id',
        'shift_id', 'cash_movement_id', 'document_number', 'document_date',
        'description', 'currency_code', 'exchange_rate', 'amount', 'tax_amount',
        'total', 'total_base', 'payment_method', 'status', 'void_reason',
        'voided_by', 'occurred_at', 'recorded_at',
    ];

    protected $casts = [
        'document_date' => 'date',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
