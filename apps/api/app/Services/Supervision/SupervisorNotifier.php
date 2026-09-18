<?php

namespace App\Services\Supervision;

use App\Models\SupervisorNotification;
use Illuminate\Http\Request;

/**
 * Avisos al supervisor (P-11).
 *
 * **Nunca bloquean.** El caso que originó la decisión es el cajero pasando
 * productos sin registrar previamente: hay que avisar rápido, no parar la fila.
 * El supervisor ve la bandeja y toma medidas después.
 *
 * Lo bloqueante es otra cosa y vive en `SupervisorAuthorizer`: la autorización
 * en el momento, con un PIN **distinto del de inicio de sesión**.
 */
class SupervisorNotifier
{
    public function __construct(private Request $request) {}

    /** @param array<string,mixed> $context */
    public function notify(
        string $event,
        string $title,
        ?string $body = null,
        array $context = [],
        string $severity = 'warning',
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $branchId = null,
    ): SupervisorNotification {
        return SupervisorNotification::create([
            // La sucursal se puede pasar explícita porque **no todo aviso nace
            // de una petición HTTP**: la cola hacia el ERP corre desde un
            // comando programado, y ahí no hay request del que deducirla. Sin
            // este respaldo, el primer rechazo del ERP fuera de una petición
            // revienta al intentar guardar el aviso.
            'branch_id' => $branchId ?? $this->request->attributes->get('branch_id'),
            'terminal_id' => $this->request->user()?->getKey(),
            'employee_id' => $this->request->attributes->get('employee_id'),
            'event' => $event,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            // "Con información detallada" es literal en D-03: sin el detalle, el
            // aviso solo dice que pasó algo.
            'context' => $context,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'occurred_at' => now(),
        ]);
    }
}
