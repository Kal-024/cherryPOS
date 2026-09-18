<?php

namespace App\Models;

use App\Models\Concerns\HasPerson;
use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cliente. Dos clases, y la distinción es toda la tabla (P-03).
 *
 * El de **efectivo** paga y se va: no se le pide cédula, por razones obvias. El
 * de **cuenta** apertura un crédito, da su cédula al pagar y cancela a fin de
 * mes: ahí la cédula es obligatoria y es además su código de cliente.
 *
 * La venta anónima —sin cliente asociado— queda permitida siempre: nadie pide
 * cédula para vender una gaseosa.
 */
class Customer extends Model
{
    use HasPerson, UsesUuid;

    protected $table = 'crm_customers';

    protected $fillable = [
        'person_id', 'kind', 'code', 'is_tax_exempt', 'tax_exempt_reference',
        'discount_percent', 'consent_email', 'consent_whatsapp',
        'consent_given_at', 'is_active', 'erp_customer_id',
    ];

    protected $appends = ['name'];

    protected $casts = [
        'is_tax_exempt' => 'boolean',
        'consent_email' => 'boolean',
        'consent_whatsapp' => 'boolean',
        'consent_given_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function creditAccount(): HasOne
    {
        return $this->hasOne(CreditAccount::class);
    }

    public function authorized(): HasMany
    {
        return $this->hasMany(CustomerAuthorized::class);
    }

    public function hasAccount(): bool
    {
        return $this->kind === 'account';
    }
}
