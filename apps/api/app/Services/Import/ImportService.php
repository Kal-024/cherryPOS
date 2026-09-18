<?php

namespace App\Services\Import;

use App\Imports\RawSheet;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

/**
 * Importación masiva en dos tiempos (D-15, H1.6).
 *
 * La implementación de OSPOS es un método de 400 líneas que escribe mientras
 * lee: si la fila 2.700 falla, quedan 2.699 productos cargados y ninguna forma
 * de saber cuáles. Aquí hay dos operaciones separadas:
 *
 *  1. **`preview`** — lee, valida y guarda el diagnóstico. **No toca el
 *     dominio.** El usuario ve cuántas filas van a crear, cuántas a actualizar y
 *     cuáles tienen problema, con el texto original al lado.
 *  2. **`apply`** — ejecuta lo previsualizado en una transacción, anotando en
 *     cada fila qué entidad tocó y cómo estaba antes.
 *
 * Y `revert` deshace la carga completa. No siempre se puede del todo —un
 * producto que desde entonces se vendió no se borra, se da de baja— y en esos
 * casos la fila queda marcada con el motivo, en vez de fallar en silencio.
 */
class ImportService
{
    /** Techo de filas por archivo. Más que esto conviene partirlo. */
    private const MAX_ROWS = 20000;

    public function __construct(
        private ImporterRegistry $registry,
        private AuditLogger $audit,
    ) {}

    /**
     * Lee el archivo, valida cada fila y guarda el diagnóstico sin escribir
     * nada del dominio.
     */
    public function preview(
        string $kind,
        UploadedFile|string $file,
        string $branchId,
        ?string $employeeId,
        ?string $filename = null,
    ): ImportBatch {
        $importer = $this->registry->for($kind);
        $rows = $this->read($file, $importer);

        return DB::transaction(function () use ($kind, $branchId, $employeeId, $filename, $importer, $rows) {
            $batch = ImportBatch::create([
                'branch_id' => $branchId,
                'employee_id' => $employeeId,
                'kind' => $kind,
                'filename' => $filename,
                'status' => ImportBatch::PREVIEWED,
                'rows_total' => count($rows),
            ]);

            $valid = 0;
            $invalid = 0;

            foreach ($rows as $number => $raw) {
                $result = $importer->inspect($raw, $branchId);
                $result['action'] === 'skip' ? $invalid++ : $valid++;

                ImportRow::create([
                    'batch_id' => $batch->id,
                    // +2: la fila 1 son los encabezados, y las personas cuentan
                    // desde 1. Así el número coincide con lo que ven en Excel.
                    'row_number' => $number + 2,
                    'raw' => $raw,
                    'normalized' => $result['normalized'],
                    'errors' => $result['errors'] ?: null,
                    'action' => $result['action'],
                    'entity_type' => $importer->entityType(),
                    'entity_id' => $result['entity_id'],
                ]);
            }

            $batch->forceFill(['rows_valid' => $valid, 'rows_invalid' => $invalid])->save();

            return $batch->fresh();
        });
    }

    /**
     * Ejecuta lo previsualizado.
     *
     * Las filas con error **se saltan**, no detienen la carga: lo contrario
     * obligaría a corregir el archivo entero para poder cargar las 2.998 filas
     * que sí estaban bien.
     */
    public function apply(ImportBatch $batch, ?string $employeeId): ImportBatch
    {
        if ($batch->status !== ImportBatch::PREVIEWED) {
            throw ValidationException::withMessages([
                'batch' => __('import.only_previewed_can_apply'),
            ]);
        }

        $importer = $this->registry->for($batch->kind);

        DB::transaction(function () use ($batch, $importer, $employeeId) {
            $applied = 0;

            foreach ($batch->rows()->where('action', '!=', 'skip')->get() as $row) {
                // Cómo estaba antes: sin esto, deshacer una actualización sería
                // adivinar.
                $row->before = $this->snapshot($importer, $row);

                $row->entity_id = $importer->apply($row, $batch->branch_id, $employeeId);
                $row->applied = true;
                $row->save();

                $applied++;
            }

            $batch->forceFill([
                'status' => ImportBatch::APPLIED,
                'rows_applied' => $applied,
                'applied_at' => now(),
            ])->save();
        });

        $this->audit->record(
            event: 'import.applied',
            entityType: 'import_batch',
            entityId: $batch->id,
            context: [
                'kind' => $batch->kind,
                'filename' => $batch->filename,
                'rows_applied' => $batch->fresh()->rows_applied,
                'rows_skipped' => $batch->rows_invalid,
            ],
            branchId: $batch->branch_id,
        );

        return $batch->fresh();
    }

