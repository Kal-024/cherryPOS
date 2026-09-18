<?php

namespace App\Services\Delivery;

use App\Models\DocumentDelivery;
use App\Models\Sale;
use App\Services\Delivery\Channels\EmailChannel;
use App\Services\Delivery\Channels\WhatsappChannel;
use App\Services\Receipts\ReceiptService;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cola de envío de comprobantes (P-04, H5.5).
 *
 * **La térmica es el canal primario y siempre funciona.** Esto es el envío
 * digital, que es *best-effort*: se encola, se despacha cuando hay internet y
 * **nunca bloquea el cierre de la venta**. El cajero ve el estado; eso alcanza.
 *
 * Solo sale **a pedido del cliente en caja** (P-04): el POS vive en una red que
 * puede no tener salida a internet, y mandar todos los comprobantes sería
 * encolar miles de mensajes que nadie pidió.
 */
class DocumentDeliveryService
{
    private const BACKOFF_BASE = 60;

    private const MAX_ATTEMPTS = 8;

    public function __construct(private ReceiptService $receipts) {}

    /** @return array<string,DeliveryChannel> */
    public function channels(): array
    {
        return [
            'whatsapp' => app(WhatsappChannel::class),
            'email' => app(EmailChannel::class),
        ];
    }

    public function channel(string $name): DeliveryChannel
    {
        return $this->channels()[$name] ?? throw ValidationException::withMessages([
            'channel' => __('delivery.unknown_channel', ['channel' => $name]),
        ]);
    }

    /**
     * Encola un envío. **No manda nada** y no puede fallar la venta.
     */
    public function queue(Sale $sale, string $channelName, string $destination, ?string $employeeId): DocumentDelivery
    {
        $channel = $this->channel($channelName);

        if (! $channel->accepts($destination)) {
            // Un destino mal tecleado se corrige ahora, con el cliente enfrente.
            // Encolarlo sería descubrirlo cuando ya se fue.
            throw ValidationException::withMessages([
                'destination' => __('delivery.invalid_destination'),
            ]);
        }

        return DocumentDelivery::create([
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'requested_by' => $employeeId,
            'channel' => $channelName,
            'destination' => $destination,
            'status' => $channel->isConfigured() ? 'queued' : 'unconfigured',
            'error_code' => $channel->isConfigured() ? null : 'channel_unconfigured',
            'next_attempt_at' => $channel->isConfigured() ? now() : null,
        ]);
    }

    /** @return array<int,DocumentDelivery> */
    public function due(int $limit = 20): array
    {
        return DocumentDelivery::query()
            ->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function dispatch(DocumentDelivery $delivery): DocumentDelivery
    {
        $channel = $this->channel($delivery->channel);

        if (! $channel->isConfigured()) {
            // Reintentar no consigue una cuenta.
            $delivery->forceFill([
                'status' => 'unconfigured',
                'error_code' => 'channel_unconfigured',
                'next_attempt_at' => null,
            ])->save();

            return $delivery;
        }

        $delivery->forceFill(['status' => 'sending', 'attempts' => $delivery->attempts + 1])->save();

        try {
            $pdf = $this->receipts->pdfFor($delivery->sale);
            $result = $channel->send($delivery, $pdf);

            $delivery->forceFill([
                'status' => 'sent',
                'provider_reference' => $result['reference'] ?? null,
                'error_code' => null,
                'error_message' => null,
                'sent_at' => now(),
                'next_attempt_at' => null,
            ])->save();
        } catch (Throwable $e) {
            $exhausted = $delivery->attempts >= self::MAX_ATTEMPTS;

            // Retroceso exponencial con techo: una red caída no se arregla
            // insistiendo cada minuto durante toda la noche.
            $delivery->forceFill([
                'status' => $exhausted ? 'failed' : 'queued',
                'error_code' => 'send_failed',
                'error_message' => mb_substr($e->getMessage(), 0, 255),
                'next_attempt_at' => $exhausted
                    ? null
                    : now()->addSeconds(min(self::BACKOFF_BASE * (2 ** min($delivery->attempts, 6)), 3600)),
            ])->save();
        }

        return $delivery->fresh();
    }
}
