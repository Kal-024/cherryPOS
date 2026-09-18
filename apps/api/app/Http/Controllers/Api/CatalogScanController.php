<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Catalog\BarcodeService;
use Illuminate\Http\Request;

/**
 * Lectura de un código de barras (B-04, H1.5).
 *
 * Existe aparte de la búsqueda porque resuelve otra pregunta: la búsqueda dice
 * "qué productos se parecen a esto", el lector dice "qué es exactamente esto, y
 * cuánto pesó".
 *
 * En la operación normal la caja **no llama a este endpoint**: el catálogo está
 * cacheado en el terminal y la resolución es local (D-04). Esto es el respaldo
 * para lo que la caché no tenga, y el contrato que la implementación local
 * replica.
 */
class CatalogScanController extends Controller
{
    public function __construct(private BarcodeService $barcodes) {}

    public function show(Request $request)
    {
        $code = trim($request->string('code')->toString());

        if ($code === '') {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => ['code' => [__('catalog.barcode_required')]],
                'status' => 422,
            ], 422);
        }

        $resolved = $this->barcodes->resolve($code);

        if ($resolved === null) {
            return response()->json([
                'message' => __('catalog.barcode_not_found', ['code' => $code]),
                'status' => 404,
            ], 404);
        }

        return response()->json([
            'message' => __('catalog.barcode_resolved'),
            'data' => [
                'product' => $resolved['barcode']->product,
                'uom' => $resolved['barcode']->productUom?->uom,
                // Informados solo cuando el código los traía embebidos: una
                // etiqueta de balanza no dice "una unidad de queso", dice
                // "queso, 0,847 kg".
                'qty' => $resolved['qty'],
                'amount' => $resolved['amount'],
            ],
            'status' => 200,
        ], 200);
    }
}
