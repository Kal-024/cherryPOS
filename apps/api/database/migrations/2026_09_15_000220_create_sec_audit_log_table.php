<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría (G-11, P1).
 *
 * **Solo inserción.** Un disparador impide `UPDATE` y `DELETE` a nivel de base
 * de datos, no solo de aplicación: una bitácora que el propio sistema puede
 * reescribir no prueba nada, y al tocar dinero la auditoría tiene que ser
 * estricta y legal (A-05).
 *
 * `occurred_at` y `recorded_at` son distintos a propósito (precondición 4): el
 * momento del hecho y el momento en que llegó al registro no coinciden cuando
 * la terminal estuvo un rato sin conexión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sec_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('cmn_terminals')->nullOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('sec_employees')->nullOnDelete();
            // Quién autorizó, cuando la acción exigió PIN de supervisor.
            $table->foreignUuid('authorized_by')->nullable()->constrained('sec_employees')->nullOnDelete();

            $table->string('event', 80);           // sale.void, discount.over_limit, …
            $table->string('entity_type', 60)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('changes')->nullable();  // antes/después
            $table->jsonb('context')->nullable();  // monto, motivo, lo que haga falta
            $table->string('ip', 45)->nullable();

            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6)->useCurrent();

            $table->index(['branch_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('event');
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sec_audit_log_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'sec_audit_log es de solo inserción: % no está permitido', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER sec_audit_log_no_update_delete
            BEFORE UPDATE OR DELETE ON sec_audit_log
            FOR EACH ROW EXECUTE FUNCTION sec_audit_log_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sec_audit_log_no_update_delete ON sec_audit_log');
        DB::statement('DROP FUNCTION IF EXISTS sec_audit_log_append_only()');
        Schema::dropIfExists('sec_audit_log');
    }
};
