<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quién está operando esta terminal ahora mismo.
 *
 * Es lo que permite el relevo de D-05: un cajero cierra su sesión de PIN y otro
 * entra en el mismo equipo, sin tocar la autenticación de la terminal.
 */
class OperatorSession extends Model
{
    use UsesUuid;

    protected $table = 'sec_operator_sessions';

    protected $fillable = [
        'terminal_id', 'employee_id', 'branch_id',
        'opened_at', 'expires_at', 'closed_at', 'pin_mode',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->expires_at->isFuture();
    }
}
