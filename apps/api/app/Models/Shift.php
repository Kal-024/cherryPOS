<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno de caja (G-04). Entidad de primera clase: todo queda vinculado a él.
 *
 * El arqueo de OSPOS no lo estaba, y por eso no podía responder "¿qué vendió la
 * caja 2 entre las 14:00 y las 22:00 del martes?".
 */
class Shift extends Model
{
    use UsesUuid;

    protected $table = 'pos_shifts';

    protected $fillable = [
        'branch_id', 'terminal_id', 'opened_by', 'closed_by', 'code', 'status',
        'opening_float', 'exchange_rate', 'opened_at', 'closed_at',
    ];

    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
