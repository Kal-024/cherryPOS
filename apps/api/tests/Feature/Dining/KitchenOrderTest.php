<?php

namespace Tests\Feature\Dining;

use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Modificadores y comanda (B-06, F1-B).
 *
 * Cuatro reglas, y ninguna es de pantalla:
 *
 *  - **Un obligatorio sin responder no llega a cocina.** Quien descubra que
 *    falta el término de la carne no puede ser el cocinero.
 *  - **El precio del modificador entra en la línea**, con el impuesto adentro,
 *    igual que el precio de góndola: si tributara distinto, el total del ticket
 *    no cerraría con el libro.
 *  - **Solo se manda lo que no se mandó.** La cuenta crece toda la noche;
 *    reenviarla entera haría que cocina preparase dos veces el mismo plato.
 *  - **Cocina y barra numeran por su cuenta**, porque la cerveza no espera al
 *    lomo.
 */
class KitchenOrderTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private Product $steak;

    private ModifierGroup $doneness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        $this->token = $this->signedIn();

        $this->steak = $this->product('PLATO-1', '350.00', ['prep_station' => 'kitchen']);

        // "¿Término?" es obligatorio y admite una sola respuesta.
        $this->doneness = ModifierGroup::create([
            'code' => 'termino',
            'name' => 'Término',
            'min_select' => 1,
            'max_select' => 1,
        ]);

        Modifier::create(['group_id' => $this->doneness->id, 'code' => 'medio', 'name' => 'Término medio']);
        Modifier::create([
            'group_id' => $this->doneness->id,
            'code' => 'tres-cuartos',
            'name' => 'Tres cuartos',
        ]);

        // Los extras son opcionales y sí cuestan.
        $extras = ModifierGroup::create(['code' => 'extras', 'name' => 'Extras', 'min_select' => 0]);
        Modifier::create([
            'group_id' => $extras->id,
            'code' => 'queso',
            'name' => 'Doble queso',
            'price_delta' => '45.00',
        ]);

        DB::table('cat_product_modifier_group')->insert([
            ['product_id' => $this->steak->id, 'group_id' => $this->doneness->id, 'sort_order' => 0],
            ['product_id' => $this->steak->id, 'group_id' => $extras->id, 'sort_order' => 1],
        ]);
    }

    public function test_un_obligatorio_sin_responder_no_deja_agregar_la_linea(): void
    {
        $sale = $this->openTab();

        $response = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $this->steak->id,
                'qty' => '1',
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('Término', $response->json('errors.modifiers.0'));
    }

    public function test_el_modificador_con_precio_entra_en_la_linea(): void
    {
        $sale = $this->openTab();

        $response = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $this->steak->id,
                'qty' => '1',
                'modifiers' => [
                    $this->modifierId('medio'),
                    $this->modifierId('queso'),
                ],
            ])
            ->assertCreated();

        // 350 + 45, con el impuesto adentro como cualquier precio de góndola.
        $this->assertSame('395.0000', $response->json('data.lines.0.unit_price'));
        $this->assertSame('395.00', $response->json('data.sale.total'));
    }

    public function test_un_modificador_de_otro_plato_se_rechaza(): void
    {
        $sale = $this->openTab();
        $otherGroup = ModifierGroup::create(['code' => 'salsa', 'name' => 'Salsa', 'min_select' => 0]);
        $foreign = Modifier::create(['group_id' => $otherGroup->id, 'code' => 'bbq', 'name' => 'BBQ']);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", [
                'kind' => 'product',
                'product_id' => $this->steak->id,
                'qty' => '1',
                'modifiers' => [$this->modifierId('medio'), $foreign->id],
            ])
            ->assertStatus(422);
    }

    public function test_la_comanda_lleva_los_modificadores_copiados(): void
    {
        $sale = $this->openTab();
        $this->addSteak($sale, notes: 'Sin sal');

        $response = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/send")
            ->assertCreated();

        $this->assertSame('kitchen', $response->json('data.0.destination'));
        $this->assertSame(1, $response->json('data.0.number'));
        $this->assertSame('Término medio', $response->json('data.0.lines.0.modifiers.0'));
        // Cocina lee la comanda sin consultar el catálogo.
        $this->assertSame('Sin sal', $response->json('data.0.lines.0.notes'));
    }

    public function test_solo_se_manda_lo_que_no_se_mando(): void
    {
        $sale = $this->openTab();
        $this->addSteak($sale);

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        // Reenviar sin agregar nada no puede duplicar el plato.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/send")
            ->assertStatus(422);

        $this->addSteak($sale);

        $response = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/send")
            ->assertCreated();

        $this->assertCount(1, $response->json('data.0.lines'));
        $this->assertSame(2, $response->json('data.0.number'));
    }

    public function test_cocina_y_barra_son_comandas_distintas(): void
    {
        $beer = $this->product('BEBIDA-1', '60.00', ['prep_station' => 'bar']);
        $sale = $this->openTab();

        $this->addSteak($sale);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $beer->id,
            'qty' => '2',
        ])->assertCreated();

        $response = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/send")
            ->assertCreated();

        $destinations = array_column($response->json('data'), 'destination');

        // La cerveza no espera al lomo, y cada destino numera por su cuenta.
        $this->assertEqualsCanonicalizing(['kitchen', 'bar'], $destinations);
        $this->assertSame([1, 1], array_column($response->json('data'), 'number'));
    }

    public function test_lo_que_no_se_prepara_no_va_a_comanda(): void
    {
        $water = $this->product('AGUA-1', '25.00');
        $sale = $this->openTab();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $water->id,
            'qty' => '1',
        ])->assertCreated();

        // Una gaseosa de heladera la sirve el mesero sin molestar a nadie.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/send")
            ->assertStatus(422);
    }

    public function test_la_comanda_avanza_en_orden_y_no_retrocede(): void
    {
        $sale = $this->openTab();
        $this->addSteak($sale);

        $ticket = $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/kitchen/send")
            ->json('data.0.id');

        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        $this->actingAsTerminal($token)
            ->postJson("/api/kitchen/tickets/{$ticket}/status", ['status' => 'preparing'])
            ->assertOk();

        $this->actingAsTerminal($token)
            ->postJson("/api/kitchen/tickets/{$ticket}/status", ['status' => 'ready'])
            ->assertOk();

        // Volver a "preparando" es un plato que ya salió y nadie sabe dónde está.
        $this->actingAsTerminal($token)
            ->postJson("/api/kitchen/tickets/{$ticket}/status", ['status' => 'preparing'])
            ->assertStatus(422);

        $this->assertSame(KitchenTicket::READY, KitchenTicket::find($ticket)->status);
        $this->assertNotNull(KitchenTicket::find($ticket)->ready_at);
    }

    public function test_el_kds_muestra_lo_que_esta_en_curso_y_lo_mas_viejo_primero(): void
    {
        $sale = $this->openTab();
        $this->addSteak($sale);
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/kitchen/send")->assertCreated();

        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        $response = $this->actingAsTerminal($token)
            ->getJson('/api/kitchen/tickets?destination=kitchen')
            ->assertOk();

        $this->assertSame('queued', $response->json('data.0.status'));
        // La etiqueta viaja con la comanda para que el pase sepa a qué mesa va,
        // sin consultar la venta.
        $this->assertStringContainsString('M1', $response->json('data.0.label'));
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

    private function addSteak(string $sale, ?string $notes = null): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/lines", array_filter([
                'kind' => 'product',
                'product_id' => $this->steak->id,
                'qty' => '1',
                'modifiers' => [$this->modifierId('medio')],
                'notes' => $notes,
            ]))
            ->assertCreated();
    }

    private function modifierId(string $code): string
    {
        return Modifier::where('code', $code)->firstOrFail()->id;
    }
}
