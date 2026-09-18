<?php

namespace App\Models;

use App\Models\Concerns\HasPerson;
use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Proveedor (B-12).
 *
 * `kind` separa al que vende mercadería del que vende gastos. Sin esa
 * separación, el reporte de compras incluye la factura del agua y el margen
 * deja de significar nada.
 */
class Supplier extends Model
{
    use HasPerson, UsesUuid;

    public const MERCHANDISE = 'merchandise';

    public const EXPENSE = 'expense';

    protected $table = 'crm_suppliers';

    protected $fillable = [
        'person_id', 'code', 'kind', 'credit_days',
        'contact_name', 'is_active', 'erp_supplier_id',
    ];

    protected $casts = [
        'credit_days' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['name'];
}
