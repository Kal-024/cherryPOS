<?php

namespace Tests\Feature\Erp;

use App\Models\Barcode;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ErpReconciliationEntry;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Lectura de maestros desde el ERP (F1-C, §12 del contrato).
 *
 * Lo que se prueba es lo que hace la integración usable el día que un ERP se
 * activa sobre un POS que ya venía vendiendo:
 *
 *  - **La fusión es por clave natural, nunca por nombre** (Q-04): una fusión
 *    equivocada mezcla el crédito de dos clientes y se descubre cuando uno
 *    reclama la deuda del otro.
 *  - **Lo del POS sobrevive**: estación de preparación, modificadores y códigos
 *    de barras adicionales no existen en el ERP, y perderlos en cada
 *    sincronización haría la integración inservible para un restaurante.
 *  - **Lo que no case avisa y no bloquea**: una caja parada por un dato viejo es
 *    peor que un dato viejo.
 */
class ErpMasterSyncTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        config([
            'pos.erp.base_url' => 'https://erp.local/api',
            'pos.erp.token' => 'token-de-prueba',
            'pos.erp.company_id' => 7,
        ]);

        $this->manager = $this->employee('ENC01', '3333', 'manager');
        $this->token = $this->signedIn($this->manager, '3333');
    }

    public function test_un_producto_nuevo_del_erp_se_crea_con_su_referencia(): void
    {
        $this->fakeErp(products: [$this->erpProduct()]);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        $product = Product::where('sku', 'ERP-001')->firstOrFail();

        // El precio viene con el impuesto adentro y se copia tal cual (§12.6).
        $this->assertSame('115.0000', (string) $product->price);
        $this->assertSame(9001, $product->erp_product_id);
        $this->assertNotNull(Barcode::where('code', '7501234567890')->first());
    }

    public function test_la_fusion_es_por_clave_natural_y_conserva_lo_del_pos(): void
    {
        $local = $this->product('ERP-001', '99.00', ['prep_station' => 'kitchen']);
        $group = ModifierGroup::create(['code' => 'termino', 'name' => 'Término', 'min_select' => 1]);
        DB::table('cat_product_modifier_group')->insert([
            'product_id' => $local->id, 'group_id' => $group->id, 'sort_order' => 0,
        ]);
        Barcode::create(['product_id' => $local->id, 'code' => '111111', 'embedded' => 'none']);

        $this->fakeErp(products: [$this->erpProduct()]);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        $fresh = $local->fresh();

        // El ERP manda en el precio…
        $this->assertSame('115.0000', (string) $fresh->price);
        $this->assertSame(9001, $fresh->erp_product_id);
        // …y lo que el ERP no modela sigue intacto: sin esto, un restaurante
        // perdería sus modificadores en cada sincronización.
        $this->assertSame('kitchen', $fresh->prep_station);
        $this->assertSame(1, $fresh->modifierGroups()->count());
        $this->assertSame(2, Barcode::where('product_id', $local->id)->count());
    }

    public function test_un_cliente_se_fusiona_por_cedula(): void
    {
        $local = $this->customer('Rosa Martínez', 'account', '001-010180-0001R');

        $this->fakeErp(customers: [$this->erpCustomer()]);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        $this->assertSame(1, Customer::count());
        $this->assertSame(5001, $local->fresh()->erp_customer_id);
    }

    public function test_un_cliente_sin_cedula_nunca_se_fusiona(): void
    {
        $this->customer('Distribuidora González', 'cash');

        // Dos nombres iguales son dos personas distintas: fusionar por nombre
        // mezclaría el crédito de dos clientes (Q-04).
        $this->fakeErp(customers: [$this->erpCustomer(taxId: null, name: 'Distribuidora González')]);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        $this->assertSame(1, Customer::count());
        $this->assertNotNull(
            ErpReconciliationEntry::where('reason', ErpReconciliationEntry::NO_KEY)->first()
        );
    }

    public function test_el_pos_no_puede_tener_dos_clientes_con_la_misma_cedula(): void
    {
        $this->customer('Rosa Martínez', 'account', '001-010180-0001R');

        // La ambigüedad que Q-04 teme la impide **la base**, no una
        // comprobación: la cédula es única en la persona y la persona es única
        // en el cliente. Por eso la fusión no necesita desempatar candidatos.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->customer('Rosa M.', 'account', '001-010180-0001R');
    }

    public function test_una_cedula_ya_fusionada_con_otro_cliente_del_erp_va_a_la_bandeja(): void
    {
        $local = $this->customer('Rosa Martínez', 'account', '001-010180-0001R');
        $local->forceFill(['erp_customer_id' => 4242])->save();

        $this->fakeErp(customers: [$this->erpCustomer()]);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        $entry = ErpReconciliationEntry::where('reason', ErpReconciliationEntry::CONFLICT)->firstOrFail();

        // Pisar la referencia mezclaría dos historiales de crédito.
        $this->assertSame(4242, $entry->detail['already_linked_to']);
        $this->assertSame(4242, $local->fresh()->erp_customer_id);
    }

    public function test_lo_que_el_pos_tiene_y_el_erp_no_queda_pendiente_sin_bloquear(): void
    {
        $orphan = $this->product('SOLO-POS', '50.00');

        $this->fakeErp(products: [$this->erpProduct()]);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        $this->assertNotNull(
            ErpReconciliationEntry::where('entity_id', $orphan->id)
                ->where('reason', ErpReconciliationEntry::NO_MATCH)->first()
        );

        // Y se sigue vendiendo: una caja parada por un dato viejo es peor que un
        // dato viejo (§12.8).
        $token = $this->signedIn();
        $sale = $this->actingAsTerminal($token)->postJson('/api/sales', [])->json('data.id');

        $this->actingAsTerminal($token)
            ->postJson("/api/sales/{$sale}/lines", ['kind' => 'product', 'product_id' => $orphan->id, 'qty' => '1'])
            ->assertCreated();
    }

    public function test_la_segunda_lectura_es_incremental(): void
    {
        $this->fakeErp(products: [$this->erpProduct()]);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();
        app(SettingsRepository::class)->flush();

        $this->assertNotNull(app(SettingsRepository::class)->get('erp.products_synced_at'));

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        // La segunda corrida pide solo lo que cambió desde la marca del ERP.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'updated_since=')
            || ! str_contains($request->url(), '/product'));
    }

    public function test_con_erp_el_catalogo_es_de_solo_lectura(): void
    {
        // Corregir un precio acá lo pisaría la siguiente sincronización sin
        // dejar rastro de por qué (§12.1).
        $this->actingAsTerminal($this->token)
            ->postJson('/api/catalog/products', [
                'sku' => 'NUEVO', 'name' => 'Nuevo', 'uom_id' => $this->unit->id, 'price' => '10',
            ])
            ->assertStatus(422);
    }

    public function test_la_bandeja_se_cierra_con_su_motivo(): void
    {
        $orphan = $this->product('SOLO-POS', '50.00');
        $this->fakeErp(products: []);

        $this->actingAsTerminal($this->token)->postJson('/api/erp/masters/pull')->assertOk();

        $entry = ErpReconciliationEntry::where('entity_id', $orphan->id)->firstOrFail();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/erp/reconciliation/{$entry->id}/resolve", ['resolution' => 'Cargado en el ERP.'])
            ->assertOk();

        $this->assertNotNull($entry->fresh()->resolved_at);

        $this->actingAsTerminal($this->token)
            ->getJson('/api/erp/reconciliation')
            ->assertNotFound();
    }

    /**
     * @param  array<int,array<string,mixed>>  $products
     * @param  array<int,array<string,mixed>>  $customers
     */
    private function fakeErp(array $products = [], array $customers = []): void
    {
        Http::fake([
            '*/product*' => Http::response(
                ['data' => $products, 'meta' => ['last_page' => 1], 'status' => 200],
                200,
                ['Date' => now()->toRfc7231String()]
            ),
            '*/customer*' => Http::response(
                ['data' => $customers, 'meta' => ['last_page' => 1], 'status' => 200],
                200,
                ['Date' => now()->toRfc7231String()]
            ),
        ]);
    }

    /** @return array<string,mixed> */
    private function erpProduct(): array
    {
        return [
            'id' => 9001,
            'code_internal' => 'ERP-001',
            'barcode' => '7501234567890',
            'name' => 'Producto del ERP',
            'current_price' => '115.0000',
            'is_tax_exempt' => false,
            'is_active' => true,
            'uom' => ['code' => 'UND'],
        ];
    }

    /** @return array<string,mixed> */
    private function erpCustomer(?string $taxId = '001-010180-0001R', string $name = 'Rosa Martínez'): array
    {
        return [
            'id' => 5001,
            'code' => 'CLI-000001',
            'legal_name' => $name,
            'tax_id' => $taxId,
            'email' => null,
            'phone' => '88887777',
            'is_tax_exempt' => false,
        ];
    }
}
