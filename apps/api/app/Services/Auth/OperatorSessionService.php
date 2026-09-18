<?php

namespace App\Services\Auth;

use App\Models\Employee;
use App\Models\OperatorSession;
use App\Models\Terminal;
use App\Services\Settings\SettingsRepository;
use Illuminate\Validation\ValidationException;

/**
 * Segunda mitad de la doble credencial (D-05).
 *
 * "Tipo abrir la calculadora únicamente cuando tenga el PIN": la terminal ya
 * está autenticada, pero facturar exige que un cajero abra su sesión. Otro
 * cajero entra después en el mismo equipo con su propio PIN, sin tocar la
 * credencial de la terminal.
 *
 * > **Nota de seguridad.** El PIN identifica al operador dentro de una sesión
 * > de terminal ya autenticada. **Nunca** es credencial de acceso al sistema
 * > desde fuera del local. De ahí el bloqueo tras intentos fallidos: es lo que
 * > hace aceptable un secreto de cuatro dígitos.
 */
class OperatorSessionService
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 5;

    /** Una sesión de PIN dura un turno largo, no un día. */
    private const SESSION_HOURS = 12;

    public function open(Terminal $terminal, string $employeeCode, string $pin): OperatorSession
    {
        // El código se compara **sin distinguir mayúsculas y sin espacios**.
        //
        // `CAJ01` y `caj01` son el mismo cajero para cualquiera que esté frente
        // a la caja, y exigir la forma exacta convierte un error de tecleo en
        // "PIN incorrecto": el mensaje culpa al PIN, el cajero lo repite, y a
        // los cinco intentos se bloquea a sí mismo por haber escrito bien el
        // PIN y mal la caja de las letras.
        $employee = Employee::query()
            ->where('branch_id', $terminal->branch_id)
            ->whereRaw('upper(code) = ?', [mb_strtoupper(trim($employeeCode))])
            ->first();

        if (! $employee || ! $employee->is_active) {
            // Mismo mensaje que un PIN equivocado —no se dice cuál de los dos
            // falló, que sería decir qué códigos existen— pero nombrando los
            // dos campos: "PIN incorrecto" a secas hacía que nadie mirara el
            // código.
            throw ValidationException::withMessages([
                'employee_code' => __('auth.operator_failed'),
            ]);
        }

        if ($employee->isPinLocked()) {
            throw ValidationException::withMessages([
                'pin' => __('auth.pin_locked', [
                    'minutes' => (int) ceil(now()->diffInMinutes($employee->pin_locked_until)),
                ]),
            ]);
        }

        if (! $employee->checkPin($pin)) {
            $this->registerFailure($employee);

            throw ValidationException::withMessages([
                'pin' => __('auth.pin_failed'),
            ]);
        }

        $employee->forceFill([
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        // Una terminal tiene un operador a la vez. Abrir una sesión cierra la
        // anterior: es exactamente el relevo que describe D-05.
        $this->closeOpenSessions($terminal);

        return OperatorSession::create([
            'terminal_id' => $terminal->id,
            'employee_id' => $employee->id,
            'branch_id' => $terminal->branch_id,
            'opened_at' => now(),
            'expires_at' => now()->addHours(self::SESSION_HOURS),
            // Cómo se verifica el PIN sin red lo decide el ERP cuando está
            // integrado (§7): `cached_hash` permite el relevo offline a cambio
            // de exponer los hashes en el equipo, y eso es un opt-in del
            // negocio, no un defecto.
            'pin_mode' => (string) app(SettingsRepository::class)
                ->get('pos.offline_pin_mode', config('pos.offline_pin_mode', 'session_grant')),
        ]);
    }

    public function close(Terminal $terminal): void
    {
        $this->closeOpenSessions($terminal);
    }

    public function current(Terminal $terminal): ?OperatorSession
    {
        return OperatorSession::query()
            ->with('employee.person')
            ->where('terminal_id', $terminal->id)
            ->whereNull('closed_at')
            ->where('expires_at', '>', now())
            ->latest('opened_at')
            ->first();
    }

    private function closeOpenSessions(Terminal $terminal): void
    {
        OperatorSession::query()
            ->where('terminal_id', $terminal->id)
            ->whereNull('closed_at')
            ->update(['closed_at' => now()]);
    }

    private function registerFailure(Employee $employee): void
    {
        $attempts = $employee->failed_pin_attempts + 1;

        $employee->forceFill([
            'failed_pin_attempts' => $attempts,
            'pin_locked_until' => $attempts >= self::MAX_ATTEMPTS
                ? now()->addMinutes(self::LOCK_MINUTES)
                : null,
        ])->save();
    }
}
