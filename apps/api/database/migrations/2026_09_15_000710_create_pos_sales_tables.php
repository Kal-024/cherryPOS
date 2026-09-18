<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La venta: un solo modelo para los cinco tipos (B-01).
 *
 * Mostrador, factura, presupuesto, orden de trabajo y devolución conviven en la
 * misma tabla con `sale_type`. Y **la venta suspendida es un estado, no cuatro
 * tablas espejo** (D-01): OSPOS duplicaba `sales`, `sales_items`,
 * `sales_items_taxes` y `sales_payments` para sostener la suspensión, de modo
 * que cada cambio al modelo había que hacerlo dos veces. Aquí `status` vale
 * `suspended` y se acabó. En perfil restaurante ese mismo estado se llama
 * "cuenta abierta" — mismo mecanismo, otro vocabulario.
 *
 * **El carrito vive aquí** (D-21). En OSPOS vivía en `$_SESSION`, con 1.727
 * líneas de lógica de negocio atadas a la sesión HTTP: ninguna venta podía
 * continuarse en otro dispositivo y ningún proceso podía validarla. El carrito
 * es una venta en estado `draft` con identidad propia; el terminal guarda
 * además su copia local y sincroniza.
 *
 * **Los importes se persisten, no se recalculan** (D-16). Si la tasa de IVA
 * cambia el año que viene, las ventas viejas siguen cuadrando. El precio que
 * rige una línea es el vigente al agregarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table) {
            // Generado por el terminal, sin consultar al servidor: es requisito
            // del modo degradado y es el `uuid` que el ERP usa para la
            // idempotencia (§2.B del contrato).
            $table->uuid('id')->primary();

            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('cmn_terminals')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('sec_employees')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();

            $table->enum('sale_type', ['counter', 'invoice', 'quote', 'work_order', 'refund'])
                ->default('counter');
            $table->enum('status', ['draft', 'suspended', 'completed', 'voided'])->default('draft');
            // Etiqueta libre de la cuenta suspendida: "Mesa 4", "Don Julio".
            $table->string('label', 80)->nullable();

            $table->foreignUuid('series_id')->nullable()->constrained('pos_document_series')->nullOnDelete();
            $table->string('number', 40)->nullable();

            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 18, 6)->nullable();

            // Totales calculados por `SaleCalculator` y congelados aquí.
            $table->decimal('gross', 18, 2)->default(0);
            $table->decimal('line_discount_total', 18, 2)->default(0);
            $table->decimal('sale_discount', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            // Base gravada y exento se guardan aparte porque el libro de ventas
            // los declara distinto, aunque sumen el subtotal.
            $table->decimal('taxable_base', 18, 2)->default(0);
            $table->decimal('exempt_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('cash_rounding', 18, 2)->default(0);
            $table->decimal('paid', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->default(0);

            // Descuento de venta tal como lo tecleó el cajero, para poder
            // explicar el importe después.
            $table->enum('sale_discount_type', ['percent', 'amount'])->nullable();
            $table->decimal('sale_discount_value', 18, 4)->nullable();
            // Quién autorizó, si pasó del tope del cajero (D-02, P-11).
            $table->foreignUuid('discount_authorized_by')->nullable()->constrained('sec_employees')->nullOnDelete();

            // Una corrección es un documento de reverso, nunca una edición (P1).
            // La clave foránea a sí misma se agrega después de crear la tabla
            // (ver `cat_categories`).
            $table->uuid('reverses_sale_id')->nullable();
            $table->string('void_reason', 255)->nullable();

            // ── Andamio fiscal (P-08) ───────────────────────────────────────
            // Campos reservados para el día que exista facturación electrónica.
            // Hoy nadie los escribe; agregarlos después costaría una migración
            // sobre una tabla con millones de filas.
            $table->unsignedBigInteger('erp_document_id')->nullable();
            $table->string('erp_document_number', 40)->nullable();
            $table->enum('erp_status', ['pending', 'sent', 'issued', 'draft', 'exception'])
                ->default('pending');
            $table->decimal('erp_tax_difference', 18, 2)->nullable();
            $table->string('fiscal_external_id', 64)->nullable();
            $table->string('fiscal_status', 30)->nullable();
            $table->jsonb('fiscal_response')->nullable();

            $table->timestampTz('opened_at', 6);
            $table->timestampTz('closed_at', 6)->nullable();
            $table->timestampTz('recorded_at', 6)->useCurrent();
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'status', 'closed_at']);
            $table->index(['shift_id', 'status']);
            $table->index(['terminal_id', 'status']);
            $table->index('erp_status');
        });

        Schema::table('pos_sales', function (Blueprint $table) {
            $table->foreign('reverses_sale_id')->references('id')->on('pos_sales')->nullOnDelete();
        });

        Schema::create('pos_sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->cascadeOnDelete();
            // Denormalizado desde el encabezado por la precondición 2: toda
            // entidad transaccional lleva su sucursal, para que la replicación
            // hacia casa matriz filtre sin recorrer relaciones.
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');

            // Nulo en el ítem temporal y en la venta por monto (D-03): son las
            // válvulas de escape del catálogo, y por eso llevan límite diario y
            // notificación al supervisor.
            $table->foreignUuid('product_id')->nullable()->constrained('cat_products')->nullOnDelete();
            $table->string('item_code', 50)->nullable();
            // Obligatoria siempre: el cajero debe nombrar lo que vendió (D-03).
            $table->string('description', 200);
            $table->enum('kind', ['product', 'temporary', 'amount', 'instrument'])->default('product');

            $table->foreignUuid('uom_id')->nullable()->constrained('cat_uoms')->nullOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();

            $table->enum('discount_type', ['percent', 'amount'])->nullable();
            $table->decimal('discount_value', 18, 4)->nullable();
            $table->decimal('line_discount', 18, 2)->default(0);
            $table->decimal('sale_discount_share', 18, 2)->default(0);

            $table->decimal('gross', 18, 2)->default(0);
            $table->decimal('taxable_base', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->boolean('is_exempt')->default(false);

            $table->foreignUuid('lot_id')->nullable()->constrained('inv_lots')->nullOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('inv_locations')->nullOnDelete();

            // Marca de combo explotado (§4 del contrato): el POS manda los
            // componentes sueltos con el descuento repartido, y esto es lo
            // único que los relaciona. El ERP no necesita saber qué es un combo.
            $table->string('group_ref', 64)->nullable();
            $table->string('group_name', 100)->nullable();

            $table->timestampsTz(6);

            $table->unique(['sale_id', 'sequence']);
            $table->index('product_id');
            $table->index('group_ref');
        });

        /**
         * Impuesto **persistido por línea** (D-16). Es el principio innegociable
         * del motor: si la tasa cambia, las ventas viejas siguen cuadrando
         * porque nadie las recalcula.
         */
        Schema::create('pos_sale_line_taxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_line_id')->constrained('pos_sale_lines')->cascadeOnDelete();
            $table->foreignUuid('tax_code_id')->nullable()->constrained('cat_tax_codes')->nullOnDelete();
            $table->string('code', 20);
            $table->decimal('rate', 9, 4);
            $table->decimal('base', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->timestampsTz(6);

            $table->index('sale_line_id');
        });

        /**
         * Pagos en tabla aparte del encabezado (B-02): varios por venta, con
         * medio, referencia y saldo pendiente calculado.
         *
         * Cada pago guarda **moneda recibida, tasa aplicada y equivalente en
         * moneda base** (Q-06). Sin la tasa persistida, releer un ticket de hace
         * un mes daría otro número.
         */
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->enum('method', ['cash', 'card', 'credit', 'transfer', 'other']);
            $table->string('currency_code', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('amount_base', 18, 2);
            // Vuelto: sale en moneda base aunque el pago haya entrado en dólares.
            $table->boolean('is_change')->default(false);
            $table->string('reference', 80)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('authorization_code', 40)->nullable();
            $table->timestampTz('paid_at', 6);
            $table->timestampsTz(6);

            $table->index(['sale_id', 'method']);
            $table->index(['shift_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos_sale_line_taxes');
        Schema::dropIfExists('pos_sale_lines');
        Schema::dropIfExists('pos_sales');
    }
};
