<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Código de impuesto.
 *
 * `code` es el mismo string que viaja al ERP en `lines[].tax_code`. Coincidir de
 * nombre no es casualidad: es lo que evita una tabla de traducción.
 */
class TaxCode extends Model
{
    use UsesUuid;

    protected $table = 'cat_tax_codes';

    public const BASE_NET = 'net';

    public const BASE_GROSS = 'gross';

    protected $fillable = ['code', 'name', 'rate', 'type', 'base', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Exento **no** es tasa cero: dan el mismo total y el libro de ventas los
     * declara distinto. Se deriva de `type` para que nadie pueda guardar una
     * fila que diga las dos cosas.
     */
    protected function isExempt(): Attribute
    {
        return Attribute::get(fn (): bool => $this->type === 'exempt');
    }

    /** El precio del catálogo ya trae este impuesto dentro. */
    public function includedInPrice(): bool
    {
        return $this->base === self::BASE_GROSS;
    }
}
