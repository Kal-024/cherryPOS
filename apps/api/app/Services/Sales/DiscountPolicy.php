<?php

namespace App\Services\Sales;

use App\Models\Employee;
use App\Services\Calc\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Tope de descuento por cajero (D-02, H2.8).
 *
 * La decisión es específica: **varios cajeros pueden tener habilitado el
 * descuento, y cada uno con un tope distinto que fija el supervisor**. No es un
 * permiso de sí o no.
 *
 * Tres niveles:
 *
 *  1. Sin permiso `pos_sale.discount` — no aplica descuentos, y punto.
 *  2. Dentro de su tope — lo aplica solo, queda en auditoría, sin interrupción.
 *  3. Sobre el tope — **autorización en el momento** con PIN de supervisor
 *     sobre la misma terminal, que es un PIN distinto del de sesión (P-11).
 *
 * El tope se compara siempre en **porcentaje efectivo**, incluso cuando el
 * cajero teclea un monto: si no, descontar "200 córdobas" sobre una venta de 210
 * esquivaría cualquier límite expresado en porcentaje.
 */
class DiscountPolicy
{
    public const PERMISSION_APPLY = 'pos_sale.discount';

    public const PERMISSION_AUTHORIZE = 'pos_sale.discount_authorize';

    /**
     * Porcentaje efectivo de un descuento sobre una base.
     *
     * Base en cero da 100 %: descontar algo sobre nada es una operación que
     * siempre tiene que pasar por autorización.
     */
    public function effectivePercent(string $type, string $value, string $base): string
    {
        if ($type === 'percent') {
            return Decimal::format(Decimal::parse($value, Decimal::RATE), Decimal::RATE);
        }

        $baseScaled = Decimal::parse($base, Decimal::MONEY);

        if (Decimal::cmp($baseScaled, '0') === 0) {
            return '100.0000';
        }

        $amount = Decimal::parse($value, Decimal::MONEY);

        // porcentaje = monto / base × 100, a escala de tasa.
        $percent = Decimal::divRoundHalfUp(
            bcmul(Decimal::abs($amount), Decimal::pow10(Decimal::RATE + 2), 0),
            Decimal::abs($baseScaled)
        );

        return Decimal::format($percent, Decimal::RATE);
    }

    /** ¿Necesita este descuento la firma de un supervisor? */
    public function requiresAuthorization(Employee $employee, string $percent, string $branchId): bool
    {
        if (! $employee->hasPermission(self::PERMISSION_APPLY, $branchId)) {
            return true;
        }

        $limit = $this->limitFor($employee);

        // Sin tope configurado, cualquier descuento pasa por autorización. El
        // defecto seguro es el restrictivo: un tope que nadie configuró no
        // significa "sin límite".
        if ($limit === null) {
            return Decimal::cmp(Decimal::parse($percent, Decimal::RATE), '0') > 0;
        }

        return Decimal::cmp(
            Decimal::parse($percent, Decimal::RATE),
            Decimal::parse($limit, Decimal::RATE)
        ) > 0;
    }

    /**
     * @throws ValidationException cuando el cajero no puede aplicar el descuento
     *                             y no vino autorización
     */
    public function assertAllowed(
        Employee $employee,
        string $percent,
        string $branchId,
        ?Employee $authorizedBy = null,
    ): void {
        if (! $this->requiresAuthorization($employee, $percent, $branchId)) {
            return;
        }

        if ($authorizedBy === null) {
            throw ValidationException::withMessages([
                'discount' => __('sales.discount_needs_authorization', [
                    'limit' => $this->limitFor($employee) ?? '0',
                ]),
            ]);
        }

        if (! $authorizedBy->hasPermission(self::PERMISSION_AUTHORIZE, $branchId)) {
            throw ValidationException::withMessages([
                'supervisor_code' => __('auth.supervisor_cannot_authorize'),
            ]);
        }
    }

    /** Tope propio del cajero, en porcentaje. Null = sin tope configurado. */
    public function limitFor(Employee $employee): ?string
    {
        return $employee->discount_limit_percent !== null
            ? (string) $employee->discount_limit_percent
            : null;
    }
}
