<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de modificadores (B-06): "término de la carne", "extras".
 *
 * `min_select` mayor que cero lo vuelve **obligatorio**, y eso bloquea el envío
 * a cocina. La regla vive acá y no en la pantalla porque una regla que solo
 * existe en la interfaz se salta con la primera terminal nueva.
 */
class ModifierGroup extends Model
{
    use UsesUuid;

    protected $table = 'cat_modifier_groups';

    protected $fillable = ['code', 'name', 'min_select', 'max_select', 'sort_order', 'is_active'];

    protected $casts = [
        'min_select' => 'integer',
        'max_select' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'group_id')->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'cat_product_modifier_group', 'group_id', 'product_id');
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }
}
