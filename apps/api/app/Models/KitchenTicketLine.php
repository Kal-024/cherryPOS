<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una comanda.
 *
 * Nombre y modificadores van **copiados**: cocina no consulta el catálogo, y la
 * comanda tiene que poder leerse aunque la línea se anule después.
 */
class KitchenTicketLine extends Model
{
    use UsesUuid;

    protected $table = 'pos_kitchen_ticket_lines';

    protected $fillable = ['ticket_id', 'sale_line_id', 'name', 'qty', 'modifiers', 'notes'];

    protected $casts = ['modifiers' => 'array'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'ticket_id');
    }
}
