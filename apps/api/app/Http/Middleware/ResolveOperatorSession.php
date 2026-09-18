<?php

namespace App\Http\Middleware;

use App\Services\Auth\OperatorSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un cajero identificado, no solo una terminal autenticada (D-05).
 *
 * Una terminal con token puede consultar el catálogo y pintar la pantalla; para
 * **facturar** hace falta que alguien haya entrado con su PIN. Ese es todo el
 * sentido de la doble credencial.
 *
 * Deja en el request `operator_session`, `employee_id` y `branch_id`, que es de
 * donde salen el autor de la venta y su alcance por sucursal.
 */
class ResolveOperatorSession
{
    public function __construct(private OperatorSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $terminal = $request->user();

        if (! $terminal) {
            return response()->json(['message' => __('auth.unauthenticated'), 'status' => 401], 401);
        }

        $session = $this->sessions->current($terminal);

        if (! $session) {
            return response()->json([
                'message' => __('auth.no_operator_session'),
                'status' => 423,
            ], 423);
        }

        $request->attributes->set('operator_session', $session);
        $request->attributes->set('employee_id', $session->employee_id);
        $request->attributes->set('branch_id', $session->branch_id);

        return $next($request);
    }
}
