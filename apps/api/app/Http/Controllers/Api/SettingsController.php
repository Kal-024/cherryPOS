<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Configuración operativa del POS (D-12, D-06, B-09, Q-06, Q-07).
 *
 * Solo se expone lo que el negocio cambia de verdad. El resto de `cmn_settings`
 * queda fuera a propósito: una pantalla con ciento cuarenta claves sueltas es el
 * patrón de OSPOS que D-12 vino a corregir, y en la práctica nadie la usa salvo
 * para romper algo.
 *
 * Tres de estas claves cambian cómo se calcula el dinero, así que llevan
 * bitácora:
 *
 *  - **El redondeo de efectivo** viaja al motor de cálculo, y el terminal usa el
 *    mismo valor cuando vende sin servidor. Cambiarlo a media jornada hace que
 *    dos tickets del mismo día cierren distinto — legítimo, pero hay que poder
 *    explicarlo después.
 *  - **El régimen de cuota fija** desactiva el traslado de IVA en todo el
 *    sistema. No es una preferencia de pantalla.
 *  - **La ventana sin sincronizar** decide cuánto puede seguir vendiendo una
 *    caja aislada antes de plantarse.
 *
 * El alcance es el negocio: la jerarquía sucursal y terminal existe en el
 * repositorio (P-05), pero mientras una instalación sea una sucursal, ofrecerla
 * sería configurar algo que nadie puede verificar.
 */
class SettingsController extends Controller
{
    /**
     * Lo editable, con su tipo. La forma la consume la pantalla tal cual.
     *
     * @var array<string,array{type:string,group:string,default:mixed}>
     */
    private const EDITABLE = [
        'receipt.footer' => ['type' => 'string', 'group' => 'receipt', 'default' => ''],
        'receipt.notice' => ['type' => 'string', 'group' => 'receipt', 'default' => ''],
        'cash.rounding_mode' => ['type' => 'string', 'group' => 'cash', 'default' => null],
        'cash.rounding_increment' => ['type' => 'string', 'group' => 'cash', 'default' => null],
        'tax.fixed_quota_regime' => ['type' => 'boolean', 'group' => 'tax', 'default' => false],
        'pos.offline_max_hours' => ['type' => 'integer', 'group' => 'offline', 'default' => null],
        'tip.enabled' => ['type' => 'boolean', 'group' => 'tip', 'default' => false],
        'tip.suggested_percent' => ['type' => 'string', 'group' => 'tip', 'default' => '10'],
    ];

    /**
     * Regla de validación de cada clave.
     *
     * `cash.rounding_mode` acepta **solo** el vocabulario que entienden los dos
     * motores de cálculo: cualquier otro valor haría que PHP y TypeScript
     * tomaran caminos distintos.
     *
     * @var array<string,string>
     */
    private const RULES = [
        'receipt.footer' => 'nullable|string|max:500',
        'receipt.notice' => 'nullable|string|max:500',
        'cash.rounding_mode' => 'required|in:none,nearest,up,down',
        'cash.rounding_increment' => 'required|numeric|gt:0',
        'tax.fixed_quota_regime' => 'required|boolean',
        'pos.offline_max_hours' => 'required|integer|min:1|max:720',
        'tip.enabled' => 'required|boolean',
        // Es una sugerencia, no un cargo: el botón teclea ese porcentaje y el
        // cliente decide. Por eso admite 0 y no fuerza ningún mínimo.
        'tip.suggested_percent' => 'required|numeric|min:0|max:100',
    ];

    public function __construct(
        private SettingsRepository $settings,
        private AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $values = [];

        foreach (self::EDITABLE as $key => $spec) {
            $values[$key] = $this->settings->get($key, $spec['default'] ?? $this->fallback($key), $branchId);
        }

        return response()->json([
            'message' => __('settings.retrieved'),
            'data' => [
                'values' => $values,
                // Lo que no se edita desde acá pero la pantalla necesita para
                // explicarse: las monedas salen de la instalación, y el
                // impuesto dentro del precio es propiedad del código fiscal,
                // nunca una bandera global.
                'fixed' => [
                    'base_currency' => config('pos.base_currency'),
                    'secondary_currency' => config('pos.secondary_currency'),
                    'business_profile' => config('pos.business_profile'),
                    'costing_method' => config('pos.costing_method'),
                    'require_shift' => (bool) config('pos.require_shift'),
                    'refund_requires_original' => (bool) config('pos.refund_requires_original'),
                    'temporary_item_daily_limit' => (int) config('pos.temporary_item_daily_limit'),
                ],
            ],
            'status' => 200,
        ], 200);
    }

    /**
     * Guarda las claves recibidas.
     *
     * El cuerpo llega como `{"settings": {"cash.rounding_mode": "up"}}` y se
     * valida clave por clave. No se usan reglas con punto: para el validador de
     * Laravel el punto es un separador de camino, así que `cash.rounding_mode`
     * significaría "la clave `rounding_mode` dentro del objeto `cash`" y la
     * regla no se aplicaría nunca. Una validación que pasa siempre es peor que
     * no tenerla.
     */
    public function update(Request $request)
    {
        $incoming = $request->input('settings');

        if (! is_array($incoming) || $incoming === []) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => ['settings' => [__('settings.nothing_to_save')]],
                'status' => 422,
            ], 422);
        }

        $errors = [];
        $clean = [];

        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, self::EDITABLE)) {
                $errors[$key] = [__('settings.unknown_key', ['key' => $key])];

                continue;
            }

            $validator = Validator::make(['value' => $value], ['value' => self::RULES[$key]]);

            if ($validator->fails()) {
                $errors[$key] = $validator->errors()->get('value');

                continue;
            }

            $clean[$key] = $value;
        }

        if ($errors !== []) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $errors,
                'status' => 422,
            ], 422);
        }

        $changes = [];

        foreach ($clean as $key => $value) {
            $spec = self::EDITABLE[$key];
            $before = $this->settings->get($key, null, $request->attributes->get('branch_id'));

            $this->settings->set(
                $key,
                $spec['type'] === 'string' ? (string) ($value ?? '') : $value,
                $spec['type'],
                'business',
                null,
                $spec['group'],
            );

            $changes[$key] = ['before' => $before, 'after' => $value];
        }

        if ($changes !== []) {
            // Cambiar el redondeo o la cuota fija cambia cuánto paga el
            // cliente: sin bitácora, dos tickets distintos del mismo día no
            // tendrían explicación.
            $this->audit->record(
                event: 'settings.changed',
                entityType: 'cmn_settings',
                changes: $changes,
            );
        }

        return response()->json([
            'message' => __('settings.saved'),
            'data' => $changes,
            'status' => 200,
        ], 200);
    }

    private function fallback(string $key): mixed
    {
        return match ($key) {
            'cash.rounding_mode' => config('pos.cash_rounding_mode'),
            'cash.rounding_increment' => config('pos.cash_rounding_increment'),
            'pos.offline_max_hours' => (int) config('pos.offline_max_hours'),
            default => null,
        };
    }
}
