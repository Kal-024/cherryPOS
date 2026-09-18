<?php

namespace App\Services\Sales;

use App\Models\Employee;
use App\Models\SaleLine;
use App\Services\Supervision\SupervisorNotifier;
use Illuminate\Validation\ValidationException;

/**
 * Control de ítems temporales y ventas por monto (D-03, H2.9).
 *
 * Son las dos válvulas de escape del catálogo: vender algo que no está cargado,
 * y cobrar un monto suelto. Indispensables —siempre hay algo que vender que no
 * está en el sistema— y a la vez el agujero por donde se escapa el control de
 * inventario y por donde un cajero puede facturar cualquier cosa.
 *
 * La decisión es literal: **cinco por cajero por día** por defecto, ampliable o
 * ilimitado por configuración, y **siempre se notifica al supervisor** con
 * detalle. El sexto exige autorización con PIN de supervisor.
 *
 * El aviso sale **aunque el uso esté dentro del límite**: "sea cual sea la
 * decisión, siempre notificar al supervisor de este tipo de acción".
 */
class SpecialLineGuard
{
    public const PERMISSION = 'pos_sale.temporary_item';

    public function __construct(private SupervisorNotifier $notifier) {}

    /** Usos de hoy de este cajero. */
    public function usedToday(Employee $employee): int
    {
        return SaleLine::query()
            ->whereIn('kind', [SaleLine::KIND_TEMPORARY, SaleLine::KIND_AMOUNT])
            ->whereDate('pos_sale_lines.created_at', now()->toDateString())
            ->whereExists(function ($query) use ($employee) {
                $query->selectRaw('1')
                    ->from('pos_sales')
                    ->whereColumn('pos_sales.id', 'pos_sale_lines.sale_id')
                    ->where('pos_sales.employee_id', $employee->id);
            })
            ->count();
    }

    /** Límite diario de este cajero: el suyo, o el del sistema. */
    public function limitFor(Employee $employee): ?int
    {
        $limit = $employee->temp_item_daily_limit
            ?? (int) config('pos.temporary_item_daily_limit', 5);

        // Cero significa **ilimitado**, que es la opción "permitir que siempre
        // pueda hacerlo" de D-03. No significa "prohibido": prohibirlo se hace
        // quitando el permiso.
        return $limit > 0 ? $limit : null;
    }

    public function exceedsLimit(Employee $employee): bool
    {
        $limit = $this->limitFor($employee);

        return $limit !== null && $this->usedToday($employee) >= $limit;
    }

    /**
     * @throws ValidationException cuando se pasó del límite sin autorización
     */
    public function assertAllowed(
        Employee $employee,
        string $branchId,
        ?Employee $authorizedBy = null,
    ): void {
        if (! $employee->hasPermission(self::PERMISSION, $branchId) && $authorizedBy === null) {
            throw ValidationException::withMessages([
                'kind' => __('sales.special_line_not_allowed'),
            ]);
        }

        if (! $this->exceedsLimit($employee)) {
            return;
        }

        if ($authorizedBy === null) {
            throw ValidationException::withMessages([
                'kind' => __('sales.special_line_limit_reached', [
                    'limit' => (string) $this->limitFor($employee),
                ]),
            ]);
        }
    }

    /**
     * Aviso al supervisor, con el detalle completo.
     *
     * No bloquea (P-11): la caja ya cobró. El supervisor lo ve en su bandeja y
     * decide si hablar con el cajero o ampliarle el límite.
     */
    public function notify(SaleLine $line, Employee $employee, ?Employee $authorizedBy = null): void
    {
        $used = $this->usedToday($employee);
        $limit = $this->limitFor($employee);

        $this->notifier->notify(
            event: 'sale.special_line',
            title: __('sales.special_line_notice_title', ['employee' => $employee->full_name]),
            body: __('sales.special_line_notice_body', [
                'description' => $line->description,
                'amount' => (string) $line->total,
                'used' => (string) $used,
                'limit' => $limit === null ? '∞' : (string) $limit,
            ]),
            context: [
                'sale_id' => $line->sale_id,
                'line_id' => $line->id,
                'kind' => $line->kind,
                'description' => $line->description,
                'qty' => (string) $line->qty,
                'unit_price' => (string) $line->unit_price,
                'total' => (string) $line->total,
                'used_today' => $used,
                'daily_limit' => $limit,
                'authorized_by' => $authorizedBy?->id,
            ],
            severity: $limit !== null && $used > $limit ? 'critical' : 'warning',
            entityType: 'sale_line',
            entityId: $line->id,
        );
    }
}
