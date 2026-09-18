<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un comprobante esperando salir hacia el cliente (P-04).
 *
 * `unconfigured` es un estado propio y no un fallo: significa que el canal
 * existe pero la instalación todavía no tiene cuenta. Tratarlo como error haría
 * que la cola reintentara para siempre algo que ninguna cantidad de reintentos
 * va a arreglar.
 */
class DocumentDelivery extends Model
{
    use UsesUuid;

    protected $table = 'pos_document_deliveries';

    protected $fillable = [
        'branch_id', 'sale_id', 'requested_by', 'channel', 'destination',
        'status', 'attempts', 'next_attempt_at', 'error_code', 'error_message',
        'provider_reference', 'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
