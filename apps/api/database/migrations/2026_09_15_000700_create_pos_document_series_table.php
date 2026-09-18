<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeración de documentos por plantilla de tokens (B-05).
 *
 * El formato lo define una plantilla configurable, no el código:
 * `{SUCURSAL}-{TIPO}-{AÑO}-{CORRELATIVO:6}`. Secuencias independientes **por
 * tipo de documento, por año y por sucursal** (precondición 3): dos locales
 * nunca comparten correlativo, porque eso rompería la consolidación el día que
 * el cliente abra el segundo.
 *
 * **Desacoplada de la lógica de venta a propósito** (P-08, andamio fiscal): un
 * módulo de facturación electrónica futuro tiene que poder tomar la numeración
 * sin tocar el flujo de caja. Y si el cliente contrata el ERP, la configuración
 * de series pasa a su módulo de facturación tomando como punto de partida lo
 * que el POS venía usando (Q-02, P3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_document_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            // counter, invoice, quote, work_order, refund
            $table->string('document_type', 20);
            $table->unsignedSmallInteger('year');
            $table->string('template', 80)->default('{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}');
            $table->unsignedBigInteger('next_number')->default(1);
            // Rango reservado para el modo degradado (H6.3). Null = sin reserva.
            $table->unsignedBigInteger('range_from')->nullable();
            $table->unsignedBigInteger('range_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'document_type', 'year'], 'pos_document_series_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_document_series');
    }
};
