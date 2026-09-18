<?php

namespace App\Providers;

use App\Database\MicrosecondPostgresGrammar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Microsegundos en las comparaciones de fecha.
         *
         * Sin esto, un `Carbon` enlazado a una consulta se trunca al segundo y
         * no encuentra las filas escritas en ese mismo segundo: el ticket recién
         * cerrado no se envía al ERP, la cuenta recién suspendida no llega a las
         * demás cajas. Nada falla a la vista, simplemente falta información.
         */
        DB::extend('pgsql', function (array $config, string $name) {
            // `ConnectionFactory` construye la conexión sin volver a pasar por
            // aquí, así que no hay recursión.
            $connection = app('db.factory')->make($config, $name);

            if ($connection instanceof PostgresConnection) {
                $connection->setQueryGrammar(new MicrosecondPostgresGrammar($connection));
            }

            return $connection;
        });

        $this->defineRateLimiters();
    }

    /**
     * Límites de intento con respuesta explicada (B-15).
     *
     * La protección de fuerza bruta ya existía, pero su respuesta era la de
     * fábrica: **"Too Many Attempts."**, en inglés y sin decir qué hacer. En una
     * caja eso se ve así: el cajero se equivoca de PIN unas cuantas veces, a
     * partir de ahí el PIN correcto también falla, y el mensaje no da ninguna
     * pista de que hay que esperar. La conclusión razonable —y equivocada— es
     * que las credenciales no sirven.
     *
     * El límite no se afloja: diez intentos por minuto sigue siendo lo correcto.
     * Lo que cambia es que ahora la respuesta dice cuántos segundos faltan, en
     * el idioma de la terminal, y con la terna `message`/`error`/`status` que el
     * resto de la API usa.
     */
    private function defineRateLimiters(): void
    {
        foreach (['terminal-login', 'operator-session'] as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'error' => 'too_many_attempts',
                    'message' => __('auth.too_many_attempts', [
                        'seconds' => $headers['Retry-After'] ?? 60,
                    ]),
                    'status' => 429,
                ], 429, $headers)));
        }
    }
}
