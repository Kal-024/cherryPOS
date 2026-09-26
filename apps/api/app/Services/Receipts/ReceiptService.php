<?php

namespace App\Services\Receipts;

use App\Models\ReceiptTemplate;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Validation\ValidationException;

/**
 * Punto de entrada de los comprobantes.
 *
 * Junta las tres piezas —plantilla, datos y renderizador— para que quien pide un
 * ticket no tenga que saber de ninguna. Devuelve **líneas** o **PDF**: las
 * primeras las dibuja el terminal y las consumirá el agente de impresión térmica
 * cuando exista (Q-05); el segundo es lo que hoy se entrega al cliente.
 */
class ReceiptService
{
    public function __construct(
        private ReceiptContext $context,
        private ReceiptRenderer $renderer,
        private ReceiptPdf $pdf,
    ) {}

    /**
     * @return array{template: ReceiptTemplate, context: array<string,mixed>, lines: array<int,array<string,mixed>>}
     */
    public function forSale(Sale $sale, ?string $templateId = null): array
    {
        $template = $this->templateFor($sale->sale_type, $sale->branch_id, $templateId);
        $context = $this->context->forSale($sale);

        return [
            'template' => $template,
            'context' => $context,
            'lines' => $this->renderer->render($template, $context),
        ];
    }

