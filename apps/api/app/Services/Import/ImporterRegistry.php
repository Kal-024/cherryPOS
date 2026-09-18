<?php

namespace App\Services\Import;

use App\Services\Import\Importers\CustomerImporter;
use App\Services\Import\Importers\ProductImporter;
use App\Services\Import\Importers\StockImporter;
use App\Services\Import\Importers\SupplierImporter;
use Illuminate\Validation\ValidationException;

/**
 * Qué se puede importar (D-15).
 *
 * Los cuatro de F1: productos, clientes, **stock inicial y proveedores**. Los
 * dos últimos entraron por pedido explícito, y son los que hacen que una
 * instalación nueva pueda empezar a vender el mismo día.
 */
class ImporterRegistry
{
    /** @var array<string,class-string<RowImporter>> */
    private const IMPORTERS = [
        'products' => ProductImporter::class,
        'customers' => CustomerImporter::class,
        'suppliers' => SupplierImporter::class,
        'stock' => StockImporter::class,
    ];

    public function for(string $kind): RowImporter
    {
        $class = self::IMPORTERS[$kind] ?? throw ValidationException::withMessages([
            'kind' => __('import.unknown_kind', ['kind' => $kind]),
        ]);

        return app($class);
    }

    /** @return array<int,string> */
    public function kinds(): array
    {
        return array_keys(self::IMPORTERS);
    }

    /**
     * Plantilla de columnas de cada tipo. La interfaz la usa para generar el
     * Excel de ejemplo: pedirle al cliente que adivine los encabezados es el
     * camino corto a cuarenta archivos rechazados.
     *
     * @return array<string,array{required:bool,label:string}>
     */
    public function template(string $kind): array
    {
        return $this->for($kind)->columns();
    }
}
