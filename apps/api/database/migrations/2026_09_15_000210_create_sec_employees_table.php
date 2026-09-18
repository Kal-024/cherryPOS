<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empleados: la persona que opera la caja.
 *
 * Segunda mitad de la doble credencial (D-05). `password_hash` sirve para el
 * ingreso administrativo por navegador; `pin_hash` identifica al operador
 * **dentro de una sesión de terminal ya autenticada**.
 *
 * > **Nota de seguridad.** Un PIN de cuatro dígitos no es una credencial
 * > fuerte y nunca es el único factor de acceso al sistema desde fuera del
 * > local. Solo es aceptable porque la terminal ya está autenticada contra el
 * > backend con credenciales reales y existe bloqueo tras intentos fallidos.
 *
 * `supervisor_pin_hash` es **otro PIN, distinto del de sesión** (P-11): el de
 * autorizar un descuento sobre el tope o un sexto ítem temporal no puede ser el
 * mismo con el que el supervisor abre su propia caja.
 *
 * `discount_limit_percent` implementa D-02: varios cajeros pueden tener el
 * permiso de descuento y cada uno con un tope distinto, que fija el supervisor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sec_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // La identidad vive en `cmn_persons` (B-11): nombre, cédula,
            // teléfono y correo son de la persona, no del rol. Único porque una
            // persona es un empleado, no varios.
            $table->foreignUuid('person_id')->unique()->constrained('cmn_persons')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 20);

            // Ingreso administrativo por navegador.
            $table->string('password_hash')->nullable();
            // Identificación del operador en una terminal ya autenticada.
            $table->string('pin_hash')->nullable();
            // PIN de autorización del supervisor — distinto del de sesión (P-11).
            $table->string('supervisor_pin_hash')->nullable();

            // Bloqueo por fuerza bruta (B-15, nota de seguridad de D-05).
            $table->unsignedSmallInteger('failed_pin_attempts')->default(0);
            $table->timestampTz('pin_locked_until', 6)->nullable();

            // Tope de descuento propio de este cajero (D-02). Null = sin tope
            // propio: rige el del rol.
            $table->decimal('discount_limit_percent', 7, 4)->nullable();
            // Ítems temporales y ventas por monto permitidos al día (D-03).
            // Null = el defecto del sistema, que son 5.
            $table->unsignedSmallInteger('temp_item_daily_limit')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'code']);
        });

        Schema::create('sec_employee_role', function (Blueprint $table) {
            $table->foreignUuid('employee_id')->constrained('sec_employees')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('sec_roles')->cascadeOnDelete();
            // Rol otorgado en una sucursal concreta. Null = en todas (B-13).
            $table->foreignUuid('branch_id')->nullable()->constrained('cmn_branches')->cascadeOnDelete();

            $table->primary(['employee_id', 'role_id'], 'sec_employee_role_primary');
            $table->index('branch_id');
        });

        Schema::create('sec_employee_permission', function (Blueprint $table) {
            $table->foreignUuid('employee_id')->constrained('sec_employees')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('sec_permissions')->cascadeOnDelete();
            // Sin `deny` no se puede quitarle un permiso a una persona sin
            // inventarle un rol propio.
            $table->enum('effect', ['grant', 'deny'])->default('grant');
            $table->foreignUuid('branch_id')->nullable()->constrained('cmn_branches')->cascadeOnDelete();

            $table->primary(['employee_id', 'permission_id'], 'sec_employee_permission_primary');
        });

        /*
         * Sesión de PIN: quién está operando esta terminal ahora mismo.
         *
         * Es lo que permite el relevo de D-05 — "tipo abrir la calculadora
         * únicamente cuando tenga el PIN": un cajero cierra su sesión y otro
         * entra en el mismo equipo, sin tocar la autenticación de la terminal.
         */
        Schema::create('sec_operator_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('terminal_id')->constrained('cmn_terminals')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('sec_employees')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('closed_at', 6)->nullable();
            // `session_grant` o `cached_hash`, según `pos_offline_pin_mode` del
            // ERP (§7 del contrato).
            $table->string('pin_mode', 20)->default('session_grant');
            $table->timestampsTz(6);

            $table->index(['terminal_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sec_operator_sessions');
        Schema::dropIfExists('sec_employee_permission');
        Schema::dropIfExists('sec_employee_role');
        Schema::dropIfExists('sec_employees');
    }
};
