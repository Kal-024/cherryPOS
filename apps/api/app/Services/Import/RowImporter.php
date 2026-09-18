<?php

namespace App\Services\Import;

use App\Models\ImportRow;

/**
 * Contrato de un importador.
 *
 * Cada tipo de carga —productos, clientes, proveedores, existencias— resuelve
 * lo mismo de forma distinta: qué columnas espera, cuál es su **clave natural**
 * y cómo se deshace lo que hizo.
 *
 * La clave natural es la pieza que decide si una fila crea o actualiza, y es la
 * misma que usará la bandeja de conciliación al integrar el ERP (Q-04): cédula
 * para clientes con cuenta, código de barras o código interno para productos.
 * **Nunca fusión automática por nombre.**
 */
interface RowImporter
{
    /**
     * Columnas del archivo. La clave es el encabezado esperado; el valor dice
     * si es obligatoria y qué significa.
     *
     * @return array<string,array{required:bool,label:string}>
     */
    public function columns(): array;

    /**
     * Valida y normaliza una fila **sin escribir nada**.
     *
     * @param  array<string,mixed>  $raw
     * @return array{normalized: array<string,mixed>, errors: array<int,string>, action: string, entity_id: ?string}
     */
    public function inspect(array $raw, string $branchId): array;

    /** Ejecuta la fila. Devuelve el identificador de lo que tocó. */
    public function apply(ImportRow $row, string $branchId, ?string $employeeId): string;

    /**
     * Deshace la fila.
     *
     * @throws \RuntimeException si ya no se puede deshacer — por ejemplo, un
     *                           producto que desde entonces se vendió
     */
    public function revert(ImportRow $row): void;

    /** Qué entidad toca, para poder explicarlo en la previsualización. */
    public function entityType(): string;
}
