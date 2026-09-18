<?php

namespace App\Http\Middleware;

use App\Models\OperatorSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permiso declarado en la ruta, misma convención que cherryB:
 * `->middleware('permission:pos_sale.create')`.
 *
 * Los permisos son del **empleado**, no de la terminal, y se resuelven contra
 * la sucursal de la sesión: un cajero de la sucursal 2 no ve datos de la 1
 * (B-13). Roles con herencia más overrides individuales, incluida la
 * revocación (D-13).
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $session = $request->attributes->get('operator_session');

        if (! $session instanceof OperatorSession) {
            return response()->json([
                'message' => __('auth.no_operator_session'),
                'status' => 423,
            ], 423);
        }

        $granted = $session->employee->permissionCodes($session->branch_id);
        $allowed = count(array_intersect($codes, $granted)) > 0;

        if (! $allowed) {
            return response()->json([
                'message' => __('auth.forbidden'),
                'status' => 403,
            ], 403);
        }

        return $next($request);
    }
}
