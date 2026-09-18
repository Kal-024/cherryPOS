<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Registro de auditoría (G-11, P1).
 *
 * "Sobre todo lo que ocurra en el POS": quién, qué entidad, qué cambió, desde
 * qué terminal, cuándo. Al tocar dinero, todo tiene que ser claro, estricto y
 * legal (A-05).
 *
 * `occurred_at` y `recorded_at` se guardan por separado a propósito
 * (precondición 4): el momento del hecho y el momento en que llegó al registro
 * no coinciden cuando la terminal estuvo un rato sin conexión.
 */
class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * @param  array<string,mixed>|null  $changes
     * @param  array<string,mixed>|null  $context
     */
    public function record(
        string $event,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $changes = null,
        ?array $context = null,
        ?string $authorizedBy = null,
        ?string $branchId = null,
    ): AuditLog {
        return AuditLog::create([
            // La sucursal se puede pasar explícita porque no todo lo auditable
            // nace de una petición HTTP: la cola hacia el ERP y los comandos
            // programados también escriben aquí, y ahí no hay request del que
            // deducirla.
            //
            // El último respaldo es la terminal autenticada: una acción que
            // ocurrió en una caja ocurrió en la sucursal de esa caja, y perder
            // la entrada de auditoría por no saberlo sería lo peor de los dos
            // mundos.
            'branch_id' => $branchId
                ?? $this->request->attributes->get('branch_id')
                ?? $this->request->user()?->branch_id,
            'terminal_id' => $this->request->user()?->getKey(),
            'employee_id' => $this->request->attributes->get('employee_id'),
            'authorized_by' => $authorizedBy,
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes,
            'context' => $context,
            'ip' => $this->request->ip(),
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);
    }
}
