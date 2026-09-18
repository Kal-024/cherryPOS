<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Exportación genérica a Excel.
 *
 * Deliberadamente tonta: recibe encabezados y filas ya armados. Quien sabe qué
 * exportar es el servicio de dominio, no esta clase — así el mismo exportador
 * sirve para el catálogo, para los clientes y para la plantilla vacía que se le
 * entrega al cliente para que la llene.
 */
class RowsExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array<int,string>  $headings
     * @param  array<int,array<int,mixed>>  $rows
     */
    public function __construct(
        private array $headings,
        private array $rows,
        private string $title = 'Datos',
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
