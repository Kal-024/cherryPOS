<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de comprobante (D-11).
 *
 * La plantilla es **dato**, no veinte banderas en el código. `content` guarda
 * una lista de bloques; el renderizador los convierte en líneas con estilo.
 */
class ReceiptTemplate extends Model
{
    use UsesUuid;

    protected $table = 'pos_receipt_templates';

    /** Caracteres por línea de cada ancho de papel. Manda sobre todo el diseño. */
    public const WIDTHS = [
        'thermal_58' => 32,
        'thermal_80' => 48,
        'letter' => 80,
        'a4' => 80,
    ];

    protected $fillable = [
        'branch_id', 'code', 'name', 'document_type', 'paper',
        'content', 'is_default', 'is_system', 'is_active',
    ];

    protected $casts = [
        'content' => 'array',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function width(): int
    {
        return self::WIDTHS[$this->paper] ?? 48;
    }

    /**
     * La plantilla que corresponde a un documento en una sucursal.
     *
     * La de la sucursal gana sobre la del negocio: una cadena puede querer un
     * pie distinto por local sin duplicar el resto.
     */
    public static function resolve(string $documentType, ?string $branchId): ?self
    {
        return self::query()
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id IS NULL')
            ->orderByDesc('is_default')
            ->first();
    }
}
