<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Identificador UUID v7 para toda entidad de dominio (precondición 1).
 *
 * v7 y no v4 porque lleva el instante dentro: ordena por creación sin columna
 * extra y no fragmenta los índices como lo haría un identificador aleatorio en
 * una tabla que crece un ticket por minuto.
 *
 * El terminal genera el identificador **sin consultar al servidor**. De eso
 * dependen dos cosas: el modo degradado y la idempotencia del ERP, que usa ese
 * mismo UUID como clave del documento (§2.B del contrato).
 */
trait UsesUuid
{
    use HasUuids;

    /**
     * Marcas de tiempo con microsegundos.
     *
     * Eloquent las escribe con formato `Y-m-d H:i:s` aunque la columna admita
     * más precisión, y entonces todo lo que ocurre dentro del mismo segundo
     * comparte instante. Eso rompe dos cosas: el cursor de sincronización
     * —que pierde lo ocurrido en el segundo en que se tomó, y en una caja son
     * varias ventas— y el orden de los movimientos del kardex cuando hay que
     * reconstruir qué pasó primero.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
