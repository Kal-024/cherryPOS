<?php

namespace App\Services\Catalog;

use App\Models\Employee;
use App\Models\Product;
use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * La lista 86: lo que se acabó hoy (F1-B).
 *
 * Del argot de cocina —*«86 the salmon»*— y es el mecanismo estándar de todo POS
 * de restaurante: el que está en la cocina marca que se acabó, y el plato aparece
 * atenuado en la pantalla del mesero, que deja de poder pedirlo.
 *
 * **No es `is_active`.** Desactivar el producto es darlo de baja del catálogo:
 * sale de los reportes y de las importaciones, y alguien tiene que acordarse de
 * reactivarlo. La disponibilidad del día es otra capa —efímera, con otro dueño—
 * que no toca el catálogo: el producto sigue existiendo, su precio no cambia y el
 * histórico queda intacto.
 *
 * **Se repone sola al empezar el servicio siguiente.** Sin eso la lista se
 * convierte en una tarea que alguien olvida, y el plato desaparece del menú sin
 * explicación. La reposición no ocurre en cada apertura de caja: ocurre cuando se
 * abre el **primer** turno del local, porque con tres cajas la segunda en abrir
 * reviviría a media mañana todo lo que la cocina marcó temprano.
 */
class AvailabilityService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Marca un producto como agotado del día.
     *
     * Vuelve a marcar sin quejarse: dos cocineros diciendo lo mismo no es un
     * error, y fallar ahí sería pedirle a alguien que revise la pantalla antes de
     * avisar que se acabó el pescado.
     */
    public function mark(Product $product, Employee $employee, ?string $branchId = null): Product
    {
        if (! $product->isUnavailable()) {
            $product->forceFill([
                'unavailable_since' => now(),
                'unavailable_by' => $employee->id,
            ])->save();

            // Queda en la bitácora porque es una decisión que le cuesta ventas al
            // local: en un reclamo —"me dijeron que no había y sí había"— es lo
            // único que dice a quién preguntarle.
            $this->audit->record(
                event: 'catalog.product_unavailable',
                entityType: 'product',
                entityId: $product->id,
                context: ['sku' => $product->sku, 'name' => $product->name],
                branchId: $branchId,
            );
        }

        return $product->fresh();
    }

    /** Vuelve a haber. */
    public function restore(Product $product, ?string $branchId = null): Product
    {
        if ($product->isUnavailable()) {
            $product->forceFill(['unavailable_since' => null, 'unavailable_by' => null])->save();

            $this->audit->record(
                event: 'catalog.product_available',
                entityType: 'product',
                entityId: $product->id,
                context: ['sku' => $product->sku, 'name' => $product->name],
                branchId: $branchId,
            );
        }

        return $product->fresh();
    }

    /**
     * Los identificadores de lo agotado.
     *
     * Viaja como lista de identificadores y **no dentro del catálogo cacheado**:
     * el catálogo es dato maestro que el terminal guarda en IndexedDB y relee
     * pocas veces (D-04), mientras esto cambia en medio del servicio. Mezclarlos
     * obligaría a rebajar todo el catálogo cada vez que se acaba un plato.
     *
     * @return array<int,string>
     */
    public function unavailableIds(): array
    {
        return Product::whereNotNull('unavailable_since')
            ->orderBy('unavailable_since')
            ->pluck('id')
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function unavailable(): array
    {
        return Product::whereNotNull('unavailable_since')
            ->with('unavailableBy.person')
            ->orderBy('unavailable_since')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'unavailable_since' => $product->unavailable_since,
                'unavailable_by' => $product->unavailableBy?->full_name,
            ])->all();
    }

    /**
     * Repone todo al empezar un servicio.
     *
     * Se llama al abrir turno **solo cuando no había ningún otro abierto**: es el
     * momento en que el local arranca y nadie pudo haber marcado nada todavía.
     */
    public function restoreForNewService(Shift $shift): int
    {
        $otherOpen = Shift::where('branch_id', $shift->branch_id)
            ->where('status', 'open')
            ->whereKeyNot($shift->id)
            ->exists();

        if ($otherOpen) {
            return 0;
        }

        $restored = DB::table('cat_products')
            ->whereNotNull('unavailable_since')
            ->update(['unavailable_since' => null, 'unavailable_by' => null]);

        if ($restored > 0) {
            $this->audit->record(
                event: 'catalog.availability_reset',
                entityType: 'shift',
                entityId: $shift->id,
                context: ['restored' => $restored, 'shift' => $shift->code],
                branchId: $shift->branch_id,
            );
        }

        return $restored;
    }
}
