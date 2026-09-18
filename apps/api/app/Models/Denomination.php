<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Billete o moneda del arqueo (D-06).
 *
 * Configurables por país porque no hay dos iguales. El seeder carga las de
 * Nicaragua: billetes de 10, 20, 50, 100 y 1000 córdobas, y monedas de 0,25,
 * 0,50, 1, 5 y 10.
 */
class Denomination extends Model
{
    use UsesUuid;

    protected $table = 'cmn_denominations';

    protected $fillable = ['currency_code', 'value', 'kind', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
