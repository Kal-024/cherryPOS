<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora de auditoría (G-11). **Solo inserción**, impuesto por disparador.
 *
 * Una bitácora que el propio sistema puede reescribir no prueba nada.
 */
class AuditLog extends Model
{
    use UsesUuid;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'sec_audit_log';

    protected $fillable = [
        'branch_id', 'terminal_id', 'employee_id', 'authorized_by',
        'event', 'entity_type', 'entity_id', 'changes', 'context', 'ip',
        'occurred_at', 'recorded_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];
}
