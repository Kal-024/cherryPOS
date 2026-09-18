<?php

namespace App\Services\Erp;

use App\Models\Sale;
use App\Services\Calc\Decimal;

/**
 * Traduce una venta al ticket que espera `POST /pos/sales` de cherryB.
 *
 * La forma no es negociable: está en `docs/CONTRATO-CHERRYB.md`, derivado de la
 * validación real de `PosSaleController`. Ante divergencia **gana cherryB**.
 *
 * Dos traducciones que parecen detalles y no lo son:
 *
 *  - **Las devoluciones van con líneas en positivo y totales en negativo.** El
 *    signo fiscal lo pone el tipo de documento (`NCF`, sign −1), no el cuerpo.
 *    Internamente el POS guarda la devolución con cantidades negativas —así el
 *    motor de cálculo la trata como el negativo exacto de su venta—, y aquí se
 *    invierte solo la cantidad.
 *  - **`group_ref` y `group_name`** son la marca del combo ya explotado. El ERP
 *    no necesita saber qué es un combo; con esa marca la factura igual dice
 *    "Combo Familiar".
 */
class ErpTicketPayload
{
    /** @return array<string,mixed> */
    public function build(Sale $sale): array
    {
        $sale->loadMissing('lines.taxes', 'lines.product', 'customer', 'terminal');

        return array_filter([
            'uuid' => $sale->id,
            'number' => $sale->number,
            'terminal_code' => $sale->terminal?->code,
            'closed_at' => $sale->closed_at?->toIso8601String(),
            'currency_code' => $sale->currency_code,
            // El UUID del ticket original, cuando esto es una devolución. El
            // ERP exige que exista y esté emitido y vigente.
            'refund_of' => $sale->reverses_sale_id,
            'customer' => $this->customer($sale),
            'lines' => $sale->lines->map(fn ($line) => array_filter([
                // El identificador del producto en el ERP, no el nuestro: el
                // POS es dueño de su catálogo hasta que se integra (P-01).
                'product_id' => $line->product?->erp_product_id,
                'item_code' => $line->item_code,
                'description' => mb_substr($line->description, 0, 200),
                // Siempre positiva, también en devolución.
                'qty' => Decimal::format(
                    Decimal::abs(Decimal::parse((string) $line->qty, Decimal::QTY)),
                    Decimal::QTY
                ),
                'unit_price' => (string) $line->unit_price,
                'discount_amount' => Decimal::format(
                    Decimal::abs(Decimal::add(
                        Decimal::parse((string) $line->line_discount, Decimal::MONEY),
                        Decimal::parse((string) $line->sale_discount_share, Decimal::MONEY)
                    )),
                    Decimal::MONEY
                ),
                'tax_code' => $line->taxes->first()?->code,
                'kind' => 'product',
                'group_ref' => $line->group_ref,
                'group_name' => $line->group_name,
            ], static fn ($value) => $value !== null && $value !== ''))->values()->all(),
            'totals' => [
                // En negativo cuando es devolución, porque así lo guardó el
                // motor de cálculo.
                'tax_total' => (string) $sale->tax_total,
                'total' => (string) $sale->total,
            ],
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string,mixed>|null */
    private function customer(Sale $sale): ?array
    {
        $customer = $sale->customer;

        if ($customer === null) {
            // Venta anónima: el ERP usa su `pos_default_customer_id`. Nadie pide
            // cédula para vender una gaseosa (P-03).
            return null;
        }

        return array_filter([
            'id' => $customer->erp_customer_id,
            'uuid' => $customer->id,
        ], static fn ($value) => $value !== null);
    }
}
