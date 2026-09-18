<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Gastos categorizados (B-14).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad."* Estaba confirmado en la
 * Parte I con 21/25 y se había quedado sin hito asignado: entra junto al turno
 * de caja, porque **un gasto pagado del cajón es un movimiento de caja**.
 * Registrarlo aparte obligaría a cuadrar dos veces la misma plata.
 */
class ExpenseTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private ExpenseCategory $servicios;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->seed(ExpenseCategorySeeder::class);

        $this->servicios = ExpenseCategory::where('code', 'servicios')->firstOrFail();

        // Registrar gastos es del supervisor: es plata que sale.
        $this->token = $this->signedIn($this->employee('SUP01', '4321', 'supervisor', '9876'), '4321');
    }

    private function expenseSupplier(string $name = 'ENACAL'): Supplier
    {
        return Supplier::createWithPerson(
            ['full_name' => $name, 'kind' => 'legal'],
            ['code' => 'PROV-'.substr($name, 0, 3), 'kind' => Supplier::EXPENSE]
        );
    }

    public function test_un_gasto_en_efectivo_sale_del_cajon(): void
    {
        $expense = $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => 'Recibo de agua de agosto',
                'amount' => '850.00',
                'tax_amount' => '0.00',
            ])
            ->assertCreated()
            ->json('data');

        // El arqueo tiene que poder explicar esa plata que ya no está.
        $this->assertNotNull($expense['cash_movement_id']);
        $this->assertDatabaseHas('pos_cash_movements', [
            'direction' => 'out',
            'reason' => 'Recibo de agua de agosto',
            'amount' => '850.00',
        ]);

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        // Fondo 1.000 menos 850 de gasto.
        $this->assertSame(
            '150.00',
            collect($summary['by_currency'])->firstWhere('currency_code', 'NIO')['expected']
        );
    }

    public function test_el_impuesto_del_gasto_va_aparte_del_monto(): void
    {
        $expense = $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => 'Internet',
                'amount' => '1000.00',
                'tax_amount' => '150.00',
            ])
            ->assertCreated()
            ->json('data');

        // El IVA de un gasto es **crédito fiscal, no costo**: sumarlo al gasto
        // infla el costo y desinfla el crédito, y las dos cifras quedan mal.
        $this->assertSame('1000.00', $expense['amount']);
        $this->assertSame('150.00', $expense['tax_amount']);
        $this->assertSame('1150.00', $expense['total']);
    }

    public function test_un_gasto_que_no_sale_del_cajon_no_toca_la_caja(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => 'Alquiler por transferencia',
                'amount' => '15000.00',
                'payment_method' => 'transfer',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('pos_cash_movements', 0);
        $this->assertNull(Expense::first()->shift_id);
    }

    public function test_el_proveedor_de_un_gasto_no_puede_ser_de_mercaderia(): void
    {
        $mercaderia = Supplier::createWithPerson(
            ['full_name' => 'Distribuidora'],
            ['code' => 'PROV-DIS', 'kind' => Supplier::MERCHANDISE]
        );

        // B-12: mezclarlos hace que el reporte de compras incluya la factura del
        // agua, y entonces el margen deja de significar nada.
        $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'supplier_id' => $mercaderia->id,
                'description' => 'Algo',
                'amount' => '100.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.supplier_id.0', __('expense.supplier_must_be_expense_kind'));
    }

    public function test_un_gasto_contra_proveedor_de_gastos_pasa(): void
    {
        $supplier = $this->expenseSupplier();

        $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'supplier_id' => $supplier->id,
                'description' => 'Recibo de agua',
                'amount' => '850.00',
                'document_number' => 'F-00123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_number', 'F-00123');
    }

    public function test_anular_un_gasto_devuelve_la_plata_al_cajon(): void
    {
        $expense = $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => 'Recibo duplicado',
                'amount' => '400.00',
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/expenses/{$expense['id']}/void", ['reason' => 'Se pagó dos veces'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        // **No se borra ni se edita** (A-05): se compensa. El histórico cuenta
        // lo que pasó, incluido el error.
        $this->assertSame(
            '1000.00',
            collect($summary['by_currency'])->firstWhere('currency_code', 'NIO')['expected']
        );
        $this->assertDatabaseCount('pos_cash_movements', 2);
        $this->assertSame(1, Expense::count());
    }

    public function test_un_gasto_anulado_no_se_anula_dos_veces(): void
    {
        $expense = $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => 'Gasto',
                'amount' => '100.00',
            ])->json('data');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/expenses/{$expense['id']}/void", ['reason' => 'Error'])->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/expenses/{$expense['id']}/void", ['reason' => 'Otra vez'])
            ->assertStatus(422);
    }

    public function test_las_categorias_separan_lo_fijo_de_lo_variable(): void
    {
        $categories = $this->actingAsTerminal($this->token)
            ->getJson('/api/expenses/categories')->assertOk()->json('data');

        $byCode = collect($categories)->keyBy('code');

        // La distinción la pide cualquier análisis de punto de equilibrio, y
        // agregarla después obliga a reclasificar el histórico a mano.
        $this->assertSame('fixed', $byCode['alquiler']['behaviour']);
        $this->assertSame('variable', $byCode['comisiones']['behaviour']);
    }

    public function test_los_gastos_se_consultan_por_periodo_y_categoria(): void
    {
        foreach (['Agua', 'Luz'] as $description) {
            $this->actingAsTerminal($this->token)->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => $description,
                'amount' => '100.00',
            ])->assertCreated();
        }

        $this->actingAsTerminal($this->token)->postJson('/api/expenses', [
            'category_id' => ExpenseCategory::where('code', 'transporte')->value('id'),
            'description' => 'Combustible',
            'amount' => '500.00',
        ])->assertCreated();

        $servicios = $this->actingAsTerminal($this->token)
            ->getJson("/api/expenses?category_id={$this->servicios->id}")
            ->assertOk()->json('data');

        $this->assertCount(2, $servicios);
    }

    public function test_el_gasto_queda_en_la_bitacora(): void
    {
        $expense = $this->actingAsTerminal($this->token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => 'Gasto auditado',
                'amount' => '100.00',
            ])->json('data');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/expenses/{$expense['id']}/void", ['reason' => 'Prueba'])->assertOk();

        $this->assertDatabaseHas('sec_audit_log', ['event' => 'expense.recorded']);
        $this->assertDatabaseHas('sec_audit_log', ['event' => 'expense.voided']);
    }

    public function test_un_cajero_no_registra_gastos(): void
    {
        $token = $this->signedIn();

        // Es plata que sale: la decide quien responde por la caja.
        $this->actingAsTerminal($token)
            ->postJson('/api/expenses', [
                'category_id' => $this->servicios->id,
                'description' => 'Algo',
                'amount' => '100.00',
            ])
            ->assertStatus(403);
    }
}
