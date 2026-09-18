<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Denomination;
use App\Models\Shift;
use App\Services\Cash\CashMovementService;
use App\Services\Cash\ShiftService;
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
