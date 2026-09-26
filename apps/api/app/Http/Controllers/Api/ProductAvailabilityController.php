<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Product;
use App\Services\Catalog\AvailabilityService;
use Illuminate\Http\Request;

/**
 * La lista 86: lo que se acabó hoy (F1-B).
 *
 * Recurso propio y no un campo más del producto, porque no es del catálogo: el
 * catálogo lo mantiene la administración —o el ERP— y cambia cuando cambia el
 * menú; esto lo marca quien está en la cocina y se vence al empezar el servicio
 * siguiente. Dueños distintos, vidas distintas, endpoints distintos.
 *
 * `index` devuelve **solo identificadores** para el sondeo del terminal: la caja
 * lo relee cada pocos segundos y bajar el catálogo entero para saber que se acabó
 * el pescado sería absurdo (D-04).
 */
class ProductAvailabilityController extends Controller
{
    public function __construct(private AvailabilityService $availability) {}

    /** Lo agotado, con quién lo marcó y desde cuándo. */
    public function index(Request $request)
    {
        $detailed = $request->boolean('detailed');

        return response()->json([
            'message' => __('catalog.availability_retrieved'),
            'data' => $detailed ? $this->availability->unavailable() : $this->availability->unavailableIds(),
            'status' => 200,
        ], 200);
    }

    /** Se acabó. */
    public function store(Request $request, string $id)
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['message' => __('catalog.product_not_found'), 'status' => 404], 404);
        }

        $employee = Employee::findOrFail($request->attributes->get('employee_id'));

        return response()->json([
            'message' => __('catalog.marked_unavailable', ['name' => $product->name]),
            'data' => $this->availability->mark($product, $employee, $request->attributes->get('branch_id')),
            'status' => 200,
        ], 200);
    }

    /** Volvió a haber. */
    public function destroy(Request $request, string $id)
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['message' => __('catalog.product_not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('catalog.marked_available', ['name' => $product->name]),
            'data' => $this->availability->restore($product, $request->attributes->get('branch_id')),
            'status' => 200,
        ], 200);
    }
}
