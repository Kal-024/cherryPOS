<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupervisorNotification;
use Illuminate\Http\Request;

/**
 * Bandeja de avisos al supervisor (P-11).
 *
 * **Interna y no bloqueante.** La caja no se detiene: se advierte para que el
 * supervisor tome medidas después. El caso que originó la decisión es el cajero
 * pasando productos sin registrar previamente — hay que avisar rápido, no parar
 * la fila.
 */
class SupervisorNotificationController extends Controller
{
    public function index(Request $request)
    {
        $items = SupervisorNotification::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->boolean('unread_only', true), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('occurred_at')
            ->limit((int) $request->integer('limit', 100))
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('supervision.none'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('supervision.retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function read(Request $request, string $id)
    {
        $item = SupervisorNotification::where('branch_id', $request->attributes->get('branch_id'))->find($id);

        if (! $item) {
            return response()->json(['message' => __('supervision.not_found'), 'status' => 404], 404);
        }

        $item->forceFill([
            'read_by' => $request->attributes->get('employee_id'),
            'read_at' => now(),
        ])->save();

        return response()->json(['message' => __('supervision.marked_read'), 'status' => 200], 200);
    }
}
