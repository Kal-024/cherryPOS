<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * El mínimo operativo de reportes (P-07).
 *
 * Las ventas del día se agrupan por cajero **y** por terminal porque responden
 * preguntas distintas: quién vendió cuánto es de personas y qué caja movió
 * cuánto es del arqueo del cajón. Con relevos dentro del mismo turno (D-05) los
 * dos cortes no coinciden, y un solo agrupamiento dejaría una de las dos
 * preguntas sin respuesta.
 */
class DailySalesReportTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
    }

    public function test_las_ventas_del_dia_se_agrupan_por_cajero_y_por_terminal(): void
    {
        $product = $this->product('SKU-1', '115.00');
        $token = $this->signedIn();

        $this->sell($token, $product->id);
        $this->sell($token, $product->id);

        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        $response = $this->actingAsTerminal($token)
            ->getJson('/api/reports/daily-sales')
            ->assertOk();

        $this->assertSame(2, (int) $response->json('data.totals.sales_count'));
        $this->assertSame('CAJ01', $response->json('data.by_employee.0.code'));
        $this->assertSame('CAJA-01', $response->json('data.by_terminal.0.code'));
        $this->assertSame(2, (int) $response->json('data.by_terminal.0.sales_count'));
    }

    public function test_una_venta_suspendida_no_cuenta_como_dinero(): void
    {
        $product = $this->product('SKU-1', '115.00');
        $token = $this->signedIn();

        $sale = $this->actingAsTerminal($token)->postJson('/api/sales', ['sale_type' => 'counter'])->json('data.id');
        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $product->id,
            'qty' => '1',
        ])->assertCreated();
        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/suspend")->assertOk();

        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $token = $this->signedIn($supervisor, '1111');

        $response = $this->actingAsTerminal($token)->getJson('/api/reports/daily-sales')->assertOk();

        // Todavía no es dinero: sumarla inflaría el día con cuentas que quizá se
        // anulen.
        $this->assertSame(0, (int) $response->json('data.totals.sales_count'));
    }

    public function test_la_bitacora_se_consulta_pero_no_se_corrige(): void
    {
        $supervisor = $this->employee('SUP01', '1111', 'supervisor');
        $manager = $this->employee('ENC01', '3333', 'manager');
        $token = $this->signedIn($manager, '3333');

        AuditLog::create([
            'branch_id' => $this->branch->id,
            'employee_id' => $supervisor->id,
            'event' => 'sale.discount_authorized',
            'entity_type' => 'pos_sales',
            'entity_id' => (string) Str::uuid7(),
            'changes' => ['percent' => '15'],
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);

        $response = $this->actingAsTerminal($token)
            ->getJson('/api/reports/audit-log?event=sale.')
            ->assertOk();

        $this->assertSame('sale.discount_authorized', $response->json('data.0.event'));
        // Quién y desde dónde: sin eso la entrada no responde la pregunta para
        // la que existe la bitácora.
        $this->assertSame($supervisor->full_name, $response->json('data.0.employee_name'));

        // No hay forma de escribirla desde la API: una bitácora que el sistema
        // puede reescribir no prueba nada.
        $this->actingAsTerminal($token)
            ->putJson('/api/reports/audit-log')
            ->assertStatus(405);
    }

    public function test_el_cajero_no_lee_la_bitacora(): void
    {
        $token = $this->signedIn();

        $this->actingAsTerminal($token)
            ->getJson('/api/reports/audit-log')
            ->assertForbidden();
    }

    private function sell(string $token, string $productId): void
    {
        $sale = $this->actingAsTerminal($token)
            ->postJson('/api/sales', ['sale_type' => 'counter'])
            ->json('data.id');

        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product',
            'product_id' => $productId,
            'qty' => '1',
        ])->assertCreated();

        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'cash',
            'amount' => '115.00',
        ])->assertCreated();

        $this->actingAsTerminal($token)->postJson("/api/sales/{$sale}/close")->assertOk();
    }
}
