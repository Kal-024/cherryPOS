<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importación masiva con previsualización y reversión (D-15, H1.6).
 *
 * **Sin importación no hay onboarding.** Nadie carga 3.000 productos a mano, y
 * es el primer obstáculo real para captar un cliente: es requisito comercial
 * antes que técnico.
 *
 * La implementación de OSPOS es un método de 400 líneas sin previsualización ni
 * reversión, y eso es justamente lo que había que rehacer. Aquí la carga ocurre
 * en dos tiempos:
 *
 *  1. **Previsualizar** — se lee el archivo, se valida fila por fila y no se
 *     escribe nada del dominio. El usuario ve qué va a pasar antes de que pase.
 *  2. **Aplicar** — se ejecuta lo previsualizado, anotando en cada fila qué
 *     entidad tocó y **cómo estaba antes**.
 *
 * Ese `before` es lo que hace posible revertir. Sin él, deshacer una
 * actualización sería adivinar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imp_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('sec_employees')->nullOnDelete();

            // products · customers · suppliers · stock
            $table->string('kind', 20);
            $table->string('filename', 255)->nullable();

            // previewed — leído y validado, sin tocar el dominio.
            // applied   — ejecutado.
            // reverted  — deshecho.
            $table->enum('status', ['previewed', 'applied', 'reverted', 'failed'])
                ->default('previewed');

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('rows_applied')->default(0);

            $table->timestampTz('applied_at', 6)->nullable();
            $table->timestampTz('reverted_at', 6)->nullable();
            $table->foreignUuid('reverted_by')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz(6);

            $table->index(['branch_id', 'kind', 'status']);
        });

        Schema::create('imp_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('imp_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            // Lo que traía el archivo, tal cual. Se conserva para que el usuario
            // pueda ver la fila original junto al error.
            $table->jsonb('raw');
            $table->jsonb('normalized')->nullable();
            $table->jsonb('errors')->nullable();

            // create — alta nueva.
            // update — la clave natural ya existía.
            // skip   — la fila no pasó la validación.
            $table->enum('action', ['create', 'update', 'skip'])->default('skip');

            $table->string('entity_type', 40)->nullable();
            $table->uuid('entity_id')->nullable();

            // Cómo estaba la entidad antes de tocarla. Es lo único que permite
            // deshacer una actualización sin adivinar.
            $table->jsonb('before')->nullable();

            $table->boolean('applied')->default(false);
            $table->boolean('reverted')->default(false);
            $table->string('revert_error', 255)->nullable();

            $table->timestampsTz(6);

            $table->unique(['batch_id', 'row_number']);
            $table->index(['batch_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imp_rows');
        Schema::dropIfExists('imp_batches');
    }
};
