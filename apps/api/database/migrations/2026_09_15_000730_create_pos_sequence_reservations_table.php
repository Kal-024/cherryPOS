<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rangos de correlativo reservados por terminal (H6.3).
 *
 * **Dos cajas sin conexión no pueden emitir el mismo número.** Es el problema
 * que hace difícil el modo degradado: el correlativo lo reparte el servidor, y
 * sin servidor no hay quién reparta.
 *
 * La solución es repartirlo **antes**: cada terminal se lleva un bloque —del
 * 1.000 al 1.099, por ejemplo— y mientras está sin red numera de ahí. El bloque
 * sale de la misma serie (B-05), así que la numeración en línea sigue su curso
 * sin pisarse con la reservada.
 *
 * El costo es que se pierden los números no usados del bloque. Es aceptable: un
 * hueco en la numeración se explica, dos facturas con el mismo número no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sequence_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('cmn_terminals')->cascadeOnDelete();
            $table->foreignUuid('series_id')->constrained('pos_document_series')->cascadeOnDelete();

            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            // El siguiente sin usar dentro del bloque. Lo lleva el terminal y lo
            // informa al sincronizar, para que el servidor sepa cuánto queda.
            $table->unsignedBigInteger('next_number');

            $table->boolean('is_active')->default(true);
            $table->timestampTz('exhausted_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['terminal_id', 'series_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sequence_reservations');
    }
};
