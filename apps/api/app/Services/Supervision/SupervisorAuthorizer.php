<?php

namespace App\Services\Supervision;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * Autorización en el momento con PIN de supervisor (D-02, D-03, P-11).
 *
 * > El PIN de autorizar es **otro, distinto del de iniciar sesión**. El
 * > supervisor lo teclea sobre la terminal del cajero, delante de él: si fuera
 * > el mismo con el que abre su propia caja, acabaría conocido por todo el
 * > local en una semana.
 *
 * Además exige el permiso correspondiente: tener un PIN de supervisor no
 * alcanza, hay que poder autorizar esa acción concreta.
 */
class SupervisorAuthorizer
{
    /**
     * @throws ValidationException si el PIN no corresponde o falta el permiso
     */
    public function authorize(
        string $supervisorCode,
        string $pin,
        string $permission,
        string $branchId,
    ): Employee {
        $supervisor = Employee::query()
            ->where('branch_id', $branchId)
            ->whereRaw('upper(code) = ?', [mb_strtoupper(trim($supervisorCode))])
            ->where('is_active', true)
            ->first();

        if (! $supervisor || ! $supervisor->checkSupervisorPin($pin)) {
            throw ValidationException::withMessages([
                'supervisor_pin' => __('auth.supervisor_pin_failed'),
            ]);
        }

        if (! $supervisor->hasPermission($permission, $branchId)) {
            throw ValidationException::withMessages([
                'supervisor_code' => __('auth.supervisor_cannot_authorize'),
            ]);
        }

        return $supervisor;
    }
}
