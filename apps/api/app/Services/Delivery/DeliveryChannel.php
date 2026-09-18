<?php

namespace App\Services\Delivery;

use App\Models\DocumentDelivery;

/**
 * Un canal por el que sale un comprobante.
 *
 * La interfaz existe para que elegir proveedor de WhatsApp —punto abierto A5—
 * no obligue a tocar la cola ni el flujo de venta: se escribe otra
 * implementación y se registra.
 */
interface DeliveryChannel
{
    /** ¿Tiene la instalación lo que hace falta para usar este canal? */
    public function isConfigured(): bool;

    /**
     * Manda el comprobante.
     *
     * @param  string  $pdf  contenido binario del PDF
     * @return array{reference: ?string}
     *
     * @throws \RuntimeException si el envío falla de forma reintentable
     */
    public function send(DocumentDelivery $delivery, string $pdf): array;

    /** Valida el destino antes de encolarlo: un número mal tecleado no se arregla reintentando. */
    public function accepts(string $destination): bool;
}
