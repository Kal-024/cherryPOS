<?php

namespace App\Services\Erp;

use App\Models\ErpOutboxEntry;
use App\Models\Sale;
use App\Services\Supervision\SupervisorNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cola de tickets hacia cherryERP (§6 del contrato, F1-C).
 *
 * **P4: el POS nunca depende del ERP para cerrar una venta.** Encolar es una
 * consecuencia del cierre, no una condición. Un ERP caído no detiene la
 * operación, y un rechazo no revierte nada — la venta ya se cobró.
 *
 * Dos reglas que gobiernan el reintento:
 *
 *  - **Ningún 4xx se reintenta.** Reintentar un 422 quema la cola contra un
 *    ticket que nunca va a pasar y retrasa los que sí pasarían. Van a
 *    `exception`, que es una bandeja que el supervisor opera.
 *  - **El 200 es éxito**, indistinguible del 201. Es el duplicado: el ticket ya
 *    estaba facturado y el ERP lo confirma. Si lo tratáramos como error,
 *    reintentaríamos para siempre algo ya hecho.
 */
class ErpOutboxService
{
    /** Espera base del retroceso exponencial, en segundos. */
    private const BACKOFF_BASE = 15;

    private const MAX_ATTEMPTS = 12;

    public function __construct(
        private ErpTicketPayload $payloads,
        private SupervisorNotifier $notifier,
    ) {}

    public function enqueue(Sale $sale): ?ErpOutboxEntry
    {
        // Sin ERP configurado el POS es autónomo y dueño de todo su dato (P-01).
        // No se encola nada: la cola existiría para nadie.
        if (! $this->configured()) {
            return null;
        }

        return ErpOutboxEntry::updateOrCreate(
            ['sale_id' => $sale->id],
            [
                'branch_id' => $sale->branch_id,
                'payload' => $this->payloads->build($sale),
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
            ]
        );
    }

    /** @return array<int,ErpOutboxEntry> */
    public function due(int $limit = 25): array
    {
        return ErpOutboxEntry::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function dispatch(ErpOutboxEntry $entry): ErpOutboxEntry
    {
        $entry->forceFill([
            'status' => 'sending',
            'attempts' => $entry->attempts + 1,
        ])->save();

        try {
            $response = Http::withToken((string) config('pos.erp.token'))
                ->withHeaders(['X-Company-Id' => (string) config('pos.erp.company_id')])
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim((string) config('pos.erp.base_url'), '/').'/pos/sales', $entry->payload);
        } catch (ConnectionException $e) {
            // Sin red no hay nada que decidir: se reintenta. Es la diferencia
            // entre "no llegué" y "llegué y me dijeron que no".
            return $this->retry($entry, null, 'connection', $e->getMessage());
        }

        $status = $response->status();
        $body = $response->json() ?? [];

        if ($status === 200 || $status === 201) {
            return $this->succeed($entry, $status, $body);
        }

        // 401 y 403 no son problemas del ticket sino de la credencial: se
        // reintentan porque un token vencido se renueva y la cola debe
        // recuperarse sola.
        if ($status >= 500 || $status === 401 || $status === 403 || $status === 429) {
            return $this->retry($entry, $status, $body['error'] ?? null, $body['message'] ?? null);
        }

        return $this->fail($entry, $status, $body);
    }

    /** @param array<string,mixed> $body */
    private function succeed(ErpOutboxEntry $entry, int $status, array $body): ErpOutboxEntry
    {
        $data = $body['data'] ?? [];
        $difference = (string) ($data['tax_difference'] ?? '0.00');

        $entry->forceFill([
            'status' => 'sent',
            'http_status' => $status,
            'erp_document_id' => $data['billing_document_id'] ?? null,
            'erp_document_number' => $data['document_number'] ?? null,
            'tax_difference' => $difference,
            'duplicate' => (bool) ($data['duplicate'] ?? false),
            'error_code' => null,
            'error_message' => null,
            'sent_at' => now(),
        ])->save();

        $entry->sale?->forceFill([
            'erp_status' => ($data['status'] ?? 'issued') === 'issued' ? 'issued' : 'draft',
            'erp_document_id' => $data['billing_document_id'] ?? null,
            'erp_document_number' => $data['document_number'] ?? null,
            'erp_tax_difference' => $difference,
        ])->save();

        $this->checkTaxDifference($entry, $difference);

        return $entry;
    }

