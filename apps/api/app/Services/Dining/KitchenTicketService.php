<?php

namespace App\Services\Dining;

use App\Models\KitchenTicket;
use App\Models\KitchenTicketLine;
use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La comanda: lo que se manda a preparar (F1-B).
 *
 * Tres decisiones que el servicio hace cumplir:
 *
 *  1. **Solo se manda lo que todavía no se mandó.** La cuenta sigue creciendo
 *     toda la noche; mandar la venta entera en cada envío haría que cocina
 *     preparara dos veces el mismo plato, que es el error más caro del salón.
 *  2. **Cocina y barra son destinos distintos**, y sale del producto
 *     (`prep_station`): la cerveza no espera al lomo, y elegir el destino a mano
 *     en cada envío se equivoca en hora pico.
 *  3. **Un modificador obligatorio sin responder bloquea el envío.** Quien
 *     descubra que falta el término de la carne no puede ser el cocinero.
 */
class KitchenTicketService
{
    public function __construct(private ModifierService $modifiers) {}

    /**
     * Las líneas que todavía no se mandaron a ninguna comanda viva.
     *
     * @return Collection<int,SaleLine>
     */
    public function pending(Sale $sale): Collection
    {
        $sent = KitchenTicketLine::query()
            ->join('pos_kitchen_tickets as t', 't.id', '=', 'pos_kitchen_ticket_lines.ticket_id')
            ->where('t.sale_id', $sale->id)
            // Una comanda cancelada no cuenta como enviada: lo que se anuló hay
            // que volver a pedirlo.
            ->where('t.status', '!=', KitchenTicket::CANCELLED)
            ->pluck('pos_kitchen_ticket_lines.sale_line_id')
            ->filter()
            ->all();

        return $sale->lines()
            ->with(['product', 'modifiers'])
            ->whereNotIn('id', $sent)
            ->get()
            // Lo que no se prepara no va a comanda: una gaseosa de heladera la
            // sirve el mesero sin molestar a nadie.
            ->filter(fn (SaleLine $line) => $line->product?->prep_station !== null)
            ->values();
    }

    /**
     * Manda a preparar lo pendiente, una comanda por destino.
     *
     * @param  array<string,mixed>  $context
     * @return Collection<int,KitchenTicket>
     */
    public function send(Sale $sale, array $context = []): Collection
    {
        if ($sale->status === Sale::STATUS_COMPLETED || $sale->status === Sale::STATUS_VOIDED) {
            throw ValidationException::withMessages(['sale' => __('dining.sale_closed')]);
        }

        $pending = $this->pending($sale);

        if ($pending->isEmpty()) {
            throw ValidationException::withMessages(['sale' => __('dining.nothing_to_send')]);
        }

        $this->assertModifiersAnswered($pending);

        // El primer curso de este envío sale ya; los de más atrás esperan a que
        // alguien los marche. La mesa canta entradas, fuertes y postres
        // seguidos, y lo que cambia no es cuándo se pide sino cuándo sale de la
        // cocina.
        $firstCourse = (int) $pending->min('course');

        return DB::transaction(function () use ($sale, $pending, $context, $firstCourse) {
            return $pending
                ->groupBy(fn (SaleLine $line) => $line->product->prep_station.'|'.$line->course)
                ->map(function (Collection $lines) use ($sale, $context, $firstCourse) {
                    $course = (int) $lines->first()->course;

                    return $this->createTicket(
                        $sale,
                        (string) $lines->first()->product->prep_station,
                        $lines,
                        $context,
                        $course,
                        $course === $firstCourse
                    );
                })
                ->sortBy([['course', 'asc'], ['destination', 'asc']])
                ->values();
        });
    }

    /**
     * Marcha un curso: lo retenido pasa al pase (G-16).
     *
     * Va por curso y no por comanda porque la cocina y la barra reciben papeles
     * distintos del mismo momento del servicio: marchar "los fuertes" tiene que
     * soltar los dos, o el plato sale sin la bebida que lo acompaña.
     *
     * @return Collection<int,KitchenTicket>
     */
    public function fire(Sale $sale, ?int $course = null): Collection
    {
        $retained = fn () => KitchenTicket::where('sale_id', $sale->id)
            ->where('status', KitchenTicket::HELD);

        // Sin curso indicado se marcha el que sigue, no todo lo retenido: el
        // botón del salón dice "marchar lo que sigue" y eso es un curso.
        $target = $course ?? (int) $retained()->min('course');

        $held = $retained()->where('course', $target)->get();

        if ($held->isEmpty()) {
            throw ValidationException::withMessages(['course' => __('dining.nothing_to_fire')]);
        }

        return DB::transaction(function () use ($held) {
            return $held->map(function (KitchenTicket $ticket) {
                // El reloj de la cocina arranca acá, no cuando el mesero tomó la
                // orden: cobrarle la espera de la mesa haría que toda comanda
                // retenida naciera atrasada.
                $ticket->forceFill([
                    'status' => KitchenTicket::QUEUED,
                    'fired_at' => now(),
                ])->save();

                return $ticket->fresh('lines');
            })->values();
        });
    }

