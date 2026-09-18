<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockBalance;
use App\Services\Calc\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Libro mayor de existencias (B-03, H1.3).
 *
 * **El stock nunca se escribe directo: solo se suma.** No hay una operación
 * "poner el stock en 40"; hay un movimiento de ajuste de +8 con su empleado, su
 * fecha y su comentario. Es la misma idea que la inmutabilidad de los
 * documentos, aplicada al inventario, y la razón por la que un disparador
 * impide `UPDATE` y `DELETE` sobre `inv_movements`.
 *
 * `inv_stock_balances` es una caché derivable, no la verdad. Se actualiza en la
 * misma transacción que el movimiento, y `recalculate()` la reconstruye desde
 * cero cuando haga falta — que es lo que la hace segura.
 */
class StockLedgerService
{
    public function __construct(private CostingService $costing) {}

    /**
     * Registra un movimiento y actualiza el saldo cacheado.
     *
     * `$qty` positiva entra, negativa sale. No hay banderas de dirección: el
     * signo es la dirección, y así el saldo es literalmente la suma.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes): InventoryMovement
    {
        $qty = (string) $attributes['qty'];

        if (Decimal::cmp(Decimal::parse($qty, Decimal::QTY), '0') === 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory.movement_zero'),
            ]);
        }

        return DB::transaction(function () use ($attributes, $qty) {
            $product = Product::findOrFail($attributes['product_id']);

            // Los servicios no llevan existencias (D-07). Registrarles
            // movimientos llenaría el kardex de filas sin significado.
            if (! $product->tracks_stock) {
                throw ValidationException::withMessages([
                    'product_id' => __('inventory.product_without_stock', ['name' => $product->name]),
                ]);
            }

            if (Decimal::isNegative(Decimal::parse($qty, Decimal::QTY))) {
                $this->assertAvailable($product, $attributes, $qty);
            }

            $movement = InventoryMovement::create([
                'branch_id' => $attributes['branch_id'],
                'location_id' => $attributes['location_id'],
                'product_id' => $product->id,
                'lot_id' => $attributes['lot_id'] ?? null,
                'reason' => $attributes['reason'],
                'qty' => $qty,
                'unit_cost' => $attributes['unit_cost'] ?? null,
                'source_type' => $attributes['source_type'] ?? null,
                'source_id' => $attributes['source_id'] ?? null,
                'employee_id' => $attributes['employee_id'] ?? null,
                'comment' => $attributes['comment'] ?? null,
                'occurred_at' => $attributes['occurred_at'] ?? now(),
                'recorded_at' => now(),
            ]);

            $this->applyToBalance($movement);
            $this->costing->apply($movement, $product);

            return $movement;
        });
    }

    /** Existencia actual, sumada desde la caché. */
    public function available(string $productId, string $locationId, ?string $lotId = null): string
    {
        $query = StockBalance::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId);

        if ($lotId !== null) {
            $query->where('lot_id', $lotId);
        }

        return Decimal::format(
            Decimal::parse((string) ($query->sum('qty') ?: '0'), Decimal::QTY),
            Decimal::QTY
        );
    }

    /**
     * Reconstruye el saldo cacheado desde los movimientos.
     *
     * La caché puede estar mal —un despliegue a medias, una restauración— y el
     * libro no. Por eso esta operación existe y por eso la verdad vive en
     * `inv_movements`.
     */
    public function recalculate(string $productId, ?string $locationId = null): int
    {
        $sums = InventoryMovement::query()
            ->selectRaw('location_id, lot_id, SUM(qty) as total')
            ->where('product_id', $productId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('location_id', 'lot_id')
            ->get();

        $touched = 0;

        foreach ($sums as $sum) {
            StockBalance::updateOrCreate(
                [
                    'product_id' => $productId,
                    'location_id' => $sum->location_id,
                    'lot_id' => $sum->lot_id,
                ],
                ['qty' => $sum->total, 'recalculated_at' => now()]
            );

            $touched++;
        }

        return $touched;
    }

    private function applyToBalance(InventoryMovement $movement): void
    {
        $balance = StockBalance::firstOrCreate(
            [
                'product_id' => $movement->product_id,
                'location_id' => $movement->location_id,
                'lot_id' => $movement->lot_id,
            ],
            ['qty' => '0']
        );

        $balance->qty = Decimal::format(
            Decimal::add(
                Decimal::parse((string) $balance->qty, Decimal::QTY),
                Decimal::parse((string) $movement->qty, Decimal::QTY)
            ),
            Decimal::QTY
        );

        $balance->save();
    }

    /** @param array<string,mixed> $attributes */
    private function assertAvailable(Product $product, array $attributes, string $qty): void
    {
        if ($product->allow_negative_stock) {
            return;
        }

        $available = $this->available(
            $product->id,
            $attributes['location_id'],
            $attributes['lot_id'] ?? null
        );

        $requested = Decimal::abs(Decimal::parse($qty, Decimal::QTY));

        if (Decimal::cmp(Decimal::parse($available, Decimal::QTY), $requested) < 0) {
            // Error con acción, nunca callejón sin salida: el mensaje dice
            // cuánto hay, que es lo que el cajero necesita para decidir.
            throw ValidationException::withMessages([
                'qty' => __('inventory.not_enough_stock', [
                    'name' => $product->name,
                    'available' => $available,
                ]),
            ]);
        }
    }
}
