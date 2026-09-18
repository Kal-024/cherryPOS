<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idioma de la respuesta, tomado de `Accept-Language` o de `?locale=`.
 *
 * P6: ningún literal visible en el código, tampoco en los mensajes de error de
 * la API. Español por defecto, inglés como segundo idioma; el ruso quedó
 * descartado (P-14).
 */
class SetLocaleFromRequest
{
    private const SUPPORTED = ['es', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('locale')
            ?? substr((string) $request->header('Accept-Language'), 0, 2);

        if (in_array($requested, self::SUPPORTED, true)) {
            App::setLocale($requested);
        }

        return $next($request);
    }
}
