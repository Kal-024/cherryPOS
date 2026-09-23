<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Validation\ValidationException;

/**
 * Devoluciones contra el ticket original (P-04, H2.6).
 *
 * Una devolución **no es una entidad aparte**: es una venta con `sale_type`
 * `refund`, cantidades negativas y pago negativo, apuntando con
 * `reverses_sale_id` al documento que reversa. El motor la trata como el
 * negativo exacto de su venta, y por eso los importes cuadran al centavo sin
 * fórmulas nuevas.
 *
 * **La regla del ticket original se impone acá, no en el ERP.** El contrato dice
 * que cherryB rechaza la nota de crédito con `refund_original_missing` si el
 * documento no existe o no está emitido, pero esa respuesta llega cuando el
 * dinero ya salió del cajón: la cola es asíncrona. Comprobarlo antes de devolver
 * es la diferencia entre un aviso y un descuadre.
 *
 * **Y lo devuelto se descuenta de lo devolvible.** Sin llevar esa cuenta, tres
 * devoluciones parciales de una unidad cada una vacían un ticket de dos: el
 * mismo producto se paga dos veces y nada lo denuncia hasta el arqueo.
 */
class RefundService
{
    /**
     * Qué queda por devolver de un ticket, línea por línea.
     *
     * @return array<string,mixed>
     */
    public function refundable(Sale $original): array
    {
        $this->assertRefundable($original);

        $returned = $this->returnedQuantities($original);

        $lines = $original->lines->map(function (SaleLine $line) use ($returned) {
            $already = $returned[$this->keyOf($line)] ?? '0';
            $pending = bcsub((string) $line->qty, $already, 4);

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'qty' => (string) $line->qty,
                'returned' => $already,
                // Lo que todavía se puede devolver. Cero significa que esa línea
                // ya volvió entera.
                'refundable' => bccomp($pending, '0', 4) === 1 ? $pending : '0.0000',
                'unit_price' => (string) $line->unit_price,
                'total' => (string) $line->total,
            ];
        })->values();

        return [
            'sale' => $original->only(['id', 'number', 'sale_type', 'status', 'total', 'closed_at']),
            'lines' => $lines,
            'fully_returned' => $lines->every(fn (array $line) => bccomp($line['refundable'], '0', 4) === 0),
        ];
    }

    /**
     * ¿Se puede devolver contra este ticket?
     *
     * @throws ValidationException
     */
    public function assertRefundable(Sale $original): void
    {
        if ($original->sale_type === 'refund') {
            throw ValidationException::withMessages([
                'reverses_sale_id' => __('sales.refund_of_refund'),
            ]);
        }

        // Una venta que no se cerró no cobró nada, y una anulada ya se reversó
        // entera: devolver sobre cualquiera de las dos sería regalar dinero.
        if ($original->status !== Sale::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'reverses_sale_id' => __('sales.refund_original_not_closed'),
            ]);
        }
    }

    /**
     * Comprueba que lo que se quiere devolver todavía esté por devolver.
     *
     * @param  array<int,array{product_id:string|null,qty:string}>  $lines
     *
     * @throws ValidationException
     */
    public function assertWithinOriginal(Sale $original, array $lines): void
    {
        $refundable = collect($this->refundable($original)['lines'])
            ->keyBy(fn (array $line) => (string) $line['product_id']);

        foreach ($lines as $line) {
            $wanted = ltrim((string) $line['qty'], '-');
            $available = $refundable[(string) ($line['product_id'] ?? '')]['refundable'] ?? '0';

            if (bccomp($wanted, $available, 4) === 1) {
                throw ValidationException::withMessages([
                    'lines' => __('sales.refund_exceeds_original', [
                        'description' => $refundable[(string) ($line['product_id'] ?? '')]['description'] ?? '',
                        'available' => rtrim(rtrim($available, '0'), '.'),
                    ]),
                ]);
            }
        }
    }

    /**
     * Cuánto se devolvió ya de cada línea, sumando todas las devoluciones
     * cerradas que apuntan a este ticket.
     *
     * @return array<string,string>
     */
    private function returnedQuantities(Sale $original): array
    {
        $totals = [];

        $refunds = Sale::where('reverses_sale_id', $original->id)
            ->where('sale_type', 'refund')
            ->where('status', Sale::STATUS_COMPLETED)
            ->with('lines')
            ->get();

        foreach ($refunds as $refund) {
            foreach ($refund->lines as $line) {
                $key = $this->keyOf($line);
                // Las líneas de devolución vienen en negativo: se acumula su
                // valor absoluto, que es lo que volvió al estante.
                $totals[$key] = bcadd($totals[$key] ?? '0', ltrim((string) $line->qty, '-'), 4);
            }
        }

        return $totals;
    }

    /** Por producto: es lo que el cajero reconoce y lo que el ERP compara. */
    private function keyOf(SaleLine $line): string
    {
        return (string) $line->product_id;
    }
}
