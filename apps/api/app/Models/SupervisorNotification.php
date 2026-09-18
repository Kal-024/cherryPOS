<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso al supervisor (P-11). **Interno y no bloqueante**: la caja no se
 * detiene, se advierte para que tome medidas después.
 *
 * Lo que sí bloquea es otra cosa y no vive aquí: la autorización en el momento
 * con PIN de supervisor.
 */
class SupervisorNotification extends Model
{
    use UsesUuid;

    protected $table = 'pos_supervisor_notifications';

    protected $fillable = [
        'branch_id', 'terminal_id', 'employee_id', 'event', 'severity',
        'title', 'body', 'context', 'entity_type', 'entity_id',
        'read_by', 'read_at', 'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'read_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];
}
