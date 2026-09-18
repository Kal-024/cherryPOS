<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Una fila de la bandeja de conciliación (F1-C, §12.8).
 *
 * Existe porque la fusión **nunca es automática por nombre** (Q-04): lo que no
 * case por clave natural queda esperando a que una persona decida, y mientras
 * tanto se sigue vendiendo.
 */
class ErpReconciliationEntry extends Model
{
    use UsesUuid;

    public const KIND_PRODUCT = 'product';

    public const KIND_CUSTOMER = 'customer';

    /** El POS lo tiene y el ERP no. */
    public const NO_MATCH = 'no_match';

    /** El ERP la asigna a otro registro del que el POS ya tenía fusionado. */
    public const CONFLICT = 'conflict';

    /** No hay clave natural: sin cédula no hay fusión posible. */
    public const NO_KEY = 'no_key';

    protected $table = 'pos_erp_reconciliation';

    protected $fillable = [
        'branch_id', 'kind', 'entity_id', 'label', 'natural_key',
        'reason', 'detail', 'resolved_by', 'resolved_at', 'resolution',
    ];

    protected $casts = ['detail' => 'array', 'resolved_at' => 'datetime'];
}
