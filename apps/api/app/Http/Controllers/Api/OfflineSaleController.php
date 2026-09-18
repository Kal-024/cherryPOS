<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxCode;
use App\Services\Offline\OfflineSaleService;
use App\Services\Sales\DocumentNumberService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Modo degradado (H6).
 *
 * Dos operaciones, y la primera es la que hace posible la segunda:
 *
 *  - **Reservar correlativos** antes de quedarse sin red, para que dos cajas no
 *    emitan el mismo número (H6.3).
 *  - **Recibir el ticket** que el terminal cerró con el servidor apagado.
 *
 * El alcance del modo degradado es **acotado a propósito** (H6): sin servidor no
 * hay stock en tiempo real de otras cajas, ni venta a crédito, ni cierre de
 * turno, ni autorización de supervisor, ni consulta de historial. Prometer
 * paridad sería prometer lo que no se puede sostener.
 */
class OfflineSaleController extends Controller
{
    public function __construct(
        private OfflineSaleService $offline,
        private DocumentNumberService $numbers,
        private SettingsRepository $settings,
    ) {}

    /**
     * Todo lo que el terminal necesita para poder vender sin servidor.
     *
     * Se pide al abrir la caja. Lo que no se haya bajado antes del corte no va a
     * poder bajarse durante.
     */
    public function bootstrap(Request $request)
    {
        $terminal = $request->user();
        $branchId = $terminal->branch_id;

        $reservations = [];

        foreach (['counter', 'invoice', 'refund'] as $documentType) {
            $reservation = $this->numbers->reservationFor($terminal, $documentType);

            if ($reservation) {
                $reservations[$documentType] = [
                    'range_from' => $reservation->range_from,
                    'range_to' => $reservation->range_to,
                    'next_number' => $reservation->next_number,
                    'remaining' => $reservation->remaining(),
                    'template' => $reservation->series->template,
                ];
            }
        }

        return response()->json([
            'message' => __('offline.bootstrap_retrieved'),
            'data' => [
                'terminal' => array_merge(
                    $terminal->only(['id', 'code', 'name']),
                    ['layout_profile' => $terminal->layoutProfile()],
                ),
                'branch' => $terminal->branch->only(['id', 'code', 'name', 'timezone']),
                'settings' => [
                    // La ventana de operación sin sincronizar (H6.5). Pasado el
                    // plazo la caja deja de vender: un ticket muy viejo choca con
                    // el cierre de período y con precios ya cambiados.
                    'offline_max_hours' => (int) $this->settings->get(
                        'pos.offline_max_hours', config('pos.offline_max_hours'), $branchId, $terminal->id
                    ),
                    'fixed_quota_regime' => (bool) $this->settings->get(
                        'tax.fixed_quota_regime', false, $branchId, $terminal->id
                    ),
                    'cash_rounding_mode' => (string) $this->settings->get(
                        'cash.rounding_mode', config('pos.cash_rounding_mode'), $branchId
                    ),
                    'cash_rounding_increment' => (string) $this->settings->get(
                        'cash.rounding_increment', config('pos.cash_rounding_increment'), $branchId
                    ),
                    // Propina (G-16). Apagada por defecto: en un mostrador de
                    // retail el renglón solo estorba, y encenderla es decisión
                    // del negocio, igual que el redondeo.
                    'tip_enabled' => (bool) $this->settings->get(
                        'tip.enabled', false, $branchId, $terminal->id
                    ),
                    'tip_suggested_percent' => (string) $this->settings->get(
                        'tip.suggested_percent', '10', $branchId, $terminal->id
                    ),
                    'base_currency' => config('pos.base_currency'),
                    'secondary_currency' => config('pos.secondary_currency'),
                ],
                // Los códigos de impuesto viajan enteros porque el motor del
                // terminal los necesita para calcular: `base` decide si el precio
                // trae el impuesto dentro.
                'tax_codes' => TaxCode::where('is_active', true)
                    ->get(['id', 'code', 'name', 'rate', 'type', 'base']),
                'reservations' => $reservations,
            ],
            'status' => 200,
        ], 200);
    }

    /** Reserva un bloque de correlativos para esta terminal (H6.3). */
    public function reserve(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:counter,invoice,quote,work_order,refund',
            'size' => 'nullable|integer|min:1|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $reservation = $this->numbers->reserve(
            $request->user(),
            $data['document_type'],
            (int) ($data['size'] ?? 100)
        );

        return response()->json([
            'message' => __('offline.reserved'),
            'data' => [
                'document_type' => $data['document_type'],
                'range_from' => $reservation->range_from,
                'range_to' => $reservation->range_to,
                'next_number' => $reservation->next_number,
                'template' => $reservation->series->template,
            ],
            'status' => 201,
        ], 201);
    }

    /**
     * Recibe un ticket cerrado sin conexión.
     *
     * Devuelve **200** cuando el ticket ya estaba registrado y **201** cuando es
     * nuevo — indistinguible del éxito para la cola del terminal, que reintenta
     * por diseño. Es la misma disciplina que el POS espera del ERP.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|uuid',
            'number' => 'required|string|max:40',
            'sale_type' => 'nullable|in:counter,invoice,quote,work_order,refund',
            'employee_id' => 'required|uuid|exists:sec_employees,id',
            'customer_id' => 'nullable|uuid|exists:crm_customers,id',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'opened_at' => 'nullable|date',
            'closed_at' => 'required|date',

            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|uuid|exists:cat_products,id',
            'lines.*.description' => 'required|string|max:200',
            'lines.*.kind' => 'nullable|in:product,temporary,amount',
            'lines.*.qty' => 'required|numeric',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percent,amount',
            'lines.*.discount_value' => 'nullable|numeric|min:0',

            // Propina cobrada sin conexión (G-16). No es fiscal, pero es plata
            // del cajón: sin ella el arqueo del turno saldría sobrado.
            'tip_amount' => 'nullable|numeric',
            'tip_employee_id' => 'nullable|uuid|exists:sec_employees,id',

            'payments' => 'nullable|array',
            // `credit` se admite en la validación de forma y se rechaza después
            // con su motivo: "el medio de pago no existe" no le dice nada al
            // cajero, y "sin conexión no se puede vender a crédito" sí.
            'payments.*.method' => 'required|in:cash,card,credit,transfer,other',
            'payments.*.amount' => 'required|numeric',
            'payments.*.currency_code' => 'nullable|string|size:3',
            'payments.*.exchange_rate' => 'nullable|numeric|gt:0',

            'totals.total' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        // Sin conexión no hay venta a crédito: el límite y el bloqueo de la
        // cuenta solo los sabe el servidor (alcance de H6).
        foreach ($request->input('payments', []) as $payment) {
            if (($payment['method'] ?? '') === 'credit') {
                return response()->json([
                    'message' => __('validation.errors'),
                    'errors' => ['payments' => [__('offline.credit_not_allowed')]],
                    'status' => 422,
                ], 422);
            }
        }

        $result = $this->offline->receive($validator->validated(), $request->user());

        return response()->json([
            'message' => $result['duplicate'] ? __('offline.already_received') : __('offline.received'),
            'data' => [
                'sale_id' => $result['sale']->id,
                'number' => $result['sale']->number,
                'duplicate' => $result['duplicate'],
                'total' => (string) $result['sale']->total,
                // Distinta de cero significa que los dos motores divergen.
                'total_difference' => $result['difference'],
            ],
            'status' => $result['duplicate'] ? 200 : 201,
        ], $result['duplicate'] ? 200 : 201);
    }
}
