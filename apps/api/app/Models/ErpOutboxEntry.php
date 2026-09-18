<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ticket a la espera de convertirse en documento fiscal en cherryERP. */
class ErpOutboxEntry extends Model
{
    use UsesUuid;

    protected $table = 'pos_erp_outbox';

    protected $fillable = [
        'branch_id', 'sale_id', 'payload', 'status', 'attempts', 'next_attempt_at',
        'http_status', 'error_code', 'error_message',
        'erp_document_id', 'erp_document_number', 'tax_difference', 'duplicate',
        'sent_at', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'duplicate' => 'boolean',
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
