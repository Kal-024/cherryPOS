<?php

namespace Database\Seeders;

use App\Models\ReceiptTemplate;
use Illuminate\Database\Seeder;

/**
 * Plantillas de comprobante de arranque (D-11).
 *
 * Un negocio que abre necesita poder entregar un ticket el primer día, no
 * diseñar uno. Estas son editables desde la pantalla de plantillas; lo que no se
 * puede cambiar es el nombre y el RUC del negocio, que vienen de la instalación
 * y se imprimen siempre (Q-08).
 */
class ReceiptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            ReceiptTemplate::updateOrCreate(
                ['branch_id' => null, 'code' => $template['code']],
                $template + ['is_default' => true, 'is_system' => true, 'is_active' => true]
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function templates(): array
    {
        return [
            [
                'code' => 'ticket-mostrador',
                'name' => 'Ticket de mostrador',
                'document_type' => 'counter',
                'paper' => 'thermal_80',
                'content' => ['blocks' => $this->saleBlocks()],
            ],
            [
                'code' => 'factura',
                // El POS no emite la factura: imprime el comprobante con los datos
                // fiscales del cliente para que el ERP lo convierta en documento.
                // El tipo sigue siendo `invoice` porque es el que viaja en el
                // contrato con cherryB.
                'name' => 'Comprobante con datos fiscales',
                'document_type' => 'invoice',
                'paper' => 'thermal_80',
                // La factura suma los datos fiscales del cliente: es lo que la
                // distingue del ticket de mostrador.
                'content' => ['blocks' => $this->saleBlocks(withCustomerTaxData: true)],
            ],
            [
                'code' => 'presupuesto',
                'name' => 'Presupuesto',
                'document_type' => 'quote',
                'paper' => 'thermal_80',
                'content' => ['blocks' => $this->quoteBlocks()],
            ],
            [
                'code' => 'nota-credito',
                // El nombre visible dice lo que el papel es: un comprobante de
                // devolución. La nota de crédito la emite el ERP.
                'name' => 'Comprobante de devolución',
                'document_type' => 'refund',
                'paper' => 'thermal_80',
                'content' => ['blocks' => $this->saleBlocks(title: 'receipt.refund_receipt')],
            ],
            [
                'code' => 'precuenta',
                // No se llama "comprobante" a propósito: no lo es, y el papel lo
                // dice en la primera línea.
                'name' => 'Cuenta para el cliente (precuenta)',
                'document_type' => 'pre_bill',
                'paper' => 'thermal_80',
                'content' => ['blocks' => $this->preBillBlocks()],
            ],
            [
                'code' => 'corte-turno',
                'name' => 'Corte de turno',
                'document_type' => 'shift_cut',
                'paper' => 'thermal_80',
                'content' => ['blocks' => $this->shiftBlocks()],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function header(): array
    {
        return [
            ['type' => 'logo', 'align' => 'center'],
            // Nombre y RUC no son editables: vienen de la instalación y son la
            // marca que hace que una copia delate al negocio original (Q-08).
            ['type' => 'text', 'content' => '{{branch.legal_name}}', 'align' => 'center', 'bold' => true],
            ['type' => 'text', 'content' => 'RUC {{branch.tax_id}}', 'align' => 'center', 'size' => 'sm'],
            ['type' => 'text', 'content' => '{{branch.address}}', 'align' => 'center', 'size' => 'sm'],
            ['type' => 'text', 'content' => 'Tel. {{branch.phone}}', 'align' => 'center', 'size' => 'sm'],
            ['type' => 'separator'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function saleBlocks(bool $withCustomerTaxData = false, string $title = 'receipt.sale'): array
    {
        $fields = [
            ['label' => 'receipt.number', 'value' => '{{sale.number}}'],
            ['label' => 'receipt.date', 'value' => '{{sale.closed_at}}'],
            ['label' => 'receipt.cashier', 'value' => '{{employee.full_name}}'],
            ['label' => 'receipt.terminal', 'value' => '{{terminal.code}}'],
            ['label' => 'receipt.customer', 'value' => '{{customer.name}}'],
        ];

        if ($withCustomerTaxData) {
            $fields[] = ['label' => 'receipt.customer_tax_id', 'value' => '{{customer.tax_id}}'];
            $fields[] = ['label' => 'receipt.customer_address', 'value' => '{{customer.address}}'];
        }

        return array_merge($this->header(), [
            ['type' => 'text', 'content' => '{{t:'.$title.'}}', 'align' => 'center', 'bold' => true, 'hide_if_empty' => false],
            ['type' => 'field_list', 'fields' => $fields],
            ['type' => 'separator'],
            ['type' => 'items', 'show_unit_price' => true, 'show_discount' => true],
            ['type' => 'separator'],
            ['type' => 'totals', 'show' => ['subtotal', 'exempt_total', 'discount_total', 'taxes', 'cash_rounding', 'total', 'tip', 'amount_due']],
            ['type' => 'separator'],
            ['type' => 'payments', 'show_change' => true],
            ['type' => 'separator'],
            ['type' => 'text', 'content' => '{{settings.receipt_footer}}', 'align' => 'center', 'size' => 'sm'],
            ['type' => 'spacer', 'lines' => 3],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function quoteBlocks(): array
    {
        return array_merge($this->header(), [
            ['type' => 'field_list', 'fields' => [
                ['label' => 'receipt.number', 'value' => '{{sale.number}}'],
                ['label' => 'receipt.date', 'value' => '{{sale.opened_at}}'],
                ['label' => 'receipt.customer', 'value' => '{{customer.name}}'],
            ]],
            ['type' => 'separator'],
            ['type' => 'items', 'show_unit_price' => true, 'show_discount' => true],
            ['type' => 'separator'],
            ['type' => 'totals', 'show' => ['subtotal', 'discount_total', 'taxes', 'total']],
            // Un presupuesto no lleva pagos ni vuelto: no se cobró nada.
            ['type' => 'spacer', 'lines' => 2],
        ]);
    }

    /**
     * La precuenta del salón (F1-B).
     *
     * Dos diferencias con el ticket, y las dos importan: **no lleva bloque de
     * pagos** —no se ha cobrado nada— y **avisa que no es comprobante de pago**.
     * Sin ese aviso, un cliente se va con la precuenta creyendo que ya tiene su
     * factura, y el ticket real queda sin entregar.
     *
     * @return array<int,array<string,mixed>>
     */
    private function preBillBlocks(): array
    {
        return array_merge($this->header(), [
            ['type' => 'text', 'content' => '{{t:receipt.pre_bill}}', 'align' => 'center', 'bold' => true, 'hide_if_empty' => false],
            ['type' => 'text', 'content' => '{{t:receipt.pre_bill_notice}}', 'align' => 'center', 'size' => 'sm', 'hide_if_empty' => false],
            ['type' => 'field_list', 'fields' => [
                // La mesa y el mesero: es lo que identifica el papel mientras la
                // cuenta no tiene número, porque todavía no es un documento.
                ['label' => 'receipt.table', 'value' => '{{sale.label}}'],
                ['label' => 'receipt.date', 'value' => '{{sale.opened_at}}'],
                ['label' => 'receipt.waiter', 'value' => '{{employee.full_name}}'],
            ]],
            ['type' => 'separator'],
            ['type' => 'items', 'show_discount' => true],
            ['type' => 'separator'],
            ['type' => 'totals', 'show' => ['subtotal', 'exempt_total', 'discount_total', 'taxes', 'total']],
            ['type' => 'separator'],
            ['type' => 'text', 'content' => '{{t:receipt.pre_bill_footer}}', 'align' => 'center', 'size' => 'sm', 'hide_if_empty' => false],
            ['type' => 'spacer', 'lines' => 3],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function shiftBlocks(): array
    {
        return array_merge($this->header(), [
            ['type' => 'text', 'content' => '{{t:receipt.shift_cut}}', 'align' => 'center', 'bold' => true, 'hide_if_empty' => false],
            ['type' => 'field_list', 'fields' => [
                ['label' => 'receipt.shift', 'value' => '{{shift.code}}'],
                ['label' => 'receipt.opened_at', 'value' => '{{shift.opened_at}}'],
                ['label' => 'receipt.closed_at', 'value' => '{{shift.closed_at}}'],
                ['label' => 'receipt.opening_float', 'value' => '{{shift.opening_float}}'],
                // La tasa va en el corte porque la caja la canta al cobrar en
                // dólares: el papel es el respaldo de a cuánto se tomó (Q-06).
                ['label' => 'receipt.exchange_rate', 'value' => '{{shift.exchange_rate}}'],
            ]],
            ['type' => 'separator'],
            ['type' => 'shift_sales'],
            ['type' => 'separator'],
            ['type' => 'shift_currencies'],
            ['type' => 'separator'],
            ['type' => 'shift_denominations'],
            ['type' => 'shift_cashiers'],
            ['type' => 'shift_tips'],
            ['type' => 'separator'],
            // El corte se entrega con el efectivo: las dos firmas son de quién
            // contó y de quién recibió. Sin ellas el papel no prueba nada.
            ['type' => 'spacer', 'lines' => 2],
            ['type' => 'text', 'content' => '{{t:receipt.delivered_by}}', 'size' => 'sm', 'hide_if_empty' => false],
            ['type' => 'spacer', 'lines' => 2],
            ['type' => 'text', 'content' => '{{t:receipt.received_by}}', 'size' => 'sm', 'hide_if_empty' => false],
            ['type' => 'spacer', 'lines' => 3],
        ]);
    }
}
