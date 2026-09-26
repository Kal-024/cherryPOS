<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Services\Audit\AuditLogger;
use App\Services\Sales\CartService;
use App\Services\Sales\SpecialLineGuard;
use App\Services\Supervision\SupervisorAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Líneas del carrito.
 *
 * Tres caminos para agregar algo, y la diferencia importa:
 *
 *  - **`product`** — lo normal. Un combo se explota en sus componentes (D-14).
 *  - **`scan`** — lo que salga del lector, incluidos los códigos de balanza con
 *    peso o precio embebidos (B-04).
 *  - **`temporary` y `amount`** — las válvulas de escape del catálogo (D-03).
 *    Llevan límite diario, exigen nombre y descripción, y **siempre** avisan al
 *    supervisor.
 */
class SaleLineController extends Controller
{
    public function __construct(
        private CartService $cart,
        private SpecialLineGuard $specialLines,
        private SupervisorAuthorizer $authorizer,
        private AuditLogger $audit,
    ) {}

    /**
     * Cuántas válvulas de escape le quedan hoy a este cajero (D-03).
     *
     * La caja lo necesita **antes** de abrir el diálogo: el límite es de cinco y
     * descubrirlo al sexto intento, con el cliente delante, es lo que convierte
     * un control en un obstáculo. Además hace visible que el supervisor va a
     * recibir el aviso, que no es un castigo escondido sino parte del acuerdo.
     */
    public function quota(Request $request)
    {
        $employee = $request->attributes->get('operator_session')->employee;
        $limit = $this->specialLines->limitFor($employee);
        $used = $this->specialLines->usedToday($employee);

        return response()->json([
            'message' => __('sales.special_line_quota'),
            'data' => [
                'used_today' => $used,
                // Nulo es **ilimitado**, que es la opción de D-03 para quien
                // siempre tiene que poder hacerlo.
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'may_authorize_self' => $employee->hasPermission(
                    SpecialLineGuard::PERMISSION,
                    $request->attributes->get('branch_id')
                ),
            ],
            'status' => 200,
        ], 200);
    }

