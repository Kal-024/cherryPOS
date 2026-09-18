<?php

namespace App\Services\Delivery\Channels;

use App\Models\DocumentDelivery;
use App\Services\Delivery\DeliveryChannel;
use RuntimeException;

/**
 * Envío por WhatsApp (D-10, Q-11).
 *
 * > ⚠️ **El envío real está pendiente del punto abierto A5**: falta elegir
 * > proveedor y decidir quién asume el costo. WhatsApp Business API exige cuenta
 * > con un proveedor autorizado y **cobra por conversación**; además, cada
 * > proveedor difiere en cómo se sube el adjunto antes de mandarlo. Escribir esa
 * > llamada ahora sería adivinar la decisión, así que no se escribe.
 *
 * Lo que **sí** está construido y decidido, que es casi todo: la cola con su
 * estado y sus reintentos, la validación del número al encolar, la cuenta
 * configurada **por instalación** y a nombre del negocio, y la garantía de que
 * la térmica sale igual y **la venta nunca se bloquea** (P-04).
 *
 * Mientras A5 siga abierto, el canal se declara no configurado y la cola marca
 * el envío como `unconfigured` en vez de reintentarlo. Es la diferencia entre
 * "todavía no lo contrataron" y "se cayó": lo primero no mejora reintentando.
 */
class WhatsappChannel implements DeliveryChannel
{
    public function isConfigured(): bool
    {
        return filled(config('pos.whatsapp.provider'))
            && filled(config('pos.whatsapp.token'))
            && filled(config('pos.whatsapp.phone_number_id'));
    }

    public function accepts(string $destination): bool
    {
        // Solo dígitos, con o sin `+`. Un número mal tecleado no se arregla
        // reintentando, así que se rechaza al encolar y el cajero lo corrige
        // mientras el cliente está enfrente.
        return preg_match('/^\+?[0-9]{8,15}$/', preg_replace('/[\s\-()]/', '', $destination) ?? '') === 1;
    }

    public function send(DocumentDelivery $delivery, string $pdf): array
    {
        throw new RuntimeException(__('delivery.whatsapp_pending_provider'));
    }
}
