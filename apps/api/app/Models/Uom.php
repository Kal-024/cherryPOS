<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidad de medida.
 *
 * `decimals` decide qué se puede fraccionar: "2,5 metros" sí, "2,5 unidades"
 * no. Es la validación que evita vender media caja registradora.
 */
class Uom extends Model
{
    use UsesUuid;

    protected $table = 'cat_uoms';

    protected $fillable = ['code', 'name', 'decimals', 'erp_uom_id', 'is_active'];

    protected $casts = ['decimals' => 'integer', 'is_active' => 'boolean'];
}
