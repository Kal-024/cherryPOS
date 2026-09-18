<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades de medida (G-08).
 *
 * Alineadas con `ProductUom` de cherryB por decisión de Q-02: el que llega
 * primero define, y si el ERP ya está instalado el POS adopta su modelo. La
 * conversión es la misma — `1 uom = (conv_num / conv_den)` unidades base, con la
 * fila de la unidad base existiendo siempre con 1/1.
 *
 * Necesario en ferretería y también en farmacia: vender unidades sueltas de una
 * caja o un bote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cat_uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 12)->unique();
            $table->string('name', 60);
            // Decimales admitidos al vender: "2,5 metros" sí, "2,5 unidades" no.
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->unsignedInteger('erp_uom_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_uoms');
    }
};
