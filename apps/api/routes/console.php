<?php

use App\Console\Commands\DispatchDocumentDeliveries;
use App\Console\Commands\DispatchErpOutbox;
use App\Console\Commands\PullErpMasters;
use App\Console\Commands\PullErpSettings;
use Illuminate\Support\Facades\Schedule;

/*
 * La cola hacia el ERP se empuja cada minuto.
 *
 * `withoutOverlapping` importa: una corrida lenta —el ERP tardando, la VPN a
 * medias— no puede solaparse con la siguiente y mandar el mismo ticket dos
 * veces. El índice único del ERP lo impediría igual, pero prevenirlo aquí
 * ahorra ruido en la bandeja.
 */
Schedule::command(DispatchErpOutbox::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Los comprobantes que el cliente pidió recibir salen cada cinco minutos.
 *
 * Menos seguido que la cola del ERP a propósito: el envío digital es un extra
 * —la térmica ya salió— y apurarlo solo gasta conversaciones, que en WhatsApp
 * Business se pagan.
 */
Schedule::command(DispatchDocumentDeliveries::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * La configuración del ERP se relee cada hora.
 *
 * No hay aviso: alguien alarga la ventana sin sincronizar o cambia el perfil de
 * pantalla en el ERP y la caja tiene que enterarse sin que nadie entre a apretar
 * un botón. Cada hora alcanza —son decisiones que no cambian dos veces por
 * turno— y no castiga el enlace de una sucursal con VPN.
 */
Schedule::command(PullErpSettings::class)
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * El catálogo y los clientes se leen del ERP cada hora.
 *
 * Es incremental —solo lo modificado desde la última marca del ERP—, así que
 * repetirlo seguido cuesta poco. La frecuencia la fija el riesgo: una caja
 * cobrando el precio de la semana pasada es un problema que se descubre tarde y
 * de la peor manera.
 */
Schedule::command(PullErpMasters::class)
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
