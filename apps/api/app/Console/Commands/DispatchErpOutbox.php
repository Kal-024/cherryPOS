<?php

namespace App\Console\Commands;

use App\Services\Erp\ErpOutboxService;
use Illuminate\Console\Command;

/**
 * Empuja la cola de tickets hacia cherryERP.
 *
 * Corre programado, no en la petición que cierra la venta: **P4 — el POS nunca
 * depende del ERP para cerrar una venta**. Si el enlace está caído, la caja
 * sigue cobrando y los tickets esperan aquí.
 *
 * Reintentar es barato; equivocarse de reintento no. Los 4xx no vuelven a la
 * cola: van a la bandeja de excepciones que opera el supervisor (§6 del
 * contrato).
 */
class DispatchErpOutbox extends Command
{
    protected $signature = 'pos:erp-outbox {--limit=25 : Tickets por corrida}';

    protected $description = 'Envía a cherryERP los tickets pendientes de facturar';

    public function handle(ErpOutboxService $outbox): int
    {
        $due = $outbox->due((int) $this->option('limit'));

        if ($due === []) {
            $this->info('No hay tickets pendientes.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($due as $entry) {
            $result = $outbox->dispatch($entry);

            $result->status === 'sent' ? $sent++ : $failed++;

            if ($result->status === 'exception') {
                // Se informa por consola además de la bandeja: quien corre esto
                // a mano tras un corte quiere verlo sin abrir la aplicación.
                $this->warn("Ticket {$result->sale_id}: {$result->error_code}");
            }
        }

        $this->info("Enviados: {$sent} · Pendientes o con excepción: {$failed}");

        return self::SUCCESS;
    }
}
