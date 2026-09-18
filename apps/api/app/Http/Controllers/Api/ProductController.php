<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Product;
use App\Services\Catalog\ProductAttributeValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Catálogo de ítems: productos y servicios en la misma tabla (D-07).
 *
 * Los atributos dinámicos se validan contra el `attribute_schema` del perfil de
 * negocio (D-24). Que exista un esquema es lo que separa la columna JSON de un
 * campo de texto libre donde cada sucursal escribiría "vencimiento", "vto" y
 * "fecha_venc" para lo mismo.
 */
class ProductController extends Controller
{
    public function __construct(private ProductAttributeValidator $attributes) {}

    public function index(Request $request)
    {
        $items = Product::query()
            // Los grupos de modificadores viajan con el catálogo porque la caja
            // busca y vende **sin red** (D-04): preguntar por ellos al tocar el
            // plato sería justo la consulta que el catálogo cacheado evita, y en
            // el salón ocurre en cada pedido.
            ->with([
                'uom:id,code,name',
                'taxCode:id,code,rate',
                'category:id,name',
                'modifierGroups.modifiers',
                // Los códigos de barras viajan con el catálogo por la misma
                // razón que los modificadores: **sin servidor, el lector es la
                // única forma de agregar** en un perfil `scan_first`, y un
                // catálogo cacheado sin códigos deja la caja mirando cómo el
                // cliente espera. Son dos columnas por producto.
                'barcodes:id,product_id,code,embedded',
            ])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = trim($request->string('search')->toString());
                $query->where(fn ($q) => $q
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('sku', 'ilike', "%{$term}%"));
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->string('category_id')))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->limit((int) $request->integer('limit', 200))
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('catalog.no_products'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('catalog.products_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function show(string $id)
    {
        $item = Product::with([
            'uom', 'taxCode', 'category', 'barcodes', 'uoms.uom',
            'components.component:id,sku,name,price',
            'modifierGroups.modifiers',
        ])
            ->find($id);

        if (! $item) {
            return response()->json(['message' => __('catalog.product_not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('catalog.product_retrieved'),
            'data' => $item,
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        if ($readonly = $this->erpOwnsCatalogue()) {
            return $readonly;
        }

        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();
        $data['attributes'] = $this->attributes->validate(
            $data['attributes'] ?? [],
            $this->profile($request)
        );

        $item = Product::create($data);

        return response()->json([
            'message' => __('catalog.product_created'),
            'data' => $item,
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        if ($readonly = $this->erpOwnsCatalogue()) {
            return $readonly;
        }

        $item = Product::find($id);

        if (! $item) {
            return response()->json(['message' => __('catalog.product_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), $this->rules($id, sometimes: true));

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        if (array_key_exists('attributes', $data)) {
            $data['attributes'] = $this->attributes->validate($data['attributes'] ?? [], $this->profile($request));
        }

        $item->update($data);

        return response()->json([
            'message' => __('catalog.product_updated'),
            'data' => $item->fresh(),
            'status' => 200,
        ], 200);
    }

    /**
     * Baja lógica, nunca borrado.
     *
     * Un producto vendido alguna vez está referenciado por líneas de venta
     * inmutables: borrarlo dejaría el histórico sin explicación.
     */
    public function destroy(string $id)
    {
        if ($readonly = $this->erpOwnsCatalogue()) {
            return $readonly;
        }

        $item = Product::find($id);

        if (! $item) {
            return response()->json(['message' => __('catalog.product_not_found'), 'status' => 404], 404);
        }

        $item->update(['is_active' => false]);

        return response()->json(['message' => __('catalog.product_deactivated'), 'status' => 200], 200);
    }

    /**
     * Con ERP integrado, el catálogo es del ERP y el POS solo lo lee (P2, §12.1).
     *
     * La regla vive en el servidor y no solo en la pantalla: una regla que solo
     * existe en la interfaz se salta con la primera llamada directa, y el precio
     * corregido acá lo pisaría la siguiente sincronización sin dejar rastro de
     * por qué.
     */
    private function erpOwnsCatalogue()
    {
        if (empty(config('pos.erp.base_url')) || empty(config('pos.erp.token'))) {
            return null;
        }

        return response()->json([
            'message' => __('catalog.owned_by_erp'),
            'status' => 422,
        ], 422);
    }

    /** @return array<string,mixed> */
    private function rules(?string $id = null, bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';
        $unique = 'unique:cat_products,sku'.($id ? ",{$id}" : '');

        return [
            'sku' => $prefix.'required|string|max:50|'.$unique,
            'name' => $prefix.'required|string|max:200',
            'description' => 'nullable|string',
            'category_id' => 'nullable|uuid|exists:cat_categories,id',
            'uom_id' => $prefix.'required|uuid|exists:cat_uoms,id',
            'tax_code_id' => 'nullable|uuid|exists:cat_tax_codes,id',
            'price' => $prefix.'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'is_exempt' => 'boolean',
            'tracks_stock' => 'boolean',
            'tracks_lots' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'min_stock' => 'nullable|numeric|min:0',
            'is_composite' => 'boolean',
            'sells_as_pack' => 'boolean',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
        ];
    }

    private function profile(Request $request): ?BusinessProfile
    {
        $code = config('pos.business_profile', 'retail');

        return BusinessProfile::where('code', $code)->first();
    }
}
