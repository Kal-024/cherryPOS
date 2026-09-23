<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Employee;
use App\Models\Sale;
use App\Services\Audit\AuditLogger;
use App\Services\Sales\CartService;
use App\Services\Sales\DiscountPolicy;
use App\Services\Sales\RefundService;
use App\Services\Sales\SaleCloser;
use App\Services\Sales\SaleTransferService;
use App\Services\Supervision\SupervisorAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
        private RefundService $refunds,
    ) {}

    public function index(Request $request)
    {
        $sales = Sale::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('shift_id'), fn ($q) => $q->where('shift_id', $request->string('shift_id')))
            // Por número: es lo que trae el cliente impreso en la mano cuando
            // viene a devolver, y buscarlo por fecha entre las del día sería
            // hacerle esperar de pie.
            ->when($request->filled('number'), fn ($q) => $q->whereRaw(
                'upper(number) like ?',
                ['%'.mb_strtoupper(trim($request->string('number')->toString())).'%']
            ))
            ->when($request->filled('sale_type'), fn ($q) => $q->where('sale_type', $request->string('sale_type')))
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
            // Devolver es del supervisor (`pos_sale.refund`). El cajero arma la
            // devolución y la autoriza con el PIN del supervisor en la misma
            // terminal, igual que un descuento sobre el tope: ir a buscar a
            // alguien que se siente en la caja, con el cliente esperando, es lo
            // que hace que los controles se salten.
            'supervisor_code' => 'nullable|string|max:40',
            'supervisor_pin' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        if (($data['sale_type'] ?? 'counter') === 'refund') {
            $this->assertRefundIsAllowed($request, $data);
        }

        unset($data['supervisor_code'], $data['supervisor_pin']);

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
     * La regla del ticket original, impuesta **antes** de devolver.
     *
     * El contrato dice que el ERP rechaza la nota de crédito con
     * `refund_original_missing` cuando el documento no existe, pero esa respuesta
     * llega por la cola asíncrona: para entonces el dinero ya salió del cajón.
     * Comprobarlo acá es la diferencia entre un aviso y un descuadre.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertRefundIsAllowed(Request $request, array $data): void
    {
        $this->assertMayRefund($request, $data);

        $originalId = $data['reverses_sale_id'] ?? null;

        if ($originalId === null) {
            if (config('pos.refund_requires_original')) {
                throw ValidationException::withMessages([
                    'reverses_sale_id' => __('sales.refund_needs_original'),
                ]);
            }

            return;
        }

        $original = Sale::where('branch_id', $request->attributes->get('branch_id'))
            ->with('lines')
            ->find($originalId);

        if (! $original) {
            throw ValidationException::withMessages([
                'reverses_sale_id' => __('sales.refund_original_not_found'),
            ]);
        }

        $this->refunds->assertRefundable($original);
    }

    /**
     * Quién puede devolver.
     *
     * Con el permiso propio alcanza. Sin él, hace falta el PIN de un supervisor
     * que sí lo tenga — el de autorización, que es distinto del de su sesión
     * (P-11), porque devolver es sacar plata del cajón.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertMayRefund(Request $request, array $data): void
    {
        $branchId = $request->attributes->get('branch_id');
        $employee = Employee::find($request->attributes->get('employee_id'));

        if ($employee && in_array('pos_sale.refund', $employee->permissionCodes($branchId), true)) {
            return;
        }

        if (empty($data['supervisor_code']) || empty($data['supervisor_pin'])) {
            throw ValidationException::withMessages([
                'supervisor_pin' => __('sales.refund_needs_authorization'),
            ]);
        }

        $this->authorizer->authorize(
            $data['supervisor_code'],
            $data['supervisor_pin'],
            'pos_sale.refund',
            $branchId
        );
    }

    /**
     * Qué queda por devolver de un ticket.
     *
     * Descuenta lo ya devuelto: sin esa cuenta, tres devoluciones parciales de
     * una unidad vacían un ticket de dos y el mismo producto se paga dos veces.
     */
    public function refundable(Request $request, string $id)
    {
        $original = Sale::where('branch_id', $request->attributes->get('branch_id'))
            ->with('lines')
            ->find($id);

        if (! $original) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('sales.retrieved'),
            'data' => $this->refunds->refundable($original),
            'status' => 200,
        ], 200);
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

    /**
     * Asigna —o quita— el cliente de una venta abierta.
     *
     * `CartService::setCustomer()` existía desde el principio y **no tenía
     * puerta**: su único llamador era una prueba. Sin ella, el cliente solo se
     * podía fijar al abrir la venta, así que en la caja no había forma de
     * cargarle la cuenta a nadie y el crédito fallaba recién al cerrar, con un
     * "el cliente no tiene cuenta de crédito abierta" que no decía cómo
     * arreglarlo.
     *
     * No recibe permiso propio: quien puede vender puede elegir a quién le
     * vende. Crear clientes sigue siendo del supervisor (Q-03).
     */
    public function setCustomer(Request $request, string $id)
    {
        $sale = $this->find($request, $id);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'customer_id' => 'nullable|uuid|exists:crm_customers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        // El cliente exonerado cambia el impuesto, así que esto recalcula la
        // venta entera: por eso no se toca una cerrada.
        $updated = $this->cart->setCustomer($sale, $validator->validated()['customer_id'] ?? null);

        $this->audit->record(
            event: 'sale.customer',
            entityType: 'sale',
            entityId: $sale->id,
            context: ['customer_id' => $updated->customer_id],
        );

        return response()->json([
            'message' => __('sales.customer_set'),
            'data' => $updated->load(['lines.taxes', 'payments', 'customer']),
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
