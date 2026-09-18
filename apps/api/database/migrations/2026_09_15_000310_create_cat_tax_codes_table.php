<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de impuesto (D-16, P-09, Q-07).
 *
 * Motor **simple**: tasas por ítem más exención de producto y exoneración de
 * cliente. Mercado inicial Nicaragua, régimen fiscal estable. El motor completo
 * con categorías y jurisdicciones llega cuando se internacionalice, y por eso
 * el cálculo vive detrás de `SaleCalculator` — la costura ya está puesta.
 *
 * `code` es el mismo string que viaja al ERP en `lines[].tax_code` (§4 del
 * contrato), que allá se resuelve contra `TaxCode`. Coincidir de nombre no es
 * casualidad: es lo que evita una tabla de traducción — y por el mismo motivo
 * `type` y `base` copian los de `tax_codes` de cherryB.
 *
 * **`base` es lo que decide si el precio trae el impuesto dentro.** El ERP lo
 * modela así, por código y no por empresa. Si el POS lo decidiera globalmente,
 * `tax_difference` saldría distinto de cero en cada ticket y la bandeja de
 * excepciones se llenaría de ruido desde el primer día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cat_tax_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->decimal('rate', 9, 4);

            // `exempt` no es "tasa cero": son dos cosas que dan el mismo total y
            // que el libro de ventas declara distinto. El medicamento
            // nicaragüense es exento, no gravado al 0 %.
            $table->enum('type', ['vat', 'exempt'])->default('vat');

            // `net` — el precio no trae impuesto y se le agrega.
            // `gross` — el precio ya lo trae dentro y hay que extraerlo.
            // En Nicaragua el precio de góndola es lo que paga el cliente.
            $table->enum('base', ['net', 'gross'])->default('gross');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_tax_codes');
    }
};
