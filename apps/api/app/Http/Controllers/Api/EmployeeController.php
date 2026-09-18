<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Ficha de empleado (D-05, D-02, D-03, D-13, H7).
 *
 * Aquí viven las tres cosas que ninguna otra pantalla puede dar:
 *
 *  - **El PIN**, que es la segunda mitad de la doble credencial. No es una
 *    credencial de acceso al sistema: identifica al operador dentro de una
 *    sesión de terminal ya autenticada, y se bloquea tras intentos fallidos.
 *  - **El PIN de supervisor**, que es **otro** (P-11). El de autorizar un
 *    descuento sobre el tope no puede ser el mismo con el que se abre la caja:
 *    si lo fuera, cualquiera que viera teclear el de sesión podría autorizarse
 *    a sí mismo.
 *  - **Los overrides individuales** (D-13), que pueden **conceder o revocar**.
 *    Sin la revocación, quitarle un permiso a una persona obligaría a
 *    inventarle un rol propio, que es exactamente el modelo que se quiso dejar
 *    atrás.
 *
 * Los topes son de la persona, no del rol: D-02 dice que varios cajeros tengan
 * el permiso de descuento y cada uno su porcentaje.
 */
class EmployeeController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $employees = Employee::with(['person', 'roles:id,code,name'])
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        if ($employees->isEmpty()) {
            return response()->json(['message' => __('auth.no_employees'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('auth.employees_retrieved'),
            'data' => $employees->map(fn (Employee $employee) => $this->present($employee)),
            'status' => 200,
        ], 200);
    }

    public function show(Request $request, string $id)
    {
        $employee = $this->find($request, $id);

        if (! $employee) {
            return response()->json(['message' => __('auth.employee_not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('auth.employee_retrieved'),
            'data' => $this->present($employee, withOverrides: true),
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules($request));

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();
        $branchId = $request->attributes->get('branch_id');

        $employee = Employee::createWithPerson(
            array_filter([
                'full_name' => $data['name'],
                'national_id' => $data['national_id'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ], fn ($value) => $value !== null),
            [
                'branch_id' => $branchId,
                'code' => $data['code'],
                'discount_limit_percent' => $data['discount_limit_percent'] ?? null,
                'temp_item_daily_limit' => $data['temp_item_daily_limit'] ?? null,
            ]
        );

        $this->syncRoles($employee, $data['roles'] ?? []);
        $this->log('employee.created', $employee, ['code' => $employee->code, 'roles' => $data['roles'] ?? []]);

        return response()->json([
            'message' => __('auth.employee_created'),
            'data' => $this->present($employee->fresh(['person', 'roles']), withOverrides: true),
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $employee = $this->find($request, $id);

        if (! $employee) {
            return response()->json(['message' => __('auth.employee_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), $this->rules($request, $employee, sometimes: true));

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        $employee->update(array_intersect_key($data, array_flip([
            'code', 'discount_limit_percent', 'temp_item_daily_limit', 'is_active',
        ])));

        // La identidad se edita en la persona (B-11): el mismo actor puede ser
        // además cliente o proveedor, y no puede tener dos nombres.
        $employee->person->update(array_filter([
            'full_name' => $data['name'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('roles', $data)) {
            $this->syncRoles($employee, $data['roles']);
            $this->log('employee.roles_changed', $employee, ['roles' => $data['roles']]);
        }

        return response()->json([
            'message' => __('auth.employee_updated'),
            'data' => $this->present($employee->fresh(['person', 'roles']), withOverrides: true),
            'status' => 200,
        ], 200);
    }

    /**
     * Overrides individuales: conceder o revocar para **esta** persona (D-13).
     *
     * Se sincroniza la lista completa, no se agregan de a uno: lo que la
     * pantalla muestra es el estado final, y mandar solo lo agregado dejaría
     * revocaciones viejas en pie sin que nadie las vea.
     */
    public function overrides(Request $request, string $id)
    {
        $employee = $this->find($request, $id);

        if (! $employee) {
            return response()->json(['message' => __('auth.employee_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'overrides' => 'present|array',
            'overrides.*.code' => 'required|string|exists:sec_permissions,code',
            'overrides.*.effect' => 'required|in:grant,deny',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $overrides = $validator->validated()['overrides'];
        $ids = Permission::whereIn('code', array_column($overrides, 'code'))->pluck('id', 'code');

        DB::transaction(function () use ($employee, $overrides, $ids) {
            DB::table('sec_employee_permission')->where('employee_id', $employee->id)->delete();

            foreach ($overrides as $override) {
                DB::table('sec_employee_permission')->insert([
                    'employee_id' => $employee->id,
                    'permission_id' => $ids[$override['code']],
                    'effect' => $override['effect'],
                    // Sin alcance de sucursal: mientras el POS opere un solo
                    // local, acotarlo sería configurar algo que nadie puede
                    // verificar. La columna existe para cuando A2 se cierre.
                    'branch_id' => null,
                ]);
            }
        });

        $this->log('employee.overrides_changed', $employee, ['overrides' => $overrides]);

        return response()->json([
            'message' => __('auth.employee_overrides_saved'),
            'data' => $this->present($employee->fresh(['person', 'roles']), withOverrides: true),
            'status' => 200,
        ], 200);
    }

    /**
     * Fija el PIN de sesión o el de autorización.
     *
     * El de supervisor tiene que ser **distinto** del de sesión (P-11): si
     * coincidieran, quien viera teclear el de sesión podría autorizarse a sí
     * mismo un descuento sobre el tope.
     */
    public function setPin(Request $request, string $id)
    {
        $employee = $this->find($request, $id);

        if (! $employee) {
            return response()->json(['message' => __('auth.employee_not_found'), 'status' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'pin' => 'required|digits_between:4,8',
            'kind' => 'required|in:session,supervisor',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        if ($data['kind'] === 'supervisor') {
            if ($employee->checkPin($data['pin'])) {
                return response()->json([
                    'message' => __('validation.errors'),
                    'errors' => ['pin' => [__('auth.supervisor_pin_must_differ')]],
                    'status' => 422,
                ], 422);
            }

            $employee->setSupervisorPin($data['pin']);
        } else {
            if ($employee->checkSupervisorPin($data['pin'])) {
                return response()->json([
                    'message' => __('validation.errors'),
                    'errors' => ['pin' => [__('auth.supervisor_pin_must_differ')]],
                    'status' => 422,
                ], 422);
            }

            $employee->setPin($data['pin']);
        }

        $employee->save();

        // El PIN nunca se registra, ni siquiera cifrado: lo que importa de este
        // evento es que alguien lo cambió y cuándo.
        $this->log('employee.pin_changed', $employee, ['kind' => $data['kind']]);

        return response()->json(['message' => __('auth.pin_saved'), 'status' => 200], 200);
    }

    /** Levanta el bloqueo por intentos fallidos (D-05, B-15). */
    public function unlock(Request $request, string $id)
    {
        $employee = $this->find($request, $id);

        if (! $employee) {
            return response()->json(['message' => __('auth.employee_not_found'), 'status' => 404], 404);
        }

        $employee->forceFill(['failed_pin_attempts' => 0, 'pin_locked_until' => null])->save();
        $this->log('employee.pin_unlocked', $employee, []);

        return response()->json(['message' => __('auth.pin_unlocked'), 'status' => 200], 200);
    }

    /** @return array<string,mixed> */
    private function present(Employee $employee, bool $withOverrides = false): array
    {
        $data = [
            'id' => $employee->id,
            'code' => $employee->code,
            'name' => $employee->full_name,
            'national_id' => $employee->person?->national_id,
            'phone' => $employee->person?->phone,
            'email' => $employee->person?->email,
            'discount_limit_percent' => $employee->discount_limit_percent,
            'temp_item_daily_limit' => $employee->temp_item_daily_limit,
            'is_active' => (bool) $employee->is_active,
            // Nunca el hash, ni truncado: lo único que la pantalla necesita
            // saber es si hay PIN puesto.
            'has_pin' => $employee->pin_hash !== null,
            'has_supervisor_pin' => $employee->supervisor_pin_hash !== null,
            'pin_locked' => $employee->isPinLocked(),
            'roles' => $employee->roles->pluck('code')->all(),
        ];

        if ($withOverrides) {
            $data['overrides'] = DB::table('sec_employee_permission as ep')
                ->join('sec_permissions as p', 'p.id', '=', 'ep.permission_id')
                ->where('ep.employee_id', $employee->id)
                ->get(['p.code', 'ep.effect'])
                ->map(fn ($row) => ['code' => $row->code, 'effect' => $row->effect]);

            // Lo que realmente puede hacer, ya resuelto: es la única respuesta
            // útil a "¿por qué no me deja?".
            $data['effective_permissions'] = $employee->permissionCodes($employee->branch_id);
        }

        return $data;
    }

    /** @param array<int,string> $codes */
    private function syncRoles(Employee $employee, array $codes): void
    {
        $roles = Role::whereIn('code', $codes)->pluck('id')->all();

        // `branch_id` null = en todas las sucursales de la instalación. Acotarlo
        // por local solo tiene sentido cuando exista consolidación
        // multi-sucursal, que es el punto abierto A2.
        $employee->roles()->sync(array_fill_keys($roles, ['branch_id' => null]));
    }

    /** @return array<string,mixed> */
    private function rules(Request $request, ?Employee $employee = null, bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';
        $branchId = $request->attributes->get('branch_id');
        $unique = 'unique:sec_employees,code,'.($employee?->id ?? 'NULL').',id,branch_id,'.$branchId;

        return [
            'name' => $prefix.'required|string|max:160',
            'code' => $prefix.'required|string|max:20|'.$unique,
            'national_id' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            // Tope propio de este cajero (D-02). Null = rige el del rol.
            'discount_limit_percent' => 'nullable|numeric|min:0|max:100',
            // Ítems temporales por día (D-03). Null = el defecto del sistema.
            'temp_item_daily_limit' => 'nullable|integer|min:0|max:999',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:sec_roles,code',
            'is_active' => 'boolean',
        ];
    }

    private function find(Request $request, string $id): ?Employee
    {
        return Employee::with(['person', 'roles'])
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->find($id);
    }

    /** @param array<string,mixed> $changes */
    private function log(string $event, Employee $employee, array $changes): void
    {
        $this->audit->record(
            event: $event,
            entityType: 'sec_employees',
            entityId: $employee->id,
            changes: $changes,
            context: ['code' => $employee->code],
        );
    }
}
