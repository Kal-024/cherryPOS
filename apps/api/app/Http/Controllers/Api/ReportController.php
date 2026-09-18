<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El mínimo operativo de reportes de F1 (P-07, D-20).
 *
 * El módulo de reportes completo es F2. Lo que entra en F1 es lo que un local no
 * puede cerrar el día sin tener: el corte de turno —que vive en la caja—, las
 * ventas del día **por cajero y por terminal**, los créditos y saldos, los
 * movimientos de inventario y la bitácora.
 *
 * Las ventas se agrupan por cajero **y** por terminal porque responden
 * preguntas distintas: quién vendió cuánto es de personas, qué caja movió
 * cuánto es del arqueo del cajón, y con relevos dentro del mismo turno (D-05)
 * los dos cortes no coinciden.
 */
class ReportController extends Controller
{
    public function dailySales(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $from = $request->date('from') ?? now()->startOfDay();
        $to = $request->date('to') ?? now()->endOfDay();

        // Columnas calificadas: los agrupamientos hacen `join` con empleados y
        // terminales, y las tres tablas tienen `branch_id`.
        $base = fn () => DB::table('pos_sales')
            ->where('pos_sales.branch_id', $branchId)
            // Solo lo cerrado: una venta suspendida todavía no es dinero, y
            // sumarla inflaría el día con cuentas que quizá se anulen.
            ->where('pos_sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('pos_sales.closed_at', [$from, $to]);

        $byEmployee = $base()
            ->join('sec_employees as e', 'e.id', '=', 'pos_sales.employee_id')
            ->join('cmn_persons as p', 'p.id', '=', 'e.person_id')
            ->groupBy('e.id', 'e.code', 'p.full_name')
            ->orderByDesc(DB::raw('SUM(pos_sales.total)'))
            ->get([
                'e.id as employee_id',
                'e.code',
                'p.full_name as name',
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(pos_sales.total) as total'),
                DB::raw('SUM(pos_sales.discount_total) as discount_total'),
            ]);

        $byTerminal = $base()
            ->join('cmn_terminals as t', 't.id', '=', 'pos_sales.terminal_id')
            ->groupBy('t.id', 't.code', 't.name')
            ->orderBy('t.code')
            ->get([
                't.id as terminal_id',
                't.code',
                't.name',
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(pos_sales.total) as total'),
            ]);

        $totals = $base()->get([
            DB::raw('COUNT(*) as sales_count'),
            DB::raw('COALESCE(SUM(pos_sales.total), 0) as total'),
            DB::raw('COALESCE(SUM(pos_sales.discount_total), 0) as discount_total'),
            DB::raw('COALESCE(SUM(tax_total), 0) as tax_total'),
            // Exento y base gravada viajan separados porque el libro de ventas
            // los declara distinto: un consolidado los haría indistinguibles.
            DB::raw('COALESCE(SUM(exempt_total), 0) as exempt_total'),
            DB::raw('COALESCE(SUM(taxable_base), 0) as taxable_base'),
        ])->first();

        return response()->json([
            'message' => __('report.daily_sales_retrieved'),
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'totals' => $totals,
                'by_employee' => $byEmployee,
                'by_terminal' => $byTerminal,
            ],
            'status' => 200,
        ], 200);
    }

    /**
     * Bitácora de auditoría (G-11, H7.4).
     *
     * Solo lectura y solo inserción del otro lado: una bitácora que el propio
     * sistema puede reescribir no prueba nada. Aquí no hay endpoint para
     * corregirla, y eso es deliberado.
     */
    public function auditLog(Request $request)
    {
        $entries = AuditLog::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->when($request->filled('event'), fn ($q) => $q->where('event', 'like', $request->string('event').'%'))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->string('employee_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', $request->date('to')))
            ->orderByDesc('occurred_at')
            ->limit((int) $request->integer('limit', 200))
            ->get();

        if ($entries->isEmpty()) {
            return response()->json(['message' => __('report.no_audit_entries'), 'status' => 404], 404);
        }

        $employees = DB::table('sec_employees as e')
            ->join('cmn_persons as p', 'p.id', '=', 'e.person_id')
            ->whereIn('e.id', $entries->pluck('employee_id')->filter()->unique())
            ->pluck('p.full_name', 'e.id');

        $terminals = DB::table('cmn_terminals')
            ->whereIn('id', $entries->pluck('terminal_id')->filter()->unique())
            ->pluck('code', 'id');

        return response()->json([
            'message' => __('report.audit_log_retrieved'),
            'data' => $entries->map(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'event' => $entry->event,
                'entity_type' => $entry->entity_type,
                'entity_id' => $entry->entity_id,
                'changes' => $entry->changes,
                'context' => $entry->context,
                'occurred_at' => $entry->occurred_at,
                // Quién y desde dónde: sin eso la entrada no responde la
                // pregunta para la que existe la bitácora.
                'employee_name' => $employees[$entry->employee_id] ?? null,
                'terminal_code' => $terminals[$entry->terminal_id] ?? null,
            ]),
            'status' => 200,
        ], 200);
    }
}
