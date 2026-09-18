<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de comprobante (D-11).
 *
 * OSPOS resuelve esto con **veinte banderas de configuración**: mostrar logo,
 * mostrar número de serie, desglosar impuestos, márgenes, tamaño de fuente. La
 * cantidad parece excesiva hasta que uno se enfrenta al parque real de
 * impresoras térmicas — pero sigue siendo veinte banderas que solo cubren lo que
 * alguien anticipó.
 *
 * Aquí la plantilla es **dato**: una lista de bloques que se ordenan, se
 * configuran y se guardan. Cambiar cómo se ve la factura de un cliente no
 * requiere un despliegue, y lo que nadie anticipó se resuelve agregando un
 * bloque, no una bandera.
 *
 * `content` guarda esos bloques. El renderizador los convierte en líneas con
 * estilo, que es la misma forma que consumirá el agente de impresión térmica
 * cuando exista (Q-05): hoy esas líneas se vuelven PDF, mañana ESC/POS, sin
 * tocar la plantilla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipt_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null = plantilla del negocio, válida para todas las sucursales.
            $table->foreignUuid('branch_id')->nullable()->constrained('cmn_branches')->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('name', 120);

            // Qué documento imprime: un presupuesto y una nota de crédito no se
            // ven igual, y el corte de turno tampoco.
            $table->string('document_type', 30);

            // El ancho manda sobre casi todo el diseño. `thermal_58` deja unos
            // 32 caracteres por línea y `thermal_80` unos 48: lo que entra en
            // una no entra en la otra.
            $table->enum('paper', ['thermal_58', 'thermal_80', 'letter', 'a4'])
                ->default('thermal_80');

            $table->jsonb('content');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'code']);
            $table->index(['document_type', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_templates');
    }
};
