<?php

namespace Tests\Feature\Sync;

use App\Models\Sale;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Sincronización entre terminales (G-13, R-01).
 *
 * El plan dejaba la tecnología de tiempo real *"por definir"*. Se resolvió a
 * favor de la **consulta periódica** sobre la red local y no de WebSockets:
 * sobre una LAN la diferencia entre un aviso instantáneo y uno de dos segundos
 * no la percibe nadie, y un servidor de conexiones persistentes es una pieza más
 * que instalar y vigilar en el equipo de cada cliente, donde no hay nadie de
 * sistemas.
 *
 * El mecanismo es un **cursor**: la terminal manda hasta dónde sabía y recibe lo
 * que cambió. Sin estado en el servidor.
 */
class ChangeFeedTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->token = $this->signedIn();
    }

    private function poll(?string $since = null): array
    {
        $query = $since ? '?since='.urlencode($since) : '';

        return $this->actingAsTerminal($this->token)
            ->getJson("/api/sync/changes{$query}")
            ->assertOk()
            ->json('data');
    }

    public function test_devuelve_un_cursor_y_cada_cuanto_volver_a_preguntar(): void
    {
        $feed = $this->poll();

        $this->assertNotEmpty($feed['cursor']);
        // El intervalo viaja en la respuesta en vez de estar cableado en el
        // cliente: una instalación con veinte cajas puede subirlo sin desplegar.
        $this->assertSame(2, $feed['poll_after_seconds']);
    }

    public function test_sin_cambios_desde_el_cursor_no_devuelve_nada(): void
    {
        $primero = $this->poll();
        $segundo = $this->poll($primero['cursor']);

        $this->assertSame([], $segundo['changes']['sales']);
        $this->assertSame([], $segundo['changes']['notifications']);
    }

    public function test_una_cuenta_suspendida_aparece_en_las_demas_terminales(): void
    {
        $cursor = $this->poll()['cursor'];

        $product = $this->product('P-001', '100.00');
        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/suspend", ['label' => 'Mesa 4'])->assertOk();

        $changes = $this->poll($cursor)['changes'];

        $suspended = collect($changes['sales'])->firstWhere('id', $sale);

        $this->assertSame('suspended', $suspended['status']);
        $this->assertSame('Mesa 4', $suspended['label']);
    }

    public function test_una_cuenta_que_otra_caja_retomo_tambien_viaja(): void
    {
        $product = $this->product('P-001', '100.00');
        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();
        $this->actingAsTerminal($this->token)
            ->postJson("/api/sales/{$sale}/suspend", ['label' => 'Mesa 4'])->assertOk();

        $cursor = $this->poll()['cursor'];

        // Otra caja se lleva la mesa 4.
        $otra = Terminal::create([
            'branch_id' => $this->branch->id,
            'code' => 'CAJA-02',
            'name' => 'Caja 2',
            'secret_hash' => Hash::make('otro-secreto'),
        ]);

        $this->app['auth']->forgetGuards();
        $otroToken = $this->postJson('/api/terminal/login', [
            'branch_code' => '001', 'terminal_code' => 'CAJA-02', 'secret' => 'otro-secreto',
        ])->json('data.token');

        $this->actingAsTerminal($otroToken)->postJson('/api/operator/session', [
            'employee_code' => 'CAJ01', 'pin' => '1234',
        ])->assertCreated();
        $this->openShiftFor($otra, $this->cashier);

        $this->actingAsTerminal($otroToken)->postJson("/api/sales/{$sale}/resume")->assertOk();

        // La primera caja tiene que poder sacarla de su lista. Mandar solo las
        // suspendidas dejaría cuentas fantasma en pantalla, que es el error que
        // un restaurante no perdona.
        $this->token = $this->signedIn();
        $changes = $this->poll($cursor)['changes'];

        $this->assertSame('draft', collect($changes['sales'])->firstWhere('id', $sale)['status']);
    }

    public function test_los_avisos_al_supervisor_viajan_en_el_mismo_sondeo(): void
    {
        $cursor = $this->poll()['cursor'];

        $supervisor = $this->employee('SUP01', '4321', 'supervisor', '9876');
        $this->token = $this->signedIn($supervisor, '4321');

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [])->json('data.id');
        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'temporary',
            'description' => 'Tornillos sueltos',
            'qty' => '1',
            'unit_price' => '250.00',
        ])->assertCreated();

        $changes = $this->poll($cursor)['changes'];

        // P-11: el aviso es interno y no bloqueante, pero tiene que llegar
        // rápido. El sondeo es su canal.
        $this->assertCount(1, $changes['notifications']);
        $this->assertSame('sale.special_line', $changes['notifications'][0]['event']);
    }

    public function test_el_catalogo_viaja_como_marca_de_agua_no_como_catalogo(): void
    {
        $feed = $this->poll();

        $this->assertNull($feed['changes']['catalog']['products_updated_at']);

        $this->product('P-001', '100.00');

        $feed = $this->poll();

        // No se mandan los productos: se manda **cuándo cambió el último**. La
        // terminal compara con su caché y decide si vale la pena bajarlo (D-04).
        // Mandarlo en cada vuelta tiraría la red abajo por nada.
        $this->assertNotNull($feed['changes']['catalog']['products_updated_at']);
    }

    public function test_el_estado_del_turno_va_en_cada_respuesta(): void
    {
        $feed = $this->poll();

        // Va siempre y no solo cuando cambia: es barato, y evita que una caja
        // siga vendiendo contra un turno que el supervisor cerró desde otra
        // pantalla.
        $this->assertNotNull($feed['changes']['shift']);
        $this->assertStringStartsWith('CAJA-01-', $feed['changes']['shift']['code']);
    }

    public function test_el_sondeo_no_deja_estado_en_el_servidor(): void
    {
        $primero = $this->poll();
        $segundo = $this->poll();

        // Dos consultas iguales dan lo mismo: reiniciar el servidor no pierde
        // nada, y una terminal que estuvo desconectada se pone al día sola con
        // su último cursor.
        $this->assertSame($primero['changes']['sales'], $segundo['changes']['sales']);
        $this->assertSame(0, Sale::count());
    }
}
