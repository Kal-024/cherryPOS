<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpOutboxEntry;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Bandeja de envíos al ERP (P4, §5 y §6 del contrato).
 *
 * **El POS nunca depende del ERP para cerrar una venta.** El ticket se cobra y
 * se cierra; el envío es posterior, asíncrono y reintentable. Esta bandeja es
 * donde se ve lo que quedó pendiente y, sobre todo, **lo que el ERP rechazó y no
 * va a aceptar por más que se reintente**: una tarjeta de regalo
 * (`instrument_unsupported`, D-18), una devolución sin documento original
 * (`refund_original_missing`).
 *
 * Dos cosas que la bandeja tiene que mostrar y no son un detalle:
 *
 *  - **`tax_difference` distinto de cero es alarma, no dato.** El documento es
 *    válido, pero significa que los dos motores de cálculo se están separando, y
 *    una diferencia sistemática se repite cada día hasta que alguien mire.
 *  - **Resolver a mano no borra.** La entrada queda con su error y con quién la
 *    dio por cerrada: si se borrara, el ticket desaparecería sin que nadie pueda
 *    explicar por qué nunca llegó al ERP.
 */
class ErpOutboxController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $entries = ErpOutboxEntry::query()
            ->with('sale:id,number,total,closed_at,erp_status')
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Lo resuelto a mano sigue existiendo, pero deja de ocupar la
            // bandeja: si no, la lista de "qué falta atender" nunca se vacía.
            ->when($request->boolean('unresolved', true), fn ($q) => $q->whereNull('resolved_at'))
            ->when(
                $request->boolean('with_difference'),
                fn ($q) => $q->whereNotNull('tax_difference')->where('tax_difference', '<>', '0.00')
            )
            ->orderByRaw("CASE status WHEN 'exception' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit((int) $request->integer('limit', 100))
            ->get();

        if ($entries->isEmpty()) {
            return response()->json(['message' => __('erp.outbox_empty'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('erp.outbox_retrieved'),
            'data' => $entries->map(fn (ErpOutboxEntry $entry) => $this->present($entry)),
            'status' => 200,
        ], 200);
    }

    /** El sobre completo: lo que se mandó, para poder explicar el rechazo. */
    public function show(Request $request, string $id)
    {
        $entry = $this->find($request, $id);

        if (! $entry) {
            return response()->json(['message' => __('erp.entry_not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('erp.entry_retrieved'),
            'data' => $this->present($entry) + ['payload' => $entry->payload],
            'status' => 200,
        ], 200);
    }

    /**
     * Devuelve la entrada a la cola.
     *
     * Se usa cuando la causa del rechazo se arregló del lado del ERP —faltaba el
     * cliente, faltaba la serie—, así que el contador de intentos vuelve a cero:
     * conservar el retroceso exponencial de ayer haría esperar horas por un
     * envío que ya puede salir.
     */
    public function retry(Request $request, string $id)
    {
        $entry = $this->find($request, $id);

        if (! $entry) {
            return response()->json(['message' => __('erp.entry_not_found'), 'status' => 404], 404);
        }

        if ($entry->status !== 'exception') {
            return response()->json(['message' => __('erp.only_exceptions_retry'), 'status' => 422], 422);
        }

        $entry->forceFill([
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => now(),
            'resolved_by' => null,
            'resolved_at' => null,
        ])->save();

        $entry->sale?->forceFill(['erp_status' => 'pending'])->save();

        $this->audit->record(
            event: 'erp.outbox_retried',
            entityType: 'pos_erp_outbox',
            entityId: $entry->id,
            changes: ['error_code' => $entry->error_code],
            context: ['sale_id' => $entry->sale_id],
        );

        return response()->json([
            'message' => __('erp.entry_requeued'),
            'data' => $this->present($entry->fresh()),
            'status' => 200,
        ], 200);
    }

    /**
     * Cierra la excepción a mano, con motivo.
     *
     * Es lo que corresponde cuando el ERP **nunca** va a aceptar el documento:
     * una tarjeta de regalo mientras A3 siga abierto. No se reintenta, porque
     * reintentar no cambia la respuesta; se deja constancia de quién decidió
     * darla por cerrada y por qué.
     */
    public function resolve(Request $request, string $id)
    {
        $entry = $this->find($request, $id);

        if (! $entry) {
            return response()->json(['message' => __('erp.entry_not_found'), 'status' => 404], 404);
        }

        if ($entry->status !== 'exception') {
            return response()->json(['message' => __('erp.only_exceptions_resolve'), 'status' => 422], 422);
        }

        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:255']);

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
        ])->save();

        // El estado queda en `exception` a propósito: la entrada no se
        // convirtió en documento, solo dejó de estar en la bandeja.
        $this->audit->record(
            event: 'erp.outbox_resolved',
            entityType: 'pos_erp_outbox',
            entityId: $entry->id,
            changes: ['error_code' => $entry->error_code, 'reason' => $validator->validated()['reason']],
            context: ['sale_id' => $entry->sale_id],
        );

        return response()->json([
            'message' => __('erp.entry_resolved'),
            'data' => $this->present($entry->fresh()),
            'status' => 200,
        ], 200);
    }

    /** @return array<string,mixed> */
    private function present(ErpOutboxEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'sale_id' => $entry->sale_id,
            'sale_number' => $entry->sale?->number,
            'sale_total' => $entry->sale?->total,
            'closed_at' => $entry->sale?->closed_at,
            'status' => $entry->status,
            'attempts' => $entry->attempts,
            'next_attempt_at' => $entry->next_attempt_at,
            'http_status' => $entry->http_status,
            'error_code' => $entry->error_code,
            'error_message' => $entry->error_message,
            'erp_document_number' => $entry->erp_document_number,
            'duplicate' => (bool) $entry->duplicate,
            // Distinto de cero es alarma: los dos motores están divergiendo.
            'tax_difference' => $entry->tax_difference,
            'sent_at' => $entry->sent_at,
            'resolved_at' => $entry->resolved_at,
        ];
    }

    private function find(Request $request, string $id): ?ErpOutboxEntry
    {
        return ErpOutboxEntry::with('sale')
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($id);
    }
}
