<?php

namespace App\Services\Import\Importers;

use App\Models\CreditAccount;
use App\Models\Customer;
use App\Models\ImportRow;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;

/**
 * Importación de clientes (D-15).
 *
 * El cliente con cuenta exige cédula, porque la cuenta es personal y la cédula
 * es su código (G-10). El de efectivo no: paga y se va, y pedírsela sería
 * inventar un requisito que el mostrador no tiene (P-03).
 */
class CustomerImporter extends PersonRoleImporter
{
    public function entityType(): string
    {
        return 'customer';
    }

    public function columns(): array
    {
        return [
            'nombre' => ['required' => true, 'label' => 'Nombre'],
            'tipo' => ['required' => false, 'label' => 'Tipo: efectivo o cuenta'],
            'cedula' => ['required' => false, 'label' => 'Cédula (obligatoria si lleva cuenta)'],
            'ruc' => ['required' => false, 'label' => 'RUC'],
            'correo' => ['required' => false, 'label' => 'Correo'],
            'telefono' => ['required' => false, 'label' => 'Teléfono'],
            'whatsapp' => ['required' => false, 'label' => 'WhatsApp'],
            'direccion' => ['required' => false, 'label' => 'Dirección'],
            'limite_credito' => ['required' => false, 'label' => 'Límite de crédito'],
            'exonerado' => ['required' => false, 'label' => 'Exonerado de IVA'],
        ];
    }

    protected function roleModel(): string
    {
        return Customer::class;
    }

    protected function roleAttributes(array $raw, array &$errors = []): array
    {
        $kind = strtolower($this->text($raw['tipo'] ?? 'efectivo'));
        $kind = in_array($kind, ['cuenta', 'account', 'credito', 'crédito'], true) ? 'account' : 'cash';

        if ($kind === 'account' && $this->text($raw['cedula'] ?? '') === '') {
            // La cuenta es personal: sin cédula no se sabe de quién es.
            $errors[] = __('import.account_needs_national_id');
        }

        return [
            'kind' => $kind,
            'code' => $this->text($raw['codigo'] ?? '') ?: null,
            'is_tax_exempt' => $this->boolean($raw['exonerado'] ?? null),
            'credit_limit' => $this->decimal($raw['limite_credito'] ?? null),
        ];
    }

    public function apply(ImportRow $row, string $branchId, ?string $employeeId): string
    {
        $data = $row->normalized;
        // El límite de crédito no es del cliente sino de su cuenta: se aparta
        // antes de guardar y se aplica después.
        $limit = $data['role']['credit_limit'] ?? null;
        unset($data['role']['credit_limit']);
        $row->normalized = $data;

        $id = parent::apply($row, $branchId, $employeeId);

        if ($limit !== null && ($data['role']['kind'] ?? 'cash') === 'account') {
            CreditAccount::updateOrCreate(
                ['customer_id' => $id],
                ['credit_limit' => $limit]
            );
        }

        return $id;
    }

    protected function hasHistory(Model $entity): bool
    {
        // Un cliente con ventas no se borra: sus tickets son inmutables y
        // quedarían apuntando al vacío.
        return Sale::where('customer_id', $entity->id)->exists();
    }
}
