<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salón, modificadores y comandas — el perfil `restaurant` (F1-B, A-04, D-01).
 *
 * Tres decisiones del plan se ven directamente en este esquema:
 *
 *  1. **La cuenta abierta no tiene tablas espejo.** Es una venta en estado
 *     `suspendida` con una mesa asociada (D-01). Por eso acá no hay
 *     `pos_table_orders`: hay `dining_table_id` en `pos_sales` y nada más. Un
 *     modelo paralelo obligaría a mantener dos verdades del mismo importe.
 *  2. **Las mesas unidas son una sola unidad.** `merged_into_id` apunta a la
 *     mesa que manda; las unidas dejan de aceptar cuenta propia. Modelarlo como
 *     una tabla de grupos costaría una consulta más en cada refresco del mapa
 *     para responder lo mismo.
 *  3. **Los modificadores obligatorios bloquean el envío a cocina**, así que su
 *     obligatoriedad vive en el grupo y no en la pantalla: una regla que solo
 *     existe en la interfaz se salta con la primera terminal nueva.
 *
 * La comanda **sí** es entidad propia, a diferencia de la cuenta: lo que va a
 * cocina no es la venta —que sigue creciendo— sino un envío concreto, y la
 * cocina necesita saber qué le mandaron a las 20:14 aunque después se agreguen
 * dos postres.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Salón ───────────────────────────────────────────────────────────
        Schema::create('pos_dining_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'code']);
        });

        Schema::create('pos_dining_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->cascadeOnDelete();
            $table->foreignUuid('area_id')->nullable()->constrained('pos_dining_areas')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name', 60)->nullable();
            // Sillas: el mapa muestra cuántas caben y el mesero sabe si le
            // entra el grupo que acaba de llegar.
            $table->unsignedSmallInteger('seats')->default(4);

            /*
             * Posición en el mapa, en una cuadrícula abstracta.
             *
             * El plan pide el mapa **según la disposición real del local**
             * (§10): sin coordenadas, el salón se vuelve una lista de botones y
             * el mesero tiene que traducir "Mesa 7" a un lugar físico.
             */
            $table->integer('pos_x')->default(0);
            $table->integer('pos_y')->default(0);
            $table->enum('shape', ['square', 'round', 'rect'])->default('square');

            // Mesas unidas: la que manda y las que quedan absorbidas.
            $table->uuid('merged_into_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'area_id']);
        });

        Schema::table('pos_dining_tables', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('pos_dining_tables')->nullOnDelete();
        });

        /*
         * La cuenta abierta vive en `pos_sales`.
         *
         * `guests` y el mesero son del servicio, no del documento: el ERP no los
         * necesita, pero sin ellos el salón no puede responder "¿cuántos son?" ni
         * "¿de quién es esta mesa?", que es la mitad del trabajo de un turno.
         */
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->foreignUuid('dining_table_id')->nullable()->after('customer_id')
                ->constrained('pos_dining_tables')->nullOnDelete();
            $table->unsignedSmallInteger('guests')->nullable()->after('dining_table_id');
            $table->foreignUuid('waiter_employee_id')->nullable()->after('guests')
                ->constrained('sec_employees')->nullOnDelete();

            $table->index(['dining_table_id', 'status']);
        });

        // ── Modificadores (B-06) ────────────────────────────────────────────
        Schema::create('cat_modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            /*
             * `min_select` mayor que cero es lo que hace obligatorio al grupo, y
             * es lo que bloquea el envío a cocina: "¿término de la carne?" no
             * admite que el mesero siga de largo.
             */
            $table->unsignedSmallInteger('min_select')->default(0);
            // Null = sin tope. Uno es el caso común: elegir **un** término.
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
        });

        Schema::create('cat_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained('cat_modifier_groups')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 80);
            /*
             * Diferencia sobre el precio de la línea, con signo: "sin cebolla"
             * no cobra y "doble queso" sí. Va en la moneda base y con el mismo
             * criterio que el precio de catálogo — impuesto incluido — porque se
             * suma al precio de góndola antes de calcular.
             */
            $table->decimal('price_delta', 18, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['group_id', 'code']);
        });

        Schema::create('cat_product_modifier_group', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained('cat_products')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('cat_modifier_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['product_id', 'group_id']);
        });

        /*
         * Lo elegido en una línea concreta, **copiado**: nombre y precio se
         * congelan igual que los impuestos de la línea (P1). Si mañana el
         * "doble queso" sube, el ticket de ayer tiene que seguir explicándose
         * solo.
         */
        Schema::create('pos_sale_line_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_line_id')->constrained('pos_sale_lines')->cascadeOnDelete();
            $table->foreignUuid('modifier_id')->nullable()->constrained('cat_modifiers')->nullOnDelete();
            $table->string('name', 80);
            $table->decimal('price_delta', 18, 2)->default(0);
            $table->timestampsTz(6);

            $table->index('sale_line_id');
        });

        // ── Comandas y KDS ──────────────────────────────────────────────────
        Schema::create('pos_kitchen_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('cmn_branches')->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('cmn_terminals')->nullOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('sec_employees')->nullOnDelete();

            // Cocina y barra imprimen aparte: la cerveza no espera al lomo.
            $table->string('destination', 30)->default('kitchen');
            // Correlativo visible del día, para que cocina y salón hablen del
            // mismo papel sin leer un UUID.
            $table->unsignedInteger('number');
            $table->enum('status', ['queued', 'preparing', 'ready', 'served', 'cancelled'])
                ->default('queued');
            $table->string('notes', 255)->nullable();

            $table->timestampTz('sent_at', 6);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('ready_at', 6)->nullable();
            $table->timestampTz('served_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['branch_id', 'destination', 'number']);
            $table->index(['branch_id', 'destination', 'status']);
            $table->index(['sale_id', 'status']);
        });

        Schema::create('pos_kitchen_ticket_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained('pos_kitchen_tickets')->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->nullable()->constrained('pos_sale_lines')->nullOnDelete();

            // Copia del nombre y de los modificadores: la comanda tiene que
            // poder leerse aunque la línea se anule después, y cocina no
            // consulta el catálogo.
            $table->string('name', 200);
            $table->decimal('qty', 18, 4);
            $table->jsonb('modifiers')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestampsTz(6);

            $table->index('ticket_id');
        });

        DB::table('cmn_schema_version')->where('id', 1)->update([
            'version' => '1.1.0',
            'applied_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_kitchen_ticket_lines');
        Schema::dropIfExists('pos_kitchen_tickets');
        Schema::dropIfExists('pos_sale_line_modifiers');
        Schema::dropIfExists('cat_product_modifier_group');
        Schema::dropIfExists('cat_modifiers');
        Schema::dropIfExists('cat_modifier_groups');

        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropIndex(['dining_table_id', 'status']);
            $table->dropConstrainedForeignId('dining_table_id');
            $table->dropConstrainedForeignId('waiter_employee_id');
            $table->dropColumn('guests');
        });

        Schema::table('pos_dining_tables', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
        });

        Schema::dropIfExists('pos_dining_tables');
        Schema::dropIfExists('pos_dining_areas');

        DB::table('cmn_schema_version')->where('id', 1)->update([
            'version' => '1.0.0',
            'applied_at' => now(),
        ]);
    }
};
