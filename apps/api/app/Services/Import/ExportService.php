<?php

namespace App\Services\Import;

use App\Exports\RowsExport;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Supplier;

/**
 * Exportación a Excel (D-15).
 *
 * Dos usos que parecen el mismo y no lo son:
 *
 *  - **Plantilla vacía** — los encabezados que el importador espera. Pedirle al
 *    cliente que los adivine es el camino corto a cuarenta archivos rechazados.
 *  - **Datos actuales** — lo que hay cargado, en el **mismo formato que se
 *    importa**. Eso convierte la exportación en la herramienta de edición
 *    masiva: se baja, se corrige en Excel y se vuelve a subir, y como la clave
 *    natural coincide, actualiza en vez de duplicar.
 */
class ExportService
{
    public function __construct(private ImporterRegistry $registry) {}

    /** Plantilla vacía con los encabezados y una fila de ayuda. */
    public function template(string $kind): RowsExport
    {
        $columns = $this->registry->template($kind);

        $headings = array_keys($columns);
        $hint = array_map(
            static fn (array $column) => $column['required']
                ? $column['label'].' (obligatorio)'
                : $column['label'],
            array_values($columns)
        );

        return new RowsExport($headings, [$hint], 'Plantilla');
    }

    public function data(string $kind, string $branchId): RowsExport
    {
        return match ($kind) {
            'products' => $this->products(),
            'customers' => $this->customers(),
            'suppliers' => $this->suppliers(),
            'stock' => $this->stock($branchId),
            default => $this->template($kind),
        };
    }

    private function products(): RowsExport
    {
        $headings = array_keys($this->registry->template('products'));

        $rows = Product::with(['uom:id,code', 'taxCode:id,code', 'category:id,name', 'barcodes'])
            ->orderBy('sku')
            ->get()
            ->map(fn (Product $p) => [
                $p->sku,
                $p->name,
                (string) $p->price,
                (string) $p->cost,
                $p->uom?->code,
                $p->taxCode?->code,
                $p->category?->name,
                $p->barcodes->firstWhere('is_primary', true)?->code,
                $p->tracks_stock ? 'si' : 'no',
                $p->min_stock !== null ? (string) $p->min_stock : null,
            ])->all();

        return new RowsExport($headings, $rows, 'Productos');
    }

    private function customers(): RowsExport
    {
        $headings = array_keys($this->registry->template('customers'));

        $rows = Customer::with(['person', 'creditAccount'])
            ->get()
            ->map(fn (Customer $c) => [
                $c->name,
                $c->kind === 'account' ? 'cuenta' : 'efectivo',
                $c->national_id,
                $c->tax_id,
                $c->email,
                $c->phone,
                $c->whatsapp,
                $c->address,
                $c->creditAccount ? (string) $c->creditAccount->credit_limit : null,
                $c->is_tax_exempt ? 'si' : 'no',
            ])->all();

        return new RowsExport($headings, $rows, 'Clientes');
    }

    private function suppliers(): RowsExport
    {
        $headings = array_keys($this->registry->template('suppliers'));

        $rows = Supplier::with('person')
            ->get()
            ->map(fn (Supplier $s) => [
                $s->name,
                $s->kind === Supplier::EXPENSE ? 'gastos' : 'mercaderia',
                $s->code,
                $s->national_id,
                $s->tax_id,
                $s->email,
                $s->phone,
                $s->address,
                $s->contact_name,
                (string) $s->credit_days,
            ])->all();

        return new RowsExport($headings, $rows, 'Proveedores');
    }

    private function stock(string $branchId): RowsExport
    {
        $headings = array_keys($this->registry->template('stock'));

        $rows = StockBalance::query()
            ->join('inv_locations as l', 'l.id', '=', 'inv_stock_balances.location_id')
            ->join('cat_products as p', 'p.id', '=', 'inv_stock_balances.product_id')
            ->leftJoin('inv_lots as lo', 'lo.id', '=', 'inv_stock_balances.lot_id')
            ->where('l.branch_id', $branchId)
            // Exportar existencias en cero llenaría el archivo de ruido: lo que
            // interesa es lo que hay.
            ->where('inv_stock_balances.qty', '!=', 0)
            ->orderBy('p.sku')
            ->get([
                'p.sku', 'inv_stock_balances.qty', 'l.code as location_code',
                'p.cost', 'lo.code as lot_code', 'lo.expires_on',
            ])
            ->map(fn ($r) => [
                $r->sku,
                (string) $r->qty,
                $r->location_code,
                (string) $r->cost,
                $r->lot_code,
                $r->expires_on,
            ])->all();

        return new RowsExport($headings, $rows, 'Existencias');
    }
}
