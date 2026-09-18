<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versión de esquema de la instalación (precondición 5 del modelo de datos).
 *
 * Con instalaciones en sitio las sucursales se desactualizan a distinto ritmo.
 * La replicación hacia casa matriz y la cola hacia el ERP tienen que poder
 * **fallar de forma visible** al detectar una versión que no entienden, en vez
 * de sincronizar a medias y descubrirlo meses después.
 *
 * Una sola fila. Se lee por API (`GET /api/system/version`) y la escribe el
 * instalador, no la aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmn_schema_version', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->string('version', 20);
            $table->string('app_version', 20)->nullable();
            $table->timestampTz('applied_at', 6);
        });

        DB::table('cmn_schema_version')->insert([
            'id' => 1,
            'version' => '1.0.0',
            'app_version' => null,
            'applied_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cmn_schema_version');
    }
};
