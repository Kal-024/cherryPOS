<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una fila del archivo, con lo que traía, lo que se entendió y lo que falló. */
class ImportRow extends Model
{
    use UsesUuid;

    protected $table = 'imp_rows';

    protected $fillable = [
        'batch_id', 'row_number', 'raw', 'normalized', 'errors', 'action',
        'entity_type', 'entity_id', 'before', 'applied', 'reverted', 'revert_error',
    ];

    protected $casts = [
        'raw' => 'array',
        'normalized' => 'array',
        'errors' => 'array',
        'before' => 'array',
        'applied' => 'boolean',
        'reverted' => 'boolean',
        'row_number' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function isValid(): bool
    {
        return $this->action !== 'skip';
    }
}
