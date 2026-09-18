<?php

namespace App\Services\Erp;

use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Configuración que baja del ERP (F1-C, §7 y §8 del contrato).
 *
 * **Se lee, no se escribe.** P2 dice un escritor por entidad: con ERP presente,
 * la configuración de caja es suya y el POS la adopta (P3, "el que llega primero
 * define"). Un `PUT` desde el POS crearía dos dueños del mismo dato y la
 * pregunta imposible de cuál gana.
 *
 * Tres de los valores que bajan **cambian cómo opera la caja**, no cómo se ve:
 *
 *  - `pos_offline_max_hours` — cuánto puede vender una caja aislada antes de
 *    plantarse. El POS lo **respeta**, no solo lo muestra (H6.5): un ticket muy
 *    viejo choca con el cierre de período y con precios ya cambiados.
 *  - `pos_offline_pin_mode` — si el relevo de cajero sin red es posible, y a
 *    cambio de qué (los hashes en el equipo). Es un opt-in consciente.
 *  - `pos_layout_profile` — el perfil de pantalla del local.
 *
 * Sin ERP configurado no hace nada y lo dice: el POS opera solo y su
 * configuración local manda (P-01, P4).
 */
class ErpSettingsService
{
    /** Clave del ERP → clave local, con su tipo. §7 del contrato. */
    private const MAP = [
        'pos_offline_max_hours' => ['key' => 'pos.offline_max_hours', 'type' => 'integer'],
        'pos_offline_pin_mode' => ['key' => 'pos.offline_pin_mode', 'type' => 'string'],
        'pos_require_shift' => ['key' => 'pos.require_shift', 'type' => 'boolean'],
        'pos_layout_profile' => ['key' => 'pos.layout_profile', 'type' => 'string'],
        // Cuentas y bodega del ERP: el POS no las usa para calcular, pero las
        // guarda para poder mostrar contra qué se va a contabilizar y para
        // detectar que el ERP todavía no las configuró.
        'pos_cash_account_id' => ['key' => 'erp.cash_account_id', 'type' => 'string'],
        'pos_cash_short_over_account_id' => ['key' => 'erp.cash_short_over_account_id', 'type' => 'string'],
        'pos_default_customer_id' => ['key' => 'erp.default_customer_id', 'type' => 'string'],
        'billing_default_warehouse_id' => ['key' => 'erp.default_warehouse_id', 'type' => 'string'],
        'billing_affects_inventory' => ['key' => 'erp.billing_affects_inventory', 'type' => 'boolean'],
    ];

    /** Valores que el POS acepta como perfil de pantalla (§8). */
    private const PROFILES = ['scan_first', 'touch_grid', 'restaurant'];

    public function __construct(private SettingsRepository $settings) {}

    public function configured(): bool
    {
        return ! empty(config('pos.erp.base_url')) && ! empty(config('pos.erp.token'));
    }

    /**
     * Baja la configuración y la aplica.
     *
     * @return array{status:string, applied?:array<string,mixed>, error?:string}
     */
    public function pull(): array
    {
        if (! $this->configured()) {
            return ['status' => 'unconfigured'];
        }

        $settings = $this->get('/pos/settings');

        if ($settings['status'] !== 'ok') {
            return $settings;
        }

        $applied = $this->apply($settings['data'] ?? []);

        // El perfil de pantalla se pide aparte porque en el ERP lo lee el cajero
        // y la configuración la lee el administrador: son dos permisos, y una
        // caja no puede quedarse sin pantalla porque el cajero no pueda ver la
        // configuración entera (§8).
        $layout = $this->get('/pos/layout');

        if ($layout['status'] === 'ok') {
            $applied += $this->apply($layout['data'] ?? []);
        }

        $this->settings->set('erp.settings_synced_at', now()->toIso8601String(), 'string', 'business', null, 'erp');

        return ['status' => 'ok', 'applied' => $applied];
    }

    /** Lo último que se supo del ERP, para poder mostrarlo sin volver a llamar. */
    public function lastSyncedAt(): ?string
    {
        $value = $this->settings->get('erp.settings_synced_at');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array{status:string, data?:array<string,mixed>, error?:string}
     */
    private function get(string $path): array
    {
        try {
            $response = Http::withToken((string) config('pos.erp.token'))
                ->withHeaders(['X-Company-Id' => (string) config('pos.erp.company_id')])
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim((string) config('pos.erp.base_url'), '/').$path);
        } catch (ConnectionException $e) {
            // El enlace caído **no impide vender** (P4, §12): se informa y la
            // caja sigue con lo último que supo.
            return ['status' => 'unreachable', 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['status' => 'error', 'error' => (string) ($response->json('message') ?? $response->status())];
        }

        return ['status' => 'ok', 'data' => (array) ($response->json('data') ?? [])];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function apply(array $data): array
    {
        $applied = [];

        foreach (self::MAP as $remote => $spec) {
            if (! array_key_exists($remote, $data)) {
                continue;
            }

            $value = $data[$remote];

            // Un perfil que el POS no sabe dibujar se ignora en vez de dejar la
            // caja en blanco: es preferible seguir con el anterior y que se vea
            // en la pantalla de integración.
            if ($remote === 'pos_layout_profile' && ! in_array($value, self::PROFILES, true)) {
                Log::warning('cherryPOS: perfil de pantalla desconocido del ERP', ['profile' => $value]);

                continue;
            }

            if ($value === null) {
                continue;
            }

            $this->settings->set(
                $spec['key'],
                $spec['type'] === 'string' ? (string) $value : $value,
                $spec['type'],
                'business',
                null,
                'erp',
            );

            $applied[$spec['key']] = $value;
        }

        return $applied;
    }
}
