<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cursos y tiempos de servicio (G-16).
 *
 * **El curso es de la línea, no del envío.** El mesero toma el pedido entero de
 * una vez —la mesa canta entradas, fuertes y postres seguidos— y lo que cambia
 * es *cuándo* sale cada cosa de la cocina. Si el curso fuera del envío, pedir el
 * postre con el resto obligaría a volver a la mesa a pedirlo de nuevo, que es
 * exactamente lo que el cuaderno del mesero no necesita.
 *
 * De ahí `held`: la comanda del segundo curso nace **retenida** y no aparece en
 * el pase hasta que alguien la marcha. Sin ese estado, o la cocina saca los tres
 * platos juntos, o el postre no se pide hasta que la mesa termina y se pierden
 * los veinte minutos que tarda.
 *
 * `fired_at` existe aparte de `sent_at` porque los dos momentos son distintos y
 * los dos importan: el pedido entró a las 20:14 y el postre se marchó a las
 * 21:02. El tiempo que mide la cocina corre desde que se marcha, no desde que se
 * tomó la orden — cobrarle a la cocina la espera de la mesa haría que toda
 * comanda retenida naciera atrasada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sale_lines', function (Blueprint $table) {
            // 1 es el primer curso, y es lo que rige en un local sin cursos:
            // todo sale junto porque todo está en el mismo.
            $table->unsignedTinyInteger('course')->default(1)->after('notes');
        });

        Schema::table('pos_kitchen_tickets', function (Blueprint $table) {
            $table->unsignedTinyInteger('course')->default(1)->after('destination');
            $table->timestampTz('fired_at', 6)->nullable()->after('sent_at');

            $table->index(['branch_id', 'destination', 'status', 'course']);
        });

        // Lo ya enviado se marchó en el momento en que se envió: antes de que
        // existieran los cursos, mandar era marchar.
        DB::table('pos_kitchen_tickets')->whereNull('fired_at')
            ->update(['fired_at' => DB::raw('sent_at')]);

        $this->replaceStatusCheck([
            'held', 'queued', 'preparing', 'ready', 'served', 'cancelled',
        ]);

        DB::table('cmn_schema_version')->where('id', 1)->update([
            'version' => '1.3.0',
            'applied_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Una comanda retenida vuelve a la cola: el estado desaparece, el pedido
        // no. Dejarla como está rompería la restricción anterior.
        DB::table('pos_kitchen_tickets')->where('status', 'held')->update(['status' => 'queued']);

        $this->replaceStatusCheck(['queued', 'preparing', 'ready', 'served', 'cancelled']);

        Schema::table('pos_kitchen_tickets', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'destination', 'status', 'course']);
            $table->dropColumn(['course', 'fired_at']);
        });

        Schema::table('pos_sale_lines', function (Blueprint $table) {
            $table->dropColumn('course');
        });

        DB::table('cmn_schema_version')->where('id', 1)->update([
            'version' => '1.2.0',
            'applied_at' => now(),
        ]);
    }

    /**
     * Reescribe la restricción del estado.
     *
     * `enum` en PostgreSQL es un `varchar` con `CHECK`, así que agregar un
     * estado es cambiar la restricción. Se hace con SQL explícito porque
     * `doctrine/dbal` no modifica restricciones de comprobación, y dejarlo en un
     * `change()` silencioso haría que la migración pareciera aplicarse sin que
     * el estado nuevo fuera válido.
     *
     * @param  array<int,string>  $statuses
     */
    private function replaceStatusCheck(array $statuses): void
    {
        $values = implode(', ', array_map(static fn (string $s) => "'{$s}'", $statuses));

        DB::statement('ALTER TABLE pos_kitchen_tickets DROP CONSTRAINT IF EXISTS pos_kitchen_tickets_status_check');
        DB::statement(
            'ALTER TABLE pos_kitchen_tickets ADD CONSTRAINT pos_kitchen_tickets_status_check '
            ."CHECK (status::text = ANY (ARRAY[{$values}]::text[]))"
        );
    }
};
