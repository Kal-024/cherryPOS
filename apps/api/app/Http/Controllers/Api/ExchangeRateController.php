<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Tipo de cambio (Q-06).
 *
 * Lo carga el supervisor y **rige hasta que lo cambie** — puede mantenerse toda
 * la semana. No hay consulta en línea: la red local puede no tener internet, y
 * una caja que no cobra en dólares porque no alcanzó una API sería absurda.
 */
class ExchangeRateController extends Controller
{
    public function __construct(private ExchangeRateService $rates) {}

    public function show(Request $request)
    {
        $currency = $request->string('currency_code')->toString()
            ?: config('pos.secondary_currency');

        $rate = $this->rates->current($currency);

        if ($rate === null) {
            return response()->json([
                'message' => __('sales.missing_exchange_rate', ['currency' => $currency]),
                'status' => 404,
            ], 404);
        }

        return response()->json([
            'message' => __('sales.exchange_rate_retrieved'),
            'data' => ['currency_code' => $currency, 'rate' => $rate],
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currency_code' => 'required|string|size:3|exists:cmn_currencies,code',
            'rate' => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $this->rates->set(
            $data['currency_code'],
            (string) $data['rate'],
            $request->attributes->get('employee_id')
        );

        return response()->json([
            'message' => __('sales.exchange_rate_saved'),
            'data' => ['currency_code' => $data['currency_code'], 'rate' => (string) $data['rate']],
            'status' => 201,
        ], 201);
    }
}
