<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ubicación de existencias. Multi-almacén desde el día uno (B-07). */
class Location extends Model
{
    use UsesUuid;

    protected $table = 'inv_locations';

    protected $fillable = ['branch_id', 'code', 'name', 'is_sales_default', 'is_active'];

    protected $casts = ['is_sales_default' => 'boolean', 'is_active' => 'boolean'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
