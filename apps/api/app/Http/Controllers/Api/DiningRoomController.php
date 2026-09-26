<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiningArea;
use App\Models\DiningTable;
use App\Services\Dining\DiningRoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * El salón: mapa de mesas y cuentas abiertas (F1-B, §10).
 *
 * El mapa es **una consulta que trae todo**, porque la pantalla del salón se
 * refresca por sondeo cada pocos segundos (G-13, R-01): pedir el estado mesa por
 * mesa multiplicaría eso por treinta sin ganar nada en una red local.
 *
 * Configurar el salón —crear mesas, moverlas, agrupar zonas— es otro permiso que
 * atenderlo: mover una mesa en el mapa no es una operación de turno, y un mesero
 * arrastrando mesas sin querer deja el plano irreconocible.
 */
class DiningRoomController extends Controller
{
    public function __construct(private DiningRoomService $dining) {}

    public function map(Request $request)
    {
        return response()->json([
            'message' => __('dining.map_retrieved'),
            'data' => $this->dining->map($request->attributes->get('branch_id')),
            'status' => 200,
        ], 200);
    }

    public function storeArea(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:80',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $area = DiningArea::create($validator->validated() + [
            'branch_id' => $request->attributes->get('branch_id'),
        ]);

        return response()->json([
            'message' => __('dining.area_created'),
            'data' => $area,
            'status' => 201,
        ], 201);
    }

    public function storeTable(Request $request)
    {
        $validator = Validator::make($request->all(), $this->tableRules($request));

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $table = DiningTable::create($validator->validated() + [
            'branch_id' => $request->attributes->get('branch_id'),
        ]);

        return response()->json([
            'message' => __('dining.table_created'),
            'data' => $this->dining->presentTable($table),
            'status' => 201,
        ], 201);
    }

    /** Editar incluye mover la mesa en el plano: es la misma operación. */
    public function updateTable(Request $request, string $id)
    {
        $table = $this->find($request, $id);

        if (! $table) {
            return response()->json(['message' => __('dining.table_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), $this->tableRules($request, $table->id, sometimes: true));

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $table->update($validator->validated());

        return response()->json([
            'message' => __('dining.table_updated'),
            'data' => $this->dining->presentTable($table->fresh()),
            'status' => 200,
        ], 200);
    }

    /**
     * Da de baja una mesa.
     *
     * Lógica, como todo lo demás: las ventas de ayer la referencian y el
     * histórico tiene que seguir diciendo en qué mesa se sirvió.
     */
    public function destroyTable(Request $request, string $id)
    {
        $table = $this->find($request, $id);

        if (! $table) {
            return response()->json(['message' => __('dining.table_not_found'), 'status' => 404], 404);
        }

        if ($table->openSale) {
            return response()->json(['message' => __('dining.table_busy'), 'status' => 422], 422);
        }

        $table->update(['is_active' => false]);

        return response()->json(['message' => __('dining.table_deactivated'), 'status' => 200], 200);
    }

    /** Abre la cuenta de la mesa: una venta suspendida, no una tabla espejo. */
    public function open(Request $request, string $id)
    {
        $table = $this->find($request, $id);

        if (! $table) {
            return response()->json(['message' => __('dining.table_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'guests' => 'nullable|integer|min:1|max:99',
            'label' => 'nullable|string|max:80',
            'waiter_employee_id' => 'nullable|uuid|exists:sec_employees,id',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $sale = $this->dining->openTable($table, $validator->validated() + [
            'terminal_id' => $request->user()->getKey(),
            'employee_id' => $request->attributes->get('employee_id'),
        ]);

        return response()->json([
            'message' => __('dining.table_opened'),
            'data' => ['sale' => $sale, 'table' => $this->dining->presentTable($table->fresh())],
            'status' => 201,
        ], 201);
    }

    public function merge(Request $request, string $id)
    {
        $table = $this->find($request, $id);

        if (! $table) {
            return response()->json(['message' => __('dining.table_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'tables' => 'required|array|min:1',
            'tables.*' => 'uuid|exists:pos_dining_tables,id',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $this->dining->merge($table, $validator->validated()['tables']);

        return response()->json([
            'message' => __('dining.tables_merged'),
            'data' => $this->dining->map($request->attributes->get('branch_id')),
            'status' => 200,
        ], 200);
    }

    /**
     * Anota algo sobre la cuenta de la mesa.
     *
     * Es del turno, no de la configuración: la escribe quien atiende
     * (`dining.serve`), en la tablet, mientras el cliente habla.
     */
    public function note(Request $request, string $id)
    {
        $table = $this->find($request, $id);

        if (! $table) {
            return response()->json(['message' => __('dining.table_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'present|nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $this->dining->setNote($table, $validator->validated()['notes']);

        return response()->json([
            'message' => __('dining.note_saved'),
            'data' => $this->dining->presentTable($table->fresh()),
            'status' => 200,
        ], 200);
    }

    public function split(Request $request, string $id)
    {
        $table = $this->find($request, $id);

        if (! $table) {
            return response()->json(['message' => __('dining.table_not_found'), 'status' => 404], 404);
        }

        $this->dining->split($table);

        return response()->json([
            'message' => __('dining.tables_split'),
            'data' => $this->dining->map($request->attributes->get('branch_id')),
            'status' => 200,
        ], 200);
    }

    /** @return array<string,mixed> */
    private function tableRules(Request $request, ?string $id = null, bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';
        $branchId = $request->attributes->get('branch_id');
        $unique = 'unique:pos_dining_tables,code,'.($id ?? 'NULL').',id,branch_id,'.$branchId;

        return [
            'code' => $prefix.'required|string|max:20|'.$unique,
            'name' => 'nullable|string|max:60',
            'area_id' => 'nullable|uuid|exists:pos_dining_areas,id',
            'seats' => 'nullable|integer|min:1|max:99',
            // La posición en el plano: sin ella el salón es una lista de
            // botones y el mesero traduce "Mesa 7" a un lugar físico de memoria.
            'pos_x' => 'nullable|integer',
            'pos_y' => 'nullable|integer',
            'shape' => 'nullable|in:square,round,rect',
            // El tamaño, en píxeles del plano. Con topes porque un local real no
            // tiene mesas de doce píxeles ni de mil: fuera de ese rango no es una
            // mesa, es un dedo que resbaló arrastrando la esquina.
            'width' => 'nullable|integer|min:60|max:600',
            'height' => 'nullable|integer|min:60|max:600',
            'is_active' => 'boolean',
        ];
    }

    /** @param array<string,mixed> $errors */
    private function invalid(array $errors)
    {
        return response()->json([
            'message' => __('validation.errors'),
            'errors' => $errors,
            'status' => 422,
        ], 422);
    }

    private function find(Request $request, string $id): ?DiningTable
    {
        return DiningTable::with('openSale')
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($id);
    }
}
