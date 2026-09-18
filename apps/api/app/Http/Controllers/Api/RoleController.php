<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Roles y sus permisos (D-13, B-13, H7).
 *
 * OSPOS asigna cada permiso persona por persona; con cuarenta empleados eso es
 * inmanejable y agregar roles después obliga a migrar todas las asignaciones.
 * Por eso el rol existe desde F1 y esta pantalla es la que lo hace usable.
 *
 * Tres reglas que el controlador hace cumplir y que no son de forma:
 *
 *  - **Los roles del sistema no se borran ni se renombran.** Hay código que los
 *    busca por `code`: `cashier`, `supervisor`, `manager`, `admin`.
 *  - **`admin` no se edita.** Es el rol que puede volver a otorgar cualquier
 *    permiso; permitir quitarle los suyos deja al negocio sin nadie capaz de
 *    arreglar el error, y en una instalación sin internet no hay a quién llamar.
 *  - **Un rol con empleados no se borra.** Borrarlo los dejaría sin permisos de
 *    un día para otro, en medio de un turno.
 */
class RoleController extends Controller
{
    /** El rol que nunca se toca: es la salida de emergencia del sistema. */
    private const PROTECTED_CODE = 'admin';

    public function __construct(private AuditLogger $audit) {}

    /** Catálogo de permisos, agrupado por módulo como se lee en pantalla. */
    public function permissions()
    {
        $items = Permission::orderBy('module')->orderBy('code')->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('auth.no_permissions'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('auth.permissions_retrieved'),
            'data' => $items->groupBy('module')->map(fn ($group) => $group->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'code' => $permission->code,
                'description' => $permission->description,
            ])->values()),
            'status' => 200,
        ], 200);
    }

    public function index()
    {
        $roles = Role::with('permissions:id,code')
            ->withCount('employees')
            ->orderBy('name')
            ->get();

        if ($roles->isEmpty()) {
            return response()->json(['message' => __('auth.no_roles'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('auth.roles_retrieved'),
            'data' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => (bool) $role->is_system,
                'is_protected' => $role->code === self::PROTECTED_CODE,
                'employees_count' => $role->employees_count,
                'permissions' => $role->permissions->pluck('code')->all(),
            ]),
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40|unique:sec_roles,code',
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:160',
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'string|exists:sec_permissions,code',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $role = Role::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $this->sync($role, $data['permissions']);
        $this->log('role.created', $role, ['permissions' => $data['permissions']]);

        return response()->json([
            'message' => __('auth.role_created'),
            'data' => $role->load('permissions:id,code'),
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json(['message' => __('auth.role_not_found'), 'status' => 404], 404);
        }

        if ($role->code === self::PROTECTED_CODE) {
            return response()->json(['message' => __('auth.role_protected'), 'status' => 422], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:80',
            'description' => 'nullable|string|max:160',
            'permissions' => 'sometimes|array|min:1',
            'permissions.*' => 'string|exists:sec_permissions,code',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        // Del rol del sistema se ajustan los permisos, nunca el nombre: hay
        // código y documentación que lo nombran.
        if ($role->is_system && array_key_exists('name', $data) && $data['name'] !== $role->name) {
            return response()->json(['message' => __('auth.role_system_readonly_name'), 'status' => 422], 422);
        }

        $before = $role->permissions->pluck('code')->all();

        $role->update(array_intersect_key($data, array_flip(['name', 'description'])));

        if (array_key_exists('permissions', $data)) {
            $this->sync($role, $data['permissions']);
            $this->log('role.permissions_changed', $role, [
                'before' => $before,
                'after' => $data['permissions'],
            ]);
        }

        return response()->json([
            'message' => __('auth.role_updated'),
            'data' => $role->fresh()->load('permissions:id,code'),
            'status' => 200,
        ], 200);
    }

    public function destroy(string $id)
    {
        $role = Role::withCount('employees')->find($id);

        if (! $role) {
            return response()->json(['message' => __('auth.role_not_found'), 'status' => 404], 404);
        }

        if ($role->is_system) {
            return response()->json(['message' => __('auth.role_system_undeletable'), 'status' => 422], 422);
        }

        if ($role->employees_count > 0) {
            // Borrarlo dejaría a esa gente sin permisos en medio de un turno.
            return response()->json([
                'message' => __('auth.role_in_use', ['count' => $role->employees_count]),
                'status' => 422,
            ], 422);
        }

        $this->log('role.deleted', $role, []);
        $role->delete();

        return response()->json(['message' => __('auth.role_deleted'), 'status' => 200], 200);
    }

    /** @param array<int,string> $codes */
    private function sync(Role $role, array $codes): void
    {
        $ids = Permission::whereIn('code', $codes)->pluck('id')->all();

        $role->permissions()->sync($ids);
    }

    /**
     * Cambiar quién puede qué es justo lo que la bitácora existe para registrar
     * (H7.4): queda quién lo cambió, desde qué terminal y qué había antes.
     *
     * @param  array<string,mixed>  $changes
     */
    private function log(string $event, Role $role, array $changes): void
    {
        $this->audit->record(
            event: $event,
            entityType: 'sec_roles',
            entityId: $role->id,
            changes: $changes,
            context: ['code' => $role->code],
        );
    }
}
