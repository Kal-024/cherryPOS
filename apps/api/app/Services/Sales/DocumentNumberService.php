<?php

namespace App\Services\Sales;

use App\Models\DocumentSeries;
use App\Models\SequenceReservation;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Numeración de documentos por plantilla de tokens (B-05).
 *
 * El formato lo define una plantilla configurable, no el código:
 * `{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}`. Cambiar cómo se ven las facturas de un
 * cliente no debería exigir un despliegue.
 *
 * Secuencias independientes **por tipo de documento, por año y por sucursal**
 * (precondición 3). Dos locales no comparten correlativo: el día que el cliente
 * abra el segundo, la consolidación en casa matriz encontraría dos facturas
 * número 1.
 *
 * **Desacoplado de la lógica de venta a propósito** (P-08): un módulo de
 * facturación electrónica futuro toma la numeración sin tocar el flujo de caja.
 * Y si el cliente contrata el ERP, la configuración de series pasa a su módulo
 * de facturación tomando como punto de partida lo que el POS venía usando.
 */
class DocumentNumberService
{
    /**
     * Reserva el siguiente número de la serie.
     *
     * El bloqueo de fila es obligatorio: dos cajas cerrando a la vez es el caso
     * normal en hora pico, y dos tickets con el mismo número es un problema
     * fiscal, no una molestia.
     */
    public function next(string $branchId, string $branchCode, string $documentType): string
    {
        return DB::transaction(function () use ($branchId, $branchCode, $documentType) {
            $year = (int) now()->format('Y');

            $series = DocumentSeries::query()
                ->where('branch_id', $branchId)
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            // El cambio de año no puede exigir intervención: la caja del 1 de
            // enero abre igual que la del 31 de diciembre.
            if (! $series) {
                $series = DocumentSeries::create([
                    'branch_id' => $branchId,
                    'document_type' => $documentType,
                    'year' => $year,
                    'template' => $this->inheritTemplate($branchId, $documentType),
                    'next_number' => 1,
                    'is_active' => true,
                ]);
            }

            $number = $series->next_number;

            if ($series->range_to !== null && $number > $series->range_to) {
                throw new RuntimeException(
                    "La serie {$documentType} agotó su rango reservado ({$series->range_to})."
                );
            }

            $series->next_number = $number + 1;
            $series->save();

            return $this->render($series->template, $branchCode, $documentType, $year, $number);
        });
    }

    /** Cómo se vería el siguiente número, sin consumirlo. */
    public function preview(string $branchId, string $branchCode, string $documentType): ?string
    {
        $year = (int) now()->format('Y');

        $series = DocumentSeries::query()
            ->where('branch_id', $branchId)
            ->where('document_type', $documentType)
            ->where('year', $year)
            ->first();

        if (! $series) {
            return null;
        }

        return $this->render($series->template, $branchCode, $documentType, $year, $series->next_number);
    }

