<?php

namespace Tests\Feature\Dining;

use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Traspasar líneas y dividir la cuenta (F1-B).
 *
 * Las dos son la misma operación con distinto destino, y las dos tocan algo
 * delicado: el importe de dos cuentas a la vez. Lo que se prueba son los bordes
 * que impiden que eso salga mal —cuentas ya pagadas, líneas ajenas, divisiones
 * que no dividen— y que la comanda **no** se reescriba: lo que salió a cocina
 * salió desde esa cuenta y a esa hora.
 */
class TableTransferTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private Product $dish;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->token = $this->signedIn();
        $this->dish = $this->product('PLATO-1', '115.00', ['prep_station' => 'kitchen']);
    }

    public function test_se_traspasan_lineas_a_otra_cuenta(): void
    {
        $origin = $this->tab('M1', lines: 2);
        $destination = $this->tab('M2', lines: 1);

        $line = SaleLine::where('sale_id', $origin)->orderBy('sequence')->first();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/transfer", [
                'target_sale_id' => $destination,
                'lines' => [$line->id],
            ])
            ->assertOk();

        // Los dos totales se recalculan: mover una línea cambia dos cuentas.
        $this->assertSame('115.00', (string) Sale::find($origin)->total);
        $this->assertSame('230.00', (string) Sale::find($destination)->total);
        $this->assertSame($destination, $line->fresh()->sale_id);
    }

    public function test_la_cuenta_entera_se_cambia_de_mesa(): void
    {
        $origin = $this->tab('M1', lines: 1);
        $table = DiningTable::create(['branch_id' => $this->branch->id, 'code' => 'M2', 'seats' => 4]);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/transfer", ['dining_table_id' => $table->id])
            ->assertOk();

        $this->assertSame($table->id, Sale::find($origin)->dining_table_id);

        $map = $this->actingAsTerminal($this->token)->getJson('/api/dining/map')->assertOk();
        $states = collect($map->json('data.tables'))->pluck('state', 'code');

        // La mesa vieja queda libre y la nueva ocupada, sin que nadie marque nada.
        $this->assertSame('free', $states['M1']);
        $this->assertSame('occupied', $states['M2']);
    }

    public function test_mover_a_una_mesa_con_cuenta_suma_a_la_existente(): void
    {
        $origin = $this->tab('M1', lines: 1);
        $other = $this->tab('M2', lines: 1);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/transfer", [
                'dining_table_id' => Sale::find($other)->dining_table_id,
            ])
            ->assertOk();

        // Dos cuentas en una mesa es cobrarle dos veces al mismo grupo.
        $this->assertSame(2, Sale::find($other)->lines()->count());
        $this->assertSame(0, Sale::find($origin)->lines()->count());
    }

    public function test_dividir_deja_las_dos_cuentas_en_la_misma_mesa(): void
    {
        $origin = $this->tab('M1', lines: 3);
        $line = SaleLine::where('sale_id', $origin)->orderBy('sequence')->first();

        $response = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/split", ['lines' => [$line->id]])
            ->assertCreated();

        $target = Sale::find($response->json('data.target.id'));

        // Dividir no es levantarse de la mesa: el mapa sigue mostrando una sola
        // mesa ocupada, con dos cuentas que se cobran por separado.
        $this->assertSame(Sale::find($origin)->dining_table_id, $target->dining_table_id);
        $this->assertSame('115.00', (string) $target->total);
        $this->assertSame('230.00', (string) Sale::find($origin)->total);
        // La cuenta nueva se distingue de la original en la lista de cuentas
        // abiertas: dos "Mesa 1" son dos cuentas que nadie sabe cuál cobra.
        $this->assertNotSame(Sale::find($origin)->label, $target->label);
        $this->assertStringContainsString('2', $target->label);
    }

    public function test_mover_todo_no_es_dividir(): void
    {
        $origin = $this->tab('M1', lines: 2);
        $lines = SaleLine::where('sale_id', $origin)->pluck('id')->all();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/split", ['lines' => $lines])
            ->assertStatus(422);
    }

    public function test_una_cuenta_con_pagos_no_se_toca(): void
    {
        $origin = $this->tab('M1', lines: 2);
        $destination = $this->tab('M2', lines: 1);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$origin}/resume")->assertOk();
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$origin}/payments", [
            'method' => 'cash',
            'amount' => '100.00',
        ])->assertCreated();

        // Mover líneas después de que entró el dinero cambia qué se pagó, y el
        // vuelto calculado deja de corresponder al ticket.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/transfer", ['target_sale_id' => $destination])
            ->assertStatus(422);
    }

    public function test_una_linea_ajena_no_se_mueve(): void
    {
        $origin = $this->tab('M1', lines: 1);
        $other = $this->tab('M2', lines: 1);
        $foreign = SaleLine::where('sale_id', $other)->firstOrFail();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/transfer", [
                'target_sale_id' => $other,
                'lines' => [$foreign->id],
            ])
            ->assertStatus(422);
    }

    public function test_la_comanda_se_queda_donde_se_mando(): void
    {
        $origin = $this->tab('M1', lines: 1);
        $destination = $this->tab('M2', lines: 1);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$origin}/kitchen/send")->assertCreated();

        $ticket = KitchenTicket::where('sale_id', $origin)->firstOrFail();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/transfer", ['target_sale_id' => $destination])
            ->assertOk();

        // Reescribirla dejaría a la cocina preparando algo que nadie pidió: la
        // línea cambia de cuenta, su historia no.
        $this->assertSame($origin, $ticket->fresh()->sale_id);
    }

    public function test_el_traspaso_queda_en_la_bitacora(): void
    {
        $origin = $this->tab('M1', lines: 2);
        $destination = $this->tab('M2', lines: 1);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$origin}/transfer", ['target_sale_id' => $destination])
            ->assertOk();

        $entry = AuditLog::where('event', 'sale.lines_transferred')->latest('occurred_at')->firstOrFail();

        $this->assertSame($destination, $entry->changes['to']);
        $this->assertSame(2, $entry->context['count']);
    }

    /** Una cuenta de mesa con sus líneas, lista para mover. */
    private function tab(string $code, int $lines): string
    {
        $table = DiningTable::create(['branch_id' => $this->branch->id, 'code' => $code, 'seats' => 4]);

        $sale = $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open")
            ->json('data.sale.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/resume")->assertOk();

        for ($i = 0; $i < $lines; $i++) {
            $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $this->dish->id,
                'qty' => '1',
            ])->assertCreated();
        }

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/suspend")->assertOk();

        return $sale;
    }
}
