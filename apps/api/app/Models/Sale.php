<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La venta: un solo modelo para los cinco tipos (B-01).
 *
 * **El carrito vive aquí** (D-21). En OSPOS vivía en `$_SESSION`, con 1.727
 * líneas de lógica atadas a la sesión HTTP: ninguna venta podía continuarse en
 * otro dispositivo y ningún proceso podía validarla. Aquí el carrito es una
 * venta en estado `draft` con identidad propia.
 *
 * Y **la venta suspendida es un estado, no cuatro tablas espejo** (D-01).
 */
class Sale extends Model
{
    use UsesUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VOIDED = 'voided';

    protected $table = 'pos_sales';

    protected $fillable = [
        'id', 'branch_id', 'terminal_id', 'shift_id', 'employee_id', 'customer_id',
        'sale_type', 'status', 'label', 'series_id', 'number',
        'currency_code', 'exchange_rate',
        'gross', 'line_discount_total', 'sale_discount', 'discount_total',
        'subtotal', 'taxable_base', 'exempt_total', 'tax_total', 'total',
        'cash_rounding', 'paid', 'balance',
        // Propina: se cobra con la cuenta y no se factura (G-16).
        'tip_amount', 'tip_employee_id',
        'sale_discount_type', 'sale_discount_value', 'discount_authorized_by',
        'reverses_sale_id', 'void_reason',
        // Salón (F1-B): la mesa, cuántos son y de quién es la mesa. Son del
        // servicio, no del documento — el ERP no los necesita.
        'dining_table_id', 'guests', 'waiter_employee_id',
        'opened_at', 'closed_at',
    ];

    protected $casts = [
        'guests' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** La mesa, cuando la venta es una cuenta abierta del salón (F1-B). */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    /**
     * El mesero.
     *
     * Distinto del cajero: quien atiende la mesa no es necesariamente quien
     * cobra, y el corte por cajero del turno no responde "¿de quién es esta
     * mesa?".
     */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'waiter_employee_id');
    }

    /**
     * Quién se lleva la propina (G-16).
     *
     * Nulo quiere decir "el mesero de la venta". Existe aparte porque en un
     * local con bote común la propina no es de quien atendió la mesa, y en uno
     * sin meseros la cobra la caja: atarla a `waiter_employee_id` obligaría a
     * inventar un mesero para poder repartir.
     */
    public function tipEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'tip_employee_id');
    }

    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }

    /** Abierta = todavía editable. La inmutabilidad empieza al cerrar (P1). */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Cómo se llama este estado en la interfaz, según el perfil de negocio.
     *
     * En restaurante una venta suspendida es una **cuenta abierta**: lo natural
     * allí es comer y pagar después, y llamarla "suspendida" confundiría al
     * mesero (D-01). El mecanismo es el mismo.
     */
    public function statusKey(?BusinessProfile $profile = null): string
    {
        $default = "sale.{$this->status}";

        return $profile?->vocabulary["sale.{$this->status}"] ?? $default;
    }
}
