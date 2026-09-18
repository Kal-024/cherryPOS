<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos categorizados (B-14).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad."* Está confirmado en la
 * Parte I con 21/25 y se había quedado sin hito asignado en el plan: entra aquí,
 * junto al turno de caja, porque **un gasto pagado del cajón es un movimiento de
 * caja**. Modelarlo aparte obligaría a cuadrar dos veces la misma plata.
 *
 * El proveedor es de tipo `expense` (B-12): la luz y el alquiler no son compras
 * de mercadería, y mezclarlos hace que el reporte de compras incluya la factura
 * del agua.
 *
 * El impuesto se guarda aparte del monto porque el IVA de un gasto es crédito
 * fiscal, no costo — es la mitad del sentido de registrarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exp_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            // Un gasto que no varía con la venta —alquiler— se lee distinto de
            // uno que sí —comisiones—. La distinción la pide cualquier análisis
            // de punto de equilibrio.
            $table->enum('behaviour', ['fixed', 'variable'])->default('fixed');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
        });

        Schema::create('exp_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('category_id')->constrained('exp_categories')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('crm_suppliers')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('sec_employees')->restrictOnDelete();

            // Si salió del cajón, queda atado al turno y al movimiento de caja:
            // el arqueo tiene que poder explicarlo.
            $table->foreignUuid('shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained('pos_cash_movements')->nullOnDelete();

            $table->string('document_number', 40)->nullable();
            $table->date('document_date');
            $table->string('description', 255);

            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('amount', 18, 2);
            // El IVA de un gasto es crédito fiscal, no costo.
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('total_base', 18, 2);

            $table->enum('payment_method', ['cash', 'card', 'transfer', 'credit', 'other'])
                ->default('cash');

            $table->enum('status', ['recorded', 'voided'])->default('recorded');
            $table->string('void_reason', 255)->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('sec_employees')->nullOnDelete();

            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6)->useCurrent();
            $table->timestampsTz(6);

            $table->index(['branch_id', 'document_date']);
            $table->index(['category_id', 'document_date']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exp_expenses');
        Schema::dropIfExists('exp_categories');
    }
};
