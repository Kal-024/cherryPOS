<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Categorías del catálogo.
 *
 * Existen por dos razones distintas y las dos mandan sobre el diseño: agrupan
 * el catálogo para la trastienda y **son la cuadrícula** del perfil
 * `touch_grid`, donde cada categoría es una pestaña con su color (§10).
 *
 * Se mantienen desde la administración, no desde la caja: al cargar un producto
 * el supervisor necesita poder crear la categoría que falta sin salir de la
 * pantalla, o el formulario queda en un callejón sin salida.
 */
class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $items = Category::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('catalog.no_categories'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('catalog.categories_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $item = Category::create($validator->validated());

        return response()->json([
            'message' => __('catalog.category_created'),
            'data' => $item,
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $item = Category::find($id);

        if (! $item) {
            return response()->json(['message' => __('catalog.category_not_found'), 'status' => 404], 404);
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

        // Una categoría no puede ser su propia madre: el árbol dejaría de serlo
        // y la cuadrícula del perfil táctil entraría en recursión infinita.
        if (($data['parent_id'] ?? null) === $item->id) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => ['parent_id' => [__('catalog.category_parent_self')]],
                'status' => 422,
            ], 422);
        }

        $item->update($data);

        return response()->json([
            'message' => __('catalog.category_updated'),
            'data' => $item->fresh(),
            'status' => 200,
        ], 200);
    }

    /**
     * Baja lógica, nunca borrado — misma razón que en productos: las líneas de
     * venta ya emitidas la referencian y son inmutables (P1).
     */
    public function destroy(string $id)
    {
        $item = Category::find($id);

        if (! $item) {
            return response()->json(['message' => __('catalog.category_not_found'), 'status' => 404], 404);
        }

        $item->update(['is_active' => false]);

        return response()->json(['message' => __('catalog.category_deactivated'), 'status' => 200], 200);
    }

    /** @return array<string,mixed> */
    private function rules(?string $id = null, bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';
        $unique = 'unique:cat_categories,code'.($id ? ",{$id}" : '');

        return [
            'code' => $prefix.'required|string|max:50|'.$unique,
            'name' => $prefix.'required|string|max:120',
            'parent_id' => 'nullable|uuid|exists:cat_categories,id',
            // Color de la pestaña en `touch_grid`; hexadecimal para que el
            // terminal lo pinte sin traducir nada.
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ];
    }
}
