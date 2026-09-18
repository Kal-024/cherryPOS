<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una carga masiva, en sus dos tiempos: previsualizar y aplicar (D-15). */
class ImportBatch extends Model
{
    use UsesUuid;

    public const PREVIEWED = 'previewed';

    public const APPLIED = 'applied';

    public const REVERTED = 'reverted';

    protected $table = 'imp_batches';

    protected $fillable = [
        'branch_id', 'employee_id', 'kind', 'filename', 'status',
        'rows_total', 'rows_valid', 'rows_invalid', 'rows_applied',
        'applied_at', 'reverted_at', 'reverted_by', 'notes',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
        'rows_total' => 'integer',
        'rows_valid' => 'integer',
        'rows_invalid' => 'integer',
        'rows_applied' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id')->orderBy('row_number');
    }

    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }
}
