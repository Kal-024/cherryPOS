<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxCode;
use Illuminate\Http\Request;

/**
 * Códigos de impuesto — solo lectura.
 *
 * Son configuración fiscal, no dato de operación: cambiar una tasa o la base
 * de un código altera cómo se factura en todo el sistema y cómo se declara el
 * libro de ventas. Se siembran en la instalación; cuando hay ERP, salen de él.
 *
 * `base = 'gross'` es lo que dice que el precio de góndola ya trae el impuesto
 * dentro — la inclusión es propiedad del código, nunca una bandera global, y
 * copiar eso de `tax_codes.base` de cherryB es lo que mantiene
 * `tax_difference` en cero.
 */
class TaxCodeController extends Controller
{
    public function index(Request $request)
    {
        $items = TaxCode::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('catalog.no_tax_codes'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('catalog.tax_codes_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }
}