    public function store(Request $request, string $saleId)
    {
        $sale = $this->findSale($request, $saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'kind' => 'required|in:product,scan,temporary,amount',
            'product_id' => 'required_if:kind,product|nullable|uuid|exists:cat_products,id',
            'code' => 'required_if:kind,scan|nullable|string|max:64',
            'description' => 'required_if:kind,temporary|required_if:kind,amount|nullable|string|max:200',
            'qty' => 'nullable|numeric',
            'unit_price' => 'nullable|numeric|min:0',
            'amount' => 'required_if:kind,amount|nullable|numeric|min:0',
            'uom_id' => 'nullable|uuid|exists:cat_uoms,id',
            'lot_id' => 'nullable|uuid|exists:inv_lots,id',
            'location_id' => 'nullable|uuid|exists:inv_locations,id',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            // Salón (B-06): lo elegido y lo que el cliente pidió aparte. Los
            // obligatorios se validan en el servicio, no acá: la regla tiene que
            // valer también para el ticket que llega del modo degradado.
            'modifiers' => 'nullable|array',
            'modifiers.*' => 'uuid|exists:cat_modifiers,id',
            'notes' => 'nullable|string|max:255',
            // Curso del servicio (G-16). Se topa en 9 porque más que eso no es
            // un menú, es un error de tecleo.
            'course' => 'nullable|integer|min:1|max:9',
            'supervisor_code' => 'nullable|string|max:20',
            'supervisor_pin' => 'nullable|string|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $lines = match ($data['kind']) {
            'product' => $this->cart->addProduct(
                $sale,
                Product::findOrFail($data['product_id']),
                (string) ($data['qty'] ?? '1'),
                array_filter([
                    'uom_id' => $data['uom_id'] ?? null,
                    'unit_price' => isset($data['unit_price']) ? (string) $data['unit_price'] : null,
                    'discount_type' => $data['discount_type'] ?? null,
                    'discount_value' => isset($data['discount_value']) ? (string) $data['discount_value'] : null,
                    'lot_id' => $data['lot_id'] ?? null,
                    'location_id' => $data['location_id'] ?? null,
                    'modifiers' => $data['modifiers'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'course' => $data['course'] ?? null,
                ], static fn ($v) => $v !== null)
            ),
            'scan' => $this->cart->addScanned(
                $sale,
                $data['code'],
                isset($data['qty']) ? (string) $data['qty'] : null
            ),
            default => [$this->special($request, $sale, $data)],
        };

        return response()->json([
            'message' => __('sales.line_added'),
            'data' => [
                'lines' => $lines,
                'sale' => $sale->fresh(),
            ],
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $saleId, string $lineId)
    {
        $line = $this->findLine($request, $saleId, $lineId);

        if (! $line) {
            return response()->json(['message' => __('sales.line_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'qty' => 'sometimes|numeric',
            'unit_price' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|string|max:200',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'lot_id' => 'nullable|uuid|exists:inv_lots,id',
            'course' => 'sometimes|integer|min:1|max:9',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        return response()->json([
            'message' => __('sales.line_updated'),
            'data' => [
                'line' => $this->cart->updateLine($line, $validator->validated()),
                'sale' => $line->sale->fresh(),
            ],
            'status' => 200,
        ], 200);
    }

    public function destroy(Request $request, string $saleId, string $lineId)
    {
        $line = $this->findLine($request, $saleId, $lineId);

        if (! $line) {
            return response()->json(['message' => __('sales.line_not_found'), 'status' => 404], 404);
        }

        $sale = $line->sale;
        $this->cart->removeLine($line);

        return response()->json([
            'message' => __('sales.line_removed'),
            'data' => ['sale' => $sale->fresh()],
            'status' => 200,
        ], 200);
    }

    /**
     * Ítem temporal o venta por monto (D-03).
     *
     * Cinco por cajero por día; el sexto exige PIN de supervisor. Y el aviso al
     * supervisor sale **siempre**, dentro o fuera del límite: "sea cual sea la
     * decisión, siempre notificar al supervisor de este tipo de acción".
     *
     * @param  array<string,mixed>  $data
     */
    private function special(Request $request, Sale $sale, array $data): SaleLine
    {
        $branchId = $request->attributes->get('branch_id');
        $employee = $request->attributes->get('operator_session')->employee;

        $authorizer = null;

        if (! empty($data['supervisor_code']) && ! empty($data['supervisor_pin'])) {
            $authorizer = $this->authorizer->authorize(
                $data['supervisor_code'],
                $data['supervisor_pin'],
                SpecialLineGuard::PERMISSION,
                $branchId
            );
        }

        $this->specialLines->assertAllowed($employee, $branchId, $authorizer);

        $line = $data['kind'] === 'temporary'
            ? $this->cart->addTemporary(
                $sale,
                $data['description'],
                (string) ($data['qty'] ?? '1'),
                (string) ($data['unit_price'] ?? '0')
            )
            : $this->cart->addAmount($sale, $data['description'], (string) $data['amount']);

        $this->specialLines->notify($line, $employee, $authorizer);

        $this->audit->record(
            event: 'sale.special_line',
            entityType: 'sale_line',
            entityId: $line->id,
            context: [
                'kind' => $line->kind,
                'description' => $line->description,
                'total' => (string) $line->total,
                'used_today' => $this->specialLines->usedToday($employee),
            ],
            authorizedBy: $authorizer?->id,
        );

        return $line;
    }

    private function findSale(Request $request, string $saleId): ?Sale
    {
        return Sale::where('branch_id', $request->attributes->get('branch_id'))->find($saleId);
    }

    private function findLine(Request $request, string $saleId, string $lineId): ?SaleLine
    {
        $sale = $this->findSale($request, $saleId);

        return $sale?->lines()->whereKey($lineId)->first();
    }
}
