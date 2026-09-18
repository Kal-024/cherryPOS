<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/** Permiso granular `recurso.accion`, con el mismo vocabulario que cherryB (B-13). */
class Permission extends Model
{
    use UsesUuid;

    protected $table = 'sec_permissions';

    protected $fillable = ['code', 'module', 'description'];
}
