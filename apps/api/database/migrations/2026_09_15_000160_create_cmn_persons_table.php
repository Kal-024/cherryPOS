<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persona: la identidad detrás de cliente, proveedor y empleado (B-11).
 *
 * **Un mismo actor cumple dos roles sin duplicarse.** El empleado que también
 * compra a crédito, el proveedor que además es cliente, el autorizado de una
 * cuenta que mañana entra a trabajar: son una persona con varios roles, no
 * varias filas que nadie vuelve a reconciliar.
 *
 * Sin esto, cambiar un teléfono obliga a recordar en cuántas tablas está la
 * misma persona — y la respuesta siempre es "en una más de las que creías".
 *
 * La cédula es única cuando existe, pero **no es obligatoria**: la mayoría de
 * las ventas no llevan cliente y nadie pide cédula para vender una gaseosa
 * (P-03). El índice parcial admite tantas personas sin cédula como haga falta y
 * garantiza que no haya dos con la misma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmn_persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Natural o jurídica. Cambia qué documento identifica: cédula o RUC.
            $table->enum('kind', ['natural', 'legal'])->default('natural');
            $table->string('full_name', 160);
            $table->string('national_id', 30)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->index('full_name');
            $table->index('tax_id');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cmn_persons_national_id_unique
            ON cmn_persons (national_id)
            WHERE national_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cmn_persons_national_id_unique');
        Schema::dropIfExists('cmn_persons');
    }
};
