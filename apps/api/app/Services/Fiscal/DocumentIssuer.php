<?php

namespace App\Services\Fiscal;

use App\Models\Sale;

/**
 * Emisor de documento fiscal — el andamio de P-08.
 *
 * G-03 decidió **no implementar** facturación electrónica: Nicaragua no la
 * exige. Pero un andamio que no se define no existe, así que P-08 concretó tres
 * cosas, y esta interfaz es la primera.
 *
 * Las otras dos ya están: los campos reservados en `pos_sales`
 * (`fiscal_external_id`, `fiscal_status`, `fiscal_response`) y la numeración
 * desacoplada de la lógica de venta (`DocumentNumberService`).
 *
 * Lo que compra: el día que un país exija emisión electrónica, se escribe otra
 * implementación de esta interfaz y **el flujo de venta no se toca**. Sin la
 * costura, ese cambio entraría por el medio de `SaleCloser`.
 *
 * > La contabilidad pura vive en el ERP (P-08). Sin ERP vinculado, el POS sirve
 * > para lo operativo y nada más; con él, lo operativo se convierte en flujo
 * > administrativo.
 */
interface DocumentIssuer
{
    /**
     * Emite el documento fiscal de una venta ya cerrada.
     *
     * @return array{external_id: ?string, status: string, response: ?array<string,mixed>}
     */
    public function issue(Sale $sale): array;

    /** Identificador del emisor, para poder decir cuál firmó cada documento. */
    public function code(): string;
}