    public function render(
        string $template,
        string $branchCode,
        string $documentType,
        int $year,
        int $sequence,
    ): string {
        $replacements = [
            '{BRANCH}' => $branchCode,
            '{TYPE}' => strtoupper(substr($documentType, 0, 3)),
            '{YEAR}' => (string) $year,
            '{YY}' => substr((string) $year, -2),
        ];

        $rendered = strtr($template, $replacements);

        // `{SEQ:6}` rellena con ceros a seis dígitos; `{SEQ}` no rellena. El
        // ancho es parte del formato del cliente, no del código.
        return preg_replace_callback(
            '/\{SEQ(?::(\d+))?\}/',
            static fn (array $m) => isset($m[1])
                ? str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT)
                : (string) $sequence,
            $rendered
        ) ?? $rendered;
    }

    /**
     * Reserva un bloque de correlativos para una terminal (H6.3).
     *
     * El bloque sale de la misma serie: se avanza `next_number` tantos números
     * como pida la reserva, y esos quedan apartados. Así la numeración en línea
     * sigue su curso sin pisarse con la que el terminal use sin conexión.
     *
     * Reservar de nuevo **cierra el bloque anterior**. Los números que queden
     * sin usar se pierden, y está bien: un hueco en la numeración se explica,
     * dos facturas con el mismo número no.
     */
    public function reserve(Terminal $terminal, string $documentType, int $size = 100): SequenceReservation
    {
        if ($size < 1 || $size > 5000) {
            throw ValidationException::withMessages([
                'size' => __('sequence.invalid_size'),
            ]);
        }

        return DB::transaction(function () use ($terminal, $documentType, $size) {
            $year = (int) now()->format('Y');

            $series = DocumentSeries::query()
                ->where('branch_id', $terminal->branch_id)
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $series) {
                $series = DocumentSeries::create([
                    'branch_id' => $terminal->branch_id,
                    'document_type' => $documentType,
                    'year' => $year,
                    'template' => $this->inheritTemplate($terminal->branch_id, $documentType),
                    'next_number' => 1,
                    'is_active' => true,
                ]);
            }

            SequenceReservation::where('terminal_id', $terminal->id)
                ->where('series_id', $series->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'exhausted_at' => now()]);

            $from = $series->next_number;
            $to = $from + $size - 1;

            $series->next_number = $to + 1;
            $series->save();

            return SequenceReservation::create([
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                'series_id' => $series->id,
                'range_from' => $from,
                'range_to' => $to,
                'next_number' => $from,
                'is_active' => true,
            ]);
        });
    }

    /** La reserva vigente de una terminal, si tiene. */
    public function reservationFor(Terminal $terminal, string $documentType): ?SequenceReservation
    {
        $year = (int) now()->format('Y');

        return SequenceReservation::query()
            ->where('terminal_id', $terminal->id)
            ->where('is_active', true)
            ->whereHas('series', fn ($q) => $q
                ->where('document_type', $documentType)
                ->where('year', $year))
            ->first();
    }

    /**
     * Comprueba que un número emitido sin conexión salga del bloque de esa
     * terminal, y marca ese número como consumido.
     *
     * Es la verificación que hace segura toda la idea: sin ella, un terminal con
     * un error de programación —o alguien manipulando la petición— podría
     * inventar números y duplicar facturas.
     */
    public function consumeReserved(Terminal $terminal, string $documentType, string $number): void
    {
        $reservation = $this->reservationFor($terminal, $documentType);

        if (! $reservation) {
            throw ValidationException::withMessages([
                'number' => __('sequence.no_reservation'),
            ]);
        }

        $sequence = $this->sequenceOf($number);

        if ($sequence === null || ! $reservation->covers($sequence)) {
            throw ValidationException::withMessages([
                'number' => __('sequence.outside_reservation', [
                    'number' => $number,
                    'from' => (string) $reservation->range_from,
                    'to' => (string) $reservation->range_to,
                ]),
            ]);
        }

        // El puntero avanza al siguiente sin usar. No se exige orden estricto:
        // un ticket puede llegar antes que otro y eso no invalida ninguno.
        if ($sequence >= $reservation->next_number) {
            $reservation->next_number = $sequence + 1;
            $reservation->save();
        }
    }

    /**
     * El correlativo dentro de un número ya formateado.
     *
     * Se toma el último grupo de dígitos de la plantilla: `001-COU-2026-000042`
     * da 42. Depende del formato, y por eso el formato es de la serie y no del
     * código.
     */
    public function sequenceOf(string $number): ?int
    {
        preg_match_all('/\d+/', $number, $matches);

        $groups = $matches[0] ?? [];

        return $groups === [] ? null : (int) end($groups);
    }

    /**
     * Plantilla del año anterior, si la hubo.
     *
     * Al crear la serie del año nuevo se hereda el formato que el cliente venía
     * usando: cambiarlo en silencio cada 1 de enero sería una sorpresa fea.
     */
    private function inheritTemplate(string $branchId, string $documentType): string
    {
        return DocumentSeries::query()
            ->where('branch_id', $branchId)
            ->where('document_type', $documentType)
            ->orderByDesc('year')
            ->value('template') ?? '{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}';
    }
}
