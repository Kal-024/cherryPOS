<?php

namespace App\Services\Dining;

use App\Models\DiningArea;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Sale;
use App\Services\Sales\CartService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El salón (F1-B, §10 del plan).
 *
 * **El estado de una mesa no se guarda: se deduce.** Ocupada es "tiene una venta
 * suspendida"; libre es "no la tiene". Guardarlo en una columna crearía dos
 * verdades del mismo hecho, y el día que una cuenta se cobre desde otra caja el
 * mapa mostraría ocupada una mesa vacía — con un mesero parado al lado
 * discutiendo con el sistema.
 *
 * **Las mesas unidas son una sola unidad** (§10): la unida deja de aceptar
 * cuenta propia y su cuenta, si tenía una, pasa a la principal. Cobrar dos veces
 * una mesa que el cliente ve como una es el error que un restaurante no
 * perdona.
 */
class DiningRoomService
{
    public function __construct(private CartService $cart) {}

    /**
     * El mapa completo con el estado de cada mesa.
     *
     * Viaja entero en una consulta porque el salón se refresca por sondeo cada
     * pocos segundos (G-13, R-01) y una consulta por mesa multiplicaría eso por
     * treinta.
     *
     * @return array<string,mixed>
     */
    public function map(string $branchId): array
    {
        $areas = DiningArea::where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $tables = DiningTable::with([
            'openSale.employee.person',
            'openSale.waiter.person',
            // El estado del pedido viaja con el mapa (G-16): el mesero mira la
            // pantalla del salón, no el pase de la cocina, y "hay comida lista
            // en la 7" es lo que lo hace caminar.
            'openSale.kitchenTickets',
        ])
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return [
            'areas' => $areas->map(fn (DiningArea $area) => [
                'id' => $area->id,
                'code' => $area->code,
                'name' => $area->name,
            ])->values(),
            'tables' => $tables->map(fn (DiningTable $table) => $this->presentTable($table))->values(),
        ];
    }

    /** @return array<string,mixed> */
    public function presentTable(DiningTable $table): array
    {
        $sale = $table->openSale;

        return [
            'id' => $table->id,
            'area_id' => $table->area_id,
            'code' => $table->code,
            'name' => $table->name,
            'seats' => $table->seats,
            'pos_x' => $table->pos_x,
            'pos_y' => $table->pos_y,
            'shape' => $table->shape,
            // El tamaño viaja con el mapa: un local real no tiene todas las mesas
            // iguales, y sin esto el plano se parece al salón por casualidad.
            'width' => $table->width,
            'height' => $table->height,
            'merged_into_id' => $table->merged_into_id,
            // Libre, ocupada o absorbida por otra mesa. Se deduce, no se guarda.
            'state' => $table->isMerged() ? 'merged' : ($sale ? 'occupied' : 'free'),
            'sale' => $sale ? [
                'id' => $sale->id,
                'label' => $sale->label,
                'total' => (string) $sale->total,
                'guests' => $sale->guests,
                // La nota viaja con el mapa: sin ella la mesa no puede marcar
                // que hay algo escrito, y una nota que nadie ve no sirve de nada.
                'notes' => $sale->notes,
                // Cuenta pedida: la mesa pasó de "están comiendo" a "están por
                // irse". El mapa muestra cuánto lleva esperando pagar, que es lo
                // que hace accionable el dato.
                'bill_requested_at' => $sale->bill_requested_at,
                // El tiempo transcurrido es la mitad del mapa: una mesa de hace
                // dos horas necesita atención distinta que una de cinco minutos.
                'opened_at' => $sale->opened_at,
                'waiter' => $sale->waiter?->full_name ?? $sale->employee?->full_name,
                'kitchen' => $this->kitchenState($sale),
            ] : null,
        ];
    }

    /**
     * En qué anda el pedido de esta mesa (G-16).
     *
     * El orden de prioridad es el del mesero, no el de la cocina: **lo listo
     * manda**, porque es lo único que exige caminar ahora mismo. Después lo que
     * se está cocinando, y al final lo retenido, que es un recordatorio de que
     * queda un curso por marchar.
     *
     * @return array<string,mixed>|null
     */
    private function kitchenState(Sale $sale): ?array
    {
        $tickets = $sale->kitchenTickets
            ->whereNotIn('status', [KitchenTicket::SERVED, KitchenTicket::CANCELLED]);

        if ($tickets->isEmpty()) {
            return null;
        }

        $state = match (true) {
            $tickets->contains('status', KitchenTicket::READY) => 'ready',
            $tickets->contains(fn (KitchenTicket $t) => in_array(
                $t->status,
                [KitchenTicket::QUEUED, KitchenTicket::PREPARING],
                true
            )) => 'cooking',
            default => 'held',
        };

        return [
            'state' => $state,
            // Los cursos que todavía esperan a que alguien los marche. Es lo que
            // el botón del salón necesita saber para ofrecerse o callarse.
            'held_courses' => $tickets->where('status', KitchenTicket::HELD)
                ->pluck('course')->unique()->sort()->values()->all(),
        ];
    }

