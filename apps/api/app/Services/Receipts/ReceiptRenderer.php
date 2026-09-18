<?php

namespace App\Services\Receipts;

use App\Models\ReceiptTemplate;
use Illuminate\Validation\ValidationException;

/**
 * Convierte una plantilla y sus datos en **líneas con estilo**.
 *
 * Esa forma intermedia es la decisión importante. Hoy esas líneas se vuelven
 * PDF, porque todavía no está definido el hardware (Q-05). Mañana, cuando exista
 * el agente de impresión en Python, las mismas líneas se vuelven ESC/POS **sin
 * tocar la plantilla ni el renderizador**: una línea con `align: center` y
 * `bold: true` se imprime igual en los dos mundos.
 *
 * El ancho del papel manda: en `thermal_58` entran 32 caracteres y en
 * `thermal_80`, 48. Lo que no entra se corta o se envuelve, y decidirlo acá
 * —y no en la impresora— es lo que hace que la vista previa se parezca al papel.
 */
class ReceiptRenderer
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function render(ReceiptTemplate $template, array $context): array
    {
        $width = $template->width();
        $lines = [];

        foreach ($template->content['blocks'] ?? [] as $block) {
            $lines = array_merge($lines, $this->renderBlock($block, $context, $width));
        }

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    private function renderBlock(array $block, array $context, int $width): array
    {
        return match ($block['type'] ?? '') {
            'text' => $this->text($block, $context),
            'logo' => [['kind' => 'logo', 'align' => $block['align'] ?? 'center']],
            'separator' => [['kind' => 'separator', 'char' => $block['char'] ?? '-']],
            'spacer' => array_fill(0, max(1, (int) ($block['lines'] ?? 1)), ['kind' => 'spacer']),
            'field_list' => $this->fieldList($block, $context, $width),
            'items' => $this->items($block, $context, $width),
            'totals' => $this->totals($block, $context, $width),
            'payments' => $this->payments($block, $context, $width),
            'taxes' => $this->taxes($context, $width),
            'qr' => [['kind' => 'qr', 'value' => $this->interpolate($block['content'] ?? '', $context)]],
            default => [],
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function text(array $block, array $context): array
    {
        $content = $this->interpolate($block['content'] ?? '', $context);

        // Una línea que quedó vacía porque su dato no existe —el cliente
        // anónimo, la dirección sin cargar— no se imprime: dejaría un hueco que
        // parece un error de la impresora.
        if (trim($content) === '' && ($block['hide_if_empty'] ?? true)) {
            return [];
        }

        return [[
            'kind' => 'text',
            'text' => $content,
            'align' => $block['align'] ?? 'left',
            'bold' => (bool) ($block['bold'] ?? false),
            'size' => $block['size'] ?? 'md',
        ]];
    }

    /** @return array<int,array<string,mixed>> */
    private function fieldList(array $block, array $context, int $width): array
    {
        $lines = [];

        foreach ($block['fields'] ?? [] as $field) {
            $value = $this->interpolate($field['value'] ?? '', $context);

            if (trim($value) === '' && ($field['hide_if_empty'] ?? true)) {
                continue;
            }

            $lines[] = [
                'kind' => 'pair',
                'label' => __($field['label'] ?? ''),
                'value' => $value,
                'text' => $this->pair(__($field['label'] ?? ''), $value, $width),
            ];
        }

        return $lines;
    }

    /** @return array<int,array<string,mixed>> */
    private function items(array $block, array $context, int $width): array
    {
        $lines = [];
        $showUnitPrice = (bool) ($block['show_unit_price'] ?? true);
        $showDiscount = (bool) ($block['show_discount'] ?? true);

        foreach ($context['items'] ?? [] as $item) {
            // La descripción va en su propia línea: en 32 caracteres no entra
            // "Gaseosa de naranja 1.5 L" junto a la cantidad y el importe.
            $lines[] = [
                'kind' => 'item',
                'text' => $item['description'],
                'align' => 'left',
                'bold' => false,
                'size' => 'md',
            ];

            $detail = $showUnitPrice
                ? sprintf('%s %s x %s', $item['qty'], $item['uom'] ?? '', $item['unit_price'])
                : $item['qty'];

            $lines[] = [
                'kind' => 'item_detail',
                'text' => $this->pair('  '.trim($detail), $item['total'], $width),
            ];

            if ($showDiscount && bccomp($item['discount'], '0', 2) > 0) {
                $lines[] = [
                    'kind' => 'item_discount',
                    'text' => $this->pair('  '.__('receipt.discount'), '-'.$item['discount'], $width),
                ];
            }
        }

        return $lines;
    }

    /** @return array<int,array<string,mixed>> */
    private function totals(array $block, array $context, int $width): array
    {
        $sale = $context['sale'] ?? [];
        $show = $block['show'] ?? ['subtotal', 'discount_total', 'taxes', 'total'];
        $lines = [];

        $map = [
            'subtotal' => ['receipt.subtotal', $sale['subtotal'] ?? '0.00'],
            'taxable_base' => ['receipt.taxable_base', $sale['taxable_base'] ?? '0.00'],
            'exempt_total' => ['receipt.exempt_total', $sale['exempt_total'] ?? '0.00'],
            'discount_total' => ['receipt.discount', $sale['discount_total'] ?? '0.00'],
            'cash_rounding' => ['receipt.rounding', $sale['cash_rounding'] ?? '0.00'],
            'tip' => ['receipt.tip', $sale['tip'] ?? '0.00'],
        ];

        // Sin propina no hay renglón de propina ni "total a pagar" repetido: un
        // ticket de mostrador imprime exactamente lo que imprimía antes (G-16).
        $hasTip = bccomp((string) ($sale['tip'] ?? '0.00'), '0.00', 2) !== 0;

        foreach ($show as $key) {
            if (($key === 'tip' || $key === 'amount_due') && ! $hasTip) {
                continue;
            }

            if ($key === 'amount_due') {
                $lines[] = [
                    'kind' => 'total',
                    'text' => $this->pair(__('receipt.amount_due'), $sale['amount_due'] ?? '0.00', $width),
                    'bold' => true,
                    'size' => 'lg',
                ];

                continue;
            }

            if ($key === 'taxes') {
                $lines = array_merge($lines, $this->taxes($context, $width));

                continue;
            }

            if ($key === 'total') {
                $lines[] = [
                    'kind' => 'total',
                    'text' => $this->pair(__('receipt.total'), $sale['total'] ?? '0.00', $width),
                    'bold' => true,
                    'size' => 'lg',
                ];

                continue;
            }

            if (! isset($map[$key])) {
                continue;
            }

            [$label, $value] = $map[$key];

            // Un importe en cero no se imprime: el exento de una venta sin
            // exentos solo gasta papel y confunde.
            if (bccomp($value, '0', 2) === 0 && $key !== 'subtotal') {
                continue;
            }

            $lines[] = ['kind' => 'amount', 'text' => $this->pair(__($label), $value, $width)];
        }

        return $lines;
    }

    /** @return array<int,array<string,mixed>> */
    private function taxes(array $context, int $width): array
    {
        return array_map(
            fn (array $tax) => [
                'kind' => 'tax',
                'text' => $this->pair(
                    sprintf('%s %s%%', $tax['code'], rtrim(rtrim($tax['rate'], '0'), '.')),
                    $tax['amount'],
                    $width
                ),
            ],
            $context['taxes'] ?? []
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function payments(array $block, array $context, int $width): array
    {
        $lines = [];

        foreach ($context['payments'] ?? [] as $payment) {
            $label = __('receipt.payment_method.'.$payment['method']);

            // Un pago en otra moneda muestra lo que el cliente entregó **y** su
            // equivalente: es lo que evita la discusión en el mostrador (Q-06).
            $text = $payment['currency'] !== ($context['sale']['currency'] ?? null)
                ? sprintf('%s %s %s', $label, $payment['currency'], $payment['amount'])
                : $label;

            $lines[] = ['kind' => 'payment', 'text' => $this->pair($text, $payment['amount_base'], $width)];
        }

        if (($block['show_change'] ?? true) && bccomp($context['change'] ?? '0.00', '0', 2) > 0) {
            $lines[] = [
                'kind' => 'change',
                'text' => $this->pair(__('receipt.change'), $context['change'], $width),
                'bold' => true,
            ];
        }

        return $lines;
    }

    /**
     * Reemplaza `{{ruta.al.dato}}` por su valor y `{{t:clave}}` por su
     * traducción.
     *
     * Los rótulos fijos de la plantilla pasan por i18n como todo lo demás (P6):
     * una plantilla escrita en español no debería obligar a rehacerla para
     * atender en inglés.
     *
     * Una ruta que no existe se vuelve cadena vacía en vez de romper: una
     * plantilla mal escrita no puede impedir que la caja entregue el ticket.
     *
     * @param  array<string,mixed>  $context
     */
    public function interpolate(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*(t:)?([a-z0-9_.]+)\s*\}\}/i',
            fn (array $matches) => $matches[1] !== ''
                ? __($matches[2])
                : (string) (data_get($context, $matches[2]) ?? ''),
            $template
        ) ?? $template;
    }

    /** Etiqueta a la izquierda, importe a la derecha, justificados al ancho. */
    private function pair(string $label, string $value, int $width): string
    {
        $available = $width - mb_strlen($value);

        if ($available < 1) {
            return mb_substr($label.' '.$value, 0, $width);
        }

        $label = mb_substr($label, 0, $available - 1);

        return $label.str_repeat(' ', max(1, $available - mb_strlen($label))).$value;
    }

    /**
     * Comprueba que la plantilla sea usable antes de guardarla.
     *
     * Es la mitad que hace segura la edición: sin validación, un bloque con un
     * tipo inventado se descubre cuando el cajero entrega un ticket en blanco.
     *
     * @param  array<string,mixed>  $content
     */
    public function validate(array $content): void
    {
        $known = ['text', 'logo', 'separator', 'spacer', 'field_list', 'items', 'totals', 'payments', 'taxes', 'qr'];
        $blocks = $content['blocks'] ?? null;

        if (! is_array($blocks) || $blocks === []) {
            throw ValidationException::withMessages(['content' => __('receipt.template_needs_blocks')]);
        }

        foreach ($blocks as $index => $block) {
            $type = $block['type'] ?? null;

            if (! in_array($type, $known, true)) {
                throw ValidationException::withMessages([
                    "content.blocks.{$index}" => __('receipt.unknown_block', ['type' => (string) $type]),
                ]);
            }
        }
    }
}
