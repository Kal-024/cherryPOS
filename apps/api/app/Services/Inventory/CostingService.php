<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Calc\Decimal;
use RuntimeException;

/**
 * Costeo de existencias.
 *
 * **El método es configurable, no cableado** (Q-02, P3 — el que llega primero
 * define). Si el ERP ya está instalado cuando llega el POS, el POS adopta la
 * configuración del módulo de inventario del ERP; si solo existe el POS, rige la
 * suya y el ERP se alinea al integrarse después.
 *
 * Esto reemplaza la elección fija de promedio ponderado que traía D-08. Que sea
 * configurable importa por una razón concreta: si el ERP costea por capas y el
 * POS por promedio, **los márgenes no coinciden entre sistemas**, que es
 * justamente el número que el dueño va a mirar.
 *
 * **A1 quedó cerrado como implementación, no como decisión.** cherryB expone
 * `cmn_company_settings.inventory_valuation_method` con enum `AVG` / `FIFO` y
 * por defecto `AVG`; el POS usa el mismo vocabulario. Lo que falta es el FIFO
 * por capas, y solo hace falta si un cliente con ese método instala el POS antes
 * que el ERP. Configurarlo hoy falla de forma ruidosa en vez de calcular de más
 * — un costo silenciosamente equivocado es peor que una caja que no arranca.
 */
class CostingService
{
    /**
     * Mismo vocabulario que `cmn_company_settings.inventory_valuation_method`
     * de cherryB, que ya implementa los dos en `StockValuationService`.
     * Coincidir de nombre evita una tabla de traducción el día que se integre.
     */
    public const AVG = 'AVG';

    public const FIFO = 'FIFO';

    public function method(): string
    {
        return config('pos.costing_method', self::AVG);
    }

    public function apply(InventoryMovement $movement, Product $product): void
    {
        // Solo las entradas con costo conocido mueven el costo. Una salida
        // consume al costo vigente y no lo altera; un ajuste sin costo tampoco.
        if ($movement->unit_cost === null || Decimal::isNegative((string) $movement->qty)) {
            return;
        }

        match ($this->method()) {
            self::AVG => $this->applyWeightedAverage($movement, $product),
            self::FIFO => throw new RuntimeException(
                'El costeo FIFO por capas todavía no está implementado en el POS. cherryB sí '
                .'lo tiene (`inv_cost_layers` + `StockValuationService`), así que solo hace '
                .'falta si un cliente que usa FIFO instala el POS **antes** que el ERP. '
                .'Configurar `POS_COSTING_METHOD=AVG` mientras tanto.'
            ),
            default => throw new RuntimeException(
                "Método de costeo desconocido: {$this->method()}"
            ),
        };
    }

    /**
     * Promedio ponderado.
     *
     * `costo = (existencia × costo_actual + entrada × costo_entrada) / total`.
     *
     * Con existencia en cero o negativa el promedio no significa nada —no hay
     * nada sobre lo que promediar—, así que el costo de la entrada pasa a ser el
     * costo, sin más.
     */
    private function applyWeightedAverage(InventoryMovement $movement, Product $product): void
    {
        $onHand = Decimal::parse(
            app(StockLedgerService::class)->available($product->id, $movement->location_id),
            Decimal::QTY
        );

        // El movimiento ya está aplicado al saldo, así que la existencia previa
        // es la actual menos lo que acaba de entrar.
        $incoming = Decimal::parse((string) $movement->qty, Decimal::QTY);
        $previous = Decimal::sub($onHand, $incoming);

        $incomingCost = Decimal::parse((string) $movement->unit_cost, Decimal::PRICE);

        if (Decimal::cmp($previous, '0') <= 0) {
            $product->cost = Decimal::format($incomingCost, Decimal::PRICE);
            $product->save();

            return;
        }

        $currentCost = Decimal::parse((string) $product->cost, Decimal::PRICE);

        $value = Decimal::add(
            bcmul($previous, $currentCost, 0),
            bcmul($incoming, $incomingCost, 0)
        );

        $product->cost = Decimal::format(
            Decimal::divRoundHalfUp($value, $onHand),
            Decimal::PRICE
        );

        $product->save();
    }
}
