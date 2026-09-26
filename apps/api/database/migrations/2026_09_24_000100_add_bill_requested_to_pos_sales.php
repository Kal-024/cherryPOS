<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Cuenta pedida»: la mesa ya vio lo que consumió (F1-B, §10).
 *
 * En un salón, imprimir la precuenta **cambia el estado de la mesa**: de «están
 * comiendo» a «están por irse». Es el momento en que el mesero deja de ofrecer
 * postre y empieza a mirar si ya pusieron la tarjeta sobre la mesa, y es el dato
 * que decide a quién atender primero cuando hay gente esperando en la puerta.
 *
 * **Se deduce de la precuenta, no se teclea.** Un botón "marcar cuenta pedida"
 * aparte sería un segundo paso que alguien olvida, y entonces el mapa mentiría.
 * Se anota al imprimirla, que es el gesto que ya existe.
 *
 * Una marca de tiempo y no un booleano: el mapa muestra **cuánto lleva esperando
 * pagar**, que es lo único que hace accionable el dato. «Pidió la cuenta hace
 * quince minutos» es un problema; «pidió la cuenta» no dice nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->timestampTz('bill_requested_at', 6)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropColumn('bill_requested_at');
        });
    }
};
