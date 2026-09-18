<?php

namespace App\Services\Fiscal;

use App\Models\Sale;

/**
 * Emisor local: el que rige hoy en Nicaragua.
 *
 * No hay ente al que enviar nada. El documento vale por estar impreso y
 * numerado, y la numeración ya la resolvió `DocumentNumberService` con su
 * plantilla de tokens y sus series por sucursal (B-05).
 *
 * Existe para que el andamio de P-08 **tenga al menos una implementación real**
 * desde el primer día. Una interfaz sin implementación es una interfaz que nadie
 * probó, y el día que llegue la segunda se descubre que no servía.
 */
class LocalPrintIssuer implements DocumentIssuer
{
    public function code(): string
    {
        return 'local';
    }

    public function issue(Sale $sale): array
    {
        return [
            // Sin ente emisor no hay identificador externo: el número del
            // documento es el que vale.
            'external_id' => null,
            'status' => 'issued_local',
            'response' => [
                'issuer' => $this->code(),
                'number' => $sale->number,
                'issued_at' => now()->toIso8601String(),
            ],
        ];
    }
}
