<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de negocio (A-04).
 *
 * Es el mecanismo de extensión de los verticales, y lo que convierte "un POS
 * configurable" en "el POS para farmacias". No son banderas sueltas como en
 * OSPOS: un perfil **preconfigura** módulos activos, esquema de atributos de
 * producto, plantillas, vocabulario visible y perfil de pantalla.
 *
 * `vocabulary` resuelve D-01: en restaurante, "suspender una venta" se llama
 * "cuenta abierta", porque lo natural allí es comer y pagar después. Es el
 * mismo estado con otro nombre, no otro mecanismo.
 *
 * `attribute_schema` es la validación de la columna JSON de producto (D-24):
 * una farmacia agrega "principio activo" sin migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cat_business_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();   // retail, restaurant, gym, pharmacy, hardware
            $table->string('name', 80);
            $table->jsonb('modules')->nullable();          // qué se enciende
            $table->jsonb('attribute_schema')->nullable(); // atributos de producto
            $table->jsonb('vocabulary')->nullable();       // claves i18n sobrescritas
            $table->enum('layout_profile', ['scan_first', 'touch_grid', 'restaurant'])
                ->default('scan_first');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_business_profiles');
    }
};
