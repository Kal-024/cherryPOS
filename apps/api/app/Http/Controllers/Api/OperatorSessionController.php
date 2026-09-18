<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\OperatorSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Sesión de operador: la segunda mitad de la doble credencial (D-05).
 *
 * El identificador de la sesión abierta viaja en `X-Operator-Session`. No es un
 * secreto adicional —la terminal ya está autenticada— sino la respuesta a "¿de
 * cuál de los cajeros de esta caja es esta venta?".
 */
class OperatorSessionController extends Controller
{
    public function __construct(private OperatorSessionService $sessions) {}

    public function show(Request $request)
    {
        $session = $this->sessions->current($request->user());

        if (! $session) {
            return response()->json([
                'message' => __('auth.no_operator_session'),
                'status' => 404,
            ], 404);
        }

        return response()->json([
            'message' => __('auth.operator_session_retrieved'),
            'data' => $this->present($session),
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_code' => 'required|string|max:20',
            'pin' => 'required|string|min:4|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();
        $terminal = $request->user();
        $session = $this->sessions->open($terminal, $data['employee_code'], $data['pin']);

        return response()->json([
            'message' => __('auth.operator_session_opened'),
            'data' => $this->present($session->load('employee')),
            'status' => 201,
        ], 201);
    }

    public function destroy(Request $request)
    {
        $this->sessions->close($request->user());

        return response()->json([
            'message' => __('auth.operator_session_closed'),
            'status' => 200,
        ], 200);
    }

    /** @return array<string,mixed> */
    private function present($session): array
    {
        $employee = $session->employee;

        return [
            'id' => $session->id,
            'opened_at' => $session->opened_at,
            'expires_at' => $session->expires_at,
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->code,
                'full_name' => $employee->full_name,
                // El tope de descuento del cajero (D-02): la interfaz lo
                // necesita para saber cuándo pedir autorización, no para
                // decidirlo — eso lo decide el servidor.
                'discount_limit_percent' => $employee->discount_limit_percent,
            ],
            'permissions' => $employee->permissionCodes($session->branch_id),
        ];
    }
}
