<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Denomination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Billetes y monedas del arqueo (D-06).
 *
 * Las de Nicaragua vienen sembradas —billetes de 10, 20, 50, 100 y 1000;
 * monedas de 0,25, 0,50, 1, 5 y 10— y son **configurables por país**, porque no
 * hay dos iguales y porque un arqueo que pide contar denominaciones inexistentes
 * se abandona a la semana.
 *
 * Se dan de baja, no se borran: los arqueos ya cerrados las referencian y el
 * histórico tiene que seguir cuadrando.
 */
class DenominationController extends Controller
{
    public function index(Request $request)
    {
        $items = Denomination::query()
            ->when($request->filled('currency_code'), fn ($q) => $q->where('currency_code', $request->string('currency_code')))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('currency_code')
            ->orderByDesc('value')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('cash.no_denominations'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('cash.denominations_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $exists = Denomination::where('currency_code', $data['currency_code'])
            ->where('value', $data['value'])
            ->first();

        // Repetir una denominación partiría el conteo en dos filas que suman lo
        // mismo y nadie sabría en cuál anotar.
        if ($exists) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => ['value' => [__('cash.denomination_exists')]],
                'status' => 422,
            ], 422);
        }

        $denomination = Denomination::create($data);

        return response()->json([
            'message' => __('cash.denomination_created'),
            'data' => $denomination,
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $denomination = Denomination::find($id);

        if (! $denomination) {
            return response()->json(['message' => __('cash.denomination_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), $this->rules(sometimes: true));

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $denomination->update($validator->validated());

        return response()->json([
            'message' => __('cash.denomination_updated'),
            'data' => $denomination->fresh(),
            'status' => 200,
        ], 200);
    }

    /** Baja lógica: los arqueos ya cerrados la referencian. */
    public function destroy(string $id)
    {
        $denomination = Denomination::find($id);

        if (! $denomination) {
            return response()->json(['message' => __('cash.denomination_not_found'), 'status' => 404], 404);
        }

        $denomination->update(['is_active' => false]);

        return response()->json(['message' => __('cash.denomination_deactivated'), 'status' => 200], 200);
    }

    /** @return array<string,mixed> */
    private function rules(bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';

        return [
            'currency_code' => $prefix.'required|string|size:3|exists:cmn_currencies,code',
            'value' => $prefix.'required|numeric|gt:0',
            // Billete o moneda: el arqueo los cuenta en bloques distintos
            // porque así están en el cajón.
            'kind' => $prefix.'required|in:bill,coin',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ];
    }
}
