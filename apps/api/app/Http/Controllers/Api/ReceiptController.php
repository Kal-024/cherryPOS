<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Models\Sale;
use App\Services\Delivery\DocumentDeliveryService;
use App\Services\Receipts\ReceiptPdf;
use App\Services\Receipts\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Comprobantes (H5).
 *
 * Tres formas del mismo documento:
 *
 *  - **Líneas** — lo que el terminal dibuja en pantalla, y lo que consumirá el
 *    agente de impresión térmica cuando exista (Q-05).
 *  - **PDF** — lo que hoy se entrega al cliente, porque el hardware todavía no
 *    está definido.
 *  - **Envío** — WhatsApp o correo, **solo a pedido del cliente en caja** y
 *    siempre encolado: nunca bloquea (P-04).
 */
class ReceiptController extends Controller
{
    public function __construct(
        private ReceiptService $receipts,
        private ReceiptPdf $pdf,
        private DocumentDeliveryService $deliveries,
    ) {}

    /** Las líneas listas para dibujar o imprimir. */
    public function show(Request $request, string $saleId)
    {
        $sale = $this->find($request, $saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $rendered = $this->receipts->forSale($sale, $request->string('template_id')->toString() ?: null);

        return response()->json([
            'message' => __('receipt.rendered'),
            'data' => [
                'paper' => $rendered['template']->paper,
                'width' => $rendered['template']->width(),
                'lines' => $rendered['lines'],
                // El cajero necesita saber si tiene que abrir la gaveta a mano:
                // mientras no exista el agente local, esto es lo único que hay
                // (Q-05, Q-12).
                'open_drawer' => $sale->payments->where('method', 'cash')->where('is_change', false)->isNotEmpty(),
            ],
            'status' => 200,
        ], 200);
    }

    public function pdf(Request $request, string $saleId)
    {
        $sale = $this->find($request, $saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $rendered = $this->receipts->forSale($sale, $request->string('template_id')->toString() ?: null);

        return $this->pdf->make($rendered['template'], $rendered['lines'], $rendered['context'])
            ->stream('comprobante-'.($sale->number ?? $sale->id).'.pdf');
    }

    /**
     * Encola el envío al cliente.
     *
     * Solo a pedido (P-04). La respuesta es inmediata porque **no manda nada**:
     * deja el pedido en la cola y devuelve su estado, que es lo que el cajero
     * mira.
     */
    public function deliver(Request $request, string $saleId)
    {
        $sale = $this->find($request, $saleId);

        if (! $sale) {
            return response()->json(['message' => __('sales.not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'channel' => 'required|in:whatsapp,email',
            'destination' => 'required|string|max:160',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $delivery = $this->deliveries->queue(
            $sale,
            $data['channel'],
            $data['destination'],
            $request->attributes->get('employee_id')
        );

        return response()->json([
            'message' => $delivery->status === 'unconfigured'
                ? __('delivery.queued_but_unconfigured')
                : __('delivery.queued'),
            'data' => $delivery,
            'status' => 201,
        ], 201);
    }

    /** Estado de los envíos de una venta: el cajero responde "¿le llegó?". */
    public function deliveries(Request $request, string $saleId)
    {
        $items = DocumentDelivery::where('sale_id', $saleId)
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->orderByDesc('created_at')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('delivery.none'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('delivery.retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    private function find(Request $request, string $saleId): ?Sale
    {
        return Sale::where('branch_id', $request->attributes->get('branch_id'))->find($saleId);
    }
}
