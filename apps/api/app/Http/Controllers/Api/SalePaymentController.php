<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\Sales\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Pagos de una venta (B-02, Q-06).
 *
 * Varios por venta: mitad efectivo y mitad tarjeta es lo normal. Cada uno guarda
 * moneda recibida, tasa aplicada y equivalente en moneda base — en Nicaragua el
 * cliente entrega dólares y el vuelto sale en córdobas todos los días.
 */
class SalePaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function store(Request $request, string $saleId)
    {
        $sale = Sale::where('branch_id', $request->attributes->get('branch_id'))->find($saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'method' => 'required|in:cash,card,credit,transfer,other',
            'amount' => 'required|numeric',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'reference' => 'nullable|string|max:80',
            'card_brand' => 'nullable|string|max:30',
            'authorization_code' => 'nullable|string|max:40',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $payment = $this->payments->register($sale, $validator->validated());

        return response()->json([
            'message' => __('sales.payment_registered'),
            'data' => ['payment' => $payment, 'sale' => $sale->fresh()],
            'status' => 201,
        ], 201);
    }

    public function destroy(Request $request, string $saleId, string $paymentId)
    {
        $payment = Payment::where('sale_id', $saleId)
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($paymentId);

        if (! $payment) {
            return response()->json(['message' => __('sales.payment_not_found'), 'status' => 404], 404);
        }

        $sale = $payment->sale;
        $this->payments->remove($payment);

        return response()->json([
            'message' => __('sales.payment_removed'),
            'data' => ['sale' => $sale->fresh()],
            'status' => 200,
        ], 200);
    }
}
