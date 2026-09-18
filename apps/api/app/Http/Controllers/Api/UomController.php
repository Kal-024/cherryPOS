<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Uom;
use Illuminate\Http\Request;

/**
 * Unidades de medida — solo lectura.
 *
 * El catálogo de unidades se alinea con `ProductUom` del ERP (§7). Mientras el
 * POS opere solo, las siembra la instalación; integrado el ERP, manda él. En
 * ninguno de los dos casos las crea el supervisor desde la caja, así que aquí
 * no hay escritura: el formulario de producto solo necesita poder elegir.
 */
class UomController extends Controller
{
    public function index(Request $request)
    {
        $items = Uom::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('catalog.no_uoms'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('catalog.uoms_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }
}
