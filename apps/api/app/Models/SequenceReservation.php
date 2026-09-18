<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque de correlativos que una terminal se lleva para numerar sin servidor
 * (H6.3).
 *
 * Los números no usados del bloque **se pierden**. Un hueco en la numeración se
 * explica; dos facturas con el mismo número, no.
 */
class SequenceReservation extends Model
{
    use UsesUuid;

    protected $table = 'pos_sequence_reservations';

    protected $fillable = [
        'branch_id', 'terminal_id', 'series_id',
        'range_from', 'range_to', 'next_number', 'is_active', 'exhausted_at',
    ];

    protected $casts = [
        'range_from' => 'integer',
        'range_to' => 'integer',
        'next_number' => 'integer',
        'is_active' => 'boolean',
        'exhausted_at' => 'datetime',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class, 'series_id');
    }

    public function remaining(): int
    {
        return max(0, $this->range_to - $this->next_number + 1);
    }

    public function covers(int $number): bool
    {
        return $number >= $this->range_from && $number <= $this->range_to;
    }
}
