<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes y crédito simple.
 *
 * **Dos clases de cliente (P-03), y la distinción es toda la tabla.** El
 * cliente de efectivo paga y se va: no se le pide cédula, por razones obvias.
 * El cliente con cuenta apertura un crédito, da su cédula al pagar y cancela a
 * fin de mes: ahí la cédula es obligatoria y es además su código de cliente
 * (G-10). La venta anónima queda permitida siempre.
 *
 * **Alcance del crédito en el POS (P-02): reducido a propósito.** Límite,
 * saldo, consumo, pago en caja, autorizados y vista de supervisor. Intereses,
 * planes de pago y cobranza son del módulo de crédito del ERP. Si el cliente
 * solo tiene el POS, esto le alcanza para operar.
 *
 * **Quién crea clientes (Q-03):** el supervisor. En caja solo se usan clientes
 * ya registrados. Integrado el ERP, la creación pasa al ERP y el cajero sigue
 * operando con cada cliente activo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Identidad compartida (B-11). La cédula —código del cliente con
            // cuenta, G-10— y el RUC —que hace que el ERP emita `FCF` en vez de
            // `CSI`, §2.D del contrato— viven en la persona.
            $table->foreignUuid('person_id')->constrained('cmn_persons')
                ->cascadeOnUpdate()->restrictOnDelete();
            // `cash` no exige cédula; `account` sí, y datos completos.
            $table->enum('kind', ['cash', 'account'])->default('cash');
            $table->string('code', 30)->nullable()->unique();

            // Exoneración de impuesto del cliente (Q-07). Distinta de la
            // exención del producto: una es del comprador, otra del bien.
            $table->boolean('is_tax_exempt')->default(false);
            $table->string('tax_exempt_reference', 60)->nullable();
            // Descuento propio del cliente (D-02).
            $table->decimal('discount_percent', 7, 4)->nullable();

            // Consentimiento explícito. Barato de agregar ahora, obligatorio el
            // día que se haga cualquier comunicación comercial (D-09).
            $table->boolean('consent_email')->default(false);
            $table->boolean('consent_whatsapp')->default(false);
            $table->timestampTz('consent_given_at', 6)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('erp_customer_id')->nullable();
            $table->timestampsTz(6);

            // Una persona puede ser cliente una sola vez.
            $table->unique('person_id');
        });

        /**
         * Personas autorizadas a consumir contra la cuenta: **hasta tres**
         * (G-10), todas con datos completos. El límite se aplica en el servicio
         * porque un `CHECK` sobre el conteo no es expresable aquí sin un
         * disparador que complicaría la importación masiva.
         */
        Schema::create('crm_customer_authorized', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            // El autorizado también es una persona: el día que abra su propia
            // cuenta, ya está identificado (B-11).
            $table->foreignUuid('person_id')->constrained('cmn_persons')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->string('relationship', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['customer_id', 'person_id']);
        });

        Schema::create('crm_credit_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->decimal('credit_limit', 18, 2)->default(0);
            // Saldo cacheado. La fuente de verdad es `crm_credit_entries`.
            $table->decimal('balance', 18, 2)->default(0);
            // Fecha de corte **configurable por cliente** (Q-10): día del mes.
            $table->unsignedTinyInteger('cut_off_day')->default(30);
            // El bloqueo es manual del supervisor (Q-10). El límite, en cambio,
            // bloquea la venta por su cuenta hasta que baje lo facturado.
            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason', 255)->nullable();
            $table->foreignUuid('blocked_by')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->timestampTz('blocked_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique('customer_id');
        });

        /**
         * Movimientos de la cuenta corriente. Solo inserción, como todo lo que
         * toca dinero: un consumo mal cargado se corrige con un ajuste, no
         * editándolo.
         */
        Schema::create('crm_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('crm_credit_accounts')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            // charge = consumo · payment = pago en caja · adjustment = corrección
            $table->enum('kind', ['charge', 'payment', 'adjustment']);
            $table->decimal('amount', 18, 2);
            $table->uuid('sale_id')->nullable();
            // Quién de los autorizados consumió. Nulo = el titular.
            $table->foreignUuid('authorized_id')->nullable()->constrained('crm_customer_authorized')->nullOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('sec_employees')->nullOnDelete();
            $table->string('comment', 255)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6)->useCurrent();

            $table->index(['account_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_credit_entries');
        Schema::dropIfExists('crm_credit_accounts');
        Schema::dropIfExists('crm_customer_authorized');
        Schema::dropIfExists('crm_customers');
    }
};
