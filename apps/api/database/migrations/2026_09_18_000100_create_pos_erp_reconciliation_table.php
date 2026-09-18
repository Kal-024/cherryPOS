<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de conciliación de maestros (F1-C, §12.8 del contrato, Q-04).
 *
 * Cuando el ERP se integra sobre un POS que ya venía vendiendo, quedan datos sin
 * par: el cliente que el supervisor cargó en caja, el producto que nunca tuvo
 * código interno. **Nunca se fusionan por nombre** —dos "Distribuidora
 * González" son dos empresas hasta que una cédula diga lo contrario, y una
 * fusión equivocada mezcla el crédito de dos clientes—, así que quedan acá para
 * que una persona decida.
 *
 * **Lo pendiente no bloquea** (§12.8): el producto sigue vendiéndose y el
 * cliente sigue comprando. Una caja parada por un dato viejo es peor que un dato
 * viejo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_erp_reconciliation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->cascadeOnDelete();

            // Qué entidad y cuál. `entity_id` no lleva clave foránea porque
            // apunta a dos tablas distintas según `kind`.
            $table->string('kind', 20);
            $table->uuid('entity_id')->nullable();
            // Cómo llamarlo en la bandeja sin volver a consultar la tabla: el
            // registro puede haberse dado de baja mientras tanto.
            $table->string('label', 200);
            $table->string('natural_key', 120)->nullable();

            /*
             * Por qué quedó pendiente:
             *
             *   no_match   — el POS lo tiene y el ERP no.
             *   ambiguous  — la clave natural apunta a más de un registro local.
             *   conflict   — el ERP la asigna a otro registro del que el POS ya
             *                tenía fusionado.
             *   no_key     — no hay clave natural con la cual fusionar.
             */
            $table->string('reason', 20);
            $table->jsonb('detail')->nullable();

            $table->foreignUuid('resolved_by')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->string('resolution', 255)->nullable();

            $table->timestampsTz(6);

            // Un mismo registro no se apunta dos veces por el mismo motivo: la
            // bandeja tiene que poder vaciarse.
            $table->unique(['branch_id', 'kind', 'entity_id', 'reason'], 'pos_erp_reconciliation_unique');
            $table->index(['branch_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_erp_reconciliation');
    }
};
