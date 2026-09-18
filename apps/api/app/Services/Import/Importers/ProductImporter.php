<?php

namespace App\Services\Import\Importers;

use App\Models\Barcode;
use App\Models\Category;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\TaxCode;
use App\Models\Uom;
use App\Services\Import\RowImporter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importación de productos y servicios (D-15).
 *
 * Clave natural: el **SKU**. Es la que el negocio ya usa y la que hace que
 * reimportar el mismo archivo actualice en vez de duplicar — que es lo que pasa
 * siempre, porque nadie acierta el archivo a la primera.
 */
class ProductImporter implements RowImporter
{
    use ParsesCells;

    public function entityType(): string
    {
        return 'product';
    }

    public function columns(): array
    {
        return [
            'sku' => ['required' => true, 'label' => 'Código'],
            'nombre' => ['required' => true, 'label' => 'Nombre'],
            'precio' => ['required' => true, 'label' => 'Precio con IVA'],
            'costo' => ['required' => false, 'label' => 'Costo'],
            'unidad' => ['required' => false, 'label' => 'Unidad de medida'],
            'impuesto' => ['required' => false, 'label' => 'Código de impuesto'],
            'categoria' => ['required' => false, 'label' => 'Categoría'],
            'codigo_barras' => ['required' => false, 'label' => 'Código de barras'],
            'maneja_stock' => ['required' => false, 'label' => 'Maneja existencias'],
            'stock_minimo' => ['required' => false, 'label' => 'Existencia mínima'],
        ];
    }

    public function inspect(array $raw, string $branchId): array
    {
        $errors = [];

        $sku = $this->text($raw['sku'] ?? '');
        $name = $this->text($raw['nombre'] ?? '');
        $price = $this->decimal($raw['precio'] ?? null);

        if ($sku === '') {
            $errors[] = __('import.column_required', ['column' => 'sku']);
        }

        if ($name === '') {
            $errors[] = __('import.column_required', ['column' => 'nombre']);
        }

        if ($price === null) {
            $errors[] = __('import.not_a_number', ['column' => 'precio']);
        }

        $uomCode = strtoupper($this->text($raw['unidad'] ?? 'UND'));
        $uom = Uom::where('code', $uomCode ?: 'UND')->first();

        if (! $uom) {
            $errors[] = __('import.unknown_reference', ['column' => 'unidad', 'value' => $uomCode]);
        }

        $taxCode = null;
        if (filled($raw['impuesto'] ?? null)) {
            $taxCode = TaxCode::where('code', strtoupper($this->text($raw['impuesto'])))->first();

            if (! $taxCode) {
                $errors[] = __('import.unknown_reference', [
                    'column' => 'impuesto', 'value' => $raw['impuesto'],
                ]);
            }
        }

        $existing = $sku !== '' ? Product::where('sku', $sku)->first() : null;

        return [
            'normalized' => [
                'sku' => $sku,
                'name' => $name,
                'price' => $price,
                'cost' => $this->decimal($raw['costo'] ?? null) ?? '0',
                'uom_id' => $uom?->id,
                'tax_code_id' => $taxCode?->id,
                'category' => $this->text($raw['categoria'] ?? ''),
                'barcode' => $this->text($raw['codigo_barras'] ?? ''),
                'tracks_stock' => $this->boolean($raw['maneja_stock'] ?? null, default: true),
                'min_stock' => $this->decimal($raw['stock_minimo'] ?? null),
            ],
            'errors' => $errors,
            'action' => $errors !== [] ? 'skip' : ($existing ? 'update' : 'create'),
            'entity_id' => $existing?->id,
        ];
    }

    public function apply(ImportRow $row, string $branchId, ?string $employeeId): string
    {
        $data = $row->normalized;

        $category = $data['category'] !== ''
            ? Category::firstOrCreate(
                ['code' => Str::slug($data['category'])],
                ['name' => $data['category']]
            )
            : null;

        $attributes = [
            'name' => $data['name'],
            'price' => $data['price'],
            'cost' => $data['cost'],
            'uom_id' => $data['uom_id'],
            'tax_code_id' => $data['tax_code_id'],
            'category_id' => $category?->id,
            'tracks_stock' => $data['tracks_stock'],
            'min_stock' => $data['min_stock'],
        ];

        $product = Product::updateOrCreate(['sku' => $data['sku']], $attributes);

        if ($data['barcode'] !== '') {
            Barcode::updateOrCreate(
                ['code' => $data['barcode']],
                ['product_id' => $product->id, 'is_primary' => true]
            );
        }

        return $product->id;
    }

    public function revert(ImportRow $row): void
    {
        $product = Product::find($row->entity_id);

        if (! $product) {
            return;
        }

        if ($row->action === 'update') {
            // Se restaura lo que había. Sin el `before` esto sería adivinar.
            $product->forceFill($row->before ?? [])->save();

            return;
        }

        // Un producto que desde la carga ya se vendió no se puede borrar: sus
        // líneas de venta son inmutables y quedarían sin explicación. Se da de
        // baja, que es lo honesto.
        if ($product->saleLinesExist()) {
            $product->update(['is_active' => false]);

            throw new RuntimeException(__('import.product_already_sold', ['sku' => $product->sku]));
        }

        Barcode::where('product_id', $product->id)->delete();
        $product->delete();
    }
}
