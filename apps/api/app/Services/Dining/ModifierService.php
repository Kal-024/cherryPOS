<?php

namespace App\Services\Dining;

use App\Models\Modifier;
use App\Models\Product;
use App\Models\SaleLine;
use App\Models\SaleLineModifier;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Modificadores de una línea (B-06, F1-B).
 *
 * **Los obligatorios se validan en el servidor.** El plan dice que bloquean el
 * envío a cocina, y una regla que solo existe en la pantalla se salta con la
 * primera terminal nueva, con la primera llamada a la API y con el primer
 * ticket recuperado del modo degradado.
 *
 * El precio de un modificador se suma al de la línea **antes** de calcular, y
 * viene con el impuesto adentro igual que el precio de góndola: mezclar
 * criterios haría que "doble queso" tributara distinto que el plato al que se le
 * agrega.
 */
class ModifierService
{
    /**
     * Valida lo elegido contra los grupos del producto.
     *
     * @param  array<int,string>  $modifierIds
     * @return Collection<int,Modifier>
     */
    public function validate(Product $product, array $modifierIds): Collection
    {
        $groups = $product->modifierGroups()->with('modifiers')->get();

        if ($groups->isEmpty() && $modifierIds !== []) {
            throw ValidationException::withMessages([
                'modifiers' => __('dining.product_without_modifiers', ['name' => $product->name]),
            ]);
        }

        $allowed = $groups->flatMap(fn ($group) => $group->modifiers)
            ->filter(fn (Modifier $modifier) => $modifier->is_active)
            ->keyBy('id');

        $chosen = collect($modifierIds)->unique()->map(function (string $id) use ($allowed, $product) {
            $modifier = $allowed->get($id);

            if (! $modifier) {
                // Elegir un modificador de otro plato es un error de la
                // pantalla, no del mesero: se rechaza en vez de cobrarlo.
                throw ValidationException::withMessages([
                    'modifiers' => __('dining.modifier_not_allowed', ['name' => $product->name]),
                ]);
            }

            return $modifier;
        });

        foreach ($groups as $group) {
            $count = $chosen->where('group_id', $group->id)->count();

            if ($count < $group->min_select) {
                throw ValidationException::withMessages([
                    'modifiers' => __('dining.modifier_required', [
                        'group' => $group->name,
                        'min' => $group->min_select,
                    ]),
                ]);
            }

            if ($group->max_select !== null && $count > $group->max_select) {
                throw ValidationException::withMessages([
                    'modifiers' => __('dining.modifier_too_many', [
                        'group' => $group->name,
                        'max' => $group->max_select,
                    ]),
                ]);
            }
        }

        return $chosen->values();
    }

    /**
     * Cuánto suma —o resta— lo elegido.
     *
     * @param  Collection<int,Modifier>  $modifiers
     */
    public function priceDelta(Collection $modifiers): string
    {
        return $modifiers->reduce(
            fn (string $total, Modifier $modifier) => bcadd($total, (string) $modifier->price_delta, 2),
            '0.00'
        );
    }

    /**
     * Copia lo elegido a la línea.
     *
     * @param  Collection<int,Modifier>  $modifiers
     */
    public function attach(SaleLine $line, Collection $modifiers): void
    {
        foreach ($modifiers as $modifier) {
            SaleLineModifier::create([
                'sale_line_id' => $line->id,
                'modifier_id' => $modifier->id,
                'name' => $modifier->name,
                'price_delta' => (string) $modifier->price_delta,
            ]);
        }
    }

    /**
     * Lo que falta elegir en una línea ya cargada.
     *
     * Se usa al mandar a cocina: una línea creada antes de que el grupo fuera
     * obligatorio, o recuperada del modo degradado, puede haber quedado sin
     * responder, y quien lo descubre no puede ser el cocinero.
     *
     * @return array<int,string>
     */
    public function missingGroups(SaleLine $line): array
    {
        $product = $line->product;

        if (! $product) {
            return [];
        }

        $chosen = $line->modifiers->pluck('modifier_id')->filter()->all();
        $missing = [];

        foreach ($product->modifierGroups()->with('modifiers')->get() as $group) {
            if ($group->min_select === 0) {
                continue;
            }

            $count = $group->modifiers->whereIn('id', $chosen)->count();

            if ($count < $group->min_select) {
                $missing[] = $group->name;
            }
        }

        return $missing;
    }
}
