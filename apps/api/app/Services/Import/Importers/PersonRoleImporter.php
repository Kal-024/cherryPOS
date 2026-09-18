<?php

namespace App\Services\Import\Importers;

use App\Models\ImportRow;
use App\Models\Person;
use App\Services\Import\RowImporter;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Base de los importadores que dan de alta un **rol de una persona** (B-11):
 * clientes y proveedores.
 *
 * Los dos comparten el problema interesante: la fila trae una identidad que
 * puede existir ya, con otro rol. La cédula es la clave natural que los une, y
 * es la misma que usará la bandeja de conciliación al integrar el ERP (Q-04).
 *
 * **Sin cédula no hay fusión.** Dos filas que se llaman igual son dos personas
 * distintas: fusionar por nombre es exactamente lo que Q-04 prohíbe, y el día
 * que junte a dos clientes que no eran el mismo, nadie lo va a poder deshacer.
 */
abstract class PersonRoleImporter implements RowImporter
{
    use ParsesCells;

    /** @return class-string<Model> */
    abstract protected function roleModel(): string;

    /**
     * Atributos propios del rol. Recibe `$errors` por referencia porque hay
     * validaciones que solo el rol conoce — que una cuenta de crédito exija
     * cédula, por ejemplo.
     *
     * @param  array<string,mixed>  $raw
     * @param  array<int,string>  $errors
     */
    abstract protected function roleAttributes(array $raw, array &$errors = []): array;

    /** @param array<string,mixed> $raw */
    protected function personAttributes(array $raw): array
    {
        return array_filter([
            'full_name' => $this->text($raw['nombre'] ?? ''),
            'national_id' => $this->text($raw['cedula'] ?? '') ?: null,
            'tax_id' => $this->text($raw['ruc'] ?? '') ?: null,
            'email' => $this->text($raw['correo'] ?? '') ?: null,
            'phone' => $this->text($raw['telefono'] ?? '') ?: null,
            'whatsapp' => $this->text($raw['whatsapp'] ?? '') ?: null,
            'address' => $this->text($raw['direccion'] ?? '') ?: null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    public function inspect(array $raw, string $branchId): array
    {
        $errors = [];
        $person = $this->personAttributes($raw);

        if (($person['full_name'] ?? '') === '') {
            $errors[] = __('import.column_required', ['column' => 'nombre']);
        }

        $role = $this->roleAttributes($raw, $errors);

        // La identidad se reconoce por cédula. Sin ella, cada fila es alguien
        // nuevo — que es lo correcto para el cliente de efectivo.
        $existingPerson = ! empty($person['national_id'])
            ? Person::where('national_id', $person['national_id'])->first()
            : null;

        $model = $this->roleModel();
        $existing = $existingPerson
            ? $model::where('person_id', $existingPerson->id)->first()
            : null;

        return [
            'normalized' => ['person' => $person, 'role' => $role],
            'errors' => $errors,
            'action' => $errors !== [] ? 'skip' : ($existing ? 'update' : 'create'),
            'entity_id' => $existing?->id,
        ];
    }

    public function apply(ImportRow $row, string $branchId, ?string $employeeId): string
    {
        $data = $row->normalized;
        $model = $this->roleModel();

        if ($row->action === 'update') {
            $entity = $model::findOrFail($row->entity_id);
            $entity->update($data['role']);
            // La persona se completa, no se pisa: el otro rol pudo haber
            // cargado sus datos con más cuidado.
            $entity->person->fillMissing($data['person']);

            return $entity->id;
        }

        return $model::createWithPerson($data['person'], $data['role'])->id;
    }

    public function revert(ImportRow $row): void
    {
        $model = $this->roleModel();
        $entity = $model::find($row->entity_id);

        if (! $entity) {
            return;
        }

        if ($row->action === 'update') {
            $entity->forceFill($row->before['role'] ?? [])->save();

            return;
        }

        if ($this->hasHistory($entity)) {
            $entity->update(['is_active' => false]);

            throw new RuntimeException(__('import.entity_already_used'));
        }

        $personId = $entity->person_id;
        $entity->delete();

        // La persona solo se borra si no le quedó ningún rol: puede haber sido
        // creada por esta carga o haber existido desde antes con otro rol.
        $person = Person::find($personId);

        if ($person && $person->roles() === []) {
            $person->delete();
        }
    }

    /** ¿La entidad ya tiene movimiento que impida borrarla? */
    abstract protected function hasHistory(Model $entity): bool;
}
