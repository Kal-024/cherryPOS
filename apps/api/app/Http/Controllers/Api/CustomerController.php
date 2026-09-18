<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Clientes (D-09, P-03, Q-03).
 *
 * **Dos clases, y la distinción es toda la ficha.** El de efectivo paga y se va:
 * solo nombre, y ni siquiera eso es obligatorio, porque la mayoría de las ventas
 * no llevan cliente. El de cuenta abre crédito: cédula obligatoria y datos
 * completos.
 *
 * **Crearlos es del supervisor** (Q-03): en caja solo se usan los ya
 * registrados. Cuando se integre el ERP, la creación pasa allá y el cajero
 * sigue operando con cada cliente activo.
 */
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $items = Customer::query()
            ->with(['person', 'creditAccount'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->string('search')->toString()))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->limit((int) $request->integer('limit', 100))
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('customer.none_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('customer.retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function show(string $id)
    {
        $customer = Customer::with(['person', 'creditAccount', 'authorized.person'])->find($id);

        if (! $customer) {
            return response()->json(['message' => __('customer.not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('customer.retrieved'),
            'data' => $customer,
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

        // La cuenta es personal: sin cédula no se sabe de quién es (G-10).
        if (($data['kind'] ?? 'cash') === 'account' && blank($data['national_id'] ?? null)) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => ['national_id' => [__('credit.account_needs_national_id')]],
                'status' => 422,
            ], 422);
        }

        $customer = Customer::createWithPerson(
            $this->personAttributes($data),
            $this->roleAttributes($data)
        );

        return response()->json([
            'message' => __('customer.created'),
            'data' => $customer->load('person'),
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $customer = Customer::with('person')->find($id);

        if (! $customer) {
            return response()->json(['message' => __('customer.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), $this->rules(sometimes: true));

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $customer->update($this->roleAttributes($data));
        // La identidad se edita en la persona: cambiarle el teléfono al cliente
        // se lo cambia también al proveedor y al empleado que sea el mismo
        // actor (B-11).
        $customer->person->update(array_filter($this->personAttributes($data), fn ($v) => $v !== null));

        return response()->json([
            'message' => __('customer.updated'),
            'data' => $customer->fresh()->load('person'),
            'status' => 200,
        ], 200);
    }

    /** @return array<string,mixed> */
    private function rules(bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';

        return [
            'name' => $prefix.'required|string|max:160',
            'kind' => 'nullable|in:cash,account',
            'national_id' => 'nullable|string|max:30',
            'tax_id' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:160',
            'phone' => 'nullable|string|max:40',
            'whatsapp' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:30',
            'is_tax_exempt' => 'boolean',
            'tax_exempt_reference' => 'nullable|string|max:60',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'consent_email' => 'boolean',
            'consent_whatsapp' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function personAttributes(array $data): array
    {
        return array_intersect_key(
            array_merge($data, ['full_name' => $data['name'] ?? null]),
            array_flip(['full_name', 'national_id', 'tax_id', 'email', 'phone', 'whatsapp', 'address'])
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function roleAttributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'kind', 'code', 'is_tax_exempt', 'tax_exempt_reference',
            'discount_percent', 'consent_email', 'consent_whatsapp', 'is_active',
        ]));

        // El consentimiento se fecha cuando se otorga: "dijo que sí alguna vez"
        // no sirve como constancia (D-09).
        if (($attributes['consent_email'] ?? false) || ($attributes['consent_whatsapp'] ?? false)) {
            $attributes['consent_given_at'] = now();
        }

        return $attributes;
    }
}
