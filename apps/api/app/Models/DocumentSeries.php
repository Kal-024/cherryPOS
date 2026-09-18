<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/** Serie de numeración por sucursal, tipo y año (B-05, precondición 3). */
class DocumentSeries extends Model
{
    use UsesUuid;

    protected $table = 'pos_document_series';

    protected $fillable = [
        'branch_id', 'document_type', 'year', 'template',
        'next_number', 'range_from', 'range_to', 'is_active',
    ];

    protected $casts = [
        'year' => 'integer',
        'next_number' => 'integer',
        'is_active' => 'boolean',
    ];
}
