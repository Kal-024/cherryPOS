<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Erp\ErpMasterSyncService;
use Illuminate\Console\Command;

/**
 * Lee catálogo y clientes del ERP (F1-C, §12 del contrato).
 *
 * Corre programado porque los precios cambian en el ERP sin avisarle al POS, y
 * una caja cobrando el precio de la semana pasada es un problema que se descubre
 * tarde y de la peor manera.
 *
 * Es **incremental**: tras la primera corrida solo pide lo modificado desde la
 * marca que devolvió el ERP, así que repetirlo seguido es barato aunque el
 * catálogo tenga miles de filas.
 */
class PullErpMasters extends Command
{
    protected $signature = 'pos:erp-masters';

    protected $description = 'Lee el catálogo y los clientes desde cherryERP';

    public function handle(ErpMasterSyncService $masters): int
    {
        // Una instalación, una sucursal (P-05): la del propio servidor.
        $branch = Branch::query()->orderBy('created_at')->first();

        if (! $branch) {
            $this->warn('Todavía no hay sucursal configurada.');

            return self::SUCCESS;
        }

        $result = $masters->pull($branch->id);

        if ($result['status'] === 'unconfigured') {
            $this->info('Sin ERP configurado: el catálogo del POS es propio.');

            return self::SUCCESS;
        }

        if ($result['status'] !== 'ok') {
            // Un ERP caído no debe llenar de alertas el cron de un local que
            // igual está vendiendo (P4).
            $this->warn($result['error'] ?? 'El ERP no contestó.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Productos: %d nuevos, %d actualizados, %d pendientes. Clientes: %d nuevos, %d actualizados, %d pendientes.',
            $result['products']['created'], $result['products']['updated'], $result['products']['pending'],
            $result['customers']['created'], $result['customers']['updated'], $result['customers']['pending'],
        ));

        return self::SUCCESS;
    }
}
