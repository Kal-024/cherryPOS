<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario: ubicaciones, lotes y el libro mayor de existencias.
 *
 * **B-03, el principio que gobierna todo esto: el stock es el resultado de
 * sumar movimientos, no un número editable.** No existe una columna
 * `cantidad_actual` que alguien pueda corregir a mano. Un ajuste es un
 * movimiento más, con su empleado, su fecha y su comentario — lo mismo que una
 * venta o una recepción. Es la misma idea que la inmutabilidad de los
 * documentos (A-05), aplicada al inventario.
 *
 * Multi-almacén desde el día uno (B-07): stock por ítem **y por ubicación**,
 * con permisos otorgados por ubicación. Es una de las cuatro decisiones que
 * resultan carísimas de agregar más tarde.
 *
 * Lotes y vencimientos (G-07) entran en el modelo desde el inicio aunque la
 * salida por primero-en-vencer se implemente en H1.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            // De dónde sale la mercancía de un ticket si no se dice otra cosa.
            $table->boolean('is_sales_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'code']);
        });

        Schema::create('inv_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('cat_products')->cascadeOnDelete();
            $table->string('code', 60);
            $table->date('expires_on')->nullable();
            $table->date('manufactured_on')->nullable();
            $table->timestampsTz(6);

            $table->unique(['product_id', 'code']);
            $table->index('expires_on');
        });

        Schema::create('inv_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inv_locations')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('cat_products')->restrictOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('inv_lots')->nullOnDelete();

            // sale, refund, receipt, transfer_in, transfer_out, adjustment, count
            $table->string('reason', 30);
            // Positiva entra, negativa sale. El saldo es la suma, sin más.
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();

            // Qué documento lo originó, sin clave foránea: el movimiento
            // sobrevive al documento y apunta a tablas distintas.
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();

            $table->foreignUuid('employee_id')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->string('comment', 255)->nullable();

            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6)->useCurrent();

            $table->index(['product_id', 'location_id']);
            $table->index(['branch_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });

        // El kardex no se corrige, se compensa con otro movimiento. Igual que la
        // bitácora de auditoría, el candado vive en la base: una regla que solo
        // vive en el código se salta con un `tinker`.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION inv_movements_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'inv_movements es de solo inserción: un error se corrige con un movimiento de ajuste, no editando el histórico (%)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER inv_movements_no_update_delete
            BEFORE UPDATE OR DELETE ON inv_movements
            FOR EACH ROW EXECUTE FUNCTION inv_movements_append_only();
        SQL);

        /**
         * Saldo cacheado por producto, ubicación y lote.
         *
         * **No es la fuente de verdad** — lo es `inv_movements`. Existe porque
         * sumar el libro entero en cada tecla del buscador no escala, y se
         * recalcula desde los movimientos cuando haga falta. Que sea derivable
         * es justamente lo que lo hace seguro.
         */
        Schema::create('inv_stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('location_id')->constrained('inv_locations')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('cat_products')->cascadeOnDelete();
            $table->foreignUuid('lot_id')->nullable()->constrained('inv_lots')->cascadeOnDelete();
            $table->decimal('qty', 18, 4)->default(0);
            $table->timestampTz('recalculated_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['location_id', 'product_id', 'lot_id'], 'inv_stock_balances_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_balances');
        DB::statement('DROP TRIGGER IF EXISTS inv_movements_no_update_delete ON inv_movements');
        DB::statement('DROP FUNCTION IF EXISTS inv_movements_append_only()');
        Schema::dropIfExists('inv_movements');
        Schema::dropIfExists('inv_lots');
        Schema::dropIfExists('inv_locations');
    }
};
