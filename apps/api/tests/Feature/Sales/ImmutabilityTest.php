<?php

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inmutabilidad de la venta cerrada (A-05, D-17, P1).
 *
 * OSPOS permite editar y borrar una venta ya cerrada. Eso rompe la auditoría y
 * es ilegal en la mayoría de los regímenes fiscales. Aquí la venta no se edita:
 * se corrige con un documento de reverso.
 *
 * Estas pruebas van **contra la base de datos**, no contra un servicio. Una
 * regla que solo vive en el código se salta con un `tinker`, con una consola
 * SQL o con el próximo servicio que alguien escriba sin leer la decisión.
 */
class ImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Terminal $terminal;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => '001', 'name' => 'Sucursal', 'timezone' => 'America/Managua',
        ]);

        $this->terminal = Terminal::create([
            'branch_id' => $this->branch->id, 'code' => 'CAJA-01', 'name' => 'Caja 1',
            'secret_hash' => Hash::make('x'),
        ]);

        $this->employee = Employee::createWithPerson(
            ['full_name' => 'Cajero'],
            ['branch_id' => $this->branch->id, 'code' => 'CAJ01']
        );
    }

    private function sale(bool $closed, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('pos_sales')->insert(array_merge([
            'id' => $id,
            'branch_id' => $this->branch->id,
            'terminal_id' => $this->terminal->id,
            'employee_id' => $this->employee->id,
            'sale_type' => 'counter',
            'status' => $closed ? 'completed' : 'draft',
            'currency_code' => 'NIO',
            'total' => '115.00',
            'subtotal' => '100.00',
            'tax_total' => '15.00',
            'opened_at' => now(),
            'closed_at' => $closed ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    public function test_el_carrito_todavia_se_edita(): void
    {
        $id = $this->sale(closed: false);

        // Mientras `closed_at` es nulo la venta es un carrito o una cuenta
        // abierta: la inmutabilidad empieza al cerrar, no al crear (D-21).
        DB::table('pos_sales')->where('id', $id)->update(['total' => '230.00']);

        $this->assertSame('230.00', DB::table('pos_sales')->where('id', $id)->value('total'));
    }

    public function test_una_venta_cerrada_no_cambia_su_total(): void
    {
        $id = $this->sale(closed: true);

        $this->expectExceptionMessageMatches('/no se edita/');

        DB::table('pos_sales')->where('id', $id)->update(['total' => '1.00']);
    }

    public function test_una_venta_cerrada_no_cambia_su_cliente_ni_su_cajero(): void
    {
        $id = $this->sale(closed: true);

        $otro = Employee::createWithPerson(
            ['full_name' => 'Otro'],
            ['branch_id' => $this->branch->id, 'code' => 'CAJ02']
        );

        $this->expectExceptionMessageMatches('/no se edita/');

        DB::table('pos_sales')->where('id', $id)->update(['employee_id' => $otro->id]);
    }

    public function test_una_venta_cerrada_no_se_borra(): void
    {
        $id = $this->sale(closed: true);

        $this->expectExceptionMessageMatches('/no se borra/');

        DB::table('pos_sales')->where('id', $id)->delete();
    }

    public function test_una_venta_cerrada_si_puede_anularse(): void
    {
        $id = $this->sale(closed: true);

        // La anulación es una anotación sobre el documento, no una edición del
        // documento: los importes siguen intactos y queda el motivo.
        DB::table('pos_sales')->where('id', $id)->update([
            'status' => 'voided',
            'void_reason' => 'Error de digitación',
        ]);

        $sale = DB::table('pos_sales')->where('id', $id)->first();

        $this->assertSame('voided', $sale->status);
        $this->assertSame('115.00', $sale->total);
    }

    public function test_una_venta_anulada_no_vuelve_atras(): void
    {
        $id = $this->sale(closed: true, overrides: ['status' => 'voided']);

        $this->expectExceptionMessageMatches('/no vuelve atrás/u');

        DB::table('pos_sales')->where('id', $id)->update(['status' => 'completed']);
    }

    public function test_el_estado_de_sincronizacion_con_el_erp_si_se_anota(): void
    {
        $id = $this->sale(closed: true);

        // La cola tiene que poder marcar el ticket como enviado: eso no es
        // editar la venta, es anotar qué pasó con ella (§5 del contrato).
        DB::table('pos_sales')->where('id', $id)->update([
            'erp_status' => 'issued',
            'erp_document_id' => 4321,
            'erp_document_number' => 'CSI-000045',
            'erp_tax_difference' => '0.00',
        ]);

        $this->assertSame('issued', DB::table('pos_sales')->where('id', $id)->value('erp_status'));
    }

    public function test_el_detalle_de_una_venta_cerrada_tampoco_se_toca(): void
    {
        $id = $this->sale(closed: true);

        $lineId = (string) Str::uuid7();
        // La línea se inserta antes del cierre en el flujo real; aquí se fuerza
        // para poder probar que el detalle queda congelado con el encabezado.
        DB::table('pos_sales')->where('id', $id)->update(['erp_status' => 'pending']);
        DB::table('pos_sale_lines')->insert([
            'id' => $lineId,
            'sale_id' => $id,
            'branch_id' => $this->branch->id,
            'sequence' => 1,
            'description' => 'Producto',
            'kind' => 'product',
            'qty' => '1.0000',
            'unit_price' => '100.0000',
            'gross' => '100.00',
            'taxable_base' => '100.00',
            'tax_total' => '15.00',
            'total' => '115.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/ya está cerrada/u');

        DB::table('pos_sale_lines')->where('id', $lineId)->update(['unit_price' => '1.0000']);
    }

    public function test_la_bitacora_de_auditoria_es_de_solo_insercion(): void
    {
        $id = (string) Str::uuid7();

        DB::table('sec_audit_log')->insert([
            'id' => $id,
            'branch_id' => $this->branch->id,
            'event' => 'sale.closed',
            'occurred_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/solo inserción/u');

        DB::table('sec_audit_log')->where('id', $id)->update(['event' => 'otra.cosa']);
    }

    public function test_el_kardex_es_de_solo_insercion(): void
    {
        $locationId = (string) Str::uuid7();
        DB::table('inv_locations')->insert([
            'id' => $locationId, 'branch_id' => $this->branch->id,
            'code' => 'PRINCIPAL', 'name' => 'Bodega', 'is_sales_default' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $uomId = (string) Str::uuid7();
        DB::table('cat_uoms')->insert([
            'id' => $uomId, 'code' => 'UND', 'name' => 'Unidad', 'decimals' => 0,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = (string) Str::uuid7();
        DB::table('cat_products')->insert([
            'id' => $productId, 'sku' => 'P-001', 'name' => 'Producto', 'uom_id' => $uomId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $movementId = (string) Str::uuid7();
        DB::table('inv_movements')->insert([
            'id' => $movementId,
            'branch_id' => $this->branch->id,
            'location_id' => $locationId,
            'product_id' => $productId,
            'reason' => 'receipt',
            'qty' => '10.0000',
            'occurred_at' => now(),
        ]);

        // B-03: el stock es la suma de movimientos, no un número editable. Un
        // error se corrige con un movimiento de ajuste.
        $this->expectExceptionMessageMatches('/movimiento de ajuste/');

        DB::table('inv_movements')->where('id', $movementId)->update(['qty' => '999.0000']);
    }
}
