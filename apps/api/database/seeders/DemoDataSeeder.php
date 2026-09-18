<?php

namespace Database\Seeders;

use App\Models\Barcode;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DiningArea;
use App\Models\DiningTable;
use App\Models\Location;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\TaxCode;
use App\Models\Terminal;
use App\Models\Uom;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de demostración para el guion de la compuerta de F1-A.
 *
 * **No entra en `DatabaseSeeder`**: una instalación real arranca con el catálogo
 * vacío y lo carga el cliente, normalmente importando desde Excel. Esto existe
 * para que la compuerta —manual o automatizada— tenga siempre los mismos cinco
 * productos con los mismos códigos de barras, y para que "vender cinco
 * productos, uno exento" no dependa de lo que alguien haya cargado a mano.
 *
 * Se niega a correr fuera de desarrollo y pruebas: un catálogo de juguete
 * mezclado con el real es imposible de separar después.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoDataSeeder no corre en producción.');

            return;
        }

        $branch = Branch::firstOrFail();
        $location = Location::where('branch_id', $branch->id)->firstOrFail();
        $unit = Uom::where('code', 'UND')->firstOrFail();
        $pound = Uom::where('code', 'LB')->firstOrFail();
        $iva = TaxCode::where('code', 'IVA')->firstOrFail();
        $exempt = TaxCode::where('code', 'EXE')->firstOrFail();

        $category = Category::updateOrCreate(
            ['code' => 'ABARROTES'],
            ['name' => 'Abarrotes', 'sort_order' => 1, 'is_active' => true]
        );

        // Precios con el IVA adentro: el de góndola es lo que paga el cliente y
        // el motor lo extrae al facturar.
        $catalogue = [
            ['sku' => 'DEMO-001', 'name' => 'Gaseosa 1.5 L', 'price' => '45.00', 'code' => '7501234567890', 'tax' => $iva, 'uom' => $unit],
            ['sku' => 'DEMO-002', 'name' => 'Café molido 500 g', 'price' => '180.00', 'code' => '7501111111111', 'tax' => $iva, 'uom' => $unit],
            ['sku' => 'DEMO-003', 'name' => 'Jabón de baño', 'price' => '32.50', 'code' => '7504444444444', 'tax' => $iva, 'uom' => $unit],
            ['sku' => 'DEMO-004', 'name' => 'Queso seco', 'price' => '160.00', 'code' => '7505555555555', 'tax' => $iva, 'uom' => $pound],
            // El exento es obligatorio en el guion: sin producto exento, una
            // farmacia no opera, y el libro de ventas lo declara aparte de la
            // base gravada.
            ['sku' => 'DEMO-005', 'name' => 'Arroz 1 lb (exento)', 'price' => '28.00', 'code' => '7506666666666', 'tax' => $exempt, 'uom' => $pound, 'exempt' => true],
        ];

        $ledger = app(StockLedgerService::class);

        foreach ($catalogue as $item) {
            $product = Product::updateOrCreate(
                ['sku' => $item['sku']],
                [
                    'name' => $item['name'],
                    'category_id' => $category->id,
                    'uom_id' => $item['uom']->id,
                    'tax_code_id' => $item['tax']->id,
                    'price' => $item['price'],
                    'cost' => bcmul($item['price'], '0.6', 2),
                    'is_exempt' => $item['exempt'] ?? false,
                    'tracks_stock' => true,
                    'allow_negative_stock' => false,
                    'min_stock' => '5',
                    'is_active' => true,
                ]
            );

            Barcode::updateOrCreate(
                ['code' => $item['code']],
                ['product_id' => $product->id, 'embedded' => 'none', 'is_primary' => true]
            );

            // Existencia inicial como movimiento, nunca como campo: el stock es
            // la suma del libro (B-03).
            $ledger->record([
                'branch_id' => $branch->id,
                'location_id' => $location->id,
                'product_id' => $product->id,
                'qty' => '100',
                'unit_cost' => bcmul($item['price'], '0.6', 2),
                'reason' => 'receipt',
                'comment' => 'Existencia inicial de demostración',
                'occurred_at' => now(),
            ]);
        }

        $this->diningRoom($branch->id, $category->id, $iva->id, $unit->id, $location->id, $ledger);

        // Un cliente con cuenta, para el paso de crédito y para el estado de
        // cuenta. La cédula es obligatoria en esta clase (G-10).
        Customer::where('code', 'DEMO-CLI')->first() ?? Customer::createWithPerson(
            ['full_name' => 'Rosa Martínez', 'national_id' => '001-010180-0001R', 'phone' => '88887777'],
            ['kind' => 'account', 'code' => 'DEMO-CLI', 'is_active' => true]
        );

        $this->command?->info('Catálogo de demostración cargado: 5 productos, uno exento.');
    }

    /**
     * El salón de demostración (F1-B).
     *
     * Cuatro mesas con su lugar en el plano, un plato con término obligatorio y
     * una cerveza que sale de la barra: lo mínimo para que el guion del
     * restaurante tenga siempre el mismo salón debajo.
     */
    private function diningRoom(
        string $branchId,
        string $categoryId,
        string $ivaId,
        string $unitId,
        string $locationId,
        StockLedgerService $ledger,
    ): void {
        /*
         * Una segunda terminal con perfil `restaurant`.
         *
         * El vocabulario es del **perfil de pantalla** (A-04): en esta caja la
         * misma acción se llama "abrir cuenta" y en la del mostrador, "suspender
         * venta". Tener las dos sembradas es lo que permite verlo sin reconfigurar
         * nada.
         */
        Terminal::updateOrCreate(
            ['branch_id' => $branchId, 'code' => 'SALON-01'],
            [
                'name' => 'Caja del salón',
                'secret_hash' => Hash::make('terminal-dev'),
                'layout_profile' => 'restaurant',
                'is_active' => true,
            ]
        );

        $area = DiningArea::updateOrCreate(
            ['branch_id' => $branchId, 'code' => 'SALON'],
            ['name' => 'Salón principal', 'sort_order' => 1, 'is_active' => true]
        );

        foreach ([
            ['code' => 'M1', 'name' => 'Mesa 1', 'seats' => 4, 'pos_x' => 40, 'pos_y' => 40],
            ['code' => 'M2', 'name' => 'Mesa 2', 'seats' => 2, 'pos_x' => 200, 'pos_y' => 40],
            ['code' => 'M3', 'name' => 'Mesa 3', 'seats' => 6, 'pos_x' => 40, 'pos_y' => 200],
            ['code' => 'M4', 'name' => 'Mesa 4', 'seats' => 4, 'pos_x' => 200, 'pos_y' => 200],
        ] as $table) {
            DiningTable::updateOrCreate(
                ['branch_id' => $branchId, 'code' => $table['code']],
                $table + ['area_id' => $area->id, 'shape' => 'square', 'is_active' => true]
            );
        }

        $doneness = ModifierGroup::updateOrCreate(
            ['code' => 'termino'],
            ['name' => 'Término', 'min_select' => 1, 'max_select' => 1, 'sort_order' => 0, 'is_active' => true]
        );

        foreach ([['medio', 'Término medio'], ['tres-cuartos', 'Tres cuartos'], ['bien', 'Bien cocido']] as [$code, $name]) {
            Modifier::updateOrCreate(
                ['group_id' => $doneness->id, 'code' => $code],
                ['name' => $name, 'price_delta' => '0.00', 'is_active' => true]
            );
        }

        $extras = ModifierGroup::updateOrCreate(
            ['code' => 'extras'],
            ['name' => 'Extras', 'min_select' => 0, 'max_select' => null, 'sort_order' => 1, 'is_active' => true]
        );

        Modifier::updateOrCreate(
            ['group_id' => $extras->id, 'code' => 'queso'],
            ['name' => 'Doble queso', 'price_delta' => '45.00', 'is_active' => true]
        );

        $steak = Product::updateOrCreate(
            ['sku' => 'DEMO-PLATO'],
            [
                'name' => 'Lomo a la plancha',
                'category_id' => $categoryId,
                'uom_id' => $unitId,
                'tax_code_id' => $ivaId,
                'price' => '350.00',
                'cost' => '180.00',
                'tracks_stock' => false,
                // Sale de la cocina: va a comanda.
                'prep_station' => 'kitchen',
                'is_active' => true,
            ]
        );

        DB::table('cat_product_modifier_group')->updateOrInsert(
            ['product_id' => $steak->id, 'group_id' => $doneness->id],
            ['sort_order' => 0]
        );
        DB::table('cat_product_modifier_group')->updateOrInsert(
            ['product_id' => $steak->id, 'group_id' => $extras->id],
            ['sort_order' => 1]
        );

        // Un postre para poder ver los cursos: se pide con el resto y sale
        // después (G-16).
        Product::updateOrCreate(
            ['sku' => 'DEMO-POSTRE'],
            [
                'name' => 'Tres leches',
                'category_id' => $categoryId,
                'uom_id' => $unitId,
                'tax_code_id' => $ivaId,
                'price' => '150.00',
                'cost' => '60.00',
                'tracks_stock' => false,
                'prep_station' => 'kitchen',
                'is_active' => true,
            ]
        );

        $beer = Product::updateOrCreate(
            ['sku' => 'DEMO-BEBIDA'],
            [
                'name' => 'Cerveza',
                'category_id' => $categoryId,
                'uom_id' => $unitId,
                'tax_code_id' => $ivaId,
                'price' => '60.00',
                'cost' => '30.00',
                'tracks_stock' => true,
                // La cerveza no espera al lomo: la prepara la barra.
                'prep_station' => 'bar',
                'is_active' => true,
            ]
        );

        $ledger->record([
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'product_id' => $beer->id,
            'qty' => '200',
            'unit_cost' => '30.00',
            'reason' => 'receipt',
            'comment' => 'Existencia inicial de demostración',
            'occurred_at' => now(),
        ]);
    }
}
