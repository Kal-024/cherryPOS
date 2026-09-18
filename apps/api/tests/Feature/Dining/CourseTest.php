<?php

namespace Tests\Feature\Dining;

use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Cursos, marcha y tiempos de servicio (G-16).
 *
 * El problema que resuelven es de sala, no de pantalla: **la mesa canta el
 * pedido entero de una vez** —entrada, fuerte y postre seguidos— y lo que cambia
 * es cuándo sale cada cosa de la cocina. Sin cursos hay dos salidas y las dos son
 * malas: o los tres platos se cocinan juntos y el postre llega frío a la mesa, o
 * el postre no se pide hasta que terminan el fuerte y se pierden los veinte
 * minutos que tarda.
 *
 * De ahí que la comanda del curso siguiente nazca **retenida**: está pedida,
 * cocina la tiene, y no entra al pase hasta que el mesero la marcha.
 */
class CourseTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private Product $starter;

    private Product $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->token = $this->signedIn();
        $this->starter = $this->product('ENTRADA-1', '120.00', ['prep_station' => 'kitchen']);
        $this->main = $this->product('FUERTE-1', '350.00', ['prep_station' => 'kitchen']);
    }

    private function openTab(): string
    {
        $table = DiningTable::create([
            'branch_id' => $this->branch->id,
            'code' => 'M1',
            'seats' => 4,
        ]);

        return $this->actingAsTerminal($this->token)
            ->postJson("/api/dining/tables/{$table->id}/open", ['guests' => 2])
            ->json('data.sale.id');
    }

    private function add(string $sale, Product $product, int $course): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $product->id,
                'qty' => '1',
                'course' => $course,
            ])
            ->assertCreated();
    }

    public function test_el_pedido_entero_se_toma_de_una_vez_y_solo_sale_el_primer_curso(): void
    {
        $sale = $this->openTab();
        $this->add($sale, $this->starter, 1);
        $this->add($sale, $this->main, 2);

        $tickets = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/send")
            ->assertCreated()
            ->json('data');

        // Dos comandas del mismo envío: la entrada al pase, el fuerte esperando.
        $this->assertCount(2, $tickets);
        $this->assertSame(1, $tickets[0]['course']);
        $this->assertSame('queued', $tickets[0]['status']);
        $this->assertSame(2, $tickets[1]['course']);
        $this->assertSame('held', $tickets[1]['status']);

        // Y lo retenido no tiene hora de marcha: es lo que le falta para empezar
        // a contar.
        $this->assertNull($tickets[1]['fired_at']);
    }

    public function test_lo_retenido_no_aparece_en_el_pase(): void
    {
        $sale = $this->openTab();
        $this->add($sale, $this->starter, 1);
        $this->add($sale, $this->main, 2);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        $pass = $this->actingAsTerminal($this->token)
            ->getJson('/api/kitchen/tickets')
            ->assertOk()
            ->json('data');

        // El cocinero ve una sola comanda. Ver las dos sería la cocina sacando
        // el fuerte mientras la mesa empieza la entrada.
        $this->assertCount(1, $pass);
        $this->assertSame(1, $pass[0]['course']);
    }

    public function test_marchar_manda_el_curso_al_pase_y_arranca_su_reloj(): void
    {
        $sale = $this->openTab();
        $this->add($sale, $this->starter, 1);
        $this->add($sale, $this->main, 2);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        $fired = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/fire")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $fired);
        $this->assertSame(2, $fired[0]['course']);
        $this->assertSame('queued', $fired[0]['status']);
        $this->assertNotNull($fired[0]['fired_at']);

        // El reloj de la cocina arranca al marchar, no al pedir: una comanda
        // retenida media hora no nace atrasada.
        $this->assertLessThan(5, $fired[0]['elapsed_seconds']);
    }

    public function test_sin_nada_retenido_marchar_avisa_en_vez_de_no_hacer_nada(): void
    {
        $sale = $this->openTab();
        $this->add($sale, $this->starter, 1);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/fire")
            ->assertStatus(422);
    }

    public function test_una_comanda_retenida_no_se_empieza_a_preparar(): void
    {
        $sale = $this->openTab();
        $this->add($sale, $this->starter, 1);
        $this->add($sale, $this->main, 2);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        $held = KitchenTicket::where('sale_id', $sale)->where('status', KitchenTicket::HELD)->firstOrFail();

        // Con el permiso del pase en la mano: el KDS es otro permiso (F1-B).
        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $kds = $this->signedIn($supervisor, '1111');

        // Aunque alguien la encuentre por su identificador: el pase no la
        // muestra y el servidor tampoco la deja avanzar.
        $this->actingAsTerminal($kds)
            ->postJson("/api/kitchen/tickets/{$held->id}/status", ['status' => 'preparing'])
            ->assertStatus(422);
    }

    public function test_el_pase_marca_lo_atrasado(): void
    {
        $sale = $this->openTab();
        $this->add($sale, $this->starter, 1);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        // Veinte minutos sobre un umbral de quince.
        KitchenTicket::where('sale_id', $sale)->update(['fired_at' => now()->subMinutes(20)]);

        $pass = $this->actingAsTerminal($this->token)
            ->getJson('/api/kitchen/tickets')
            ->assertOk()
            ->json('data');

        $this->assertTrue($pass[0]['is_late']);
        $this->assertGreaterThanOrEqual(1200, $pass[0]['elapsed_seconds']);
    }

    public function test_el_mapa_dice_en_que_anda_el_pedido_de_cada_mesa(): void
    {
        $sale = $this->openTab();
        $this->add($sale, $this->starter, 1);
        $this->add($sale, $this->main, 2);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        $table = $this->tableOf($sale);

        // Cocinando, con el segundo curso esperando a que lo marchen.
        $this->assertSame('cooking', $table['sale']['kitchen']['state']);
        $this->assertSame([2], $table['sale']['kitchen']['held_courses']);

        KitchenTicket::where('sale_id', $sale)
            ->where('status', KitchenTicket::QUEUED)
            ->update(['status' => KitchenTicket::READY, 'ready_at' => now()]);

        // Lo listo manda: es lo único que exige caminar ahora mismo.
        $this->assertSame('ready', $this->tableOf($sale)['sale']['kitchen']['state']);
    }

    /** @return array<string,mixed> */
    private function tableOf(string $saleId): array
    {
        $map = $this->actingAsTerminal($this->token)
            ->getJson('/api/dining/map')
            ->assertOk()
            ->json('data');

        foreach ($map['tables'] as $table) {
            if (($table['sale']['id'] ?? null) === $saleId) {
                return $table;
            }
        }

        $this->fail('La mesa de la cuenta no apareció en el mapa.');
    }
}
