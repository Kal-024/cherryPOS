<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuadrar una caja ya cerrada, sin tocar el arqueo (P1).
 *
 * Al cerrar aparece el faltante o el sobrante, y hasta acá no había nada que
 * hacer con él: el turno quedaba cerrado y descuadrado para siempre. Eso importa
 * más allá del POS — el ERP no emite el comprobante contable del día si la caja
 * no cuadra.
 *
 * La corrección **no reescribe el conteo**: se salda con un movimiento de caja
 * como cualquier otro —entrada si sobró, salida si faltó—, con su motivo, su
 * autorización y su asiento en la bitácora. El conteo original queda tal como se
 * hizo, que es lo que permite auditar quién contó qué; y como lo esperado en el
 * cajón ya suma los movimientos, la diferencia se va sola a cero.
 *
 * La bandera distingue ese asiento de un retiro o un ingreso normal: sin ella,
 * el histórico de movimientos mezclaría "pagué al proveedor" con "faltaban
 * veinte córdobas".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_cash_movements', function (Blueprint $table) {
            $table->boolean('is_settlement')->default(false)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('pos_cash_movements', function (Blueprint $table) {
            $table->dropColumn('is_settlement');
        });
    }
};
