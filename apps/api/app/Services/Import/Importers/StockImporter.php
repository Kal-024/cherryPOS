<?php

namespace App\Services\Import\Importers;

use App\Models\ImportRow;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Product;
use App\Services\Calc\Decimal;
use App\Services\Import\RowImporter;
use App\Services\Inventory\StockLedgerService;

/**
 * Carga de existencias iniciales (D-15).
 *
 * **Revertir aquí no es borrar: es compensar.** El kardex es de solo inserción
 * —un disparador lo impone— porque el stock es la suma de movimientos y no un
 * número editable (B-03). Deshacer una carga de 3.000 líneas significa 3.000
 * movimientos en sentido contrario, y eso está bien: el histórico cuenta lo que
 * pasó, incluido el error.
 *
 * El costo unitario importa más de lo que parece: es el que alimenta el costeo
 * y, por lo tanto, el margen de todos los reportes. Una carga inicial sin costo
 * deja el margen en 100 % hasta la primera compra.
 */
class StockImporter implements RowImporter
{
    use ParsesCells;

    public function __construct(private StockLedgerService $ledger) {}

    public function entityType(): string
    {
        return 'inventory_movement';
    }

    public function columns(): array
    {
        return [
            'sku' => ['required' => true, 'label' => 'Código del producto'],
            'cantidad' => ['required' => true, 'label' => 'Cantidad'],
            'ubicacion' => ['required' => false, 'label' => 'Bodega (por defecto, la de venta)'],
            'costo_unitario' => ['required' => false, 'label' => 'Costo unitario'],
            'lote' => ['required' => false, 'label' => 'Lote'],
            'vence' => ['required' => false, 'label' => 'Fecha de vencimiento'],
        ];
    }

    public function inspect(array $raw, string $branchId): array
    {
        $errors = [];

        $sku = $this->text($raw['sku'] ?? '');
        $product = $sku !== '' ? Product::where('sku', $sku)->first() : null;

        if (! $product) {
            $errors[] = __('import.unknown_reference', ['column' => 'sku', 'value' => $sku]);
        } elseif (! $product->tracks_stock) {
            // Un servicio no lleva existencias (D-07): cargarle stock llenaría
            // el kardex de filas sin significado.
            $errors[] = __('import.product_without_stock', ['sku' => $sku]);
        }

        $qty = $this->decimal($raw['cantidad'] ?? null);

        if ($qty === null || Decimal::cmp(Decimal::parse($qty, Decimal::QTY), '0') === 0) {
            $errors[] = __('import.not_a_number', ['column' => 'cantidad']);
        }

        $locationCode = $this->text($raw['ubicacion'] ?? '');
        $location = $locationCode !== ''
            ? Location::where('branch_id', $branchId)->where('code', $locationCode)->first()
            : Location::where('branch_id', $branchId)->where('is_sales_default', true)->first();

        if (! $location) {
            $errors[] = __('import.unknown_reference', ['column' => 'ubicacion', 'value' => $locationCode]);
        }

        $expires = $this->date($raw['vence'] ?? null);
        $lotCode = $this->text($raw['lote'] ?? '');

        if ($product && $product->tracks_lots && $lotCode === '') {
            $errors[] = __('import.lot_required', ['sku' => $sku]);
        }

        return [
            'normalized' => [
                'product_id' => $product?->id,
                'location_id' => $location?->id,
                'qty' => $qty,
                'unit_cost' => $this->decimal($raw['costo_unitario'] ?? null),
                'lot_code' => $lotCode,
                'expires_on' => $expires,
            ],
            'errors' => $errors,
            // Una carga de existencias siempre suma un asiento nuevo: no existe
            // "actualizar un movimiento".
            'action' => $errors !== [] ? 'skip' : 'create',
            'entity_id' => null,
        ];
    }

    public function apply(ImportRow $row, string $branchId, ?string $employeeId): string
    {
        $data = $row->normalized;

        $lotId = null;
        if ($data['lot_code'] !== '') {
            $lotId = Lot::firstOrCreate(
                ['product_id' => $data['product_id'], 'code' => $data['lot_code']],
                ['expires_on' => $data['expires_on']]
            )->id;
        }

        return $this->ledger->record([
            'branch_id' => $branchId,
            'location_id' => $data['location_id'],
            'product_id' => $data['product_id'],
            'lot_id' => $lotId,
            // `count` y no `adjustment`: es un inventario inicial, no la
            // corrección de un error.
            'reason' => 'count',
            'qty' => $data['qty'],
            'unit_cost' => $data['unit_cost'],
            'source_type' => 'import',
            'source_id' => $row->batch_id,
            'employee_id' => $employeeId,
            'comment' => __('import.initial_stock_comment'),
            'occurred_at' => now(),
        ])->id;
    }

    public function revert(ImportRow $row): void
    {
        $movement = InventoryMovement::find($row->entity_id);

        if (! $movement) {
            return;
        }

        // El asiento original se queda donde está: se le suma su contrario. Es
        // la misma regla que gobierna las ventas — no se edita, se reversa.
        $this->ledger->record([
            'branch_id' => $movement->branch_id,
            'location_id' => $movement->location_id,
            'product_id' => $movement->product_id,
            'lot_id' => $movement->lot_id,
            'reason' => 'adjustment',
            'qty' => Decimal::format(
                Decimal::negate(Decimal::parse((string) $movement->qty, Decimal::QTY)),
                Decimal::QTY
            ),
            'unit_cost' => null,
            'source_type' => 'import_revert',
            'source_id' => $row->batch_id,
            'employee_id' => $movement->employee_id,
            'comment' => __('import.reverted_stock_comment'),
            'occurred_at' => now(),
        ]);
    }
}
