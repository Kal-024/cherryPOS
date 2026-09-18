<?php

namespace App\Services\Inventory;

use App\Models\Lot;
use App\Models\StockBalance;
use App\Services\Calc\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Asignación de lotes en una salida (G-07, H1.4).
 *
 * **Primero en vencer, primero en salir.** No es una preferencia contable: en
 * farmacia y en alimentos, sacar el lote de atrás es cómo se llega a vender
 * mercadería vencida, y eso no se arregla con un ajuste después.
 *
 * Los lotes sin fecha de vencimiento van al final: se prefiere mover lo que
 * caduca antes que lo que no caduca nunca.
 */
class LotAllocationService
{
    /**
     * @return array<int,array{lot_id: string, qty: string}>
     */
    public function allocate(string $productId, string $locationId, string $qty): array
    {
        $needed = Decimal::parse($qty, Decimal::QTY);

        if (Decimal::cmp($needed, '0') <= 0) {
            return [];
        }

        $balances = StockBalance::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereNotNull('lot_id')
            ->where('qty', '>', 0)
            ->get();

        $lots = Lot::whereIn('id', $balances->pluck('lot_id'))->get()->keyBy('id');

        $ordered = $balances->sortBy(function ($balance) use ($lots) {
            $lot = $lots->get($balance->lot_id);

            // Sin vencimiento va al final: se prefiere mover lo que caduca.
            return $lot?->expires_on?->format('Y-m-d') ?? '9999-12-31';
        })->values();

        $allocation = [];

        foreach ($ordered as $balance) {
            if (Decimal::cmp($needed, '0') <= 0) {
                break;
            }

            $onHand = Decimal::parse((string) $balance->qty, Decimal::QTY);
            $take = Decimal::cmp($onHand, $needed) >= 0 ? $needed : $onHand;

            $allocation[] = [
                'lot_id' => $balance->lot_id,
                'qty' => Decimal::format($take, Decimal::QTY),
            ];

            $needed = Decimal::sub($needed, $take);
        }

        if (Decimal::cmp($needed, '0') > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory.not_enough_lots', [
                    'missing' => Decimal::format($needed, Decimal::QTY),
                ]),
            ]);
        }

        return $allocation;
    }

    /**
     * Lotes vencidos con existencia. Alimenta la alerta, no un reporte que
     * alguien tenga que acordarse de abrir (D-20).
     *
     * @return Collection<int,Lot>
     */
    public function expired(string $locationId)
    {
        $withStock = StockBalance::query()
            ->where('location_id', $locationId)
            ->whereNotNull('lot_id')
            ->where('qty', '>', 0)
            ->pluck('lot_id');

        return Lot::whereIn('id', $withStock)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', now())
            ->orderBy('expires_on')
            ->get();
    }
}
