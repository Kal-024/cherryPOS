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

        return $this->tidy($lines);
    }

    /**
     * Quita las rayas que quedaron pegadas y la que abriría el papel.
     *
     * Un bloque que calla porque no tiene datos —el conteo de un turno sin
     * arqueo, los impuestos de una venta exenta— deja sus dos separadores juntos,
     * y dos rayas seguidas se leen como una impresora que repitió una línea. La
     * plantilla no puede preverlo: solo acá se sabe qué bloque quedó vacío.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array<string,mixed>>
     */
    private function tidy(array $lines): array
    {
        $tidy = [];

        foreach ($lines as $line) {
            $previous = end($tidy);

            if (($line['kind'] ?? '') === 'separator'
                && ($previous === false || ($previous['kind'] ?? '') === 'separator')) {
                continue;
            }

            $tidy[] = $line;
        }

        return array_values($tidy);
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
            // El corte de turno (H4). Son listas —monedas, cajeros,
            // denominaciones— y ningún bloque sabía recorrerlas: el desglose se
            // calculaba entero y no tenia por donde salir al papel.
            'shift_sales' => $this->shiftSales($block, $context, $width),
            'shift_currencies' => $this->shiftCurrencies($block, $context, $width),
            'shift_cashiers' => $this->shiftCashiers($block, $context, $width),
            'shift_denominations' => $this->shiftDenominations($block, $context, $width),
            'shift_tips' => $this->shiftTips($block, $context, $width),
            'qr' => [['kind' => 'qr', 'value' => $this->interpolate($block['content'] ?? '', $context)]],
            default => [],
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function text(array $block, array $context): array
    {
        $raw = (string) ($block['content'] ?? '');
        $content = $this->interpolate($raw, $context);

        // Una línea que quedó vacía porque su dato no existe —el cliente
        // anónimo, la dirección sin cargar— no se imprime: dejaría un hueco que
        // parece un error de la impresora.
        //
        // Y tampoco se imprime la que **solo conserva su etiqueta**: un ticket
        // que dice "RUC" y "Tel." sin nada al lado se lee como una impresora a
        // medio andar, no como un negocio sin teléfono cargado.
        if ($this->onlyLabelLeft($raw, $content) && ($block['hide_if_empty'] ?? true)) {
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

    /**
     * El detalle de productos, en dos formas.
     *
     * **`compact`** —la de fábrica— usa **una línea por producto** en tres
     * columnas: descripción, cantidad e importe. En un rollo, cada producto que
     * ocupa dos renglones en vez de uno es el doble de papel y el doble de
     * tiempo de impresión; con veinte productos son veinte centímetros de más en
     * cada ticket del día.
     *
     * **`detailed`** conserva el formato anterior: la descripción entera en su
     * renglón y debajo "cantidad × precio unitario". Sirve cuando el precio
     * unitario tiene que quedar impreso —ferretería, venta por peso— o cuando
     * las descripciones son tan largas que truncarlas las vuelve irreconocibles.
     *
     * El descuento de línea sigue en su propio renglón en ambas: es la excepción,
     * y esconderlo en una columna estrecha sería esconder justo lo que el cliente
     * revisa.
     */
    private function items(array $block, array $context, int $width): array
    {
        return ($block['layout'] ?? 'compact') === 'detailed'
            ? $this->itemsDetailed($block, $context, $width)
            : $this->itemsCompact($block, $context, $width);
    }

    /** @return array<int,array<string,mixed>> */
    private function itemsCompact(array $block, array $context, int $width): array
    {
        $lines = [];
        $showDiscount = (bool) ($block['show_discount'] ?? true);
        $showHeader = (bool) ($block['show_header'] ?? true);
        $items = $context['items'] ?? [];

        // Anchos de columna. El importe manda —es lo que el cliente revisa— y la
        // descripción cede lo que haga falta: un nombre recortado se entiende,
        // un importe recortado no sirve de nada.
        $amountWidth = $width >= 40 ? 10 : 9;
        $qtyWidth = $width >= 40 ? 7 : 5;
        $descWidth = max(8, $width - $amountWidth - $qtyWidth - 2);

        /** Tres celdas en sus columnas: el formato de la fila y el del encabezado. */
        $row = $this->grid($width, $qtyWidth, $amountWidth);

        // **Las columnas se rotulan.** Una raya sola no dice qué es cada número:
        // el cliente que revisa su ticket tiene que poder saber, sin preguntar,
        // cuál es la cantidad y cuál el importe. Cuesta un renglón por ticket, no
        // uno por producto, que era el gasto que valía la pena recortar.
        if ($showHeader && $items !== []) {
            $lines[] = [
                'kind' => 'item_header',
                'text' => $row(__('receipt.item'), __('receipt.qty'), __('receipt.amount')),
                'align' => 'left',
                'bold' => true,
                'size' => 'md',
            ];
        }

        foreach ($items as $item) {
            $description = mb_substr((string) $item['description'], 0, $descWidth);
            $qty = mb_substr((string) $item['qty'], 0, $qtyWidth);

            $lines[] = [
                'kind' => 'item',
                'text' => $row($description, $qty, (string) $item['total']),
                'align' => 'left',
                'bold' => false,
                'size' => 'md',
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
    private function itemsDetailed(array $block, array $context, int $width): array
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
     * Resumen de ventas del turno (H4).
     *
     * Es lo primero que alguien busca en un corte: cuántos tickets salieron y
     * por cuánto. Después el desglose por medio de pago, que es lo que dice
     * cuánta de esa plata tiene que estar **en el cajón** y cuánta se fue en
     * tarjeta o quedó a crédito — sin él, el arqueo parece faltar justo el monto
     * de las tarjetas del día.
     *
     * **Un turno sin ventas imprime cero, no nada.** Es la excepción a la regla
     * de callar cuando no hay datos: un corte en blanco no se distingue de una
     * impresora que falló, y el papel se archiva igual.
     */
    private function shiftSales(array $block, array $context, int $width): array
    {
        $sales = $context['sales'] ?? null;

        // La clave `count` es lo que distingue el contexto de un turno del de una
        // venta: puesto en una plantilla de ticket, el bloque no imprime nada en
        // vez de inventar ceros.
        if (! is_array($sales) || ! array_key_exists('count', $sales)) {
            return [];
        }

        $lines = $this->sectionTitle($block, 'receipt.sales_summary');

        $lines[] = $this->amount(__('receipt.tickets'), (string) $sales['count'], $width);
        $lines[] = $this->amount(__('receipt.sales_total'), (string) ($sales['total'] ?? '0.00'), $width);

        if (bccomp((string) ($sales['tax_total'] ?? '0'), '0', 2) !== 0) {
            $lines[] = $this->amount(__('receipt.tax_total'), (string) $sales['tax_total'], $width);
        }

        foreach ($sales['by_method'] ?? [] as $method => $total) {
            $lines[] = $this->amount(
                '  '.__('receipt.payment_method.'.$method),
                (string) $total,
                $width
            );
        }

        return $lines;
    }

    /**
     * El arqueo por moneda: esperado, contado y diferencia (H4, Q-06).
     *
     * Va en vertical y no en columnas porque son tres importes por moneda, y en
     * 32 caracteres no entran cuatro celdas legibles. Un corte se lee con
     * atención —se firma y se archiva—, así que el renglón de más vale acá lo
     * que no valdría en un ticket de mostrador.
     *
     * **El signo de la diferencia se imprime.** Sobrar no es faltar, aunque las
     * dos descuadren: sin el signo, quien archiva el papel no sabe si el cajero
     * debe plata o si le sobró.
     */
    private function shiftCurrencies(array $block, array $context, int $width): array
    {
        $rows = $context['currencies'] ?? [];

        if ($rows === []) {
            return [];
        }

        $lines = $this->sectionTitle($block, 'receipt.cash_count');

        foreach ($rows as $row) {
            $lines[] = $this->groupTitle((string) $row['currency_code']);
            $lines[] = $this->amount('  '.__('receipt.expected'), (string) $row['expected'], $width);
            $lines[] = $this->amount('  '.__('receipt.counted'), (string) $row['counted'], $width);

            $difference = (string) $row['difference'];

            $lines[] = [
                'kind' => 'shift_difference',
                'text' => $this->pair('  '.__('receipt.difference'), $this->signed($difference), $width),
                // El descuadre va en negrita: es el único renglón del corte que
                // obliga a alguien a hacer algo.
                'bold' => bccomp($difference, '0', 2) !== 0,
            ];
        }

        return $lines;
    }

    /**
     * Ventas por cajero (D-05).
     *
     * El turno es del equipo sobre un cajón físico, pero cada venta queda
     * atribuida a su cajero: este bloque es el que permite el relevo sin contar
     * el cajón, porque dice quién vendió cuánto dentro del mismo turno.
     */
    private function shiftCashiers(array $block, array $context, int $width): array
    {
        $rows = $context['cashiers'] ?? [];

        if ($rows === []) {
            return [];
        }

        $row = $this->grid($width, 5, $width >= 40 ? 12 : 10);
        $lines = $this->sectionTitle($block, 'receipt.by_cashier');

        $lines[] = [
            'kind' => 'shift_header',
            'text' => $row(__('receipt.cashier'), __('receipt.tickets_short'), __('receipt.amount')),
            'align' => 'left',
            'bold' => true,
            'size' => 'md',
        ];

        foreach ($rows as $item) {
            $lines[] = [
                'kind' => 'shift_cashier',
                'text' => $row(
                    (string) ($item['employee_name'] ?? $item['employee_code'] ?? ''),
                    (string) $item['sales'],
                    (string) $item['total']
                ),
            ];
        }

        return $lines;
    }

    /**
     * El conteo por denominación (D-06).
     *
     * Es el desglose que convierte "falta plata" en "faltan tres billetes de
     * 50". Se agrupa por moneda solo cuando hay más de una: el rótulo "NIO"
     * sobre la única moneda del local es ruido.
     */
    private function shiftDenominations(array $block, array $context, int $width): array
    {
        $rows = $context['denominations'] ?? [];

        if ($rows === []) {
            return [];
        }

        $row = $this->grid($width, 5, $width >= 40 ? 12 : 10);
        $lines = $this->sectionTitle($block, 'receipt.denominations');

        $lines[] = [
            'kind' => 'shift_header',
            'text' => $row(__('receipt.denomination'), __('receipt.count'), __('receipt.amount')),
            'align' => 'left',
            'bold' => true,
            'size' => 'md',
        ];

        $currencies = array_unique(array_column($rows, 'currency_code'));
        $multi = count($currencies) > 1;
        $current = null;

        foreach ($rows as $item) {
            if ($multi && $item['currency_code'] !== $current) {
                $current = $item['currency_code'];
                $lines[] = $this->groupTitle((string) $current);
            }

            $lines[] = [
                'kind' => 'shift_denomination',
                'text' => $row(
                    $this->plain((string) $item['denomination']),
                    (string) $item['count'],
                    (string) $item['subtotal']
                ),
            ];
        }

        return $lines;
    }

    /**
     * Propinas del turno (G-16).
     *
     * Ya están contadas dentro de lo esperado en el cajón —es dinero que
     * entró—, así que el arqueo cuadra sin tocarlas. Van en el papel porque al
     * cerrar hay que **sacarlas** y entregarlas, y sin este renglón el
     * supervisor tendría que ir ticket por ticket para saber cuánto.
     */
    private function shiftTips(array $block, array $context, int $width): array
    {
        $tips = $context['tips'] ?? null;

        if (! is_array($tips) || bccomp((string) ($tips['total'] ?? '0'), '0', 2) === 0) {
            return [];
        }

        $lines = $this->sectionTitle($block, 'receipt.tips');

        $lines[] = [
            'kind' => 'shift_tip_total',
            'text' => $this->pair(__('receipt.tip_total'), (string) $tips['total'], $width),
            'bold' => true,
        ];

        foreach ($tips['by_employee'] ?? [] as $item) {
            $lines[] = $this->amount(
                '  '.((string) ($item['employee_name'] ?? '') ?: __('receipt.tip_unassigned')),
                (string) $item['total'],
                $width
            );
        }

        return $lines;
    }

    /**
     * El rótulo de una sección del corte.
     *
     * Un corte sin rótulos es una columna de números sin dueño: quien lo archiva
     * no puede saber si "1,234.00" es lo que se esperaba en el cajón o lo que se
     * vendió. Se apaga por bloque, para quien arme el suyo a mano.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sectionTitle(array $block, string $key): array
    {
        if (! ($block['show_title'] ?? true)) {
            return [];
        }

        return [[
            'kind' => 'section',
            'text' => __($key),
            'align' => 'left',
            'bold' => true,
            'size' => 'md',
        ]];
    }

    /** Encabezado de un grupo dentro de una sección: la moneda, casi siempre. */
    private function groupTitle(string $text): array
    {
        return ['kind' => 'shift_group', 'text' => $text, 'align' => 'left', 'bold' => true, 'size' => 'md'];
    }

    /** Un renglón de etiqueta e importe, que es la forma de casi todo el corte. */
    private function amount(string $label, string $value, int $width): array
    {
        return ['kind' => 'amount', 'text' => $this->pair($label, $value, $width)];
    }

    /**
     * Tres columnas: la primera cede, las otras dos se respetan.
     *
     * El importe manda —es lo que se revisa— y la descripción cede lo que haga
     * falta: un nombre recortado se entiende, un importe recortado no sirve de
     * nada. Lo usan el detalle de la venta y las listas del corte, y por eso
     * vive acá y no dentro de uno de los dos.
     */
    private function grid(int $width, int $middle, int $right): callable
    {
        $left = max(8, $width - $middle - $right - 2);

        return fn (string $a, string $b, string $c): string => sprintf(
            '%s %s %s',
            $this->pad(mb_substr($a, 0, $left), $left),
            $this->pad(mb_substr($b, 0, $middle), $middle, left: true),
            $this->pad(mb_substr($c, 0, $right), $right, left: true)
        );
    }

    /** Lo que sobra se imprime con su signo: sobrar no es faltar. */
    private function signed(string $amount): string
    {
        return bccomp($amount, '0', 2) === 1 ? '+'.$amount : $amount;
    }

    /** "1000.00" se lee mejor como "1000"; "0.25" se queda como está. */
    private function plain(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
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
    /**
     * ¿De la línea solo quedó el texto fijo?
     *
     * Verdadero cuando el bloque tenía marcadores y **todos** resolvieron a
     * vacío. Distingue "RUC {{branch.tax_id}}" sin RUC cargado —que no se
     * imprime— de una línea escrita a mano como "Gracias por su compra", que no
     * tiene marcadores y siempre se imprime.
     */
    private function onlyLabelLeft(string $raw, string $rendered): bool
    {
        if (trim($rendered) === '') {
            return true;
        }

        if (preg_match_all('/\{\{\s*(t:)?([a-z0-9_.]+)\s*\}\}/i', $raw, $matches) === 0) {
            return false;
        }

        // Se quita del original todo lo que no sea marcador: lo que queda es la
        // parte fija. Si lo dibujado es exactamente eso, ningún dato llegó.
        $fixed = trim(preg_replace('/\{\{\s*(t:)?[a-z0-9_.]+\s*\}\}/i', '', $raw) ?? '');

        return $fixed !== '' && trim($rendered) === $fixed;
    }

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

    /**
     * Rellena con espacios contando **caracteres**, no bytes.
     *
     * `str_pad` mide bytes y una "ñ" ocupa dos: con acentos, las columnas se
     * desalinean justo en los productos de nombre español. `mb_str_pad` llegó en
     * PHP 8.3 y el proyecto admite 8.2, así que se hace a mano.
     */
    private function pad(string $value, int $width, bool $left = false): string
    {
        $missing = max(0, $width - mb_strlen($value));
        $spaces = str_repeat(' ', $missing);

        return $left ? $spaces.$value : $value.$spaces;
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
        $known = [
            'text', 'logo', 'separator', 'spacer', 'field_list', 'items', 'totals', 'payments', 'taxes', 'qr',
            // Corte de turno (H4).
            'shift_sales', 'shift_currencies', 'shift_cashiers', 'shift_denominations', 'shift_tips',
        ];
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
