<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Rol con permisos heredados (D-13). Los del sistema no se borran ni se renombran. */
class Role extends Model
{
    use UsesUuid;

    protected $table = 'sec_roles';

    protected $fillable = ['code', 'name', 'description', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'sec_role_permission');
    }

    /**
     * Quiénes lo tienen. El rol se otorga por sucursal (B-13), así que la tabla
     * intermedia lleva `branch_id`: sin declararlo, quitar un rol en un local lo
     * quitaría en todos.
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'sec_employee_role')
            ->withPivot('branch_id');
    }
}
