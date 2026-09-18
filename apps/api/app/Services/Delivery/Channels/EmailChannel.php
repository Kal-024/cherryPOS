<?php

namespace App\Services\Delivery\Channels;

use App\Models\DocumentDelivery;
use App\Services\Delivery\DeliveryChannel;
use Illuminate\Support\Facades\Mail;

/**
 * Envío por correo.
 *
 * D-10 priorizó WhatsApp, no descartó el correo: hay clientes que lo piden y el
 * canal es barato de sostener. Igual que WhatsApp, sale **solo a pedido** y
 * nunca bloquea la venta.
 */
class EmailChannel implements DeliveryChannel
{
    public function isConfigured(): bool
    {
        return config('mail.default') !== null && config('mail.default') !== 'array';
    }

    public function accepts(string $destination): bool
    {
        return filter_var($destination, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(DocumentDelivery $delivery, string $pdf): array
    {
        $number = $delivery->sale?->number ?? '';

        Mail::raw(__('delivery.email_body', ['number' => $number]), function ($message) use ($delivery, $pdf, $number) {
            $message->to($delivery->destination)
                ->subject(__('delivery.email_subject', ['number' => $number]))
                ->attachData($pdf, "comprobante-{$number}.pdf", ['mime' => 'application/pdf']);
        });

        return ['reference' => null];
    }
}
