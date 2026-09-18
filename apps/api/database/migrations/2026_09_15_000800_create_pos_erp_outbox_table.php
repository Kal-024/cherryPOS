<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de salida hacia cherryERP (`POST /pos/sales`).
 *
 * **P4: el POS nunca depende del ERP para cerrar una venta.** La caja cobra,
 * cierra el ticket y lo deja aquí; el envío es posterior, asíncrono y
 * reintentable. Un ERP caído no detiene la operación, y un rechazo no revierte
 * nada — la venta ya se cobró y el dinero está en el cajón.
 *
 * **Ningún 4xx se reintenta** (§6 del contrato): reintentar un 422 quema la
 * cola contra un ticket que nunca va a pasar y retrasa los que sí pasarían. Van
 * a `exception`, que es una bandeja que el supervisor opera.
 *
 * `issue_failed` (409) merece la atención especial que anota el contrato: el
 * documento **sí se creó**, quedó en borrador con su `pos_ticket_uuid`. Un
 * reintento devolvería 200 "duplicado" y daríamos por sincronizado algo que
 * nadie emitió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_erp_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->cascadeOnDelete();

            // Payload congelado en el momento del cierre. Se envía tal cual en
            // cada reintento: recomponerlo desde la venta abriría la puerta a
            // mandar algo distinto de lo que se cobró.
            $table->jsonb('payload');

            $table->enum('status', ['pending', 'sending', 'sent', 'exception'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at', 6)->nullable();

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedBigInteger('erp_document_id')->nullable();
            $table->string('erp_document_number', 40)->nullable();
            // Distinto de cero es alarma, no dato (§5 del contrato): los dos
            // motores de cálculo están divergiendo.
            $table->decimal('tax_difference', 18, 2)->nullable();
            $table->boolean('duplicate')->default(false);

            $table->timestampTz('sent_at', 6)->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique('sale_id');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_erp_outbox');
    }
};
