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
        ];
    }
}
