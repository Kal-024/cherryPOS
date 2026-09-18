<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Perfil de negocio (A-04): el mecanismo de extensión de los verticales.
 *
 * Preconfigura módulos activos, esquema de atributos de producto, vocabulario
 * visible y perfil de pantalla. `vocabulary` es lo que hace que en restaurante
 * "suspender una venta" se llame **cuenta abierta** — mismo estado, otro
 * nombre, no otro mecanismo (D-01).
 */
class BusinessProfile extends Model
{
    use UsesUuid;

    protected $table = 'cat_business_profiles';

    protected $fillable = [
        'code', 'name', 'modules', 'attribute_schema', 'vocabulary',
        'layout_profile', 'is_system',
    ];

    protected $casts = [
        'modules' => 'array',
        'attribute_schema' => 'array',
        'vocabulary' => 'array',
        'is_system' => 'boolean',
    ];

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }
}
