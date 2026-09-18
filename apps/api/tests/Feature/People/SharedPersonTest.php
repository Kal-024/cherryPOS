<?php

namespace Tests\Feature\People;

use App\Models\Customer;
use App\Models\Person;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Modelo de persona compartido (B-11).
 *
 * *"Cliente, proveedor y empleado comparten la entidad persona. Sin duplicación
 * cuando un mismo actor cumple dos roles."*
 *
 * Lo que compra esta normalización se ve al cambiar un teléfono: sin ella hay
 * que recordar en cuántas tablas está la misma persona, y la respuesta siempre
 * es "en una más de las que creías".
 */
class SharedPersonTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
    }

    public function test_el_proveedor_que_tambien_compra_es_una_sola_persona(): void
    {
        $cedula = '001-010180-0001A';

        $supplier = Supplier::createWithPerson(
            ['full_name' => 'Ferretería El Tornillo', 'kind' => 'legal', 'national_id' => $cedula],
            ['code' => 'PROV-001', 'kind' => Supplier::MERCHANDISE]
        );

        // El mismo actor abre cuenta como cliente. La cédula lo delata.
        $customer = Customer::createWithPerson(
            ['full_name' => 'Ferretería El Tornillo', 'national_id' => $cedula],
            ['kind' => 'account', 'code' => 'CLI-001']
        );

        $this->assertSame($supplier->person_id, $customer->person_id);
        // Una sola identidad para esa cédula, no dos que nadie reconcilia.
        $this->assertSame(1, Person::where('national_id', $cedula)->count());
    }

    public function test_cambiar_un_dato_lo_cambia_para_todos_los_roles(): void
    {
        $cedula = '001-020290-0002B';

        $supplier = Supplier::createWithPerson(
            ['full_name' => 'Juan Pérez', 'national_id' => $cedula, 'phone' => '8888-1111'],
            ['code' => 'PROV-002', 'kind' => Supplier::EXPENSE]
        );

        $customer = Customer::createWithPerson(
            ['full_name' => 'Juan Pérez', 'national_id' => $cedula],
            ['kind' => 'account']
        );

        $supplier->person->update(['phone' => '8888-2222']);

        // Un solo cambio, no tres. Es toda la promesa de B-11.
        $this->assertSame('8888-2222', $customer->fresh()->phone);
    }

    public function test_el_alta_completa_los_huecos_sin_pisar_lo_que_ya_habia(): void
    {
        $cedula = '001-030390-0003C';

        Customer::createWithPerson(
            ['full_name' => 'María López', 'national_id' => $cedula, 'phone' => '8888-3333'],
            ['kind' => 'account']
        );

        // El alta como proveedora trae correo nuevo y un teléfono distinto.
        $supplier = Supplier::createWithPerson(
            ['full_name' => 'María López', 'national_id' => $cedula, 'phone' => '7777-9999', 'email' => 'maria@ejemplo.ni'],
            ['code' => 'PROV-003', 'kind' => Supplier::EXPENSE]
        );

        $person = $supplier->person->fresh();

        // El correo faltaba: se completa. El teléfono ya estaba: no se pisa —
        // el otro rol pudo haberlo cargado con más cuidado.
        $this->assertSame('maria@ejemplo.ni', $person->email);
        $this->assertSame('8888-3333', $person->phone);
    }

    public function test_sin_cedula_cada_alta_es_una_persona_distinta(): void
    {
        // Dos clientes de efectivo que se llaman igual no son la misma persona,
        // y fusionarlos por nombre sería exactamente lo que Q-04 prohíbe.
        $uno = Customer::createWithPerson(['full_name' => 'Cliente de mostrador'], ['kind' => 'cash']);
        $dos = Customer::createWithPerson(['full_name' => 'Cliente de mostrador'], ['kind' => 'cash']);

        $this->assertNotSame($uno->person_id, $dos->person_id);
        $this->assertSame(2, Person::where('full_name', 'Cliente de mostrador')->count());
    }

    public function test_la_persona_sabe_qué_roles_cumple(): void
    {
        $cedula = '001-040490-0004D';

        $employee = $this->employee('CAJ50', '1111', 'cashier');
        $employee->person->update(['national_id' => $cedula]);

        Customer::createWithPerson(
            ['full_name' => $employee->full_name, 'national_id' => $cedula],
            ['kind' => 'account']
        );

        // La pantalla de persona lo usa para no ofrecer un alta que ya existe.
        $this->assertEqualsCanonicalizing(
            ['customer', 'employee'],
            $employee->person->fresh()->roles()
        );
    }

    public function test_la_cedula_no_se_repite_entre_personas(): void
    {
        Customer::createWithPerson(
            ['full_name' => 'Primero', 'national_id' => '001-050590-0005E'],
            ['kind' => 'account']
        );

        // El índice parcial lo impide en la base, no solo en el servicio.
        $this->expectExceptionMessageMatches('/cmn_persons_national_id_unique/');

        Person::create(['full_name' => 'Segundo', 'national_id' => '001-050590-0005E']);
    }

    public function test_el_proveedor_de_gastos_se_distingue_del_de_mercaderia(): void
    {
        // B-12: sin esa separación el reporte de compras incluye la factura del
        // agua, y entonces el margen deja de significar nada.
        Supplier::createWithPerson(['full_name' => 'Distribuidora'], ['code' => 'P1', 'kind' => Supplier::MERCHANDISE]);
        Supplier::createWithPerson(['full_name' => 'ENACAL'], ['code' => 'P2', 'kind' => Supplier::EXPENSE]);

        $this->assertSame(1, Supplier::where('kind', Supplier::MERCHANDISE)->count());
        $this->assertSame(1, Supplier::where('kind', Supplier::EXPENSE)->count());
    }

    public function test_se_busca_por_nombre_o_por_documento(): void
    {
        Customer::createWithPerson(
            ['full_name' => 'Ferretería El Tornillo', 'national_id' => '001-060690-0006F'],
            ['kind' => 'account']
        );

        // Es como busca un cajero: escribe lo que el cliente le dice.
        $this->assertSame(1, Customer::search('Tornillo')->count());
        $this->assertSame(1, Customer::search('060690')->count());
        $this->assertSame(0, Customer::search('Inexistente')->count());
    }
}