    /**
     * Deshace la carga.
     *
     * Se recorre **en orden inverso** porque una fila puede haber creado algo de
     * lo que dependen las siguientes — una categoría, un lote.
     */
    public function revert(ImportBatch $batch, ?string $employeeId): ImportBatch
    {
        if (! $batch->isApplied()) {
            throw ValidationException::withMessages([
                'batch' => __('import.only_applied_can_revert'),
            ]);
        }

        $importer = $this->registry->for($batch->kind);
        $failures = 0;

        DB::transaction(function () use ($batch, $importer, $employeeId, &$failures) {
            $rows = $batch->rows()->where('applied', true)->get()->sortByDesc('row_number');

            foreach ($rows as $row) {
                try {
                    $importer->revert($row);
                    $row->forceFill(['reverted' => true, 'revert_error' => null])->save();
                } catch (Throwable $e) {
                    // Lo que ya no se puede deshacer se anota y se sigue. Fallar
                    // entero dejaría la carga a medio revertir, que es peor.
                    $row->forceFill([
                        'reverted' => false,
                        'revert_error' => mb_substr($e->getMessage(), 0, 255),
                    ])->save();
                    $failures++;
                }
            }

            $batch->forceFill([
                'status' => ImportBatch::REVERTED,
                'reverted_at' => now(),
                'reverted_by' => $employeeId,
            ])->save();
        });

        $this->audit->record(
            event: 'import.reverted',
            entityType: 'import_batch',
            entityId: $batch->id,
            context: ['kind' => $batch->kind, 'rows_not_reverted' => $failures],
            branchId: $batch->branch_id,
        );

        return $batch->fresh();
    }

    /**
     * Estado de la entidad antes de tocarla.
     *
     * @return array<string,mixed>|null
     */
    private function snapshot(RowImporter $importer, ImportRow $row): ?array
    {
        if ($row->action !== 'update' || $row->entity_id === null) {
            return null;
        }

        return match ($importer->entityType()) {
            'product' => Product::find($row->entity_id)?->only([
                'name', 'price', 'cost', 'uom_id', 'tax_code_id', 'category_id',
                'tracks_stock', 'min_stock', 'is_active',
            ]),
            'customer' => ['role' => Customer::find($row->entity_id)?->only([
                'kind', 'code', 'is_tax_exempt', 'is_active',
            ])],
            'supplier' => ['role' => Supplier::find($row->entity_id)?->only([
                'code', 'kind', 'contact_name', 'credit_days', 'is_active',
            ])],
            default => null,
        };
    }

    /**
     * Lee el Excel y normaliza los encabezados.
     *
     * Los encabezados llegan como los escribió una persona: con mayúsculas,
     * tildes y espacios. Normalizarlos evita rechazar un archivo por escribir
     * "Código" en vez de "codigo".
     *
     * @return array<int,array<string,mixed>>
     */
    private function read(UploadedFile|string $file, RowImporter $importer): array
    {
        // Se pasa el `UploadedFile` entero, no su ruta: el archivo temporal no
        // tiene extensión y el lector no podría adivinar el formato.
        $sheets = Excel::toArray(new RawSheet, $file);
        $sheet = $sheets[0] ?? [];

        if ($sheet === []) {
            throw ValidationException::withMessages(['file' => __('import.empty_file')]);
        }

        $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), array_shift($sheet));
        $expected = array_keys($importer->columns());

        $missing = array_filter(
            $expected,
            fn ($column) => $importer->columns()[$column]['required'] && ! in_array($column, $headers, true)
        );

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => __('import.missing_columns', ['columns' => implode(', ', $missing)]),
            ]);
        }

        if (count($sheet) > self::MAX_ROWS) {
            throw new RuntimeException(__('import.too_many_rows', ['max' => self::MAX_ROWS]));
        }

        $rows = [];

        foreach ($sheet as $line) {
            $row = [];

            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = $line[$index] ?? null;
                }
            }

            // Una fila enteramente vacía es el final del archivo o un hueco
            // decorativo, no un error que reportar.
            if (array_filter($row, static fn ($v) => $v !== null && $v !== '') !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $clean = mb_strtolower(trim($header));
        $clean = strtr($clean, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return preg_replace('/[^a-z0-9]+/', '_', $clean) ?? $clean;
    }
}
