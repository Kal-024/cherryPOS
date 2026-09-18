<?php

namespace App\Services\Auth;

use App\Models\Terminal;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Primera mitad de la doble credencial (D-05).
 *
 * La terminal se autentica con credenciales reales —código de sucursal, código
 * de terminal y secreto— y conserva un token de Sanctum. Se hace **una vez al
 * día**, no una vez por cajero: en hora pico el operador cambia cada treinta
 * segundos y pedir usuario y contraseña en cada relevo es inviable.
 *
 * El secreto se guarda con hash y nunca vuelve al cliente.
 */
class TerminalAuthService
{
    /** Vigencia del token de terminal. Se renueva en la apertura del día. */
    private const TOKEN_HOURS = 24;

    public function authenticate(string $branchCode, string $terminalCode, string $secret): array
    {
        $terminal = Terminal::query()
            ->with('branch')
            // Igual que el código de cajero: `caja-01` y `CAJA-01` son la
            // misma caja, y la diferencia solo sirve para que alguien pase diez
            // minutos comprobando un secreto que estaba bien.
            ->whereHas('branch', fn ($q) => $q->whereRaw('upper(code) = ?', [mb_strtoupper(trim($branchCode))]))
            ->whereRaw('upper(code) = ?', [mb_strtoupper(trim($terminalCode))])
            ->first();

        // Mismo mensaje exista o no la terminal: enumerar terminales válidas le
        // ahorra trabajo a quien esté probando secretos.
        if (! $terminal || ! Hash::check($secret, $terminal->secret_hash)) {
            /*
             * En la bitácora sí se distingue, porque ahí no lo lee un atacante.
             *
             * Sirve para las dos cosas que pasan de verdad: ver que alguien está
             * probando secretos, y resolver el "no puedo entrar" de un encargado
             * —que casi siempre es el **nombre** de la caja tecleado en lugar de
             * su código, y desde la pantalla se ve idéntico a un secreto
             * equivocado—. El secreto nunca se registra.
             */
            Log::warning('Activación de terminal rechazada', [
                'branch_code' => $branchCode,
                'terminal_code' => $terminalCode,
                'motivo' => $terminal ? 'secreto incorrecto' : 'no existe esa terminal en esa sucursal',
            ]);

            throw ValidationException::withMessages([
                'terminal_code' => __('auth.terminal_failed'),
            ]);
        }

        if (! $terminal->is_active || ! $terminal->branch->is_active) {
            throw ValidationException::withMessages([
                'terminal_code' => __('auth.terminal_inactive'),
            ]);
        }

        // Un token por terminal: reautenticar invalida el anterior, de modo que
        // un equipo robado deja de servir en cuanto el local vuelve a abrir.
        $terminal->tokens()->delete();

        $token = $terminal->createToken(
            "terminal:{$terminal->code}",
            ['terminal'],
            now()->addHours(self::TOKEN_HOURS)
        );

        $terminal->forceFill([
            'authenticated_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        return [
            'token' => $token->plainTextToken,
            'expires_at' => now()->addHours(self::TOKEN_HOURS),
            'terminal' => array_merge(
                $terminal->only(['id', 'code', 'name']),
                ['layout_profile' => $terminal->layoutProfile()],
            ),
            'branch' => $terminal->branch->only(['id', 'code', 'name', 'timezone', 'tax_id']),
        ];
    }
}