    /**
     * Abre la cuenta de una mesa.
     *
     * La cuenta **es** una venta suspendida (D-01): no hay tabla espejo. Nace
     * suspendida aunque esté vacía porque sentarse y pedir cinco minutos después
     * es lo normal, y la mesa tiene que verse ocupada desde que el grupo se
     * sienta.
     *
     * @param  array<string,mixed>  $context
     */
    public function openTable(DiningTable $table, array $context): Sale
    {
        if ($table->isMerged()) {
            throw ValidationException::withMessages(['table' => __('dining.table_merged')]);
        }

        if ($table->openSale) {
            throw ValidationException::withMessages(['table' => __('dining.table_busy')]);
        }

        return DB::transaction(function () use ($table, $context) {
            $sale = $this->cart->open([
                'branch_id' => $table->branch_id,
                'terminal_id' => $context['terminal_id'],
                'employee_id' => $context['employee_id'],
                'sale_type' => 'counter',
            ]);

            $sale->forceFill([
                'dining_table_id' => $table->id,
                'guests' => $context['guests'] ?? null,
                'waiter_employee_id' => $context['waiter_employee_id'] ?? $context['employee_id'],
                'status' => Sale::STATUS_SUSPENDED,
                'label' => $context['label'] ?? $this->defaultLabel($table),
            ])->save();

            return $sale->fresh();
        });
    }

    /**
     * Anota algo sobre la cuenta abierta (F1-B, §10).
     *
     * Es la libreta del mesero: «cumpleaños», «apurados», «paga el de la camisa
     * azul». No es la nota de la línea —esa va a la comanda y es de la cocina—
     * ni una nota de la mesa: se escribe sobre la **cuenta**, y por eso se va con
     * ella al cobrar, que es toda la vida útil que tiene.
     *
     * Sin cuenta abierta no hay dónde anotar. Guardar la nota en la mesa para
     * cubrir ese caso la haría sobrevivir al cliente, y entonces alguien tendría
     * que acordarse de borrarla.
     */
    public function setNote(DiningTable $table, ?string $note): Sale
    {
        $sale = $table->openSale;

        if (! $sale) {
            throw ValidationException::withMessages(['table' => __('dining.note_needs_tab')]);
        }

        // Una nota en blanco es ninguna nota: así el mesero la borra sin un
        // segundo botón que preguntar si está seguro.
        $note = trim((string) $note);

        $sale->forceFill(['notes' => $note === '' ? null : $note])->save();

        return $sale->fresh();
    }

    /**
     * Une mesas: una manda y las demás quedan absorbidas.
     *
     * Si alguna de las absorbidas ya tenía cuenta, sus líneas **no** se mueven
     * solas: hacerlo en silencio cambiaría el importe de dos cuentas a la vez.
     * Se exige que estén libres, y quien ya sirvió en las dos cobra una y
     * traspasa a mano.
     *
     * **Un grupo que se une a otro se funde en uno solo.** Arrastrar la principal
     * de un grupo sobre una tercera mesa dejaba a sus unidas apuntando a una mesa
     * que a su vez estaba unida —la cadena 4 → 3 → 2—, y todo el salón mira **un
     * solo salto**: la 4 quedaba fuera del grupo, sin color de unida y sin forma
     * de soltarse. Las unidas pasan a la nueva principal, que es lo que el mesero
     * ve cuando empuja dos mesas contra una tercera: una sola unidad de tres.
     *
     * @param  array<int,string>  $tableIds
     */
    public function merge(DiningTable $main, array $tableIds): DiningTable
    {
        if ($main->isMerged()) {
            throw ValidationException::withMessages(['table' => __('dining.table_merged')]);
        }

        $others = DiningTable::with('openSale')
            ->where('branch_id', $main->branch_id)
            ->whereIn('id', $tableIds)
            ->where('id', '!=', $main->id)
            ->get();

        if ($others->isEmpty()) {
            throw ValidationException::withMessages(['tables' => __('dining.nothing_to_merge')]);
        }

        foreach ($others as $other) {
            if ($other->openSale) {
                throw ValidationException::withMessages([
                    'tables' => __('dining.merge_busy', ['code' => $other->code]),
                ]);
            }
        }

        DB::transaction(function () use ($main, $others) {
            foreach ($others as $other) {
                // Las que ya dependían de la absorbida pasan a la nueva
                // principal: sin esto queda una cadena y `merged_into_id` deja de
                // significar "la mesa que manda".
                DiningTable::where('merged_into_id', $other->id)
                    ->update(['merged_into_id' => $main->id, 'updated_at' => now()]);

                $other->forceFill(['merged_into_id' => $main->id])->save();
            }
        });

        return $main->fresh();
    }

    /**
     * Suelta mesas del grupo. Las sueltas vuelven a aceptar cuenta propia.
     *
     * **Qué se suelta depende de cuál mesa llega.** Si es una unida, se suelta
     * solo ella y el resto del grupo sigue junto: con tres mesas empujadas contra
     * una, devolver una sola a su sitio obligaba a separar las cuatro y rearmar
     * a mano lo que nadie quiso deshacer. Si es la principal, se disuelve el
     * grupo entero, que es lo que hacía siempre.
     */
    public function split(DiningTable $table): DiningTable
    {
        if ($table->isMerged()) {
            $main = $table->mergedInto;

            $table->forceFill(['merged_into_id' => null])->save();

            // Se devuelve la principal y no la suelta: quien llamó espera saber
            // cómo quedó el grupo, que es lo que el mapa vuelve a dibujar.
            return ($main ?? $table)->fresh();
        }

        DiningTable::where('merged_into_id', $table->id)
            ->update(['merged_into_id' => null, 'updated_at' => now()]);

        return $table->fresh();
    }

    private function defaultLabel(DiningTable $table): string
    {
        return $table->name ?: __('dining.table_label', ['code' => $table->code]);
    }
}
