<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles con herencia y overrides individuales (D-13, B-13).
 *
 * OSPOS asigna cada permiso persona por persona; con 40 empleados es
 * inmanejable, y agregar roles después obliga a migrar todas las asignaciones
 * existentes. Por eso entra desde F1.
 *
 * Tres niveles conviven:
 *
 *  - **Rol** — el grueso. Cajero, supervisor, encargado, administrador.
 *  - **Override individual** — la excepción, que puede **conceder o revocar**
 *    (`effect`). Sin la revocación, quitarle un permiso a una persona obligaría
 *    a inventarle un rol propio.
 *  - **Alcance por ubicación** — B-13: los permisos se otorgan por sucursal, y
 *    un cajero de la sucursal 2 no ve datos de la 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sec_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // `recurso.accion`, igual que en cherryB: `pos_sale.create`.
            $table->string('code', 80)->unique();
            $table->string('module', 40);
            $table->string('description', 160)->nullable();
            $table->timestampsTz(6);

            $table->index('module');
        });

        Schema::create('sec_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 160)->nullable();
            // Los roles del sistema no se borran ni se renombran: hay código
            // que depende de ellos.
            $table->boolean('is_system')->default(false);
            $table->timestampsTz(6);
        });

        Schema::create('sec_role_permission', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('sec_roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('sec_permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sec_role_permission');
        Schema::dropIfExists('sec_roles');
        Schema::dropIfExists('sec_permissions');
    }
};
