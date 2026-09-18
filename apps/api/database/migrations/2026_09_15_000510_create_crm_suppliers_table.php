<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores (B-12).
 *
 * **Dos tipos, y la distinción ordena la contabilidad de egresos:** el de
 * mercadería vende lo que después se revende; el de gastos vende luz, alquiler
 * o papelería. Mezclarlos hace que el reporte de compras incluya la factura del
 * agua, y entonces el margen deja de significar nada.
 *
 * Comparte la entidad persona con clientes y empleados (B-11): el ferretero que
 * además le compra al negocio es una sola persona con dos roles.
 *
 * En F1 el proveedor es **dato maestro**: sirve para la importación inicial
 * (D-15), para categorizar gastos (B-14) y para la recepción de mercadería. Las
 * compras completas son del ERP (D-08, G-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->constrained('cmn_persons')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 30)->nullable()->unique();
            // merchandise — mercadería para revender.
            // expense      — servicios y gastos operativos.
            $table->enum('kind', ['merchandise', 'expense'])->default('merchandise');
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->string('contact_name', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('erp_supplier_id')->nullable();
            $table->timestampsTz(6);

            $table->unique('person_id');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_suppliers');
    }
};
