<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sucursal. Una instalación pertenece a un solo negocio (P-05): "tenant" no
 * existe en el vocabulario de cherryPOS.
 */
class Branch extends Model
{
    use UsesUuid;

    protected $table = 'cmn_branches';

    protected $fillable = [
        'code', 'name', 'legal_name', 'tax_id', 'address', 'phone',
        'timezone', 'is_headquarters', 'erp_company_id', 'is_active',
    ];

    protected $casts = [
        'is_headquarters' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
