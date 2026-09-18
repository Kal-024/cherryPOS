<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Services\Inventory\LotAllocationService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Existencias y su libro mayor (B-03, H1.3).
 *
 * **No hay endpoint para "poner el stock en 40".** Hay uno para registrar un
 * ajuste de +8 con su motivo, y el saldo es la suma. Es la misma idea que la
 * inmutabilidad de los documentos, aplicada al inventario: un número editable no
 * se puede auditar.
 */
class InventoryController extends Controller
{
    public function __construct(
        private StockLedgerService $ledger,
        private LotAllocationService $lots,
    ) {}

    /** Movimientos: el libro, no el saldo. Es lo que responde "¿qué pasó?". */
    public function movements(Request $request)
    {
        $movements = InventoryMovement::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->string('product_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->string('location_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->date('from')))
            ->with(['product:id,sku,name', 'location:id,code,name', 'lot:id,code,expires_on'])
            ->orderByDesc('occurred_at')
            ->limit((int) $request->integer('limit', 200))
            ->get();

        if ($movements->isEmpty()) {
            return response()->json(['message' => __('inventory.no_movements'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('inventory.movements_retrieved'),
            'data' => $movements,
            'status' => 200,
        ], 200);
    }

    /**
     * Existencias de una ubicación, producto por producto.
     *
     * Lee `inv_stock_balances`, que es saldo **cacheado**: la fuente de verdad
     * sigue siendo el libro de movimientos. Sumar el libro entero para pintar
     * una tabla de mil productos sería exacto y también inusable, y el saldo se
     * recalcula ante la duda.
     *
     * `below_min` responde la única pregunta que produce acción inmediata: qué
     * hay que reponer.
     */
    public function balances(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'location_id' => 'required|uuid|exists:inv_locations,id',
            'search' => 'nullable|string|max:120',
            'below_min' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $rows = DB::table('inv_stock_balances as b')
            ->join('cat_products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('cat_uoms as u', 'u.id', '=', 'p.uom_id')
            ->where('b.location_id', $request->string('location_id'))
            ->where('p.is_active', true)
            ->where('p.tracks_stock', true)
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = trim($request->string('search')->toString());
                $query->where(fn ($q) => $q
                    ->where('p.name', 'ilike', "%{$term}%")
                    ->orWhere('p.sku', 'ilike', "%{$term}%"));
            })
            // Comparar contra el mínimo solo cuando hay mínimo: un producto sin
            // umbral no está "por debajo" de nada.
            ->when($request->boolean('below_min'), fn ($q) => $q
                ->whereNotNull('p.min_stock')
                ->whereColumn('b.qty', '<', 'p.min_stock'))
            ->selectRaw('p.id as product_id, p.sku, p.name, p.min_stock, u.code as uom_code, SUM(b.qty) as qty')
            ->groupBy('p.id', 'p.sku', 'p.name', 'p.min_stock', 'u.code')
            ->orderBy('p.name')
            ->limit((int) $request->integer('limit', 200))
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => __('inventory.no_balances'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('inventory.balances_retrieved'),
            'data' => $rows,
            'status' => 200,
        ], 200);
    }

    public function balance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|uuid|exists:cat_products,id',
            'location_id' => 'required|uuid|exists:inv_locations,id',
            'lot_id' => 'nullable|uuid|exists:inv_lots,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        return response()->json([
            'message' => __('inventory.balance_retrieved'),
            'data' => [
                'qty' => $this->ledger->available(
                    $data['product_id'],
                    $data['location_id'],
                    $data['lot_id'] ?? null
                ),
            ],
            'status' => 200,
        ], 200);
    }

    /**
     * Ajuste de existencias.
     *
     * `qty` es la **diferencia**, con signo, no el nuevo saldo. El motivo es
     * obligatorio: un ajuste sin motivo es exactamente lo que después nadie sabe
     * explicar.
     */
    public function adjust(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|uuid|exists:cat_products,id',
            'location_id' => 'required|uuid|exists:inv_locations,id',
            'lot_id' => 'nullable|uuid|exists:inv_lots,id',
            'qty' => 'required|numeric',
            'unit_cost' => 'nullable|numeric|min:0',
            'comment' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $movement = $this->ledger->record(array_merge($validator->validated(), [
            'branch_id' => $request->attributes->get('branch_id'),
            'employee_id' => $request->attributes->get('employee_id'),
            'reason' => 'adjustment',
            'occurred_at' => now(),
        ]));

        return response()->json([
            'message' => __('inventory.adjusted'),
            'data' => $movement,
            'status' => 201,
        ], 201);
    }

    /** Recepción de mercadería. El costeo lo decide `CostingService` (Q-02). */
    public function receive(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|uuid|exists:cat_products,id',
            'location_id' => 'required|uuid|exists:inv_locations,id',
            'lot_id' => 'nullable|uuid|exists:inv_lots,id',
            'qty' => 'required|numeric|gt:0',
            'unit_cost' => 'required|numeric|min:0',
            'comment' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $movement = $this->ledger->record(array_merge($validator->validated(), [
            'branch_id' => $request->attributes->get('branch_id'),
            'employee_id' => $request->attributes->get('employee_id'),
            'reason' => 'receipt',
            'occurred_at' => now(),
        ]));

        return response()->json([
            'message' => __('inventory.received'),
            'data' => $movement,
            'status' => 201,
        ], 201);
    }

    /**
     * Lotes vencidos con existencia.
     *
     * Es una **alerta**, no un reporte que alguien tenga que acordarse de abrir
     * (D-20): lo único que produce acción operativa directa.
     */
    public function expiredLots(Request $request)
    {
        $locationId = $request->string('location_id')->toString();

        if ($locationId === '') {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => ['location_id' => [__('inventory.location_required')]],
                'status' => 422,
            ], 422);
        }

        $lots = $this->lots->expired($locationId);

        if ($lots->isEmpty()) {
            return response()->json(['message' => __('inventory.no_expired_lots'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('inventory.expired_lots_retrieved'),
            'data' => $lots->load('product:id,sku,name'),
            'status' => 200,
        ], 200);
    }
}
