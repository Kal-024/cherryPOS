<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\ResolveOperatorSession;
use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Misma convención de alias que cherryB, para que una ruta se lea igual
        // en los dos proyectos.
        $middleware->alias([
            'operator' => ResolveOperatorSession::class,
            'permission' => CheckPermission::class,
        ]);

        // P6: todo texto pasa por i18n, también el de los errores de la API.
        $middleware->api(prepend: [SetLocaleFromRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Una ruta de API nunca contesta con una redirección.
         *
         * Sin esto, un error de validación en una petición con `multipart/form-data`
         * —subir un Excel, por ejemplo— devuelve 302 en vez de 422, y el cliente
         * se queda esperando un JSON que nunca llega.
         */
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );

        /*
         * El token de terminal vencido o reemplazado.
         *
         * Pasa todos los días: el token dura veinticuatro horas, y volver a
         * activar la terminal —en este equipo o en otro— invalida el anterior
         * a propósito, para que un equipo robado deje de servir. Lo que no
         * puede pasar es que la caja muestre **"Unauthenticated."**: en inglés,
         * sin decir qué hacer y sin camino de vuelta. El terminal usa el código
         * para mandar a activar de nuevo.
         */
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json([
                'error' => 'terminal_unauthenticated',
                'message' => __('auth.terminal_expired'),
                'status' => 401,
            ], 401)
            : null);
    })->create();
