<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monedas y tipo de cambio (Q-06).
 *
 * Pagar en dólares en caja es rutina en Nicaragua: el cliente entrega dólares,
 * se cobra a la tasa del día y el vuelto sale en córdobas. No es multi-moneda
 * contable — es operación de caja, y por eso sube a F1.
 *
 * La tasa la carga el supervisor y **rige hasta que se actualice**: mientras no
 * cargue una nueva, vale la última. Con ERP vinculado sale de su tabla de tipos
 * de cambio. El histórico se conserva porque el pago guarda la tasa que aplicó
 * y hay que poder releer un ticket de hace un mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmn_currencies', function (Blueprint $table) {
            $table->string('code', 3)->primary();
            $table->string('name', 60);
            $table->string('symbol', 6);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
        });

        Schema::create('cmn_exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('cmn_currencies')
                ->cascadeOnUpdate()->restrictOnDelete();
            // Unidades de moneda base por una unidad de esta moneda.
            $table->decimal('rate', 18, 6);
            $table->timestampTz('effective_from', 6);
            $table->foreignUuid('created_by')->nullable();
            $table->string('source', 20)->default('manual'); // manual | erp
            $table->timestampsTz(6);

            $table->index(['currency_code', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmn_exchange_rates');
        Schema::dropIfExists('cmn_currencies');
    }
};
