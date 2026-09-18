<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Gramática de consulta con microsegundos.
 *
 * Laravel convierte cualquier `Carbon` enlazado a una consulta usando
 * `Y-m-d H:i:s` —sin microsegundos— aunque la columna sí los guarde. El efecto
 * es silencioso y desagradable: `where('next_attempt_at', '<=', now())` compara
 * un valor con microsegundos contra uno truncado al segundo, y la fila que se
 * acaba de escribir no aparece hasta el segundo siguiente.
 *
 * En una caja eso significa que un ticket recién cerrado no se envía, que una
 * cuenta recién suspendida no llega a las demás terminales y que el cursor de
 * sincronización pierde lo ocurrido en su propio segundo. Nada falla a la
 * vista; simplemente falta información.
 */
class MicrosecondPostgresGrammar extends PostgresGrammar
{
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s.u';
    }
}
