<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Sale;
use App\Services\Audit\AuditLogger;
use App\Services\Sales\CartService;
use App\Services\Sales\DiscountPolicy;
use App\Services\Sales\SaleCloser;
use App\Services\Sales\SaleTransferService;
use App\Services\Supervision\SupervisorAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * La venta y su carrito (B-01, D-21).
 *
 * El carrito es una venta en estado `draft` con identidad propia, y su
 * identificador lo genera **el terminal**: necesita poder armar una venta sin
 * consultar al servidor, tanto para el modo degradado como para que el ERP tenga
 * su clave de idempotencia desde el primer momento.
 */
class SaleController extends Controller
{
    public function __construct(
        private CartService $cart,
        private SaleCloser $closer,
        private DiscountPolicy $discounts,
        private SupervisorAuthorizer $authorizer,
        private AuditLogger $audit,
        private SaleTransferService $transfers,
    ) {}

    public function index(Request $request)
    {
        $sales = Sale::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('shift_id'), fn ($q) => $q->where('shift_id', $request->string('shift_id')))
            ->with(['customer.person:id,full_name', 'employee.person:id,full_name'])
            ->orderByDesc('opened_at')
            ->limit((int) $request->integer('limit', 100))
            ->get();

        if ($sales->isEmpty()) {
            return response()->json(['message' => __('sales.none_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('sales.retrieved'),
            'data' => $sales,
            'status' => 200,
        ], 200);
    }

    public function show(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('sales.retrieved'),
            'data' => $sale->load(['lines.taxes', 'payments', 'customer']),
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Lo genera el terminal (D-21). Si no viene, lo genera el servidor.
            'id' => 'nullable|uuid',
            'sale_type' => 'nullable|in:counter,invoice,quote,work_order,refund',
            'customer_id' => 'nullable|uuid|exists:crm_customers,id',
            'shift_id' => 'nullable|uuid|exists:pos_shifts,id',
            'currency_code' => 'nullable|string|size:3',
            'reverses_sale_id' => 'nullable|uuid|exists:pos_sales,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $sale = $this->cart->open(array_merge($data, [
            'branch_id' => $request->attributes->get('branch_id'),
            'terminal_id' => $request->user()->getKey(),
            'employee_id' => $request->attributes->get('employee_id'),
        ]));

        if (! empty($data['reverses_sale_id'])) {
            $sale->forceFill(['reverses_sale_id' => $data['reverses_sale_id']])->save();
        }

        return response()->json([
            'message' => __('sales.opened'),
            'data' => $sale->fresh(),
            'status' => 201,
        ], 201);
    }

    /**
     * Descuento sobre el total de la venta (D-02).
     *
     * El tope es **por cajero** y lo fija el supervisor. Pasado el tope, la
     * operación no se rechaza: exige autorización en el momento con el PIN de
     * supervisor, que es distinto del de sesión (P-11).
     */
    public function discount(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:percent,amount',
            'value' => 'nullable|numeric|min:0',
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
        $branchId = $request->attributes->get('branch_id');
        $employee = $request->attributes->get('operator_session')->employee;

        // Quitar el descuento no necesita autorización: deshacer un favor no es
        // un favor.
        if (($data['type'] ?? null) === null) {
            return response()->json([
                'message' => __('sales.discount_cleared'),
                'data' => $this->cart->setSaleDiscount($sale, null, null),
                'status' => 200,
            ], 200);
        }

        $percent = $this->discounts->effectivePercent(
            $data['type'],
            (string) $data['value'],
            (string) $sale->gross
        );

        $authorizer = null;

        if (! empty($data['supervisor_code']) && ! empty($data['supervisor_pin'])) {
            $authorizer = $this->authorizer->authorize(
                $data['supervisor_code'],
                $data['supervisor_pin'],
                DiscountPolicy::PERMISSION_AUTHORIZE,
                $branchId
            );
        }

        $this->discounts->assertAllowed($employee, $percent, $branchId, $authorizer);

        $updated = $this->cart->setSaleDiscount(
            $sale,
            $data['type'],
            (string) $data['value'],
            $authorizer?->id
        );

        $this->audit->record(
            event: $authorizer !== null ? 'sale.discount_authorized' : 'sale.discount',
            entityType: 'sale',
            entityId: $sale->id,
            context: [
                'type' => $data['type'],
                'value' => (string) $data['value'],
                'effective_percent' => $percent,
                'limit' => $this->discounts->limitFor($employee),
            ],
            authorizedBy: $authorizer?->id,
        );

        return response()->json([
            'message' => __('sales.discount_applied'),
            'data' => $updated,
            'status' => 200,
        ], 200);
    }

    /**
     * Fija la propina de la cuenta (G-16).
     *
     * Queda en bitácora aunque no sea un importe fiscal: es dinero que entra al
     * cajón y sale a otra mano, y al cuadrar el turno hay que poder decir quién
     * la tecleó. Sin eso, un faltante y una propina mal puesta se ven igual.
     */
    public function tip(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric',
            'employee_id' => 'nullable|uuid|exists:sec_employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $updated = $this->cart->setTip(
            $sale,
            (string) $data['amount'],
            $data['employee_id'] ?? null
        );

        $this->audit->record(
            event: 'sale.tip',
            entityType: 'sale',
            entityId: $sale->id,
            context: [
                'amount' => (string) $updated->tip_amount,
                'employee_id' => $updated->tip_employee_id,
            ],
        );

        return response()->json([
            'message' => __('sales.tip_set'),
            'data' => $updated,
            'status' => 200,
        ], 200);
    }

    /** Suspender es un cambio de estado, no una copia a tablas espejo (D-01). */
    /**
     * Traspasa líneas a otra cuenta, o la cuenta entera a otra mesa (F1-B).
     *
     * Es la misma operación con dos destinos: el grupo que se cambió de mesa y
     * la pareja que se sentó con otros. Lo que ya salió a cocina **no** se
     * reescribe: la línea cambia de cuenta, su comanda no.
     */
    public function transfer(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'target_sale_id' => 'required_without:dining_table_id|nullable|uuid|exists:pos_sales,id',
            'dining_table_id' => 'required_without:target_sale_id|nullable|uuid|exists:pos_dining_tables,id',
            'lines' => 'nullable|array',
            'lines.*' => 'uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $result = match (true) {
            ! empty($data['target_sale_id']) => $this->transfers->transfer(
                $sale,
                $this->find($request, $data['target_sale_id']) ?? abort(404),
                $data['lines'] ?? []
            ),
            default => $this->transfers->moveToTable(
                $sale,
                DiningTable::where('branch_id', $request->attributes->get('branch_id'))
                    ->findOrFail($data['dining_table_id']),
                ['employee_id' => $request->attributes->get('employee_id')]
            ),
        };

        return response()->json([
            'message' => __('sales.transferred'),
            'data' => ['sale' => $result['source'], 'target' => $result['target']],
            'status' => 200,
        ], 200);
    }

    /**
     * Divide la cuenta: lo elegido se va a una cuenta nueva **en la misma mesa**.
     *
     * Dividir no es levantarse: el mapa tiene que seguir mostrando una sola mesa
     * ocupada, con dos cuentas que se cobran por separado.
     */
    public function split(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'lines' => 'required|array|min:1',
            'lines.*' => 'uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $result = $this->transfers->split($sale, $validator->validated()['lines'], [
            'terminal_id' => $request->user()->getKey(),
            'employee_id' => $request->attributes->get('employee_id'),
        ]);

        return response()->json([
            'message' => __('sales.split_done'),
            'data' => ['sale' => $result['source'], 'target' => $result['target']],
            'status' => 201,
        ], 201);
    }

    public function suspend(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $suspended = $this->cart->suspend($sale, $request->string('label')->toString() ?: null);

        $this->audit->record('sale.suspended', 'sale', $sale->id, context: ['label' => $suspended->label]);

        return response()->json([
            'message' => __('sales.suspended'),
            'data' => $suspended,
            'status' => 200,
        ], 200);
    }

    /** Se retoma en **cualquier** terminal de la sucursal, no solo donde empezó. */
    public function resume(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $resumed = $this->cart->resume(
            $sale,
            $request->user()->getKey(),
            $request->attributes->get('employee_id')
        );

        $this->audit->record('sale.resumed', 'sale', $sale->id);

        return response()->json([
            'message' => __('sales.resumed'),
            'data' => $resumed->load('lines.taxes', 'payments'),
            'status' => 200,
        ], 200);
    }

    public function close(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $closed = $this->closer->close($sale);

        return response()->json([
            'message' => __('sales.closed'),
            'data' => $closed->load('lines.taxes', 'payments'),
            'status' => 200,
        ], 200);
    }

    private function find(Request $request, string $id): ?Sale
    {
        return Sale::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($id);
    }
}
