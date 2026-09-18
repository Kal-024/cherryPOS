<?php

namespace App\Services\Credit;

use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerAuthorized;
use App\Models\Person;
use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use App\Services\Cash\CashMovementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crédito de clientes, en la versión reducida del POS (P-02, G-10, H8).
 *
 * *"Lo básico para tener control e información sobre las cuentas de clientes que
 * deciden esta forma de pago."* Intereses, planes de pago y cobranza son del
 * módulo de crédito del ERP; si el cliente solo tiene el POS, esto le alcanza
 * para operar.
 *
 * Tres reglas que vienen de la decisión y no se negocian:
 *
 *  - **Hasta tres autorizados por cuenta**, todos con datos completos.
 *  - **El límite bloquea la venta**, no solo advierte.
 *  - **El bloqueo por mora es manual del supervisor**, y se suma al límite: una
 *    cuenta puede estar dentro del límite y aun así bloqueada.
 */
class CreditService
{
    /** G-10, literal: "hasta 3 personas autorizadas por cuenta". */
    public const MAX_AUTHORIZED = 3;

    public function __construct(
        private CashMovementService $cash,
        private AuditLogger $audit,
    ) {}

    /**
     * Abre la cuenta de un cliente.
     *
     * Solo para clientes de tipo `account`: el de efectivo paga y se va, y
     * abrirle una cuenta sería inventarle un vínculo que no pidió (P-03).
     */
    public function openAccount(
        Customer $customer,
        string $limit,
        int $cutOffDay = 30,
        ?string $branchId = null,
    ): CreditAccount {
        if (! $customer->hasAccount()) {
            throw ValidationException::withMessages([
                'customer_id' => __('credit.customer_is_cash'),
            ]);
        }

        if (blank($customer->national_id)) {
            // La cuenta es personal: sin cédula no se sabe de quién es (G-10).
            throw ValidationException::withMessages([
                'national_id' => __('credit.account_needs_national_id'),
            ]);
        }

        $account = CreditAccount::updateOrCreate(
            ['customer_id' => $customer->id],
            ['credit_limit' => $limit, 'cut_off_day' => $cutOffDay]
        );

        $this->audit->record(
            event: 'credit.account_opened',
            entityType: 'credit_account',
            entityId: $account->id,
            context: ['customer' => $customer->name, 'limit' => $limit, 'cut_off_day' => $cutOffDay],
            branchId: $branchId,
        );

        return $account;
    }

    /**
     * Agrega un autorizado a consumir contra la cuenta.
     *
     * Con **datos completos**, porque el autorizado es quien firma el consumo y
     * el día que haya que reclamarlo hace falta saber quién es. Es una persona
     * del sistema (B-11): si mañana abre su propia cuenta, ya está identificada.
     *
     * @param  array<string,mixed>  $person
     */
    public function addAuthorized(
        CreditAccount $account,
        array $person,
        ?string $relationship = null,
        ?string $branchId = null,
    ): CustomerAuthorized {
        $active = CustomerAuthorized::where('customer_id', $account->customer_id)
            ->where('is_active', true)
            ->count();

        if ($active >= self::MAX_AUTHORIZED) {
            throw ValidationException::withMessages([
                'authorized' => __('credit.too_many_authorized', ['max' => self::MAX_AUTHORIZED]),
            ]);
        }

        if (blank($person['national_id'] ?? null)) {
            throw ValidationException::withMessages([
                'national_id' => __('credit.authorized_needs_national_id'),
            ]);
        }

        return DB::transaction(function () use ($account, $person, $relationship, $branchId) {
            $identity = Person::firstOrCreate(['national_id' => $person['national_id']], $person);
            $identity->fillMissing($person);

            $authorized = CustomerAuthorized::updateOrCreate(
                ['customer_id' => $account->customer_id, 'person_id' => $identity->id],
                ['relationship' => $relationship, 'is_active' => true]
            );

            $this->audit->record(
                event: 'credit.authorized_added',
                entityType: 'credit_account',
                entityId: $account->id,
                context: ['name' => $identity->full_name, 'national_id' => $identity->national_id],
                branchId: $branchId,
            );

            return $authorized;
        });
    }

    public function removeAuthorized(CustomerAuthorized $authorized, ?string $branchId = null): void
    {
        // Baja lógica: los consumos viejos apuntan a esta fila y borrarla
        // dejaría el histórico sin decir quién se llevó la mercadería.
        $authorized->update(['is_active' => false]);

        $this->audit->record(
            event: 'credit.authorized_removed',
            entityType: 'customer_authorized',
            entityId: $authorized->id,
            context: ['name' => $authorized->name],
            branchId: $branchId,
        );
    }

