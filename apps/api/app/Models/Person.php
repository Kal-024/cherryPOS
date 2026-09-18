<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Persona: la identidad detrás de cliente, proveedor y empleado (B-11).
 *
 * Un mismo actor cumple dos roles sin duplicarse. La cédula es la clave natural
 * que los une —y la que usa la bandeja de conciliación al integrar el ERP
 * (Q-04)—, pero no es obligatoria: la mayoría de los clientes de efectivo no la
 * dan, y no hay por qué pedírsela.
 */
class Person extends Model
{
    use UsesUuid;

    protected $table = 'cmn_persons';

    protected $fillable = [
        'kind', 'full_name', 'national_id', 'tax_id', 'email', 'phone',
        'whatsapp', 'address', 'birth_date', 'notes', 'is_active',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Completa lo que falta sin pisar lo que ya hay.
     *
     * Cuando alguien que ya es cliente se da de alta como proveedor, el alta
     * trae datos nuevos —un correo, una dirección— pero no debe borrar los que
     * el otro rol cargó con más cuidado.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function fillMissing(array $attributes): bool
    {
        $missing = [];

        foreach ($this->fillable as $field) {
            $incoming = $attributes[$field] ?? null;

            if ($incoming !== null && $incoming !== '' && blank($this->{$field})) {
                $missing[$field] = $incoming;
            }
        }

        return $missing === [] ? false : $this->forceFill($missing)->save();
    }

    /**
     * Roles que cumple hoy. Lo usa la pantalla de persona para no ofrecer un
     * alta que ya existe.
     *
     * @return array<int,string>
     */
    public function roles(): array
    {
        return array_keys(array_filter([
            'customer' => $this->customer()->exists(),
            'supplier' => $this->supplier()->exists(),
            'employee' => $this->employee()->exists(),
        ]));
    }
}
