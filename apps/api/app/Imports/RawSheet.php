<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Lector crudo: devuelve la hoja como matriz, sin interpretar nada.
 *
 * La interpretación es del importador de cada tipo, que sabe qué significa cada
 * columna. Este objeto existe solo porque `Excel::toArray()` exige una clase de
 * importación, aunque lo único que se quiera sea leer.
 */
class RawSheet implements ToArray
{
    public function array(array $rows): void
    {
        // Sin efecto: `Excel::toArray()` devuelve las filas al llamador.
    }
}