    /**
     * Abono en caja, **total o parcial** (H8.4).
     *
     * El dinero entra al cajón, así que se asienta también como movimiento de
     * caja: el arqueo tiene que poder explicar esa plata que apareció y que no
     * viene de una venta.
     */
    public function pay(
        CreditAccount $account,
        string $amount,
        string $branchId,
        string $employeeId,
        ?Shift $shift = null,
        string $method = 'cash',
        ?string $comment = null,
    ): CreditEntry {
        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => __('credit.amount_must_be_positive')]);
        }

        // Cobrar de más no es un favor: deja la cuenta en saldo a favor y a
        // nadie le queda claro por qué.
        if (bccomp($amount, (string) $account->balance, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('credit.amount_exceeds_balance', ['balance' => (string) $account->balance]),
            ]);
        }

        return DB::transaction(function () use ($account, $amount, $branchId, $employeeId, $shift, $method, $comment) {
            if ($method === 'cash') {
                if (! $shift) {
                    throw ValidationException::withMessages(['shift' => __('credit.cash_needs_shift')]);
                }

                $this->cash->record($shift, [
                    'direction' => 'in',
                    'reason' => __('credit.payment_reason', ['customer' => $account->customer->name]),
                    'amount' => $amount,
                    'employee_id' => $employeeId,
                ]);
            }

            $entry = CreditEntry::create([
                'account_id' => $account->id,
                // La sucursal es donde se recibió el abono, no donde vive la
                // cuenta: el mismo cliente puede pagar en cualquier local.
                'branch_id' => $branchId,
                'kind' => 'payment',
                'amount' => $amount,
                'employee_id' => $employeeId,
                'comment' => $comment,
                'occurred_at' => now(),
                'recorded_at' => now(),
            ]);

            $account->balance = bcsub((string) $account->balance, $amount, 2);
            $account->save();

            $this->audit->record(
                event: 'credit.payment',
                entityType: 'credit_account',
                entityId: $account->id,
                context: [
                    'amount' => $amount,
                    'method' => $method,
                    'balance_after' => (string) $account->balance,
                ],
                branchId: $branchId,
            );

            return $entry;
        });
    }

    /**
     * Bloqueo manual del supervisor (H8.6, Q-10).
     *
     * Se suma al límite, no lo reemplaza: una cuenta puede estar dentro del
     * límite y aun así bloqueada por mora. Desbloquearla es una decisión
     * distinta y también queda registrada.
     */
    public function block(
        CreditAccount $account,
        string $employeeId,
        string $reason,
        ?string $branchId = null,
    ): CreditAccount {
        $account->forceFill([
            'is_blocked' => true,
            'blocked_reason' => $reason,
            'blocked_by' => $employeeId,
            'blocked_at' => now(),
        ])->save();

        $this->audit->record(
            event: 'credit.account_blocked',
            entityType: 'credit_account',
            entityId: $account->id,
            context: ['reason' => $reason],
            branchId: $branchId,
        );

        return $account->fresh();
    }

    public function unblock(CreditAccount $account, string $employeeId, ?string $branchId = null): CreditAccount
    {
        $account->forceFill([
            'is_blocked' => false,
            'blocked_reason' => null,
            'blocked_by' => null,
            'blocked_at' => null,
        ])->save();

        $this->audit->record(
            event: 'credit.account_unblocked',
            entityType: 'credit_account',
            entityId: $account->id,
            context: ['by' => $employeeId],
            branchId: $branchId,
        );

        return $account->fresh();
    }

    /**
     * Estado de cuenta del período (H8.5).
     *
     * La fecha de corte es **configurable por cliente** (Q-10): no todos cobran
     * el 30. El período va del día siguiente al corte anterior hasta el corte
     * que corresponde a la fecha pedida.
     *
     * @return array<string,mixed>
     */
    public function statement(CreditAccount $account, ?Carbon $reference = null): array
    {
        $reference ??= now();
        [$from, $to] = $this->period($reference, $account->cut_off_day);

        $opening = CreditEntry::where('account_id', $account->id)
            ->where('occurred_at', '<', $from)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'payment' THEN -amount ELSE amount END), 0) AS balance")
            ->value('balance');

        $entries = CreditEntry::where('account_id', $account->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->get();

        $charges = $entries->whereIn('kind', ['charge', 'adjustment'])->sum('amount');
        $payments = $entries->where('kind', 'payment')->sum('amount');

        return [
            'customer' => [
                'name' => $account->customer->name,
                'national_id' => $account->customer->national_id,
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'cut_off_day' => $account->cut_off_day,
            ],
            'opening_balance' => number_format((float) $opening, 2, '.', ''),
            'charges' => number_format((float) $charges, 2, '.', ''),
            'payments' => number_format((float) $payments, 2, '.', ''),
            'closing_balance' => number_format((float) $opening + (float) $charges - (float) $payments, 2, '.', ''),
            'credit_limit' => (string) $account->credit_limit,
            'available' => $account->available(),
            'is_blocked' => (bool) $account->is_blocked,
            'entries' => $entries->map(fn (CreditEntry $entry) => [
                'date' => $entry->occurred_at?->toDateString(),
                'kind' => $entry->kind,
                'amount' => (string) $entry->amount,
                'sale_id' => $entry->sale_id,
                'comment' => $entry->comment,
            ])->all(),
        ];
    }

    /**
     * Período de corte que contiene una fecha.
     *
     * Un corte el 30 en febrero cae el 28: el día del mes se acota al último día
     * disponible en vez de saltar al mes siguiente, que es lo que haría Carbon
     * si se le pide el 30 de febrero.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(Carbon $reference, int $cutOffDay): array
    {
        $cutThisMonth = $this->cutOff($reference, $cutOffDay);

        $to = $reference->lessThanOrEqualTo($cutThisMonth)
            ? $cutThisMonth
            : $this->cutOff($reference->copy()->addMonthNoOverflow(), $cutOffDay);

        $from = $this->cutOff($to->copy()->subMonthNoOverflow(), $cutOffDay)->addDay()->startOfDay();

        return [$from, $to->endOfDay()];
    }

    private function cutOff(Carbon $month, int $cutOffDay): Carbon
    {
        return $month->copy()->day(min($cutOffDay, $month->copy()->endOfMonth()->day))->endOfDay();
    }
}
