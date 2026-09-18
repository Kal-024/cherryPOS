<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\Request;

/**
 * Ubicaciones de existencias — solo lectura (B-07).
 *
 * Multi-almacén desde el día uno: la bodega y el mostrador son dos sitios
 * distintos aunque estén a tres metros. Toda operación de inventario exige decir
 * **en cuál** ocurre, así que la pantalla necesita poder ofrecerlas; crearlas es
 * parte de la instalación, no del día a día.
 *
 * El alcance es la sucursal de la terminal: una caja no toca el inventario de
 * otro local.
 */
class LocationController extends Controller
{
    public function index(Request $request)
    {
        $items = Location::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_sales_default')
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('inventory.no_locations'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('inventory.locations_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }
}
