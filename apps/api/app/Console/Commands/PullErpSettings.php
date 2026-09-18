<?php

namespace App\Console\Commands;

use App\Services\Erp\ErpSettingsService;
use Illuminate\Console\Command;

/**
 * Baja la configuración del ERP (F1-C, §7 y §8 del contrato).
 *
 * Corre programado porque la configuración cambia en el ERP sin avisar al POS:
 * alguien alarga la ventana sin sincronizar o cambia el perfil de pantalla, y la
 * caja tiene que enterarse sin que nadie entre a apretar un botón.
 *
 * Que el ERP no conteste **no es un fallo del POS**: se informa y se sigue con
 * lo último que se supo (P4).
 */
class PullErpSettings extends Command
{
    protected $signature = 'pos:erp-settings';

    protected $description = 'Baja la configuración de caja desde cherryERP';

    public function handle(ErpSettingsService $erp): int
    {
        $result = $erp->pull();

        return match ($result['status']) {
            'unconfigured' => $this->note('Sin ERP configurado: rige la configuración local.'),
            'ok' => $this->note(sprintf('Configuración aplicada: %s', implode(', ', array_keys($result['applied'] ?? [])) ?: 'sin cambios')),
            default => $this->warning($result['error'] ?? 'El ERP no contestó.'),
        };
    }

    private function note(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function warning(string $message): int
    {
        $this->warn($message);

        // Éxito para el planificador: un ERP caído no debe llenar de alertas el
        // cron de un local que igual está vendiendo.
        return self::SUCCESS;
    }
}
