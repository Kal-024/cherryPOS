<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos al supervisor (P-11).
 *
 * **Internos y no bloqueantes.** La caja no se detiene: se advierte para que el
 * supervisor tome medidas después. El caso que originó la decisión es el cajero
 * pasando productos sin registrar previamente — hay que avisar rápido, no parar
 * la fila.
 *
 * Lo que **sí** bloquea es otra cosa y no vive aquí: la autorización en el
 * momento con PIN de supervisor, cuando el descuento pasa el tope del cajero
 * (D-02) o cuando ya van cinco ítems temporales en el día (D-03). Ese PIN es
 * **distinto del de inicio de sesión**, por razones obvias de seguridad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_supervisor_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('cmn_terminals')->nullOnDelete();
            // Quién la provocó.
            $table->foreignUuid('employee_id')->nullable()->constrained('sec_employees')->nullOnDelete();

            // temporary_item.used, discount.over_limit, cash.shortage, …
            $table->string('event', 60);
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->string('title', 160);
            $table->text('body')->nullable();
            // Detalle completo: monto, producto, venta. "Con información
            // detallada" es literal en D-03.
            $table->jsonb('context')->nullable();

            $table->string('entity_type', 60)->nullable();
            $table->uuid('entity_id')->nullable();

            $table->foreignUuid('read_by')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->timestampTz('read_at', 6)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->timestampsTz(6);

            $table->index(['branch_id', 'read_at']);
            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_supervisor_notifications');
    }
};
