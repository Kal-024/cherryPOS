<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\Import\ExportService;
use App\Services\Import\ImporterRegistry;
use App\Services\Import\ImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Importación y exportación masiva (D-15, H1.6).
 *
 * **Sin importación no hay onboarding**: nadie carga 3.000 productos a mano.
 * Es requisito comercial antes que técnico.
 *
 * El flujo tiene tres pasos y el del medio es el que OSPOS no tiene:
 * previsualizar, **mirar**, aplicar. Y un cuarto por si acaso: revertir.
 */
class ImportController extends Controller
{
    public function __construct(
        private ImportService $imports,
        private ExportService $exports,
        private ImporterRegistry $registry,
    ) {}

    /** Qué se puede importar y qué columnas espera cada tipo. */
    public function kinds()
    {
        $data = [];

        foreach ($this->registry->kinds() as $kind) {
            $data[$kind] = $this->registry->template($kind);
        }

        return response()->json([
            'message' => __('import.kinds_retrieved'),
            'data' => $data,
            'status' => 200,
        ], 200);
    }

    /**
     * Lee el archivo y **no escribe nada**.
     *
     * Es el paso que convierte "subí el archivo y rezá" en "mirá qué va a
     * pasar".
     */
    public function preview(Request $request, string $kind)
    {
        $validator = Validator::make(
            array_merge($request->all(), ['kind' => $kind]),
            [
                'kind' => 'required|in:'.implode(',', $this->registry->kinds()),
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $file = $request->file('file');

        $batch = $this->imports->preview(
            $kind,
            $file,
            $request->attributes->get('branch_id'),
            $request->attributes->get('employee_id'),
            $file->getClientOriginalName(),
        );

        return response()->json([
            'message' => __('import.previewed'),
            'data' => $this->present($batch),
            'status' => 201,
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $batch = $this->find($request, $id);

        if (! $batch) {
            return response()->json(['message' => __('import.batch_not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('import.batch_retrieved'),
            'data' => $this->present($batch, withRows: true),
            'status' => 200,
        ], 200);
    }

    public function apply(Request $request, string $id)
    {
        $batch = $this->find($request, $id);

        if (! $batch) {
            return response()->json(['message' => __('import.batch_not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('import.applied'),
            'data' => $this->present(
                $this->imports->apply($batch, $request->attributes->get('employee_id'))
            ),
            'status' => 200,
        ], 200);
    }

    public function revert(Request $request, string $id)
    {
        $batch = $this->find($request, $id);

        if (! $batch) {
            return response()->json(['message' => __('import.batch_not_found'), 'status' => 404], 404);
        }

        $reverted = $this->imports->revert($batch, $request->attributes->get('employee_id'));

        return response()->json([
            'message' => __('import.reverted'),
            'data' => $this->present($reverted, withRows: true),
            'status' => 200,
        ], 200);
    }

    /** Plantilla vacía con los encabezados que el importador espera. */
    public function template(string $kind)
    {
        return Excel::download(
            $this->exports->template($kind),
            "plantilla-{$kind}.xlsx"
        );
    }

    /**
     * Datos actuales en el **mismo formato que se importa**: bajar, corregir en
     * Excel y volver a subir es la forma real de editar mil productos.
     */
    public function export(Request $request, string $kind)
    {
        return Excel::download(
            $this->exports->data($kind, $request->attributes->get('branch_id')),
            "{$kind}-".now()->format('Ymd-His').'.xlsx'
        );
    }

    /** @return array<string,mixed> */
    private function present(ImportBatch $batch, bool $withRows = false): array
    {
        $data = $batch->only([
            'id', 'kind', 'filename', 'status',
            'rows_total', 'rows_valid', 'rows_invalid', 'rows_applied',
            'applied_at', 'reverted_at',
        ]);

        if ($withRows) {
            // `normalized` junto a `raw` es lo que permite verificar la
            // lectura antes de aplicar: "1.234,56" leído como 1234.56.
            $data['rows'] = $batch->rows()->get()->map(fn ($row) => $row->only([
                'row_number', 'action', 'errors', 'raw', 'normalized', 'entity_id',
                'applied', 'reverted', 'revert_error',
            ]));
        } else {
            // En la previsualización interesan los problemas, que es lo que hay
            // que corregir antes de aplicar.
            $data['problems'] = $batch->rows()->where('action', 'skip')->get()
                ->map(fn ($row) => [
                    'row_number' => $row->row_number,
                    'errors' => $row->errors,
                    'raw' => $row->raw,
                ]);

            // Muestra de lo que se entendió, para verificar antes de aplicar.
            $data['sample'] = $batch->rows()->where('action', '!=', 'skip')
                ->limit(5)->get()
                ->map(fn ($row) => [
                    'row_number' => $row->row_number,
                    'action' => $row->action,
                    'normalized' => $row->normalized,
                ]);
        }

        return $data;
    }

    private function find(Request $request, string $id): ?ImportBatch
    {
        return ImportBatch::where('branch_id', $request->attributes->get('branch_id'))->find($id);
    }
}
