<?php

namespace App\Console\Commands;

use App\Services\Delivery\DocumentDeliveryService;
use Illuminate\Console\Command;

/**
 * Despacha los comprobantes que el cliente pidió recibir (P-04).
 *
 * Corre programado y no en la petición que cierra la venta: el envío digital es
 * *best-effort* y **nunca bloquea la caja**. La térmica sale igual; esto es el
 * extra.
 */
class DispatchDocumentDeliveries extends Command
{
    protected $signature = 'pos:deliveries {--limit=20 : Envíos por corrida}';

    protected $description = 'Envía los comprobantes encolados por WhatsApp o correo';

    public function handle(DocumentDeliveryService $deliveries): int
    {
        $due = $deliveries->due((int) $this->option('limit'));

        if ($due === []) {
            $this->info('No hay envíos pendientes.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $delivery) {
            $result = $deliveries->dispatch($delivery);

            if ($result->status === 'sent') {
                $sent++;
            } else {
                $this->warn("Envío {$result->id}: {$result->status} · {$result->error_code}");
            }
        }

        $this->info('Enviados: '.$sent.' de '.count($due));

        return self::SUCCESS;
    }
}
