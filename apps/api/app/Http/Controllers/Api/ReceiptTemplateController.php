<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReceiptTemplate;
use App\Services\Receipts\ReceiptRenderer;
use App\Services\Receipts\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Editor de plantillas de comprobante (D-11).
 *
 * La decisión fue ir al editor y no a las veinte banderas de OSPOS. Lo que hace
 * usable un editor es la **vista previa**: sin ella, configurar una plantilla es
 * teclear a ciegas y descubrir el resultado en el primer cliente.
 */
class ReceiptTemplateController extends Controller
{
    public function __construct(
        private ReceiptService $receipts,
        private ReceiptRenderer $renderer,
    ) {}

    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $items = ReceiptTemplate::query()
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->string('document_type')))
            ->orderBy('document_type')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('receipt.no_templates'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('receipt.templates_retrieved'),
            'data' => $items,
            'status' => 200,
        ], 200);
    }

    public function show(Request $request, string $id)
    {
        $template = $this->find($request, $id);

        if (! $template) {
            return response()->json(['message' => __('receipt.template_not_found'), 'status' => 404], 404);
        }

        return response()->json([
            'message' => __('receipt.template_retrieved'),
            'data' => $template,
            'status' => 200,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();
        $this->renderer->validate($data['content']);

        $template = ReceiptTemplate::create(array_merge($data, [
            'branch_id' => $request->attributes->get('branch_id'),
        ]));

        return response()->json([
            'message' => __('receipt.template_created'),
            'data' => $template,
            'status' => 201,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $template = $this->find($request, $id);

        if (! $template) {
            return response()->json(['message' => __('receipt.template_not_found'), 'status' => 404], 404);
        }

        if ($template->is_system && $template->branch_id === null) {
            // Las del sistema son el respaldo cuando alguien rompe la suya. Se
            // copian a la sucursal y se edita la copia.
            return response()->json([
                'message' => __('receipt.system_template_readonly'),
                'status' => 422,
            ], 422);
        }

        $validator = Validator::make($request->all(), $this->rules(sometimes: true));

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        if (isset($data['content'])) {
            $this->renderer->validate($data['content']);
        }

        DB::transaction(function () use ($template, $data) {
            $template->update($data);

            // Predeterminada hay una sola por tipo de documento y sucursal. Sin
            // esto el desempate quedaba en el orden que devolviera la base, que
            // es decir "cualquiera".
            //
            // Elegirla la enciende: marcar como predeterminada una plantilla
            // apagada y que se siguiera imprimiendo otra sería el mismo engaño
            // que se acaba de quitar al duplicar.
            if (($data['is_default'] ?? false) === true) {
                $template->update(['is_active' => true]);

                ReceiptTemplate::where('branch_id', $template->branch_id)
                    ->where('document_type', $template->document_type)
                    ->whereKeyNot($template->id)
                    ->update(['is_default' => false]);
            }
        });

        return response()->json([
            'message' => __('receipt.template_updated'),
            'data' => $template->fresh(),
            'status' => 200,
        ], 200);
    }

    /**
     * Borra una copia de la sucursal.
     *
     * Las del sistema no se tocan: son el punto de retorno cuando alguien deja
     * la suya irreconocible. Tampoco se borra la que está en uso — quedarse sin
     * plantilla predeterminada deja la caja sin poder emitir, y eso se descubre
     * con un cliente delante.
     */
    public function destroy(Request $request, string $id)
    {
        $template = $this->find($request, $id);

        if (! $template) {
            return response()->json(['message' => __('receipt.template_not_found'), 'status' => 404], 404);
        }

        if ($template->is_system && $template->branch_id === null) {
            return response()->json([
                'message' => __('receipt.system_template_readonly'),
                'status' => 422,
            ], 422);
        }

        if ($template->is_default) {
            return response()->json([
                'message' => __('receipt.default_template_kept'),
                'status' => 422,
            ], 422);
        }

        $template->delete();

        return response()->json([
            'message' => __('receipt.template_deleted'),
            'data' => null,
            'status' => 200,
        ], 200);
    }

    /**
     * Copia una plantilla del sistema a la sucursal, para poder editarla.
     *
     * Es lo que permite personalizar sin perder el respaldo: la original queda
     * intacta y sirve de punto de retorno cuando alguien deja la suya
     * irreconocible.
     */
    public function duplicate(Request $request, string $id)
    {
        $template = $this->find($request, $id);

        if (! $template) {
            return response()->json(['message' => __('receipt.template_not_found'), 'status' => 404], 404);
        }

        $copy = $template->replicate(['is_system']);
        $copy->branch_id = $request->attributes->get('branch_id');
        // Microsegundos, no segundos: con precisión de segundo, duplicar dos
        // veces seguidas —o un doble clic— chocaba contra el índice único de
        // (sucursal, código) y devolvía un error de base de datos en la cara.
        $copy->code = mb_substr($template->code, 0, 20).'-'.now()->format('ymdHisu');
        $copy->name = $template->name.' ('.__('receipt.copy').')';
        $copy->is_system = false;
        // **La copia nace apagada.** `ReceiptTemplate::resolve()` prefiere la de
        // la sucursal sobre la del sistema, así que una copia activa pasaba a ser
        // lo que se imprimía **desde el momento de duplicarla**: quien duplicaba
        // para probar un diseño ya había cambiado el comprobante real sin que
        // nada se lo dijera. Apagada, duplicar no cambia nada y elegir qué se usa
        // vuelve a ser un acto explícito.
        $copy->is_default = false;
        $copy->is_active = false;
        $copy->save();

        return response()->json([
            'message' => __('receipt.template_duplicated'),
            'data' => $copy,
            'status' => 201,
        ], 201);
    }

    /** Vista previa con datos de ejemplo. Sin esto, el editor no sirve. */
    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|array',
            'paper' => 'nullable|in:thermal_58,thermal_80,letter,a4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        return response()->json([
            'message' => __('receipt.preview_rendered'),
            'data' => [
                'paper' => $data['paper'] ?? 'thermal_80',
                'width' => ReceiptTemplate::WIDTHS[$data['paper'] ?? 'thermal_80'],
                'lines' => $this->receipts->preview($data['content'], $data['paper'] ?? 'thermal_80'),
            ],
            'status' => 200,
        ], 200);
    }

    /** @return array<string,mixed> */
    private function rules(bool $sometimes = false): array
    {
        $prefix = $sometimes ? 'sometimes|' : '';

        return [
            'code' => $prefix.'required|string|max:40',
            'name' => $prefix.'required|string|max:120',
            'document_type' => $prefix.'required|string|max:30',
            'paper' => $prefix.'required|in:thermal_58,thermal_80,letter,a4',
            'content' => $prefix.'required|array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    private function find(Request $request, string $id): ?ReceiptTemplate
    {
        $branchId = $request->attributes->get('branch_id');

        return ReceiptTemplate::where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->find($id);
    }
}
