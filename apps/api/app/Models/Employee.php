<?php

namespace App\Models;

use App\Models\Concerns\HasPerson;
use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;

/**
 * Empleado: la persona que opera la caja.
 *
 * No es la identidad autenticada del sistema — esa es la `Terminal` (D-05). El
 * empleado se identifica con un PIN **dentro** de una sesión de terminal ya
 * autenticada, y por eso este modelo no lleva `HasApiTokens`.
 */
class Employee extends Model
{
    use HasPerson, UsesUuid;

    protected $table = 'sec_employees';

    protected $fillable = [
        'person_id', 'branch_id', 'code',
        'discount_limit_percent', 'temp_item_daily_limit', 'is_active',
    ];

    /** La identidad se sirve desde la persona (B-11), no desde esta tabla. */
    protected $appends = ['full_name'];

    protected $hidden = ['password_hash', 'pin_hash', 'supervisor_pin_hash'];

    protected $casts = [
        'pin_locked_until' => 'datetime',
        'is_active' => 'boolean',
        'discount_limit_percent' => 'decimal:4',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'sec_employee_role')
            ->withPivot('branch_id');
    }

    public function setPin(string $pin): void
    {
        $this->pin_hash = Hash::make($pin);
        $this->failed_pin_attempts = 0;
        $this->pin_locked_until = null;
    }

    /** El de autorizar, distinto del de iniciar sesión (P-11). */
    public function setSupervisorPin(string $pin): void
    {
        $this->supervisor_pin_hash = Hash::make($pin);
    }

    public function checkPin(string $pin): bool
    {
        return $this->pin_hash !== null && Hash::check($pin, $this->pin_hash);
    }

    public function checkSupervisorPin(string $pin): bool
    {
        return $this->supervisor_pin_hash !== null
            && Hash::check($pin, $this->supervisor_pin_hash);
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    /**
     * Permisos efectivos: los de sus roles, menos las revocaciones
     * individuales, más las concesiones individuales (D-13).
     *
     * El orden importa: una revocación explícita gana sobre el rol, porque es
     * la excepción que alguien configuró a mano para esta persona.
     *
     * @return array<int,string>
     */
    public function permissionCodes(?string $branchId = null): array
    {
        $fromRoles = Permission::query()
            ->join('sec_role_permission as rp', 'rp.permission_id', '=', 'sec_permissions.id')
            ->join('sec_employee_role as er', 'er.role_id', '=', 'rp.role_id')
            ->where('er.employee_id', $this->id)
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->whereNull('er.branch_id')->orWhere('er.branch_id', $branchId);
            }))
            ->pluck('sec_permissions.code')
            ->all();

        $overrides = Permission::query()
            ->join('sec_employee_permission as ep', 'ep.permission_id', '=', 'sec_permissions.id')
            ->where('ep.employee_id', $this->id)
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->whereNull('ep.branch_id')->orWhere('ep.branch_id', $branchId);
            }))
            ->get(['sec_permissions.code', 'ep.effect']);

        $granted = array_flip($fromRoles);

        foreach ($overrides as $override) {
            if ($override->effect === 'deny') {
                unset($granted[$override->code]);
            } else {
                $granted[$override->code] = true;
            }
        }

        return array_keys($granted);
    }

    public function hasPermission(string $code, ?string $branchId = null): bool
    {
        return in_array($code, $this->permissionCodes($branchId), true);
    }
}
