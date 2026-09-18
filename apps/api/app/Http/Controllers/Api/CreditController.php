<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditAccount;
use App\Models\Customer;
use App\Models\CustomerAuthorized;
use App\Services\Cash\ShiftService;
use App\Services\Credit\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Crédito de clientes (P-02, G-10, Q-10, H8).
 *
 * Versión reducida a propósito: límite, saldo, consumo, abono en caja,
 * autorizados y vista de supervisor. Intereses, planes de pago y cobranza son
 * del módulo de crédito del ERP.
 */
class CreditController extends Controller
{
    public function __construct(
        private CreditService $credit,
        private ShiftService $shifts,
    ) {}

    /**
     * Cuentas y su morosidad: la vista que el supervisor necesita para decidir
     * a quién llamar (G-10, P-07).
     */
    public function index(Request $request)
    {
        $accounts = CreditAccount::query()
            ->with('customer.person')
            ->when($request->boolean('with_balance_only', false), fn ($q) => $q->where('balance', '>', 0))
            ->when($request->boolean('blocked_only', false), fn ($q) => $q->where('is_blocked', true))
            ->orderByDesc('balance')
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json(['message' => __('credit.none_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('credit.retrieved'),
            'data' => $accounts->map(fn (CreditAccount $account) => [
                'id' => $account->id,
                'customer_id' => $account->customer_id,
                'customer_name' => $account->customer->name,
                'national_id' => $account->customer->national_id,
                'credit_limit' => (string) $account->credit_limit,
                'balance' => (string) $account->balance,
                'available' => $account->available(),
                'is_blocked' => (bool) $account->is_blocked,
                'blocked_reason' => $account->blocked_reason,
                'cut_off_day' => $account->cut_off_day,
            ]),
            'status' => 200,
        ], 200);
    }

    public function show(string $customerId)
    {
        $account = $this->find($customerId);

        if (! $account) {
            return response()->json(['message' => __('credit.not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('credit.retrieved'),
            'data' => [
                'account' => $account,
                'customer' => $account->customer->load('person'),
                'authorized' => CustomerAuthorized::with('person')
                    ->where('customer_id', $account->customer_id)
                    ->where('is_active', true)
                    ->get(),
                'available' => $account->available(),
            ],
            'status' => 200,
        ], 200);
    }

    public function open(Request $request, string $customerId)
    {
        $customer = Customer::with('person')->find($customerId);

        if (! $customer) {
            return response()->json(['message' => __('customer.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'credit_limit' => 'required|numeric|min:0',
            // Configurable por cliente: no todos cobran el 30 (Q-10).
            'cut_off_day' => 'nullable|integer|min:1|max:31',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $account = $this->credit->openAccount(
            $customer,
            (string) $data['credit_limit'],
            (int) ($data['cut_off_day'] ?? 30),
            $request->attributes->get('branch_id')
        );

        return response()->json([
            'message' => __('credit.account_opened'),
            'data' => $account,
            'status' => 201,
        ], 201);
    }

    /** Hasta tres, con datos completos (G-10). */
    public function addAuthorized(Request $request, string $customerId)
    {
        $account = $this->find($customerId);

        if (! $account) {
            return response()->json(['message' => __('credit.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:160',
            'national_id' => 'required|string|max:30',
            'phone' => 'nullable|string|max:40',
            'relationship' => 'nullable|string|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $authorized = $this->credit->addAuthorized(
            $account,
            [
                'full_name' => $data['name'],
                'national_id' => $data['national_id'],
                'phone' => $data['phone'] ?? null,
            ],
            $data['relationship'] ?? null,
            $request->attributes->get('branch_id')
        );

        return response()->json([
            'message' => __('credit.authorized_added'),
            'data' => $authorized->load('person'),
            'status' => 201,
        ], 201);
    }

    public function removeAuthorized(Request $request, string $customerId, string $authorizedId)
    {
        $authorized = CustomerAuthorized::where('customer_id', $customerId)->find($authorizedId);

        if (! $authorized) {
            return response()->json(['message' => __('credit.authorized_not_found'), 'status' => 404], 404);
        }

        $this->credit->removeAuthorized($authorized, $request->attributes->get('branch_id'));

        return response()->json(['message' => __('credit.authorized_removed'), 'status' => 200], 200);
    }

    /** Abono en caja, total o parcial (H8.4). El dinero entra al cajón. */
    public function pay(Request $request, string $customerId)
    {
        $account = $this->find($customerId);

        if (! $account) {
            return response()->json(['message' => __('credit.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|gt:0',
            'method' => 'nullable|in:cash,card,transfer,other',
            'comment' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $entry = $this->credit->pay(
            $account,
            (string) $data['amount'],
            $request->attributes->get('branch_id'),
            $request->attributes->get('employee_id'),
            $this->shifts->current($request->user()),
            $data['method'] ?? 'cash',
            $data['comment'] ?? null
        );

        return response()->json([
            'message' => __('credit.payment_recorded'),
            'data' => ['entry' => $entry, 'account' => $account->fresh()],
            'status' => 201,
        ], 201);
    }

    /** Bloqueo manual del supervisor (H8.6). Se suma al límite, no lo reemplaza. */
    public function block(Request $request, string $customerId)
    {
        $account = $this->find($customerId);

        if (! $account) {
            return response()->json(['message' => __('credit.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:255']);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        return response()->json([
            'message' => __('credit.account_blocked'),
            'data' => $this->credit->block(
                $account,
                $request->attributes->get('employee_id'),
                $validator->validated()['reason'],
                $request->attributes->get('branch_id')
            ),
            'status' => 200,
        ], 200);
    }

    public function unblock(Request $request, string $customerId)
    {
        $account = $this->find($customerId);

        if (! $account) {
            return response()->json(['message' => __('credit.not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('credit.account_unblocked'),
            'data' => $this->credit->unblock(
                $account,
                $request->attributes->get('employee_id'),
                $request->attributes->get('branch_id')
            ),
            'status' => 200,
        ], 200);
    }

    /**
     * Estado de cuenta del período (H8.5).
     *
     * Se emite a pedido o al cancelar (Q-10). El envío por correo queda para
     * cuando exista el canal; hoy se imprime.
     */
    public function statement(Request $request, string $customerId)
    {
        $account = $this->find($customerId);

        if (! $account) {
            return response()->json(['message' => __('credit.not_found'), 'status' => 404], 404);
        }

        $reference = $request->filled('date')
            ? Carbon::parse($request->string('date')->toString())
            : null;

        return response()->json([
            'message' => __('credit.statement_retrieved'),
            'data' => $this->credit->statement($account, $reference),
            'status' => 200,
        ], 200);
    }

    private function find(string $customerId): ?CreditAccount
    {
        return CreditAccount::with('customer.person')->where('customer_id', $customerId)->first();
    }
}
