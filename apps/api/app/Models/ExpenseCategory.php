<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Categoría de gasto (B-14).
 *
 * `behaviour` separa lo fijo de lo variable. Un alquiler no se lee igual que
 * una comisión: la distinción la pide cualquier análisis de punto de
 * equilibrio, y agregarla después obliga a reclasificar el histórico a mano.
 */
class ExpenseCategory extends Model
{
    use UsesUuid;

    protected $table = 'exp_categories';

    protected $fillable = ['code', 'name', 'behaviour', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
