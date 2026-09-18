<?php

namespace Tests\Feature\Import;

use App\Exports\RowsExport;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Import\ImporterRegistry;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Importación masiva con previsualización y reversión (D-15, H1.6).
 *
 * Criterio de aceptación del hito, literal: **importar 1.000 productos, revisar
 * y revertir por completo**.
 *
 * Lo que se prueba aquí no es "que cargue", sino las tres cosas que OSPOS no
 * tiene: que **no escriba nada** al previsualizar, que **explique** qué va a
 * pasar antes de que pase, y que se pueda **deshacer**.
 */
class ImportTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        // Importar es del encargado, no del cajero: carga masiva sobre el
        // catálogo no es una operación de mostrador.
        $this->manager = $this->employee('ENC01', '4321', 'manager');
        $this->token = $this->signedIn($this->manager, '4321');
    }

    /**
     * @param  array<int,string>  $headings
     * @param  array<int,array<int,mixed>>  $rows
     */
    private function excel(array $headings, array $rows): UploadedFile
    {
        Storage::fake('local');
        $path = 'imports/'.Str::uuid7().'.xlsx';

        Excel::store(new RowsExport($headings, $rows), $path, 'local');

        return new UploadedFile(
            Storage::disk('local')->path($path),
            'carga.xlsx',
            null,
            null,
            true
        );
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function preview(string $kind, array $headings, array $rows): array
    {
        return $this->actingAsTerminal($this->token)
            ->post("/api/imports/{$kind}/preview", ['file' => $this->excel($headings, $rows)])
            ->assertCreated()
            ->json('data');
    }

    private const PRODUCT_HEADERS = [
        'sku', 'nombre', 'precio', 'costo', 'unidad', 'impuesto',
        'categoria', 'codigo_barras', 'maneja_stock', 'stock_minimo',
    ];

    public function test_previsualizar_no_escribe_nada(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz 1 lb', '25.00', '18.00', 'UND', 'IVA', 'Abarrotes', '7501234567890', 'si', '10'],
            ['P-002', 'Aceite 1 L', '90.00', '70.00', 'UND', 'IVA', 'Abarrotes', '', 'si', '5'],
        ]);

        $this->assertSame('previewed', $batch['status']);
        $this->assertSame(2, $batch['rows_total']);
        $this->assertSame(2, $batch['rows_valid']);

        // El paso que OSPOS no tiene: mirar antes de que pase.
        $this->assertSame(0, Product::count());
    }

    public function test_la_previsualizacion_explica_cada_problema_con_su_fila(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz 1 lb', '25.00', '18.00', 'UND', 'IVA', '', '', 'si', ''],
            ['', 'Sin código', '10.00', '', 'UND', 'IVA', '', '', 'si', ''],
            ['P-003', 'Precio raro', 'ochenta', '', 'UND', 'IVA', '', '', 'si', ''],
            ['P-004', 'Unidad inventada', '10.00', '', 'QQQ', 'IVA', '', '', 'si', ''],
        ]);

        $this->assertSame(1, $batch['rows_valid']);
        $this->assertSame(3, $batch['rows_invalid']);

        $problems = collect($batch['problems'])->keyBy('row_number');

        // El número coincide con lo que el usuario ve en Excel: la fila 1 son
        // los encabezados.
        $this->assertSame([3, 4, 5], $problems->keys()->sort()->values()->all());
        $this->assertStringContainsString('sku', $problems[3]['errors'][0]);
        $this->assertStringContainsString('precio', $problems[4]['errors'][0]);
        $this->assertStringContainsString('QQQ', $problems[5]['errors'][0]);
    }

    public function test_aplicar_carga_solo_las_filas_buenas(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz 1 lb', '25.00', '18.00', 'UND', 'IVA', 'Abarrotes', '7501234567890', 'si', '10'],
            ['', 'Sin código', '10.00', '', 'UND', 'IVA', '', '', 'si', ''],
            ['P-003', 'Frijol 1 lb', '30.00', '', 'UND', 'IVA', 'Abarrotes', '', 'si', ''],
        ]);

        $applied = $this->actingAsTerminal($this->token)
            ->postJson("/api/imports/{$batch['id']}/apply")
            ->assertOk()
            ->json('data');

        // Detener la carga por una fila mala obligaría a corregir el archivo
        // entero para poder cargar las que sí estaban bien.
        $this->assertSame('applied', $applied['status']);
        $this->assertSame(2, $applied['rows_applied']);
        $this->assertSame(2, Product::count());
        $this->assertSame('25.0000', (string) Product::where('sku', 'P-001')->value('price'));
        $this->assertDatabaseHas('cat_barcodes', ['code' => '7501234567890']);
    }

    public function test_reimportar_el_mismo_archivo_actualiza_en_vez_de_duplicar(): void
    {
        $first = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz 1 lb', '25.00', '', 'UND', 'IVA', '', '', 'si', ''],
        ]);
        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$first['id']}/apply")->assertOk();

        // Nadie acierta el archivo a la primera: el SKU es la clave natural que
        // hace que el segundo intento corrija en vez de duplicar.
        $second = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz 1 libra', '27.50', '', 'UND', 'IVA', '', '', 'si', ''],
        ]);

        $this->assertSame(1, collect($second)->get('rows_valid'));

        $rows = $this->actingAsTerminal($this->token)
            ->getJson("/api/imports/{$second['id']}")->json('data.rows');
        $this->assertSame('update', $rows[0]['action']);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$second['id']}/apply")->assertOk();

        $this->assertSame(1, Product::count());
        $this->assertSame('Arroz 1 libra', Product::where('sku', 'P-001')->value('name'));
    }

    public function test_revertir_una_carga_nueva_borra_lo_cargado(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz', '25.00', '', 'UND', 'IVA', '', '', 'si', ''],
            ['P-002', 'Frijol', '30.00', '', 'UND', 'IVA', '', '', 'si', ''],
        ]);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();
        $this->assertSame(2, Product::count());

        $reverted = $this->actingAsTerminal($this->token)
            ->postJson("/api/imports/{$batch['id']}/revert")
            ->assertOk()
            ->json('data');

        $this->assertSame('reverted', $reverted['status']);
        $this->assertSame(0, Product::count());
    }

    public function test_revertir_una_actualizacion_restaura_lo_que_habia(): void
    {
        $original = $this->product('P-001', '25.00');

        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Nombre nuevo', '99.00', '', 'UND', 'IVA', '', '', 'si', ''],
        ]);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();
        $this->assertSame('99.0000', (string) $original->fresh()->price);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/revert")->assertOk();

        // Sin la foto de cómo estaba antes, deshacer esto sería adivinar.
        $this->assertSame('25.0000', (string) $original->fresh()->price);
        $this->assertSame('Producto P-001', $original->fresh()->name);
    }

    public function test_un_producto_ya_vendido_se_da_de_baja_en_vez_de_borrarse(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz', '25.00', '', 'UND', 'IVA', '', '', 'no', ''],
        ]);
        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();

        $product = Product::where('sku', 'P-001')->firstOrFail();

        // Se vende. A partir de aquí su línea de venta es inmutable.
        $cashierToken = $this->signedIn();
        $sale = $this->actingAsTerminal($cashierToken)->postJson('/api/sales', [])->json('data.id');
        $this->actingAsTerminal($cashierToken)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        // Reautenticar la terminal invalidó el token anterior —es un token por
        // terminal, para que un equipo robado deje de servir en cuanto el local
        // vuelve a abrir— así que el encargado vuelve a entrar.
        $this->token = $this->signedIn($this->manager, '4321');

        $result = $this->actingAsTerminal($this->token)
            ->postJson("/api/imports/{$batch['id']}/revert")
            ->assertOk()
            ->json('data');

        // Borrarlo dejaría el histórico apuntando al vacío. Se da de baja y la
        // fila queda marcada con el motivo, en vez de fallar en silencio.
        $this->assertNotNull(Product::find($product->id));
        $this->assertFalse((bool) $product->fresh()->is_active);
        $this->assertStringContainsString('P-001', $result['rows'][0]['revert_error']);
    }

    public function test_los_clientes_con_cuenta_exigen_cedula(): void
    {
        $headers = [
            'nombre', 'tipo', 'cedula', 'ruc', 'correo', 'telefono',
            'whatsapp', 'direccion', 'limite_credito', 'exonerado',
        ];

        $batch = $this->preview('customers', $headers, [
            ['Don Julio', 'cuenta', '001-010180-0001A', '', '', '8888-1111', '', '', '5000', 'no'],
            ['Sin cédula', 'cuenta', '', '', '', '', '', '', '2000', 'no'],
            ['Cliente de mostrador', 'efectivo', '', '', '', '', '', '', '', 'no'],
        ]);

        // El de efectivo paga y se va; pedirle cédula sería inventar un
        // requisito que el mostrador no tiene (P-03).
        $this->assertSame(2, $batch['rows_valid']);
        $this->assertSame(1, $batch['rows_invalid']);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();

        $this->assertSame(2, Customer::count());
        $this->assertSame('5000.00', (string) Customer::whereHas(
            'person', fn ($q) => $q->where('national_id', '001-010180-0001A')
        )->first()->creditAccount->credit_limit);
    }

    public function test_un_proveedor_que_ya_es_cliente_reutiliza_su_persona(): void
    {
        $cedula = '001-020290-0002B';

        Customer::createWithPerson(
            ['full_name' => 'Ferretería El Tornillo', 'national_id' => $cedula],
            ['kind' => 'account']
        );

        $batch = $this->preview('suppliers', [
            'nombre', 'tipo', 'codigo', 'cedula', 'ruc', 'correo',
            'telefono', 'direccion', 'contacto', 'dias_credito',
        ], [
            ['Ferretería El Tornillo', 'mercaderia', 'PROV-01', $cedula, '', 'ventas@tornillo.ni', '', '', 'Marta', '30'],
        ]);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();

        // B-11: el mismo actor con dos roles, no dos identidades que nadie
        // reconcilia.
        $this->assertSame(1, Person::where('national_id', $cedula)->count());
        $this->assertSame(
            Customer::first()->person_id,
            Supplier::first()->person_id
        );
        // El correo faltaba en la persona: la carga lo completa.
        $this->assertSame('ventas@tornillo.ni', Supplier::first()->email);
    }

    public function test_la_carga_de_existencias_se_revierte_compensando_no_borrando(): void
    {
        $product = $this->product('P-100', '50.00');
        $ledger = app(StockLedgerService::class);

        $batch = $this->preview('stock', ['sku', 'cantidad', 'ubicacion', 'costo_unitario', 'lote', 'vence'], [
            ['P-100', '120', 'PRINCIPAL', '30.00', '', ''],
        ]);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();
        $this->assertSame('120.0000', $ledger->available($product->id, $this->location->id));

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/revert")->assertOk();

        // El kardex es de solo inserción: el asiento original se queda donde
        // está y se le suma su contrario. El histórico cuenta lo que pasó,
        // incluido el error.
        $this->assertSame('0.0000', $ledger->available($product->id, $this->location->id));
        $this->assertDatabaseCount('inv_movements', 2);
    }

    public function test_un_archivo_sin_las_columnas_obligatorias_se_rechaza_entero(): void
    {
        $file = $this->excel(['nombre', 'precio'], [['Arroz', '25.00']]);

        $this->actingAsTerminal($this->token)
            ->post('/api/imports/products/preview', ['file' => $file])
            ->assertStatus(422);

        // Nada se creó: ni el lote de importación.
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_los_encabezados_se_normalizan(): void
    {
        // El archivo lo arma una persona: "Código", "PRECIO", "Maneja Stock".
        // Rechazarlo por eso le haría perder la tarde.
        $batch = $this->preview('products', [
            'SKU', 'Nombre', 'Precio', 'Costo', 'Unidad', 'Impuesto',
            'Categoría', 'Código Barras', 'Maneja Stock', 'Stock Mínimo',
        ], [
            ['P-001', 'Arroz', '25.00', '', 'UND', 'IVA', '', '', 'Sí', ''],
        ]);

        $this->assertSame(1, $batch['rows_valid']);
    }

    public function test_los_numeros_con_coma_decimal_se_entienden(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz', '1.234,56', '', 'UND', 'IVA', '', '', 'si', ''],
        ]);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();

        // Excel en español produce esto y rechazarlo sería absurdo.
        $this->assertSame('1234.5600', (string) Product::where('sku', 'P-001')->value('price'));
    }

    public function test_no_se_aplica_dos_veces_la_misma_carga(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz', '25.00', '', 'UND', 'IVA', '', '', 'si', ''],
        ]);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/imports/{$batch['id']}/apply")
            ->assertStatus(422);

        $this->assertSame(1, Product::count());
    }

    public function test_la_carga_queda_en_la_bitacora(): void
    {
        $batch = $this->preview('products', self::PRODUCT_HEADERS, [
            ['P-001', 'Arroz', '25.00', '', 'UND', 'IVA', '', '', 'si', ''],
        ]);

        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/apply")->assertOk();
        $this->actingAsTerminal($this->token)->postJson("/api/imports/{$batch['id']}/revert")->assertOk();

        $this->assertDatabaseHas('sec_audit_log', ['event' => 'import.applied']);
        $this->assertDatabaseHas('sec_audit_log', ['event' => 'import.reverted']);
    }

    public function test_se_descarga_la_plantilla_de_cada_tipo(): void
    {
        foreach (['products', 'customers', 'suppliers', 'stock'] as $kind) {
            $this->actingAsTerminal($this->token)
                ->get("/api/imports/templates/{$kind}")
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    public function test_lo_exportado_se_puede_volver_a_importar(): void
    {
        $this->product('P-001', '25.00');

        // Bajar, corregir en Excel y volver a subir es la forma real de editar
        // mil productos. Solo funciona si los formatos coinciden.
        $this->actingAsTerminal($this->token)->get('/api/exports/products')->assertOk();

        $this->assertSame(
            array_keys(app(ImporterRegistry::class)->template('products')),
            self::PRODUCT_HEADERS
        );
    }
}
