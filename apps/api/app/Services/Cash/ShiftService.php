<?php

namespace App\Services\Cash;

use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\Denomination;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Terminal;
use App\Services\Audit\AuditLogger;
use App\Services\Calc\Decimal;
use App\Services\Catalog\AvailabilityService;
use App\Services\Sales\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turno de caja (G-04, H4.1–H4.3).
 *
 * **El turno es del equipo, no de la persona.** El cajón de dinero es físico y
 * el arqueo cuenta ese cajón; dentro del turno cada venta queda atribuida a su
 * cajero y el corte se desglosa por persona. Un turno por cajero obligaría a
 * contar el cajón en cada relevo, que es justo lo que la doble credencial de
 * D-05 quiso evitar.
 *
 * El arqueo de OSPOS no estaba atado a un turno, y por eso no podía responder
 * *"¿qué vendió la caja 2 entre las 14:00 y las 22:00 del martes?"*. Un arqueo
 * sin turno es la mitad del control.
 */
class ShiftService
{
    public function __construct(
        private ExchangeRateService $rates,
        private AuditLogger $audit,
        private AvailabilityService $availability,
    ) {}

    public function current(Terminal $terminal): ?Shift
    {
        return Shift::where('terminal_id', $terminal->id)
            ->where('status', 'open')
            ->first();
    }

    /**
     * Abre el turno con su fondo inicial.
     *
     * La tasa del día se congela aquí: un turno que abrió a 36,62 cobra todo el
     * día a 36,62, aunque el supervisor cargue otra a media tarde. Releer el
     * turno mañana con la tasa de mañana daría otro número.
     *
     * @param  array<int,array{denomination_value:string,count:int,currency_code?:string}>  $opening
     */
    public function open(Terminal $terminal, Employee $employee, string $float, array $opening = []): Shift
    {
        if ($this->current($terminal)) {
            throw ValidationException::withMessages([
                'terminal' => __('shift.already_open'),
            ]);
        }

        return DB::transaction(function () use ($terminal, $employee, $float, $opening) {
            $shift = Shift::create([
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                'opened_by' => $employee->id,
                'code' => $this->nextCode($terminal),
                'status' => 'open',
                'opening_float' => $float,
                'exchange_rate' => $this->rates->current(config('pos.secondary_currency')),
                'opened_at' => now(),
            ]);

            $this->storeCount($shift, 'opening', $opening);

            // La lista 86 se vence al empezar el servicio (F1-B): lo que se acabó
            // ayer vuelve a estar hoy sin que nadie tenga que acordarse. Solo
            // cuando este es el primer turno abierto del local — con tres cajas,
            // la segunda en abrir reviviría a media mañana lo que la cocina marcó
            // temprano.
            $this->availability->restoreForNewService($shift);

            $this->audit->record(
                event: 'shift.opened',
                entityType: 'shift',
                entityId: $shift->id,
                context: ['code' => $shift->code, 'opening_float' => $float],
                branchId: $shift->branch_id,
            );

            return $shift;
        });
    }