    /**
     * `tax_difference` distinto de cero es **alarma, no dato** (§5 del contrato).
     *
     * El documento es válido, pero una diferencia sistemática se repetirá cada
     * día hasta que alguien mire por qué, y para entonces habrá cientos de
     * documentos con ella dentro. Es además el detector de que los dos motores
     * de cálculo se están separando.
     */
    private function checkTaxDifference(ErpOutboxEntry $entry, string $difference): void
    {
        if (bccomp($difference, '0', 2) === 0) {
            return;
        }

        Log::warning('cherryPOS: tax_difference distinto de cero', [
            'sale_id' => $entry->sale_id,
            'difference' => $difference,
        ]);

        $this->notifier->notify(
            event: 'erp.tax_difference',
            title: __('erp.tax_difference_title'),
            body: __('erp.tax_difference_body', ['difference' => $difference]),
            context: ['sale_id' => $entry->sale_id, 'difference' => $difference],
            severity: 'critical',
            entityType: 'sale',
            entityId: $entry->sale_id,
            branchId: $entry->branch_id,
        );
    }

    private function retry(ErpOutboxEntry $entry, ?int $status, ?string $code, ?string $message): ErpOutboxEntry
    {
        // Retroceso exponencial con techo: reintentar cada quince segundos
        // durante una noche entera no acerca al ERP y sí llena el registro.
        $delay = min(self::BACKOFF_BASE * (2 ** min($entry->attempts, 8)), 3600);
        $exhausted = $entry->attempts >= self::MAX_ATTEMPTS;

        $entry->forceFill([
            'status' => $exhausted ? 'exception' : 'pending',
            'http_status' => $status,
            'error_code' => $code,
            'error_message' => $message,
            'next_attempt_at' => $exhausted ? null : now()->addSeconds($delay),
        ])->save();

        if ($exhausted) {
            $this->notifyException($entry, $code ?? 'unreachable');
        }

        return $entry;
    }

    /** @param array<string,mixed> $body */
    private function fail(ErpOutboxEntry $entry, int $status, array $body): ErpOutboxEntry
    {
        $code = $body['error'] ?? 'validation';

        $entry->forceFill([
            'status' => 'exception',
            'http_status' => $status,
            'error_code' => $code,
            'error_message' => $body['message'] ?? null,
            'next_attempt_at' => null,
            // `issue_failed` es el caso delicado: el documento **sí** se creó y
            // quedó en borrador con su uuid. Reintentar devolvería 200
            // "duplicado" y daríamos por sincronizado algo que nadie emitió.
            'erp_document_id' => $body['billing_document_id'] ?? $entry->erp_document_id,
        ])->save();

        $entry->sale?->forceFill(['erp_status' => 'exception'])->save();

        $this->notifyException($entry, $code);

        return $entry;
    }

    private function notifyException(ErpOutboxEntry $entry, string $code): void
    {
        $this->notifier->notify(
            event: 'erp.exception',
            title: __('erp.exception_title'),
            body: __('erp.exception_body', ['code' => $code]),
            context: [
                'sale_id' => $entry->sale_id,
                'error_code' => $code,
                'http_status' => $entry->http_status,
                'erp_document_id' => $entry->erp_document_id,
            ],
            severity: 'critical',
            entityType: 'sale',
            entityId: $entry->sale_id,
            branchId: $entry->branch_id,
        );
    }

    private function configured(): bool
    {
        return ! empty(config('pos.erp.base_url')) && ! empty(config('pos.erp.token'));
    }
}
