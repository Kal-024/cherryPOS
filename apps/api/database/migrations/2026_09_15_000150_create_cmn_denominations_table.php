<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denominaciones de billetes y monedas para el arqueo (D-06).
 *
 * El desglose convierte "falta plata" en "faltan tres billetes de 50". Las
 * denominaciones son **configurables por país** porque no hay dos iguales; el
 * seeder carga las de Nicaragua por defecto: billetes 10 / 20 / 50 / 100 / 1000
 * y monedas 0,25 / 0,50 / 1 / 5 / 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmn_denominations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('cmn_currencies')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->decimal('value', 12, 2);
            $table->enum('kind', ['bill', 'coin']);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['currency_code', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmn_denominations');
    }
};
