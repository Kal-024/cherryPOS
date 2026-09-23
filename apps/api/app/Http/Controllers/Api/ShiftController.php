<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Denomination;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Cash\CashMovementService;
use App\Services\Cash\ShiftService;
use App\Services\Receipts\ReceiptPdf;
use App\Services\Receipts\ReceiptService;
use App\Services\Supervision\SupervisorAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Turno de caja y arqueo (G-04, D-06, H4).
 *
 * El turno es **del equipo**: el cajón de dinero es físico y el arqueo cuenta
 * ese cajón. El corte se desglosa por cajero, que es lo que permite el relevo de
 * D-05 sin contar la caja en cada cambio de persona.
 */
class ShiftController extends Controller
{
    public function __construct(
        private ShiftService $shifts,
        private CashMovementService $movements,
        private SupervisorAuthorizer $authorizer,
        private ReceiptService $receipts,
        private ReceiptPdf $pdf,
    ) {}

    /** Denominaciones a contar. Configurables por país (D-06). */
    public function denominations(Request $request)
    {
        $items = Denomination::where('is_active', true)
            ->when($request->filled('currency_code'), fn ($q) => $q->where('currency_code', $request->string('currency_code')))
            ->orderBy('currency_code')
            ->orderByDesc('value')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('cash.no_denominations'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('cash.denominations_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function current(Request $request)
    {
        $shift = $this->shifts->current($request->user());

        if (! $shift) {
            return response()->json(['message' => __('shift.none_open'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('shift.retrieved'),
            'data' => $this->shifts->summary($shift),
            'status' => 200,
        ], 200);
    }

    public function open(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'opening_float' => 'required|numeric|min:0',
            'counts' => 'nullable|array',
            'counts.*.denomination_value' => 'required|numeric|min:0',
            'counts.*.count' => 'required|integer|min:0',
            'counts.*.currency_code' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $shift = $this->shifts->open(
            $request->user(),
            $request->attributes->get('operator_session')->employee,
            (string) $data['opening_float'],
            $data['counts'] ?? []
        );

        return response()->json([
            'message' => __('shift.opened'),
            'data' => $shift,
            'status' => 201,
        ], 201);
    }

    /**
     * Cierra el turno con el conteo físico.
     *
     * La respuesta es el corte completo: esperado, contado y diferencia **por
     * moneda**, más el desglose por denominación y por cajero.
     */
    public function close(Request $request)
    {
        $shift = $this->shifts->current($request->user());

        if (! $shift) {
            return response()->json(['message' => __('shift.none_open'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'counts' => 'required|array|min:1',
            'counts.*.denomination_value' => 'required|numeric|min:0',
            'counts.*.count' => 'required|integer|min:0',
            'counts.*.currency_code' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $closed = $this->shifts->close(
            $shift,
            $request->attributes->get('operator_session')->employee,
            $validator->validated()['counts']
        );

        return response()->json([
            'message' => __('shift.closed'),
            'data' => $this->shifts->summary($closed),
            'status' => 200,
        ], 200);
    }

    /** Corte de un turno ya cerrado. Mínimo operativo de F1 (P-07). */
    public function summary(Request $request, string $id)
    {
        $shift = Shift::where('branch_id', $request->attributes->get('branch_id'))->find($id);

        if (! $shift) {
            return response()->json(['message' => __('shift.not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('shift.retrieved'),
            'data' => $this->shifts->summary($shift),
            'status' => 200,
        ], 200);
    }

    /**
     * El corte, en papel.
     *
     * `ReceiptService::forShift()` y la plantilla `shift_cut` existían desde el
     * principio y no tenían quién las pidiera. El corte se entrega con el
     * efectivo y se archiva: es el respaldo de lo que había en el cajón cuando
     * alguien lo contó.
     */
    public function cutPdf(Request $request, string $id)
    {
        $shift = Shift::where('branch_id', $request->attributes->get('branch_id'))->find($id);

        if (! $shift) {
            return response()->json(['message' => __('shift.not_found'), 'status' => 404], 404);
        }

        $rendered = $this->receipts->forShift($shift, $this->shifts->summary($shift));

        return $this->pdf->make($rendered['template'], $rendered['lines'], $rendered['context'])
            ->stream('corte-'.$shift->code.'.pdf');
    }

    /**
     * Los turnos ya cerrados, con su descuadre.
     *
     * Sin esta lista, un turno que cerró con faltante desaparecía de la vista:
     * el corte existía pero había que saber su identificador para pedirlo.
     */
    public function index(Request $request)
    {
        $shifts = Shift::where('branch_id', $request->attributes->get('branch_id'))
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->limit((int) $request->integer('limit', 50))
            ->get();

        $rows = $shifts->map(function (Shift $shift) {
            $summary = $this->shifts->summary($shift);

            return [
                'id' => $shift->id,
                'code' => $shift->code,
                'terminal_id' => $shift->terminal_id,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'by_currency' => $summary['by_currency'],
                'settled' => $summary['settled'],
            ];
        });

        // Solo los que no cuadran: es la bandeja que el supervisor tiene que
        // vaciar antes de que el ERP pueda cerrar el día.
        if ($request->boolean('pending_only')) {
            $rows = $rows->reject(fn (array $row) => $row['settled'])->values();
        }

        return response()->json([
            'message' => __('shift.retrieved'),
            'data' => $rows,
            'status' => 200,
        ], 200);
    }

    /**
     * Cuadra un turno cerrado con diferencia.
     *
     * Exige **PIN de supervisor**, que es distinto del de sesión (P-11): mover
     * plata de un arqueo ya cerrado es justo donde conviene el segundo freno.
     * El monto no se recibe — es la diferencia que el corte calculó.
     */
    public function settle(Request $request, string $id)
    {
        $branchId = $request->attributes->get('branch_id');
        $shift = Shift::where('branch_id', $branchId)->find($id);

        if (! $shift) {
            return response()->json(['message' => __('shift.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:120',
            'supervisor_code' => 'required|string|max:40',
            'supervisor_pin' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $supervisor = $this->authorizer->authorize(
            $data['supervisor_code'],
            $data['supervisor_pin'],
            'pos_shift.settle',
            $branchId
        );

        $employee = Employee::find($request->attributes->get('employee_id'));

        return response()->json([
            'message' => __('shift.settled'),
            'data' => $this->shifts->settle($shift, $employee ?? $supervisor, $data['reason'], $supervisor),
            'status' => 200,
        ], 200);
    }

    /** Entradas y salidas que no son ventas (H4.4). El motivo es obligatorio. */
    public function movement(Request $request)
    {
        $shift = $this->shifts->current($request->user());

        if (! $shift) {
            return response()->json(['message' => __('shift.none_open'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'direction' => 'required|in:in,out',
            'reason' => 'required|string|max:120',
            'amount' => 'required|numeric|gt:0',
            'currency_code' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $movement = $this->movements->record($shift, array_merge($validator->validated(), [
            'employee_id' => $request->attributes->get('employee_id'),
        ]));

        return response()->json([
            'message' => __('cash.movement_recorded'),
            'data' => $movement,
            'status' => 201,
        ], 201);
    }
}
