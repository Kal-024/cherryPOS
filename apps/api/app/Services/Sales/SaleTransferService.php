<?php

namespace App\Services\Sales;

use App\Models\DiningTable;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Services\Audit\AuditLogger;
use App\Services\Dining\DiningRoomService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Traspasar líneas y dividir la cuenta (F1-B).
 *
 * Las dos operaciones son la misma con distinto destino: mover líneas de una
 * venta abierta a otra. Traspasar elige una cuenta existente —el grupo se cambió
 * de mesa, o la mitad se sentó en la de al lado— y dividir crea una nueva —cada
 * uno paga lo suyo—.
 *
 * Tres reglas que hacen que esto no rompa nada:
 *
 *  1. **Solo sobre ventas abiertas.** Una venta cerrada es inmutable (P1) y el
 *     disparador de la base lo impide; pedirlo acá da un mensaje entendible en
 *     vez de un error de PostgreSQL.
 *  2. **Nunca con pagos ya recibidos.** Mover líneas después de que entró el
 *     dinero cambia qué se pagó: el vuelto calculado deja de corresponder al
 *     ticket. Se cobra o se anula el pago primero.
 *  3. **La comanda no se mueve.** Lo que ya salió a cocina salió desde esa
 *     cuenta y a esa hora; reescribirlo dejaría a la cocina preparando algo que
 *     nadie pidió. La línea cambia de cuenta, su historia no.
 */
class SaleTransferService
{
    public function __construct(
        private SaleCalculationService $calculation,
        private DiningRoomService $dining,
        private AuditLogger $audit,
    ) {}

    /**
     * Mueve líneas de una venta a otra.
     *
     * @param  array<int,string>  $lineIds  vacío = toda la cuenta
     * @return array{source: Sale, target: Sale}
     */
    public function transfer(Sale $source, Sale $target, array $lineIds = []): array
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['target' => __('sales.transfer_same_sale')]);
        }

        $this->assertMovable($source);
        $this->assertMovable($target);

        $lines = $this->linesOf($source, $lineIds);

        DB::transaction(function () use ($lines, $source, $target) {
            $sequence = (int) SaleLine::where('sale_id', $target->id)->max('sequence');

            foreach ($lines as $line) {
                $line->forceFill([
                    'sale_id' => $target->id,
                    'sequence' => ++$sequence,
                ])->save();
            }

            $this->resequence($source);
        });

        $this->calculation->recalculate($source->fresh());
        $this->calculation->recalculate($target->fresh());

        $this->audit->record(
            event: 'sale.lines_transferred',
            entityType: 'pos_sales',
            entityId: $source->id,
            changes: ['lines' => $lines->pluck('id')->all(), 'to' => $target->id],
            context: ['count' => $lines->count()],
            branchId: $source->branch_id,
        );

        return ['source' => $source->fresh(), 'target' => $target->fresh()];
    }

    /**
     * Divide la cuenta: las líneas elegidas se van a una cuenta nueva.
     *
     * La nueva **queda en la misma mesa** cuando la hay: dividir no es levantarse
     * de la mesa, es pagar por separado, y el mapa tiene que seguir mostrando
     * una sola mesa ocupada.
     *
     * @param  array<int,string>  $lineIds
     * @return array{source: Sale, target: Sale}
     */
    public function split(Sale $source, array $lineIds, array $context = []): array
    {
        if ($lineIds === []) {
            throw ValidationException::withMessages(['lines' => __('sales.split_needs_lines')]);
        }

        $this->assertMovable($source);

        $lines = $this->linesOf($source, $lineIds);

        if ($lines->count() === $source->lines()->count()) {
            // Mover todo a una cuenta nueva no divide nada: deja la primera
            // vacía y duplica el trabajo.
            throw ValidationException::withMessages(['lines' => __('sales.split_needs_remainder')]);
        }

        $target = Sale::create([
            'id' => (string) Str::uuid7(),
            'branch_id' => $source->branch_id,
            'terminal_id' => $context['terminal_id'] ?? $source->terminal_id,
            'shift_id' => $source->shift_id,
            'employee_id' => $context['employee_id'] ?? $source->employee_id,
            'customer_id' => null,
            'sale_type' => $source->sale_type,
            'status' => $source->status,
            'currency_code' => $source->currency_code,
            'dining_table_id' => $source->dining_table_id,
            'waiter_employee_id' => $source->waiter_employee_id,
            'label' => $this->splitLabel($source),
            'opened_at' => now(),
        ]);

        return $this->transfer($source, $target, $lines->pluck('id')->all());
    }

    /** Mueve la cuenta entera a otra mesa. */
    public function moveToTable(Sale $sale, DiningTable $table, array $context = []): array
    {
        if ($table->isMerged()) {
            throw ValidationException::withMessages(['table' => __('dining.table_merged')]);
        }

        $existing = $table->openSale;

        // Si la mesa destino ya tiene cuenta, las líneas se le suman: dos cuentas
        // en una mesa es cobrar dos veces al mismo grupo.
        if ($existing) {
            return $this->transfer($sale, $existing);
        }

        $this->assertMovable($sale);

        $sale->forceFill([
            'dining_table_id' => $table->id,
            'label' => $table->name ?: __('dining.table_label', ['code' => $table->code]),
        ])->save();

        $this->audit->record(
            event: 'sale.table_changed',
            entityType: 'pos_sales',
            entityId: $sale->id,
            changes: ['to_table' => $table->id],
            branchId: $sale->branch_id,
        );

        return ['source' => $sale->fresh(), 'target' => $sale->fresh()];
    }

    /** @return Collection<int,SaleLine> */
    private function linesOf(Sale $sale, array $lineIds)
    {
        $query = SaleLine::where('sale_id', $sale->id);

        if ($lineIds !== []) {
            $query->whereIn('id', $lineIds);
        }

        $lines = $query->orderBy('sequence')->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales.transfer_no_lines')]);
        }

        if ($lineIds !== [] && $lines->count() !== count(array_unique($lineIds))) {
            // Pedir una línea que no es de esta cuenta es un error de la
            // pantalla: moverla en silencio cambiaría una cuenta ajena.
            throw ValidationException::withMessages(['lines' => __('sales.transfer_foreign_line')]);
        }

        return $lines;
    }

    private function assertMovable(Sale $sale): void
    {
        if ($sale->isClosed()) {
            throw ValidationException::withMessages(['sale' => __('sales.already_closed')]);
        }

        if ($sale->payments()->count() > 0) {
            throw ValidationException::withMessages(['sale' => __('sales.transfer_has_payments')]);
        }
    }

    private function resequence(Sale $sale): void
    {
        $sequence = 1;

        foreach (SaleLine::where('sale_id', $sale->id)->orderBy('sequence')->get() as $line) {
            $line->forceFill(['sequence' => $sequence++])->save();
        }
    }

    private function splitLabel(Sale $source): string
    {
        $base = $source->label ?: __('sales.split_default_label');
        $siblings = Sale::where('dining_table_id', $source->dining_table_id)
            ->whereIn('status', [Sale::STATUS_DRAFT, Sale::STATUS_SUSPENDED])
            ->count();

        return __('sales.split_label', ['label' => $base, 'number' => $siblings + 1]);
    }
}
