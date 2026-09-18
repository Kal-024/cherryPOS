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

    /** @param array<int,array<string,mixed>> $lines */
    public function make(ReceiptTemplate $template, array $lines, array $context): PdfDocument
    {
        $pdf = Pdf::loadHTML($this->html($template, $lines, $context));

        if (isset(self::PAPER_MM[$template->paper])) {
            $width = self::PAPER_MM[$template->paper] * self::MM_TO_PT;
            // Altura estimada por la cantidad de líneas más margen de corte:
            // sobra papel antes que cortar el total.
            $height = count($lines) * 12 + 80;

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
        $fontSize = $thermal ? '9pt' : '11pt';
        $padding = $thermal ? '2mm' : '12mm';

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
                    font-size: {$fontSize};
                    line-height: 1.35;
                    margin: 0;
                    padding: {$padding};
                    white-space: pre;
                }
                .center { text-align: center; }
                .right { text-align: right; }
                .bold { font-weight: bold; }
                .lg { font-size: 1.25em; }
                .sm { font-size: 0.85em; }
                .sep { border-top: 1px dashed #000; margin: 2px 0; }
                .logo { text-align: center; font-weight: bold; padding: 2px 0; }
            </style>
        </head>
        <body>{$body}</body>
        </html>
        HTML;
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
            // Sin logo cargado se imprime el nombre del negocio: un hueco donde
            // debería ir la identidad se lee como una impresora rota.
            return '<div class="logo">'.e($context['branch']['legal_name'] ?? '').'</div>';
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
