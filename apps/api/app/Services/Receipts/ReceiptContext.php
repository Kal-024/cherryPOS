<?php

namespace App\Services\Receipts;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * Los datos que una plantilla puede nombrar.
 *
 * Se arma aquí y no dentro del renderizador para que el conjunto de campos
 * disponibles sea **explícito**: la pantalla del editor lo lista, y una
 * plantilla no puede alcanzar nada que no esté acá adentro.
 *
 * Las fechas se formatean en la zona de la sucursal (`cmn_branches.timezone`).
 * El sistema guarda en UTC; el cliente que recibe el ticket vive en su hora.
 */
class ReceiptContext
{
    public function __construct(private SettingsRepository $settings) {}

    /** @return array<string,mixed> */
    public function forSale(Sale $sale): array
    {
        $sale->loadMissing([
            'lines.taxes', 'lines.product.uom', 'payments',
            'customer.person', 'employee.person', 'terminal',
        ]);

        $branch = Branch::findOrFail($sale->branch_id);

        return [
            'branch' => $this->branch($branch),
            'settings' => $this->receiptSettings($branch->id),
            'sale' => [
                'number' => $sale->number,
                'type' => $sale->sale_type,
                'status' => $sale->status,
                'closed_at' => $this->localTime($sale->closed_at, $branch),
                'opened_at' => $this->localTime($sale->opened_at, $branch),
                'currency' => $sale->currency_code,
                'gross' => (string) $sale->gross,
                'discount_total' => (string) $sale->discount_total,
                'subtotal' => (string) $sale->subtotal,
                'taxable_base' => (string) $sale->taxable_base,
                'exempt_total' => (string) $sale->exempt_total,
                'tax_total' => (string) $sale->tax_total,
                'total' => (string) $sale->total,
                'cash_rounding' => (string) $sale->cash_rounding,
                // La propina se imprime aparte del total fiscal: el documento
                // dice lo que se vendió y el renglón de abajo lo que el cliente
                // entregó (G-16).
                'tip' => (string) $sale->tip_amount,
                // Lo que había que pagar, propina incluida. Sale de `paid` más
                // `balance` en vez de recomponerse sumando: esa identidad vale
                // igual en efectivo, en tarjeta y en una devolución, mientras
                // que rehacer la cuenta acá sería una tercera implementación de
                // la fórmula.
                'amount_due' => bcadd((string) $sale->paid, (string) $sale->balance, 2),
                'paid' => (string) $sale->paid,
                'balance' => (string) $sale->balance,
            ],
            'terminal' => [
                'code' => $sale->terminal?->code,
                'name' => $sale->terminal?->name,
            ],
            'employee' => [
                'code' => $sale->employee?->code,
                'full_name' => $sale->employee?->full_name,
            ],
            'customer' => [
                'name' => $sale->customer?->name,
                'national_id' => $sale->customer?->national_id,
                'tax_id' => $sale->customer?->tax_id,
                'address' => $sale->customer?->address,
            ],
            'items' => $sale->lines->map(fn ($line) => [
                'qty' => $this->trimZeros((string) $line->qty),
                'uom' => $line->product?->uom?->code,
                'description' => $line->description,
                'item_code' => $line->item_code,
                'unit_price' => (string) $line->unit_price,
                'discount' => (string) bcadd((string) $line->line_discount, (string) $line->sale_discount_share, 2),
                'total' => (string) $line->total,
                'is_exempt' => (bool) $line->is_exempt,
                'group_name' => $line->group_name,
            ])->all(),
            'taxes' => $this->taxes($sale),
            'payments' => $sale->payments->where('is_change', false)->map(fn ($payment) => [
                'method' => $payment->method,
                'currency' => $payment->currency_code,
                'amount' => (string) $payment->amount,
                'amount_base' => (string) $payment->amount_base,
                'exchange_rate' => $payment->exchange_rate !== null ? (string) $payment->exchange_rate : null,
                'reference' => $payment->reference,
            ])->values()->all(),
            // El vuelto se guarda como pago negativo para que el arqueo cuadre;
            // en el comprobante se muestra en positivo, que es como lo lee una
            // persona.
            'change' => $this->change($sale),
        ];
    }

    /** @return array<string,mixed> */
    public function forShift(Shift $shift, array $summary): array
    {
        $branch = Branch::findOrFail($shift->branch_id);

        return [
            'branch' => $this->branch($branch),
            'settings' => $this->receiptSettings($branch->id),
            'shift' => [
                'code' => $shift->code,
                'opened_at' => $this->localTime($shift->opened_at, $branch),
                'closed_at' => $this->localTime($shift->closed_at, $branch),
                'opening_float' => (string) $shift->opening_float,
                'exchange_rate' => $shift->exchange_rate !== null ? (string) $shift->exchange_rate : null,
            ],
            'currencies' => $summary['by_currency'] ?? [],
            'cashiers' => $summary['by_cashier'] ?? [],
            'sales' => $summary['sales'] ?? [],
            'denominations' => $summary['denominations'] ?? [],
        ];
    }

    /**
     * Identidad del negocio.
     *
     * Nombre y RUC **no son editables desde la plantilla** (Q-08): provienen de
     * la instalación y se imprimen en cada comprobante. Es la marca indeleble
     * que hace que una copia no autorizada delate al negocio original en sus
     * propias facturas.
     *
     * @return array<string,mixed>
     */
    private function branch(Branch $branch): array
    {
        return [
            'name' => $branch->name,
            'legal_name' => $branch->legal_name ?? $branch->name,
            'tax_id' => $branch->tax_id,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'code' => $branch->code,
        ];
    }

    /**
     * Textos configurables del comprobante: el pie, el aviso de devoluciones,
     * lo que cada negocio quiera decir.
     *
     * @return array<string,mixed>
     */
    private function receiptSettings(string $branchId): array
    {
        return [
            'receipt_footer' => (string) $this->settings->get('receipt.footer', '', $branchId),
            'receipt_notice' => (string) $this->settings->get('receipt.notice', '', $branchId),
        ];
    }

    /** @return array<int,array<string,string>> */
    private function taxes(Sale $sale): array
    {
        $buckets = [];

        foreach ($sale->lines as $line) {
            foreach ($line->taxes as $tax) {
                $buckets[$tax->code] ??= ['code' => $tax->code, 'rate' => (string) $tax->rate, 'base' => '0.00', 'amount' => '0.00'];
                $buckets[$tax->code]['base'] = bcadd($buckets[$tax->code]['base'], (string) $tax->base, 2);
                $buckets[$tax->code]['amount'] = bcadd($buckets[$tax->code]['amount'], (string) $tax->amount, 2);
            }
        }

        return array_values($buckets);
    }

    private function change(Sale $sale): string
    {
        $change = $sale->payments->where('is_change', true)->sum('amount');

        return number_format(abs((float) $change), 2, '.', '');
    }

    private function localTime(?Carbon $moment, Branch $branch): ?string
    {
        return $moment?->copy()->setTimezone($branch->timezone ?? 'UTC')->format('d/m/Y H:i');
    }

    /** `2.0000` se imprime como `2`; `0.8470` como `0.847`. */
    private function trimZeros(string $value): string
    {
        return str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
    }
}