    /** @param Collection<int,SaleLine> $lines */
    private function createTicket(
        Sale $sale,
        string $destination,
        Collection $lines,
        array $context,
        int $course = 1,
        bool $fired = true
    ): KitchenTicket {
        $ticket = KitchenTicket::create([
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'terminal_id' => $context['terminal_id'] ?? $sale->terminal_id,
            'employee_id' => $context['employee_id'] ?? $sale->waiter_employee_id ?? $sale->employee_id,
            'destination' => $destination,
            'course' => $course,
            'number' => $this->nextNumber($sale->branch_id, $destination),
            'status' => $fired ? KitchenTicket::QUEUED : KitchenTicket::HELD,
            'notes' => $context['notes'] ?? null,
            'sent_at' => now(),
            // Retenida no tiene hora de marcha todavía: es justamente lo que le
            // falta para empezar a contar.
            'fired_at' => $fired ? now() : null,
        ]);

        foreach ($lines as $line) {
            KitchenTicketLine::create([
                'ticket_id' => $ticket->id,
                'sale_line_id' => $line->id,
                // Copia: cocina no consulta el catálogo, y la comanda tiene que
                // poder leerse aunque la línea se anule después.
                'name' => $line->description,
                'qty' => (string) $line->qty,
                'modifiers' => $line->modifiers->map(fn ($modifier) => $modifier->name)->values()->all(),
                'notes' => $line->notes,
            ]);
        }

        return $ticket->fresh('lines');
    }

    /**
     * Correlativo por sucursal y destino.
     *
     * Es el número que grita el pase: "¡la 14!". Tiene que ser corto y propio de
     * cada destino, porque cocina y barra numeran por su cuenta.
     */
    private function nextNumber(string $branchId, string $destination): int
    {
        // Se bloquea **la última fila**, no el agregado: PostgreSQL no admite
        // `FOR UPDATE` sobre `max()`. Dos envíos simultáneos al mismo destino
        // hacen cola acá; el índice único de `(sucursal, destino, número)` es la
        // red de seguridad del caso en que todavía no exista ninguna fila que
        // bloquear.
        $last = KitchenTicket::where('branch_id', $branchId)
            ->where('destination', $destination)
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        return (int) $last + 1;
    }

    /** @param Collection<int,SaleLine> $lines */
    private function assertModifiersAnswered(Collection $lines): void
    {
        foreach ($lines as $line) {
            $missing = $this->modifiers->missingGroups($line);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'modifiers' => __('dining.modifier_blocks_send', [
                        'item' => $line->description,
                        'groups' => implode(', ', $missing),
                    ]),
                ]);
            }
        }
    }

    /**
     * Avanza la comanda.
     *
     * El orden es el del pase —en cola, preparando, lista, servida— y no se
     * salta hacia atrás: una comanda que vuelve de "lista" a "en cola" es un
     * plato que ya salió y nadie sabe dónde está.
     */
    public function advance(KitchenTicket $ticket, string $status): KitchenTicket
    {
        $allowed = [
            // Retenida solo se cancela desde acá: pasarla al pase es marchar, y
            // eso tiene su propio momento registrado (`fired_at`).
            KitchenTicket::HELD => [KitchenTicket::CANCELLED],
            KitchenTicket::QUEUED => [KitchenTicket::PREPARING, KitchenTicket::CANCELLED],
            KitchenTicket::PREPARING => [KitchenTicket::READY, KitchenTicket::CANCELLED],
            KitchenTicket::READY => [KitchenTicket::SERVED],
            KitchenTicket::SERVED => [],
            KitchenTicket::CANCELLED => [],
        ];

        if (! in_array($status, $allowed[$ticket->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => __('dining.invalid_transition', [
                    'from' => $ticket->status,
                    'to' => $status,
                ]),
            ]);
        }

        $stamps = [
            KitchenTicket::PREPARING => 'started_at',
            KitchenTicket::READY => 'ready_at',
            KitchenTicket::SERVED => 'served_at',
        ];

        $ticket->forceFill(array_filter([
            'status' => $status,
            $stamps[$status] ?? 'updated_at' => now(),
        ]))->save();

        return $ticket->fresh('lines');
    }
}
