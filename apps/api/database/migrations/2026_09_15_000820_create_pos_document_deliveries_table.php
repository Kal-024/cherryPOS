<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envío del comprobante al cliente (D-10, P-04, Q-11, H5.5).
 *
 * **La térmica es el canal primario y siempre funciona.** El envío digital es
 * *best-effort*: se encola, se despacha cuando hay internet y **nunca bloquea el
 * cierre de la venta**. El cajero ve el estado; eso es todo lo que necesita.
 *
 * Se envía **solo a pedido del cliente en caja** (P-04). El POS vive en una red
 * que puede no tener salida a internet, así que mandar todos los comprobantes
 * por WhatsApp sería encolar miles de mensajes que nadie pidió.
 *
 * La tabla existe aunque el proveedor todavía no esté elegido (punto abierto
 * A5): lo que falta es la cuenta y quién paga por conversación, no el mecanismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_document_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('sec_employees')->nullOnDelete();

            $table->enum('channel', ['whatsapp', 'email']);
            // Número o correo tal como se tecleó en caja. Se guarda con el envío
            // y no se lee del cliente: el cliente pudo pedir que fuera a otro
            // número, y el histórico tiene que decir adónde se mandó.
            $table->string('destination', 160);

            $table->enum('status', ['queued', 'sending', 'sent', 'failed', 'unconfigured'])
                ->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at', 6)->nullable();

            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->timestampTz('sent_at', 6)->nullable();

            $table->timestampsTz(6);

            $table->index(['status', 'next_attempt_at']);
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_document_deliveries');
    }
};
