<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/** Impuesto persistido por línea (D-16). El principio innegociable del motor. */
class SaleLineTax extends Model
{
    use UsesUuid;

    protected $table = 'pos_sale_line_taxes';

    protected $fillable = ['sale_line_id', 'tax_code_id', 'code', 'rate', 'base', 'amount'];
}
