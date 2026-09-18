<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración tipada y jerárquica (D-12).
 *
 * Dos correcciones sobre el patrón de OSPOS, que guardaba ~140 claves de texto
 * plano sin tipo ni validación y todas globales:
 *
 *  1. **Tipada.** `value_type` permite validar y convertir; una clave booleana
 *     mal escrita falla al guardarse, no al leerse en medio de una venta.
 *  2. **Jerárquica** (P-05): `business` → `branch` → `terminal`. El valor más
 *     específico gana. Una cadena con tres locales necesita impresora distinta
 *     por local y política fiscal común.
 *
 * `scope_id` es nulo en el nivel `business` porque no hay a qué apuntar: no
 * existe multi-tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmn_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('scope', ['business', 'branch', 'terminal']);
            $table->uuid('scope_id')->nullable();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->enum('value_type', ['string', 'integer', 'decimal', 'boolean', 'json', 'enum'])
                ->default('string');
            $table->string('group', 60)->default('general');
            // Qué acepta la clave: lista de opciones de un enum, mínimo y
            // máximo de un número. Lo usa la interfaz y la validación.
            $table->jsonb('constraints')->nullable();
            $table->timestampsTz(6);

            // Una clave no puede estar definida dos veces en el mismo nivel.
            $table->unique(['scope', 'scope_id', 'key']);
            $table->index(['key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmn_settings');
    }
};
