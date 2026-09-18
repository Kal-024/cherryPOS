<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comanda: lo que se mandó a cocina en un momento concreto (F1-B).
 *
 * Es entidad propia, a diferencia de la cuenta abierta —que es la venta
 * suspendida—, porque lo que cocina prepara no es la venta, que sigue
 * creciendo, sino **ese envío**: la cocina necesita saber qué le mandaron a las
 * 20:14 aunque después se agreguen dos postres.
 */
class KitchenTicket extends Model
{
    use UsesUuid;

    protected $table = 'pos_kitchen_tickets';

    /**
     * Retenida: pedida pero todavía no marchada (G-16).
     *
     * No aparece en el pase. Es lo que permite tomar el pedido entero de una vez
     * y que el postre no empiece a cocinarse mientras la mesa come el fuerte.
     */
    public const HELD = 'held';

    public const QUEUED = 'queued';

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const SERVED = 'served';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id', 'sale_id', 'terminal_id', 'employee_id',
        'destination', 'course', 'number', 'status', 'notes',
        'sent_at', 'fired_at', 'started_at', 'ready_at', 'served_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'course' => 'integer',
        'sent_at' => 'datetime',
        'fired_at' => 'datetime',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KitchenTicketLine::class, 'ticket_id');
    }

    /**
     * Segundos que la cocina lleva con esta comanda.
     *
     * Cuenta desde que se **marchó**, no desde que se pidió, y se congela cuando
     * el plato queda listo: lo que mide el pase es cuánto tarda la cocina, no
     * cuánto tarda el mesero en llevarlo a la mesa.
     */
    public function elapsedSeconds(): int
    {
        $from = $this->fired_at ?? $this->sent_at;

        if ($from === null) {
            return 0;
        }

        return (int) max(0, $from->diffInSeconds($this->ready_at ?? now()));
    }
}
