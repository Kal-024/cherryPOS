<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\ChangeFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Sincronización entre terminales (G-13).
 *
 * La terminal pregunta *"¿qué cambió desde este momento?"* y recibe el cambio
 * más un cursor nuevo. Sin estado en el servidor: reiniciarlo no pierde nada, y
 * una terminal que estuvo desconectada se pone al día sola con su último cursor.
 *
 * Se eligió consulta periódica sobre WebSockets porque todo ocurre dentro de la
 * red local: la latencia no se nota y no hay que operar un servidor de
 * conexiones persistentes en el equipo de cada cliente.
 */
class SyncController extends Controller
{
    public function __construct(private ChangeFeed $feed) {}

    public function changes(Request $request)
    {
        $since = $request->filled('since')
            ? Carbon::parse($request->string('since')->toString())
            : null;

        return response()->json([
            'message' => __('sync.changes_retrieved'),
            'data' => $this->feed->since(
                $since,
                $request->user(),
                $request->attributes->get('branch_id')
            ),
            'status' => 200,
        ], 200);
    }
}
