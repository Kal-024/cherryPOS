<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de Sanctum.
 *
 * Dos cambios sobre la migración que publica el paquete:
 *
 *  1. **`uuidMorphs`** en vez de `morphs`. La identidad autenticada de
 *     cherryPOS es la `Terminal`, y su clave es UUID v7 como la de toda entidad
 *     de dominio (precondición 1). Con el `bigint` original, autenticar una
 *     terminal falla con "invalid input syntax for type bigint".
 *  2. **Marcas de tiempo con zona** (precondición 4), como el resto del
 *     esquema.
 *
 * La tabla conserva su `id` autoincremental: es andamiaje del framework, no una
 * entidad transaccional que vaya a replicarse a casa matriz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable()->index();
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