    /**
     * La precuenta del salón (F1-B): lo consumido, todavía sin cobrar.
     *
     * **No es el comprobante.** El cliente pide ver cuánto va antes de pagar, y
     * ese papel tiene que decir que no es un documento de pago — si no, alguien
     * se va creyendo que ya tiene su factura y el ticket real queda sin
     * entregar. Por eso es un tipo de documento aparte con su propia plantilla,
     * sin bloque de pagos y con el aviso arriba.
     *
     * Solo sobre cuentas abiertas: una cobrada ya tiene su comprobante de
     * verdad.
     *
     * @return array{template: ReceiptTemplate, context: array<string,mixed>, lines: array<int,array<string,mixed>>}
     */
    public function forPreBill(Sale $sale): array
    {
        if ($sale->closed_at !== null) {
            throw ValidationException::withMessages([
                'sale' => __('receipt.pre_bill_already_closed'),
            ]);
        }

        $template = $this->templateFor('pre_bill', $sale->branch_id);
        $context = $this->context->forSale($sale);

        return [
            'template' => $template,
            'context' => $context,
            'lines' => $this->renderer->render($template, $context),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array{template: ReceiptTemplate, context: array<string,mixed>, lines: array<int,array<string,mixed>>}
     */
    public function forShift(Shift $shift, array $summary): array
    {
        $template = $this->templateFor('shift_cut', $shift->branch_id);
        $context = $this->context->forShift($shift, $summary);

        return [
            'template' => $template,
            'context' => $context,
            'lines' => $this->renderer->render($template, $context),
        ];
    }

    /** El PDF binario de una venta, listo para descargar o adjuntar. */
    public function pdfFor(Sale $sale, ?string $templateId = null): string
    {
        $rendered = $this->forSale($sale, $templateId);

        return $this->pdf->make($rendered['template'], $rendered['lines'], $rendered['context'])->output();
    }

    /**
     * Vista previa de una plantilla con datos de ejemplo.
     *
     * Es lo que hace usable el editor: sin verla, configurar una plantilla es
     * teclear a ciegas y descubrir el resultado en el primer cliente.
     *
     * @param  array<string,mixed>  $content
     * @return array<int,array<string,mixed>>
     */
    public function preview(array $content, string $paper = 'thermal_80'): array
    {
        $this->renderer->validate($content);

        $template = new ReceiptTemplate([
            'paper' => $paper,
            'content' => $content,
            'document_type' => 'preview',
            'code' => 'preview',
            'name' => 'Vista previa',
        ]);

        return $this->renderer->render($template, $this->sampleContext());
    }

    private function templateFor(string $documentType, ?string $branchId, ?string $templateId = null): ReceiptTemplate
    {
        if ($templateId !== null) {
            return ReceiptTemplate::findOrFail($templateId);
        }

        return ReceiptTemplate::resolve($documentType, $branchId)
            // Sin plantilla configurada no hay nada que imprimir, y el seeder
            // deja una para cada tipo: llegar acá significa que alguien borró
            // la del sistema.
            ?? throw ValidationException::withMessages([
                'template' => __('receipt.no_template', ['type' => $documentType]),
            ]);
    }

    /**
     * Datos de ejemplo para la vista previa.
     *
     * Deliberadamente feos: nombres largos, decimales incómodos y un cliente sin
     * cédula. Una vista previa con datos bonitos esconde justo los problemas que
     * uno quiere ver antes de imprimir mil tickets.
     *
     * @return array<string,mixed>
     */
    private function sampleContext(): array
    {
        return [
            'branch' => [
                'name' => 'Sucursal Central',
                'legal_name' => 'Comercial El Ejemplo, S.A.',
                'tax_id' => 'J0310000000000',
                'address' => 'Km 8 Carretera a Masaya, Managua',
                'phone' => '2222-3333',
                'code' => '001',
            ],
            'sale' => [
                'number' => '001-COU-2026-000123',
                'closed_at' => '16/09/2026 14:32',
                'currency' => 'NIO',
                'gross' => '1149.98',
                'discount_total' => '57.50',
                'subtotal' => '950.00',
                'taxable_base' => '700.00',
                'exempt_total' => '250.00',
                'tax_total' => '105.00',
                'total' => '1092.48',
                'cash_rounding' => '0.00',
                'tip' => '0.00',
                'amount_due' => '1092.48',
                'paid' => '1200.00',
                'balance' => '0.00',
            ],
            'terminal' => ['code' => 'CAJA-01', 'name' => 'Caja 1'],
            'employee' => ['code' => 'CAJ01', 'full_name' => 'María de los Ángeles Rodríguez'],
            'customer' => ['name' => 'Cliente de mostrador', 'national_id' => null, 'tax_id' => null],
            'items' => [
                ['qty' => '2', 'uom' => 'UND', 'description' => 'Gaseosa de naranja 1.5 L retornable',
                    'unit_price' => '45.0000', 'discount' => '0.00', 'total' => '90.00', 'is_exempt' => false],
                ['qty' => '0.847', 'uom' => 'KG', 'description' => 'Queso seco',
                    'unit_price' => '180.0000', 'discount' => '7.50', 'total' => '144.96', 'is_exempt' => false],
                ['qty' => '1', 'uom' => 'UND', 'description' => 'Acetaminofén 500 mg caja x 20',
                    'unit_price' => '250.0000', 'discount' => '0.00', 'total' => '250.00', 'is_exempt' => true],
            ],
            'taxes' => [['code' => 'IVA', 'rate' => '15.0000', 'base' => '700.00', 'amount' => '105.00']],
            'payments' => [
                ['method' => 'cash', 'currency' => 'NIO', 'amount' => '1000.00', 'amount_base' => '1000.00', 'exchange_rate' => null, 'reference' => null],
                ['method' => 'cash', 'currency' => 'USD', 'amount' => '5.46', 'amount_base' => '200.00', 'exchange_rate' => '36.624300', 'reference' => null],
            ],
            'change' => '107.52',
            // El corte de turno se previsualiza con la misma plantilla que se
            // imprime, así que necesita sus propios datos de ejemplo: sin ellos,
            // cada marcador resolvía vacío y el editor mostraba el esquema sin un
            // solo número — que es exactamente lo que se reportó del papel.
            'shift' => [
                'code' => 'T-2026-0912',
                'opened_at' => '16/09/2026 08:00',
                'closed_at' => '16/09/2026 20:14',
                'opening_float' => '2000.00',
                'exchange_rate' => '36.624300',
            ],
            'sales' => [
                'count' => 37,
                'total' => '48210.75',
                'tax_total' => '6288.35',
                'by_method' => ['cash' => '31210.75', 'card' => '12000.00', 'credit' => '5000.00'],
            ],
            // Con faltante a propósito: una vista previa que cuadra esconde justo
            // el renglón que hay que poder leer de un vistazo.
            'currencies' => [
                ['currency_code' => 'NIO', 'expected' => '33210.75', 'counted' => '33150.00', 'difference' => '-60.75'],
                ['currency_code' => 'USD', 'expected' => '150.00', 'counted' => '155.00', 'difference' => '5.00'],
            ],
            'cashiers' => [
                ['employee_code' => 'CAJ01', 'employee_name' => 'María de los Ángeles Rodríguez', 'sales' => 24, 'total' => '31980.50'],
                ['employee_code' => 'CAJ02', 'employee_name' => 'Josué Martínez', 'sales' => 13, 'total' => '16230.25'],
            ],
            'denominations' => [
                ['currency_code' => 'NIO', 'denomination' => '1000.00', 'count' => 28, 'subtotal' => '28000.00'],
                ['currency_code' => 'NIO', 'denomination' => '100.00', 'count' => 45, 'subtotal' => '4500.00'],
                ['currency_code' => 'NIO', 'denomination' => '10.00', 'count' => 60, 'subtotal' => '600.00'],
                ['currency_code' => 'NIO', 'denomination' => '0.50', 'count' => 100, 'subtotal' => '50.00'],
                ['currency_code' => 'USD', 'denomination' => '20.00', 'count' => 7, 'subtotal' => '140.00'],
                ['currency_code' => 'USD', 'denomination' => '5.00', 'count' => 3, 'subtotal' => '15.00'],
            ],
            'tips' => [
                'total' => '1450.00',
                'by_employee' => [
                    ['employee_id' => null, 'employee_name' => 'Josué Martínez', 'total' => '950.00'],
                    ['employee_id' => null, 'employee_name' => null, 'total' => '500.00'],
                ],
            ],
        ];
    }
}
