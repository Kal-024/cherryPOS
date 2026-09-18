<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turno de caja (G-04) y arqueo con denominaciones (D-06).
 *
 * El turno es **entidad de primera clase desde F1**: todo queda vinculado a él.
 * El arqueo de OSPOS no lo estaba, y por eso no podía responder "¿qué vendió la
 * caja 2 entre las 14:00 y las 22:00 del martes?" — un arqueo sin turno es la
 * mitad del control.
 *
 * El desglose se guarda **por denominación y por moneda** (Q-06): en Nicaragua
 * la caja tiene córdobas y dólares, y el faltante hay que poder atribuirlo a
 * uno de los dos.
 *
 * `exchange_rate` se fija al abrir el turno y rige para él: la tasa que el
 * supervisor cargó es la que se usó, y releer el turno mañana con la tasa de
 * mañana daría otro número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('cmn_terminals')->restrictOnDelete();
            $table->foreignUuid('opened_by')->constrained('sec_employees')->restrictOnDelete();
            $table->foreignUuid('closed_by')->nullable()->constrained('sec_employees')->nullOnDelete();

            $table->string('code', 30);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('opening_float', 18, 2)->default(0);
            // Tasa vigente durante el turno (Q-06).
            $table->decimal('exchange_rate', 18, 6)->nullable();

            $table->timestampTz('opened_at', 6);
            $table->timestampTz('closed_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'code']);
            // Una terminal no puede tener dos turnos abiertos. El índice parcial
            // lo impide en la base, no solo en el servicio.
            $table->index(['terminal_id', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pos_shifts_one_open_per_terminal
            ON pos_shifts (terminal_id)
            WHERE status = 'open';
        SQL);

        /**
         * Conteo físico del cierre. Una fila por denominación contada y por
         * moneda: es lo que convierte "falta plata" en "faltan tres billetes de
         * 50".
         */
        Schema::create('pos_cash_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->enum('moment', ['opening', 'closing'])->default('closing');
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('cmn_currencies')->restrictOnDelete();
            $table->foreignUuid('denomination_id')->nullable()->constrained('cmn_denominations')->nullOnDelete();
            $table->decimal('denomination_value', 12, 2);
            $table->unsignedInteger('count');
            $table->decimal('subtotal', 18, 2);
            $table->timestampsTz(6);

            $table->index(['shift_id', 'currency_code']);
        });

        /**
         * Entradas y salidas de caja que no son ventas: retiro parcial, fondo
         * adicional, pago de un gasto menor. Con motivo obligatorio — un retiro
         * sin motivo es exactamente lo que después nadie sabe explicar.
         */
        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('sec_employees')->restrictOnDelete();
            $table->foreignUuid('authorized_by')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->string('reason', 120);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('amount_base', 18, 2);
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6)->useCurrent();

            $table->index(['shift_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_cash_counts');
        DB::statement('DROP INDEX IF EXISTS pos_shifts_one_open_per_terminal');
        Schema::dropIfExists('pos_shifts');
    }
};
