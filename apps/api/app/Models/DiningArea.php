<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Zona del salón: terraza, salón principal, barra (F1-B). */
class DiningArea extends Model
{
    use UsesUuid;

    protected $table = 'pos_dining_areas';

    protected $fillable = ['branch_id', 'code', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class, 'area_id');
    }
}
