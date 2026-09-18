<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpOutboxEntry;
use App\Models\ErpReconciliationEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Erp\ErpMasterSyncService;
use App\Services\Erp\ErpSettingsService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Estado de la integración con el ERP (F1-C).
 *
 * Responde la pregunta que hace el encargado cuando algo no cuadra: **¿esto está
 * conectado, desde cuándo, y qué falta mandar?** Sin una pantalla que lo diga, la
 * respuesta sale de mirar la base de datos.
 *
 * Bajar la configuración es una lectura, no una escritura: con ERP presente la
 * configuración de caja es suya y el POS la adopta (P2, P3). El POS nunca le
 * escribe encima.
 */
class ErpIntegrationController extends Controller
{
    public function __construct(
        private ErpSettingsService $erp,
        private ErpMasterSyncService $masters,
        private SettingsRepository $settings,
        private AuditLogger $audit,
    ) {}

    public function status(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        return response()->json([
            'message' => __('erp.status_retrieved'),
            'data' => [
                // Sin ERP configurado el POS opera solo, y eso es un estado
                // válido —no un error— mientras no haya ERP instalado (P-01).
                'configured' => $this->erp->configured(),
                'base_url' => config('pos.erp.base_url'),
                'company_id' => config('pos.erp.company_id'),
                'settings_synced_at' => $this->erp->lastSyncedAt(),
                'queue' => [
                    'pending' => ErpOutboxEntry::where('branch_id', $branchId)->where('status', 'pending')->count(),
                    'exceptions' => ErpOutboxEntry::where('branch_id', $branchId)
                        ->where('status', 'exception')->whereNull('resolved_at')->count(),
                    'sent_today' => ErpOutboxEntry::where('branch_id', $branchId)
                        ->where('status', 'sent')->whereDate('sent_at', today())->count(),
                ],
                // Lo que rige hoy, venga del ERP o de la configuración local:
                // es lo que hay que mirar cuando la caja se comporta distinto de
                // lo que alguien esperaba.
                'reconciliation_pending' => ErpReconciliationEntry::where('branch_id', $branchId)
                    ->whereNull('resolved_at')->count(),
                'masters_synced_at' => $this->settings->get('erp.products_synced_at'),
                'effective' => [
                    'offline_max_hours' => (int) $this->settings->get('pos.offline_max_hours', config('pos.offline_max_hours')),
                    'offline_pin_mode' => (string) $this->settings->get('pos.offline_pin_mode', config('pos.offline_pin_mode')),
                    'require_shift' => (bool) $this->settings->get('pos.require_shift', config('pos.require_shift')),
                    'layout_profile' => (string) $this->settings->get('pos.layout_profile', config('pos.layout_profile', 'scan_first')),
                ],
            ],
            'status' => 200,
        ], 200);
    }

    /**
     * Baja catálogo y clientes del ERP (§12).
     *
     * Con ERP presente el maestro es suyo y el POS solo lee (P2). Lo que no case
     * por clave natural queda en la bandeja de conciliación y **no bloquea**: el
     * producto se sigue vendiendo y el cliente sigue comprando.
     */
    public function pullMasters(Request $request)
    {
        $result = $this->masters->pull($request->attributes->get('branch_id'));

        if ($result['status'] === 'unconfigured') {
            return response()->json(['message' => __('erp.not_configured'), 'status' => 422], 422);
        }

        if ($result['status'] !== 'ok') {
            return response()->json([
                'message' => __('erp.pull_failed', ['error' => $result['error'] ?? '']),
                'status' => 503,
            ], 503);
        }

        $this->audit->record(
            event: 'erp.masters_pulled',
            entityType: 'cat_products',
            changes: ['products' => $result['products'], 'customers' => $result['customers']],
        );

        return response()->json([
            'message' => __('erp.masters_pulled'),
            'data' => ['products' => $result['products'], 'customers' => $result['customers']],
            'status' => 200,
        ], 200);
    }

    /** La bandeja de conciliación: lo que espera decisión humana (§12.8, Q-04). */
    public function reconciliation(Request $request)
    {
        $entries = ErpReconciliationEntry::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->boolean('unresolved', true), fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('created_at')
            ->limit((int) $request->integer('limit', 200))
            ->get();

        if ($entries->isEmpty()) {
            return response()->json(['message' => __('erp.reconciliation_empty'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('erp.reconciliation_retrieved'),
            'data' => $entries,
            'status' => 200,
        ], 200);
    }

    /**
     * Cierra una fila de la bandeja.
     *
     * No fusiona nada: fusionar por nombre es justo lo que Q-04 prohíbe. Lo que
     * hace es dejar constancia de que una persona la miró y qué decidió.
     */
    public function resolveReconciliation(Request $request, string $id)
    {
        $entry = ErpReconciliationEntry::where('branch_id', $request->attributes->get('branch_id'))->find($id);

        if (! $entry) {
            return response()->json(['message' => __('erp.reconciliation_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), ['resolution' => 'required|string|max:255']);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $entry->forceFill([
            'resolved_by' => $request->attributes->get('employee_id'),
            'resolved_at' => now(),
            'resolution' => $validator->validated()['resolution'],
        ])->save();

        $this->audit->record(
            event: 'erp.reconciliation_resolved',
            entityType: 'pos_erp_reconciliation',
            entityId: $entry->id,
            changes: ['reason' => $entry->reason, 'resolution' => $entry->resolution],
        );

        return response()->json(['message' => __('erp.reconciliation_resolved'), 'status' => 200], 200);
    }

    /** Baja la configuración del ERP y la aplica. */
    public function pull()
    {
        $result = $this->erp->pull();

        if ($result['status'] === 'unconfigured') {
            return response()->json(['message' => __('erp.not_configured'), 'status' => 422], 422);
        }

        if ($result['status'] !== 'ok') {
            // El enlace caído no es un fallo del POS: se informa y la caja sigue
            // vendiendo con lo último que supo (P4).
            return response()->json([
                'message' => __('erp.pull_failed', ['error' => $result['error'] ?? '']),
                'status' => 503,
            ], 503);
        }

        // Cambiar cuánto puede vender una caja aislada o si exige turno es una
        // decisión de operación: queda registrada aunque venga de afuera.
        $this->audit->record(
            event: 'erp.settings_pulled',
            entityType: 'cmn_settings',
            changes: $result['applied'] ?? [],
        );

        return response()->json([
            'message' => __('erp.settings_pulled'),
            'data' => ['applied' => $result['applied'] ?? []],
            'status' => 200,
        ], 200);
    }
}
