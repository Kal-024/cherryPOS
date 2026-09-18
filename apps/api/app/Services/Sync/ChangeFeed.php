<?php

namespace App\Services\Sync;

use App\Models\Terminal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sincronización entre terminales por **consulta periódica** (G-13, R-01).
 *
 * El plan dejaba la tecnología de tiempo real "por definir". Se resolvió a favor
 * del *polling* y no de WebSockets, por una razón concreta: **todo ocurre dentro
 * de la red local del negocio**. Sobre una LAN, la diferencia entre un aviso
 * instantáneo y uno de dos segundos no la percibe nadie, y un servidor de
 * WebSockets es una pieza más que instalar, vigilar y reiniciar en el equipo de
 * cada cliente — donde no hay nadie de sistemas.
 *
 * El *polling* además se degrada bien: si una terminal se queda sin red,
 * reintenta y al volver se pone al día sola. Una conexión persistente caída hay
 * que detectarla y reabrirla, y esa lógica es donde viven los errores raros.
 *
 * El mecanismo es un **cursor**: la terminal manda hasta dónde sabía y recibe lo
 * que cambió desde entonces. No hay estado en el servidor, así que reiniciarlo
 * no pierde nada.
 */
class ChangeFeed
{
    /**
     * Cada cuánto conviene volver a preguntar, en segundos.
     *
     * Dos segundos en la caja es imperceptible para una persona y ridículo para
     * un servidor en la misma habitación. El cliente lo recibe en la respuesta
     * en vez de tenerlo cableado, para poder subirlo si alguna instalación tiene
     * veinte cajas.
     */
    private const POLL_SECONDS = 2;

    /** Techo de filas por recurso. Un cursor muy viejo no puede tumbar la caja. */
    private const MAX_ROWS = 200;

    /**
     * @return array<string,mixed>
     */
    public function since(?CarbonInterface $since, Terminal $terminal, string $branchId): array
    {
        // El cursor se toma **antes** de consultar: lo que se escriba mientras
        // corre esta consulta entra en la próxima vuelta, no se pierde entre
        // las dos.
        $cursor = Carbon::now();
        $from = $since ?? Carbon::now()->subDay();

        return [
            // Con microsegundos: `toIso8601String()` trunca al segundo, y un
            // cursor al segundo pierde todo lo que ocurrió en ese mismo segundo
            // — en una caja, varias ventas.
            'cursor' => $cursor->format('Y-m-d\TH:i:s.uP'),
            'poll_after_seconds' => self::POLL_SECONDS,
            'changes' => [
                'sales' => $this->sales($branchId, $from),
                'notifications' => $this->notifications($branchId, $from),
                'shift' => $this->shift($terminal),
                'catalog' => $this->catalog(),
            ],
        ];
    }

    /**
     * Ventas que cambiaron.
     *
     * Incluye las que **dejaron** de estar suspendidas, no solo las que lo
     * están: si otra caja retomó la mesa 4, esta terminal tiene que sacarla de
     * su lista. Mandar solo las suspendidas dejaría cuentas fantasma en
     * pantalla, que es exactamente el error que un restaurante no perdona.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sales(string $branchId, CarbonInterface $from): array
    {
        return DB::table('pos_sales')
            ->where('branch_id', $branchId)
            ->where('updated_at', '>', $from)
            ->orderBy('updated_at')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'status', 'label', 'sale_type', 'total', 'terminal_id', 'employee_id', 'updated_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'status' => $row->status,
                'label' => $row->label,
                'sale_type' => $row->sale_type,
                'total' => (string) $row->total,
                'terminal_id' => $row->terminal_id,
                'employee_id' => $row->employee_id,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function notifications(string $branchId, CarbonInterface $from): array
    {
        return DB::table('pos_supervisor_notifications')
            ->where('branch_id', $branchId)
            ->whereNull('read_at')
            ->where('created_at', '>', $from)
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'event', 'severity', 'title', 'body', 'occurred_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Estado del turno de esta terminal.
     *
     * Va en cada respuesta y no solo cuando cambia: es barato y evita que una
     * caja siga vendiendo contra un turno que el supervisor cerró desde otra
     * pantalla.
     *
     * @return array<string,mixed>|null
     */
    private function shift(Terminal $terminal): ?array
    {
        $shift = DB::table('pos_shifts')
            ->where('terminal_id', $terminal->id)
            ->where('status', 'open')
            ->first(['id', 'code', 'opened_at', 'exchange_rate']);

        return $shift ? (array) $shift : null;
    }

    /**
     * Marca de agua del catálogo.
     *
     * No se mandan los productos: se manda **cuándo cambió el último**. La
     * terminal compara con lo que tiene cacheado y decide si vale la pena
     * volver a bajarlo. Mandar el catálogo en cada vuelta sería tirar la red
     * abajo por nada (D-04).
     *
     * @return array<string,mixed>
     */
    private function catalog(): array
    {
        return [
            'products_updated_at' => DB::table('cat_products')->max('updated_at'),
            'tax_codes_updated_at' => DB::table('cat_tax_codes')->max('updated_at'),
            'exchange_rate_updated_at' => DB::table('cmn_exchange_rates')->max('created_at'),
        ];
    }
}
