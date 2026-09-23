<?php

namespace App\Services\Receipts;

use App\Models\ReceiptTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Las líneas renderizadas, en papel (H5.3).
 *
 * **Por qué PDF y no impresión térmica todavía:** el hardware está pendiente
 * (Q-05, Q-12) y la decisión fue explícita — por ahora se genera PDF, y donde
 * correspondería abrir la gaveta se avisa por pantalla. El agente local en
 * Python llega en fase posterior.
 *
 * El PDF respeta el ancho del papel porque esa es la vista previa honesta: un
 * ticket que en pantalla entra y en la térmica se corta no sirve de vista
 * previa. Un rollo térmico no tiene alto fijo, así que la altura se calcula a
 * partir de las líneas.
 */
class ReceiptPdf
{
    /** Milímetros de ancho útil por tipo de papel. */
    private const PAPER_MM = [
        'thermal_58' => 58,
        'thermal_80' => 80,
    ];

    private const MM_TO_PT = 2.83465;

    /**
     * Cuánto avanza un carácter en una monoespaciada, en proporción al cuerpo.
     *
     * DejaVu Sans Mono avanza 0,602 em por carácter. Es el número que convierte
     * "cuarenta y ocho caracteres" en milímetros, y sin él no hay forma de saber
     * si el ticket entra en el rollo.
     */
    private const ADVANCE = 0.602;

    /** Margen a cada lado, en milímetros. */
    private const PADDING_MM = ['thermal' => 2.0, 'page' => 12.0];

    /** @param array<int,array<string,mixed>> $lines */
    public function make(ReceiptTemplate $template, array $lines, array $context): PdfDocument
    {
        $pdf = Pdf::loadHTML($this->html($template, $lines, $context));

        if (isset(self::PAPER_MM[$template->paper])) {
            $width = self::PAPER_MM[$template->paper] * self::MM_TO_PT;
            // Altura estimada a partir del cuerpo real y el interlineado, más
            // margen de corte: sobra papel antes que cortar el total.
            $lineHeight = $this->fontSizeFor($template->paper, self::PADDING_MM['thermal']) * 1.35;
            $height = count($lines) * $lineHeight + 80;

            $pdf->setPaper([0, 0, $width, $height]);
        } else {
            $pdf->setPaper('letter');
        }

        return $pdf;
    }

    /** @param array<int,array<string,mixed>> $lines */
    public function html(ReceiptTemplate $template, array $lines, array $context): string
    {
        $body = '';

        foreach ($lines as $line) {
            $body .= $this->line($line, $context);
        }

        $thermal = str_starts_with($template->paper, 'thermal');
        $padding = $thermal ? self::PADDING_MM['thermal'] : self::PADDING_MM['page'];
        $fontSize = $this->fontSizeFor($template->paper, $padding);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <style>
                @page { margin: 0; }
                body {
                    /* Monoespaciada a propósito: el renderizador justifica con
                       espacios contando caracteres, y con una tipografía
                       proporcional los importes no quedarían alineados. */
                    font-family: "DejaVu Sans Mono", monospace;
                    font-size: {$fontSize}pt;
                    line-height: 1.35;
                    margin: 0;
                    padding: {$padding}mm;
                    white-space: pre;
                }
                .center { text-align: center; }
                .right { text-align: right; }
                .bold { font-weight: bold; }
                /* **No agranda el cuerpo.** El ancho del ticket está medido en
                   caracteres, así que una línea más grande se saldría del rollo
                   y perdería justo lo que lleva a la derecha: el importe. El
                   énfasis de verdad llega con la térmica, que sabe imprimir a
                   doble alto sin ocupar más ancho. */
                .lg { font-weight: bold; }
                .sm { font-size: 0.85em; }
                .sep { border-top: 1px dashed #000; margin: 2px 0; }
                .logo { text-align: center; font-weight: bold; padding: 2px 0; }
            </style>
        </head>
        <body>{$body}</body>
        </html>
        HTML;
    }

    /**
     * Qué cuerpo de letra hace que el ticket entre en el papel.
     *
     * El renderizador justifica **contando caracteres** —32 en un rollo de 58 mm,
     * 48 en uno de 80— y el PDF tiene que respetar esa misma cuenta. Con un
     * cuerpo fijo no la respetaba: a 9 pt, 48 caracteres ocupan unos 91 mm y el
     * papel tiene 80, así que **todo lo alineado a la derecha se salía de la
     * hoja**. Lo que se perdía era exactamente lo que el cliente mira: el número
     * de comprobante, la fecha y la columna entera de importes, incluido el
     * total.
     *
     * Se calcula al revés: del ancho útil se deduce cuánto puede medir cada
     * carácter, y de ahí el cuerpo. El 2 % de margen absorbe el redondeo de la
     * métrica de la fuente.
     */
    private function fontSizeFor(string $paper, float $paddingMm): float
    {
        $chars = ReceiptTemplate::WIDTHS[$paper] ?? 48;
        $widthMm = self::PAPER_MM[$paper] ?? 215.9;
        $usablePt = ($widthMm - 2 * $paddingMm) * self::MM_TO_PT;

        return round($usablePt / ($chars * self::ADVANCE) * 0.98, 2);
    }

    /** @param array<string,mixed> $line */
    private function line(array $line, array $context): string
    {
        $kind = $line['kind'] ?? 'text';

        if ($kind === 'separator') {
            return '<div class="sep"></div>';
        }

        if ($kind === 'spacer') {
            return '<div>&nbsp;</div>';
        }

        if ($kind === 'logo') {
            // **Sin logo cargado no se imprime nada.** Imprimir el nombre del
            // negocio como respaldo lo dejaba dos veces seguidas, porque la
            // plantilla ya lo lleva en su propia línea justo debajo. Un
            // comprobante que repite el nombre del local parece mal armado, que
            // es peor que uno sin logo.
            return '';
        }

        if ($kind === 'qr') {
            return '<div class="center sm">'.e($line['value'] ?? '').'</div>';
        }

        $classes = array_filter([
            ($line['align'] ?? 'left') === 'center' ? 'center' : null,
            ($line['align'] ?? 'left') === 'right' ? 'right' : null,
            ($line['bold'] ?? false) ? 'bold' : null,
            in_array($line['size'] ?? 'md', ['lg', 'sm'], true) ? $line['size'] : null,
        ]);

        $class = $classes === [] ? '' : ' class="'.implode(' ', $classes).'"';

        return '<div'.$class.'>'.e($line['text'] ?? '').'</div>';
    }
}
