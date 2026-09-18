<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde se prepara cada ítem y qué pidió el cliente aparte (F1-B).
 *
 * **`prep_station` es del producto, no de la pantalla.** La cerveza sale de la
 * barra y el lomo de la cocina, y cada uno imprime su comanda: sin esto, o la
 * barra recibe pedidos de cocina que no le tocan, o alguien tiene que elegir el
 * destino a mano en cada envío y se equivoca en la hora pico. `null` significa
 * "no se prepara": una gaseosa de heladera no va a ninguna comanda.
 *
 * `notes` en la línea es el "sin sal" y el "término medio" que no entra en
 * ningún modificador. Vive en la línea y no en la comanda porque sobrevive a los
 * envíos: si se agrega un plato después, la nota del primero sigue ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cat_products', function (Blueprint $table) {
            $table->string('prep_station', 30)->nullable()->after('tracks_stock');
        });

        Schema::table('pos_sale_lines', function (Blueprint $table) {
            $table->string('notes', 255)->nullable()->after('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_lines', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('cat_products', function (Blueprint $table) {
            $table->dropColumn('prep_station');
        });
    }
};
