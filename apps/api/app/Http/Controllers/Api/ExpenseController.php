<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Cash\ShiftService;
use App\Services\Expenses\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Gastos categorizados (B-14).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad."* Un gasto pagado del cajón
 * sale como movimiento de caja, para que el arqueo pueda explicar esa plata.
 */
class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseService $expenses,
        private ShiftService $shifts,
    ) {}

    public function categories()
    {
        $items = ExpenseCategory::where('is_active', true)->orderBy('name')->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('expense.no_categories'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('expense.categories_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function index(Request $request)
    {
        $items = Expense::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('document_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('document_date', '<=', $request->date('to')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->string('category_id')))
            ->when($request->filled('shift_id'), fn ($q) => $q->where('shift_id', $request->string('shift_id')))
            ->with(['category:id,code,name,behaviour', 'supplier.person:id,full_name'])
            ->orderByDesc('document_date')
            ->limit((int) $request->integer('limit', 200))
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('expense.none_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('expense.retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|uuid|exists:exp_categories,id',
            'supplier_id' => 'nullable|uuid|exists:crm_suppliers,id',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'document_number' => 'nullable|string|max:40',
            'document_date' => 'nullable|date',
            'payment_method' => 'nullable|in:cash,card,transfer,credit,other',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $expense = $this->expenses->record(
            array_merge($validator->validated(), [
                'branch_id' => $request->attributes->get('branch_id'),
                'employee_id' => $request->attributes->get('employee_id'),
            ]),
            $this->shifts->current($request->user())
        );

        return response()->json([
            'message' => __('expense.recorded'),
            'data' => $expense->load('category', 'supplier.person'),
            'status' => 201,
        ], 201);
    }

    /** Un gasto no se borra ni se edita: se anula y se compensa (A-05). */
    public function void(Request $request, string $id)
    {
        $expense = Expense::where('branch_id', $request->attributes->get('branch_id'))->find($id);

        if (! $expense) {
            return response()->json(['message' => __('expense.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $voided = $this->expenses->void(
            $expense,
            $request->attributes->get('employee_id'),
            $validator->validated()['reason'],
            $this->shifts->current($request->user())
        );

        return response()->json([
            'message' => __('expense.voided'),
            'data' => $voided,
            'status' => 200,
        ], 200);
    }
}
