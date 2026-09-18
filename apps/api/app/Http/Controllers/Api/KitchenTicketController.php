<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KitchenTicket;
use App\Models\Sale;
use App\Services\Dining\KitchenTicketService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Comandas y pantalla de cocina (F1-B).
 *
 * El KDS es **otra pantalla y otro permiso**: cuelga de la pared de la cocina,
 * no tiene cajero identificado delante y solo hace tres cosas —ver, empezar,
 * marcar lista—. Mezclarlo con la caja obligaría a poner un PIN cada vez que un
 * cocinero toca una comanda con las manos ocupadas.
 *
 * El listado ordena por antigüedad a propósito: lo que lleva más tiempo esperando
 * va arriba, que es como se despacha una cocina.
 */
class KitchenTicketController extends Controller
{
    public function __construct(
        private KitchenTicketService $kitchen,
        private SettingsRepository $settings,
    ) {}

    /** Manda a preparar lo que la cuenta todavía no mandó. */
    public function store(Request $request, string $saleId)
    {
        $sale = Sale::with('lines.product', 'lines.modifiers')
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), ['notes' => 'nullable|string|max:255']);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $tickets = $this->kitchen->send($sale, [
            'terminal_id' => $request->user()->getKey(),
            'employee_id' => $request->attributes->get('employee_id'),
            'notes' => $validator->validated()['notes'] ?? null,
        ]);

        return response()->json([
            'message' => __('dining.ticket_sent'),
            'data' => $tickets->map(fn (KitchenTicket $ticket) => $this->present($ticket)),
            'status' => 201,
        ], 201);
    }

    /** Lo que la cuenta todavía no mandó: el mesero lo ve antes de comandar. */
    public function pending(Request $request, string $saleId)
    {
        $sale = Sale::with('lines.product', 'lines.modifiers')
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('dining.pending_retrieved'),
            'data' => $this->kitchen->pending($sale)->map(fn ($line) => [
                'sale_line_id' => $line->id,
                'name' => $line->description,
                'qty' => (string) $line->qty,
                'destination' => $line->product?->prep_station,
                'course' => $line->course,
                'modifiers' => $line->modifiers->pluck('name'),
                'notes' => $line->notes,
            ]),
            'status' => 200,
        ], 200);
    }

    public function index(Request $request)
    {
        $tickets = KitchenTicket::with(['lines', 'sale:id,label,dining_table_id'])
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->filled('destination'), fn ($q) => $q->where('destination', $request->string('destination')))
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')),
                // Por defecto, lo que la cocina tiene entre manos. Lo retenido
                // todavía no es suyo: está pedido y sin marchar.
                fn ($q) => $q->whereIn('status', [KitchenTicket::QUEUED, KitchenTicket::PREPARING, KitchenTicket::READY])
            )
            // Por hora de marcha, no de envío: una comanda retenida a las 20:14
            // y marchada a las 21:02 entra al pase ahora, no hace una hora.
            ->orderByRaw('COALESCE(fired_at, sent_at)')
            ->limit((int) $request->integer('limit', 50))
            ->get();

        if ($tickets->isEmpty()) {
            return response()->json(['message' => __('dining.no_tickets'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('dining.tickets_retrieved'),
            'data' => $tickets->map(fn (KitchenTicket $ticket) => $this->present($ticket)),
            'status' => 200,
        ], 200);
    }

    /**
     * Marcha el curso que sigue (G-16).
     *
     * Es del salón, no del KDS: quien decide que la mesa ya terminó el fuerte es
     * el mesero que la está mirando, no el cocinero.
     */
    public function fire(Request $request, string $saleId)
    {
        $sale = Sale::where('branch_id', $request->attributes->get('branch_id'))->find($saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), ['course' => 'nullable|integer|min:1|max:9']);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $tickets = $this->kitchen->fire($sale, $validator->validated()['course'] ?? null);

        return response()->json([
            'message' => __('dining.course_fired'),
            'data' => $tickets->map(fn (KitchenTicket $ticket) => $this->present($ticket)),
            'status' => 200,
        ], 200);
    }

    public function advance(Request $request, string $id)
    {
        $ticket = KitchenTicket::with('lines')
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($id);

        if (! $ticket) {
            return response()->json(['message' => __('dining.ticket_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:preparing,ready,served,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        return response()->json([
            'message' => __('dining.ticket_updated'),
            'data' => $this->present($this->kitchen->advance($ticket, $validator->validated()['status'])),
            'status' => 200,
        ], 200);
    }

    /** @return array<string,mixed> */
    private function present(KitchenTicket $ticket): array
    {
        $lateAfter = (int) $this->settings->get(
            'kitchen.late_minutes',
            config('pos.kitchen_late_minutes'),
            $ticket->branch_id
        );

        $elapsed = $ticket->elapsedSeconds();

        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'destination' => $ticket->destination,
            'course' => $ticket->course,
            'status' => $ticket->status,
            'sale_id' => $ticket->sale_id,
            'label' => $ticket->sale?->label,
            'notes' => $ticket->notes,
            // La cocina despacha por tiempo: cuánto lleva esperando es lo
            // primero que mira el pase.
            'sent_at' => $ticket->sent_at,
            'fired_at' => $ticket->fired_at,
            'started_at' => $ticket->started_at,
            'ready_at' => $ticket->ready_at,
            // El tiempo lo calcula el servidor, no la pantalla: el KDS cuelga de
            // una pared y su reloj no es el de nadie. Y `is_late` es una
            // decisión del negocio, no un umbral cableado en la interfaz.
            'elapsed_seconds' => $elapsed,
            'is_late' => $ticket->status !== KitchenTicket::READY
                && $lateAfter > 0
                && $elapsed >= $lateAfter * 60,
            'lines' => $ticket->lines->map(fn ($line) => [
                'id' => $line->id,
                'name' => $line->name,
                'qty' => (string) $line->qty,
                'modifiers' => $line->modifiers ?? [],
                'notes' => $line->notes,
            ]),
        ];
    }
}
