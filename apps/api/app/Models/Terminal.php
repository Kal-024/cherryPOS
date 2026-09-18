<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use App\Services\Settings\SettingsRepository;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * Terminal de caja.
 *
 * **Es la identidad autenticada del sistema**, no el cajero: la terminal se
 * autentica una vez al día con credenciales reales y conserva el token de
 * Sanctum; el PIN del cajero identifica al operador dentro de esa sesión
 * (D-05). Por eso `HasApiTokens` vive aquí y no en `Employee`.
 *
 * Implementa `Authenticatable` porque el guard de Sanctum resuelve el token
 * contra el modelo autenticado — no porque una terminal "inicie sesión" en el
 * sentido de una persona. Quién opera la caja lo dice `OperatorSession`.
 */
class Terminal extends Model implements Authenticatable
{
    use AuthenticatableTrait, HasApiTokens, UsesUuid;

    protected $table = 'cmn_terminals';

    protected $fillable = [
        'branch_id', 'code', 'name', 'secret_hash', 'layout_profile',
        'authenticated_at', 'last_seen_at', 'is_active',
    ];

    protected $hidden = ['secret_hash'];

    protected $casts = [
        'authenticated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Perfil de pantalla efectivo (A-04).
     *
     * La terminal manda sobre el defecto del negocio, tal como anticipa el ERP
     * para cuando exista su tabla `pos_terminals` (§8 del contrato).
     */
    public function layoutProfile(?string $fallback = null): string
    {
        // Orden: lo que diga la terminal, después lo que bajó del ERP para la
        // empresa (§8), y al final la configuración local. Sin ERP, la última es
        // la única que hay y manda (P-01).
        return $this->layout_profile
            ?? $fallback
            ?? (string) app(SettingsRepository::class)->get(
                'pos.layout_profile',
                config('pos.layout_profile', 'scan_first')
            );
    }
}
