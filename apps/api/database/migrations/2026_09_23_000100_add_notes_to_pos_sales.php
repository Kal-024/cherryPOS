<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La nota de la cuenta abierta (F1-B, §10).
 *
 * Es lo que el mesero quiere tener a mano mientras atiende: «cumpleaños»,
 * «apurados, tienen función a las 8», «paga el de la camisa azul». Hoy solo
 * existía la nota **de línea** —la que viaja a la comanda: «sin cebolla»—, y esa
 * es de la cocina, no del servicio.
 *
 * **Va en la cuenta, no en la mesa.** La cuenta muere al cobrar y la nota se va
 * con ella, que es exactamente su vida útil. Guardarla en la mesa la haría
 * sobrevivir al cliente: alguien tendría que acordarse de borrarla, y el día que
 * no lo haga el mesero siguiente lee un dato de otra gente.
 *
 * `text` y no `string`: un límite de ochenta caracteres obligaría a abreviar
 * justo cuando hay prisa, que es cuando se escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
