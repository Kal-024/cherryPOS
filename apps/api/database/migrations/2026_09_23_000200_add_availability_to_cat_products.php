<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lista 86: lo que se acabó hoy (F1-B).
 *
 * Del argot de cocina —*«86 the salmon»*, dejen de venderlo— y es el mecanismo
 * estándar de todo POS de restaurante. Sin él, la única herramienta era
 * `is_active`, que es **baja de catálogo**: saca el producto de los reportes y de
 * las importaciones, y alguien tiene que acordarse de reactivarlo. El día que no
 * lo haga, el plato desaparece del menú sin que nadie sepa por qué.
 *
 * **Es una capa aparte del catálogo, efímera y con otro dueño.** El catálogo lo
 * mantiene la administración —o el ERP, cuando existe— y cambia cuando cambia el
 * menú o el precio. La disponibilidad del día la marca quien está en la cocina y
 * se vence sola al abrir el turno siguiente.
 *
 * **Una fecha y no un booleano.** Con `unavailable_since` la reposición es «todo
 * lo marcado antes de que abriera este turno vuelve», que se resuelve al abrir
 * caja sin un trabajo programado que alguien tenga que mantener vivo. Un booleano
 * obligaría a recordar *cuándo* se marcó en otra columna, o a un cron.
 *
 * Estas dos columnas son **del local, no del ERP**: `ErpMasterSyncService` escribe
 * una lista blanca de campos (nombre, precio, unidad, exento, activo) y no las
 * toca, así que un pull de maestros no revive un plato que se acabó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cat_products', function (Blueprint $table) {
            $table->timestampTz('unavailable_since', 6)->nullable()->after('is_active');
            // Quién lo marcó: en un reclamo —"me dijeron que no había y sí
            // había"— es la única forma de saber a quién preguntarle.
            $table->foreignUuid('unavailable_by')->nullable()
                ->after('unavailable_since')
                ->constrained('sec_employees')->nullOnDelete();

            $table->index('unavailable_since');
        });
    }

    public function down(): void
    {
        Schema::table('cat_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unavailable_by');
            $table->dropIndex(['unavailable_since']);
            $table->dropColumn('unavailable_since');
        });
    }
};