    /**
     * Cierra el turno comparando lo contado contra lo esperado (H4.3).
     *
     * La diferencia se reporta **por moneda**: en Nicaragua la caja tiene
     * córdobas y dólares, y un faltante hay que poder atribuirlo a uno de los
     * dos. Un total consolidado escondería que sobran córdobas y faltan dólares.
     *
     * @param  array<int,array{denomination_value:string,count:int,currency_code?:string}>  $counted
     */
    public function close(Shift $shift, Employee $employee, array $counted): Shift
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('shift.already_closed')]);
        }

        return DB::transaction(function () use ($shift, $employee, $counted) {
            $this->storeCount($shift, 'closing', $counted);

            $shift->forceFill([
                'status' => 'closed',
                'closed_by' => $employee->id,
                'closed_at' => now(),
            ])->save();

            $summary = $this->summary($shift->fresh());

            $this->audit->record(
                event: 'shift.closed',
                entityType: 'shift',
                entityId: $shift->id,
                context: ['code' => $shift->code, 'differences' => $summary['by_currency']],
                branchId: $shift->branch_id,
            );

            return $shift->fresh();
        });
    }

    /**
     * Cuadra un turno ya cerrado, sin reescribir el arqueo (P1).
     *
     * Al cerrar aparece el faltante o el sobrante y hasta acá no había nada que
     * hacer con él: el turno quedaba descuadrado para siempre. No es un detalle
     * de orden — el ERP no emite el comprobante contable del día si la caja no
     * cuadra, y el objetivo del negocio es cerrar siempre cuadrado.
     *
     * **El conteo no se toca.** La diferencia se salda con un movimiento de caja
     * por moneda —salida si faltó, entrada si sobró—, con su motivo y su
     * autorización. Como lo esperado en el cajón ya suma los movimientos, la
     * diferencia queda en cero por aritmética y no por decreto, y el conteo
     * original sigue diciendo qué se contó de verdad.
     *
     * El monto no se teclea: es exactamente la diferencia que el propio corte
     * calculó. Dejarlo a mano abriría la puerta a "cuadrar" con un número
     * redondo que no corresponde a nada.
     *
     * @return array<string,mixed> el corte ya cuadrado
     */
    public function settle(Shift $shift, Employee $employee, string $reason, ?Employee $authorizedBy = null): array
    {
        if ($shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('shift.not_closed')]);
        }

        $summary = $this->summary($shift);
        $pending = array_filter(
            $summary['by_currency'],
            static fn (array $row) => bccomp($row['difference'], '0', 2) !== 0
        );

        if ($pending === []) {
            throw ValidationException::withMessages(['shift' => __('shift.already_settled')]);
        }

        return DB::transaction(function () use ($shift, $employee, $reason, $authorizedBy, $pending) {
            foreach ($pending as $row) {
                $difference = $row['difference'];
                $falta = bccomp($difference, '0', 2) === -1;

                CashMovement::create([
                    'shift_id' => $shift->id,
                    'branch_id' => $shift->branch_id,
                    'employee_id' => $employee->id,
                    'authorized_by' => $authorizedBy?->id,
                    // Faltó: sale del cajón lo que no estaba. Sobró: entra.
                    'direction' => $falta ? 'out' : 'in',
                    'reason' => $reason,
                    'is_settlement' => true,
                    'amount' => ltrim($difference, '-'),
                    'currency_code' => $row['currency_code'],
                    'exchange_rate' => $shift->exchange_rate,
                    'amount_base' => ltrim($difference, '-'),
                    'occurred_at' => now(),
                    'recorded_at' => now(),
                ]);
            }

            $this->audit->record(
                event: 'shift.settled',
                entityType: 'shift',
                entityId: $shift->id,
                context: [
                    'code' => $shift->code,
                    'reason' => $reason,
                    'settled' => array_values($pending),
                ],
                authorizedBy: $authorizedBy?->id,
                branchId: $shift->branch_id,
            );

            return $this->summary($shift->fresh());
        });
    }

    /**
     * Corte del turno: lo esperado, lo contado y la diferencia, por moneda; más
     * el desglose por cajero.
     *
     * @return array<string,mixed>
     */
    public function summary(Shift $shift): array
    {
        $base = config('pos.base_currency');
        $currencies = $this->currenciesOf($shift);

        $byCurrency = [];

        foreach ($currencies as $currency) {
            $expected = $this->expected($shift, $currency, $base);
            $counted = $this->counted($shift, $currency);

            $byCurrency[$currency] = [
                'currency_code' => $currency,
                'expected' => $expected,
                'counted' => $counted,
                // Positiva sobra, negativa falta. El signo importa: sobrar no es
                // lo mismo que faltar, aunque las dos descuadren.
                'difference' => bcsub($counted, $expected, 2),
            ];
        }

        return [
            // La tasa va en el corte porque la caja la muestra al cobrar en
            // dólares: el cajero la canta cuando el cliente pregunta "¿a cuánto
            // me lo tomás?" (Q-06).
            'shift' => $shift->only([
                'id', 'code', 'status', 'opening_float', 'exchange_rate', 'opened_at', 'closed_at',
            ]),
            'by_currency' => array_values($byCurrency),
            'by_cashier' => $this->byCashier($shift),
            'sales' => $this->salesTotals($shift),
            // La propina cobrada en efectivo ya está contada dentro de lo
            // esperado en el cajón —es dinero que entró—, así que el arqueo
            // cuadra sin tocarla. Va aparte porque al cerrar hay que **sacarla**
            // y entregarla, y sin este renglón el supervisor tendría que ir
            // ticket por ticket para saber cuánto (G-16).
            'tips' => $this->tipTotals($shift),
            'denominations' => $this->denominationBreakdown($shift),
            // Cuadrado es que no quede diferencia en ninguna moneda. Se deduce
            // de los números, no se guarda: un turno "marcado como cuadrado"
            // con la diferencia viva sería exactamente lo que hay que evitar.
            'settled' => array_reduce(
                $byCurrency,
                static fn (bool $carry, array $row) => $carry && bccomp($row['difference'], '0', 2) === 0,
                true
            ),
            'settlements' => CashMovement::where('shift_id', $shift->id)
                ->where('is_settlement', true)
                ->orderBy('occurred_at')
                ->get(['id', 'direction', 'reason', 'amount', 'currency_code', 'authorized_by', 'occurred_at']),
        ];
    }

    /**
     * Efectivo que debería haber en el cajón, en una moneda.
     *
     * Fondo inicial —solo en moneda base— más lo cobrado en efectivo, menos los
     * vueltos, más y menos los movimientos de caja. Nada de esto sale de un
     * campo acumulado: se suma cada vez, por la misma razón que el stock.
     */
    public function expected(Shift $shift, string $currency, string $baseCurrency): string
    {
        $total = $currency === $baseCurrency ? (string) $shift->opening_float : '0.00';

        // Los pagos en efectivo suman; el vuelto viene con signo negativo, así
        // que la misma suma lo descuenta.
        $payments = DB::table('pos_payments')
            ->where('shift_id', $shift->id)
            ->where('method', 'cash')
            ->where('currency_code', $currency)
            ->sum('amount');

        $total = bcadd($total, (string) ($payments ?: '0'), 2);

        $movements = DB::table('pos_cash_movements')
            ->where('shift_id', $shift->id)
            ->where('currency_code', $currency)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net")
            ->value('net');

        return bcadd($total, (string) ($movements ?: '0'), 2);
    }

    public function counted(Shift $shift, string $currency): string
    {
        $total = CashCount::where('shift_id', $shift->id)
            ->where('moment', 'closing')
            ->where('currency_code', $currency)
            ->sum('subtotal');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Ventas y cobros por cajero.
     *
     * Es la mitad que hace útil el turno del equipo: el cajón se cuenta una vez,
     * pero se sabe quién vendió qué.
     *
     * @return array<int,array<string,mixed>>
     */
    private function byCashier(Shift $shift): array
    {
        return DB::table('pos_sales')
            ->join('sec_employees as e', 'e.id', '=', 'pos_sales.employee_id')
            ->join('cmn_persons as p', 'p.id', '=', 'e.person_id')
            ->where('pos_sales.shift_id', $shift->id)
            ->where('pos_sales.status', 'completed')
            ->groupBy('e.id', 'e.code', 'p.full_name')
            ->selectRaw('e.code, p.full_name, COUNT(*) AS sales, COALESCE(SUM(pos_sales.total), 0) AS total')
            ->orderBy('e.code')
            ->get()
            ->map(fn ($row) => [
                'employee_code' => $row->code,
                'employee_name' => $row->full_name,
                'sales' => (int) $row->sales,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])->all();
    }

    /** @return array<string,mixed> */
    private function salesTotals(Shift $shift): array
    {
        $row = DB::table('pos_sales')
            ->where('shift_id', $shift->id)
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(tax_total), 0) AS tax')
            ->first();

        $byMethod = DB::table('pos_payments')
            ->where('shift_id', $shift->id)
            ->where('is_change', false)
            ->groupBy('method')
            ->selectRaw('method, COALESCE(SUM(amount_base), 0) AS total')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->method => number_format((float) $r->total, 2, '.', '')])
            ->all();

        return [
            'count' => (int) ($row->count ?? 0),
            'total' => number_format((float) ($row->total ?? 0), 2, '.', ''),
            'tax_total' => number_format((float) ($row->tax ?? 0), 2, '.', ''),
            'by_method' => $byMethod,
        ];
    }

    /**
     * Propinas del turno, en total y por quien se las lleva (G-16).
     *
     * Se agrupa por `tip_employee_id` y se cae al mesero de la venta cuando está
     * vacío: en un local sin bote común la propina es de quien atendió, y
     * obligar a elegirlo en cada cuenta sería teclear lo que ya se sabe.
     *
     * @return array<string,mixed>
     */
    private function tipTotals(Shift $shift): array
    {
        $rows = DB::table('pos_sales')
            ->leftJoin('sec_employees', 'sec_employees.id', '=', DB::raw('COALESCE(pos_sales.tip_employee_id, pos_sales.waiter_employee_id)'))
            // El nombre es de la persona, no del empleado (B-11).
            ->leftJoin('cmn_persons', 'cmn_persons.id', '=', 'sec_employees.person_id')
            ->where('pos_sales.shift_id', $shift->id)
            ->where('pos_sales.status', 'completed')
            ->where('pos_sales.tip_amount', '<>', 0)
            ->groupBy('sec_employees.id', 'cmn_persons.full_name')
            ->selectRaw('sec_employees.id AS employee_id, cmn_persons.full_name AS employee_name, SUM(pos_sales.tip_amount) AS total')
            ->get();

        return [
            'total' => number_format((float) $rows->sum(fn ($r) => (float) $r->total), 2, '.', ''),
            'by_employee' => $rows->map(fn ($r) => [
                'employee_id' => $r->employee_id,
                'employee_name' => $r->employee_name,
                'total' => number_format((float) $r->total, 2, '.', ''),
            ])->values()->all(),
        ];
    }

    /**
     * El desglose que convierte "falta plata" en "faltan tres billetes de 50".
     *
     * @return array<int,array<string,mixed>>
     */
    private function denominationBreakdown(Shift $shift): array
    {
        return CashCount::where('shift_id', $shift->id)
            ->where('moment', 'closing')
            ->orderBy('currency_code')
            ->orderByDesc('denomination_value')
            ->get()
            ->map(fn (CashCount $c) => [
                'currency_code' => $c->currency_code,
                'denomination' => (string) $c->denomination_value,
                'count' => $c->count,
                'subtotal' => (string) $c->subtotal,
            ])->all();
    }

    /** @return array<int,string> */
    private function currenciesOf(Shift $shift): array
    {
        $base = config('pos.base_currency');

        $used = DB::table('pos_payments')->where('shift_id', $shift->id)
            ->distinct()->pluck('currency_code')
            ->merge(DB::table('pos_cash_movements')->where('shift_id', $shift->id)
                ->distinct()->pluck('currency_code'))
            ->merge(CashCount::where('shift_id', $shift->id)
                ->distinct()->pluck('currency_code'));

        return $used->push($base)->unique()->values()->all();
    }

    /**
     * Guarda el conteo físico, una fila por denominación.
     *
     * @param  array<int,array{denomination_value:string,count:int,currency_code?:string}>  $counts
     */
    private function storeCount(Shift $shift, string $moment, array $counts): void
    {
        $base = config('pos.base_currency');

        foreach ($counts as $entry) {
            $currency = $entry['currency_code'] ?? $base;
            $value = (string) $entry['denomination_value'];
            $count = (int) $entry['count'];

            if ($count <= 0) {
                continue;
            }

            $denomination = Denomination::where('currency_code', $currency)
                ->where('value', $value)
                ->first();

            CashCount::create([
                'shift_id' => $shift->id,
                'moment' => $moment,
                'currency_code' => $currency,
                'denomination_id' => $denomination?->id,
                'denomination_value' => $value,
                'count' => $count,
                'subtotal' => Decimal::format(
                    Decimal::mulShift(
                        Decimal::parse($value, Decimal::MONEY),
                        Decimal::parse((string) $count, 0),
                        0
                    ),
                    Decimal::MONEY
                ),
            ]);
        }
    }

    /** `CAJA-01-20260916-1`: legible por una persona que busca en una lista. */
    private function nextCode(Terminal $terminal): string
    {
        $today = now()->format('Ymd');

        $count = Shift::where('terminal_id', $terminal->id)
            ->whereDate('opened_at', now()->toDateString())
            ->count();

        return "{$terminal->code}-{$today}-".($count + 1);
    }
}
