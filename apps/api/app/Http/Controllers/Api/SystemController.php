<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Estado de la instalación.
 *
 * `version` es la precondición 5 del modelo de datos: con instalaciones en
 * sitio las sucursales se desactualizan a distinto ritmo, y la replicación
 * hacia casa matriz debe **fallar de forma visible** al encontrar una versión
 * que no entiende, en vez de sincronizar a medias.
 *
 * Es pública a propósito: el instalador y la cola la consultan antes de tener
 * credenciales.
 */
class SystemController extends Controller
{
    public function version()
    {
        $schema = DB::table('cmn_schema_version')->where('id', 1)->first();

        return response()->json([
            'message' => __('system.version_retrieved'),
            'data' => [
                'schema_version' => $schema?->version,
                'app_version' => config('app.version'),
                'applied_at' => $schema?->applied_at,
            ],
            'status' => 200,
        ], 200);
    }
}
