<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminales de caja.
 *
 * Primera mitad de la doble credencial (D-05): la terminal se autentica con
 * credenciales reales —código y secreto— y conserva un token; **facturar exige
 * además el PIN del cajero**. Así un cajero cierra su sesión de PIN y otro
 * entra en la misma terminal sin reautenticar el equipo.
 *
 * Anticipa la tabla `pos_terminals` que el ERP ya declaró (§2 del contrato):
 * cuando exista, `layout_profile` de la terminal sobrescribirá el defecto de la
 * empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmn_terminals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            // Secreto de la terminal, con hash. Nunca viaja de vuelta.
            $table->string('secret_hash');
            // Perfil de pantalla (A-04). Null = hereda el de la sucursal.
            $table->enum('layout_profile', ['scan_first', 'touch_grid', 'restaurant'])->nullable();
            $table->timestampTz('authenticated_at', 6)->nullable();
            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmn_terminals');
    }
};
