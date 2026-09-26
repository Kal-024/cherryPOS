<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tamaño de cada mesa en el plano (F1-B, §10).
 *
 * El plan pide el mapa **según la disposición real del local**, y un local real
 * no tiene todas las mesas iguales: hay una redonda de seis, dos cuadradas de
 * cuatro y la larga del fondo donde caben doce. Con un tamaño único, el mapa se
 * parece al local solo por casualidad, y el mesero vuelve a traducir "Mesa 7" a
 * un lugar físico — que es justo lo que el plano vino a evitar.
 *
 * Había además un síntoma sin causa aparente: `shape` acepta `rect` desde la
 * primera migración y la pantalla **dibujaba el rectángulo igual que el
 * cuadrado**, porque sin ancho y alto propios no hay forma de que uno se vea
 * distinto del otro.
 *
 * En píxeles de la cuadrícula del plano, y **ajustados a la rejilla de 20** que
 * ya usa el editor: dos mesas alineadas a ojo quedan alineadas de verdad.
 *
 * Los valores por defecto son los que la pantalla dibujaba fijos hasta hoy, así
 * que un plano existente se ve igual después de migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_dining_tables', function (Blueprint $table) {
            $table->unsignedSmallInteger('width')->default(140)->after('shape');
            $table->unsignedSmallInteger('height')->default(120)->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('pos_dining_tables', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
    }
};
