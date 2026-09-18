<?php

namespace App\Services\Import\Importers;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Importación de proveedores (D-15, B-12).
 *
 * `tipo` separa al de mercadería del de gastos. Si la columna no viene, se
 * asume mercadería: es lo que el negocio carga primero y lo que más filas trae.
 */
class SupplierImporter extends PersonRoleImporter
{
    public function entityType(): string
    {
        return 'supplier';
    }

    public function columns(): array
    {
        return [
            'nombre' => ['required' => true, 'label' => 'Nombre o razón social'],
            'tipo' => ['required' => false, 'label' => 'Tipo: mercaderia o gastos'],
            'codigo' => ['required' => false, 'label' => 'Código'],
            'cedula' => ['required' => false, 'label' => 'Cédula'],
            'ruc' => ['required' => false, 'label' => 'RUC'],
            'correo' => ['required' => false, 'label' => 'Correo'],
            'telefono' => ['required' => false, 'label' => 'Teléfono'],
            'direccion' => ['required' => false, 'label' => 'Dirección'],
            'contacto' => ['required' => false, 'label' => 'Persona de contacto'],
            'dias_credito' => ['required' => false, 'label' => 'Días de crédito'],
        ];
    }

    protected function roleModel(): string
    {
        return Supplier::class;
    }

    protected function roleAttributes(array $raw, array &$errors = []): array
    {
        $kind = strtolower($this->text($raw['tipo'] ?? ''));
        $isExpense = in_array($kind, ['gasto', 'gastos', 'expense', 'servicios'], true);

        return [
            'code' => $this->text($raw['codigo'] ?? '') ?: null,
            'kind' => $isExpense ? Supplier::EXPENSE : Supplier::MERCHANDISE,
            'contact_name' => $this->text($raw['contacto'] ?? '') ?: null,
            'credit_days' => $this->integer($raw['dias_credito'] ?? null) ?? 0,
        ];
    }

    protected function hasHistory(Model $entity): bool
    {
        // En F1 el proveedor todavía no acumula documentos propios: las compras
        // son del ERP (D-08). Se revisa el gasto, que sí existe (B-14).
        return DB::table('exp_expenses')
            ->where('supplier_id', $entity->id)
            ->exists();
    }
}
