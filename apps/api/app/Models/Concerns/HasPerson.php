<?php

namespace App\Models\Concerns;

use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Rol que cuelga de una persona (B-11).
 *
 * Los accesores delegan en `cmn_persons` para que el resto del código siga
 * leyendo `$customer->name` o `$employee->full_name` sin enterarse de que la
 * identidad se normalizó. Lo que **no** se delega es la escritura: quien crea un
 * cliente decide qué persona usa, y ahí está el punto de la decisión —
 * reutilizar la que ya existe o dar de alta una nueva.
 */
trait HasPerson
{
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getFullNameAttribute(): ?string
    {
        return $this->person?->full_name;
    }

    /** Alias de `full_name`. Un cliente se lee mejor como "nombre". */
    public function getNameAttribute(): ?string
    {
        return $this->person?->full_name;
    }

    public function getNationalIdAttribute(): ?string
    {
        return $this->person?->national_id;
    }

    public function getTaxIdAttribute(): ?string
    {
        return $this->person?->tax_id;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->person?->email;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->person?->phone;
    }

    public function getWhatsappAttribute(): ?string
    {
        return $this->person?->whatsapp;
    }

    public function getAddressAttribute(): ?string
    {
        return $this->person?->address;
    }

    /**
     * Crea el rol junto con su persona, **reutilizando la que ya exista** con
     * esa cédula.
     *
     * Es el comportamiento que justifica toda la normalización: dar de alta como
     * proveedor a alguien que ya es cliente no debe crear una segunda identidad.
     *
     * @param  array<string,mixed>  $person
     * @param  array<string,mixed>  $attributes
     */
    public static function createWithPerson(array $person, array $attributes = []): static
    {
        return DB::transaction(function () use ($person, $attributes) {
            $identity = ! empty($person['national_id'])
                ? Person::firstOrCreate(['national_id' => $person['national_id']], $person)
                : Person::create($person);

            // La persona existía pero con datos más pobres: se completan los
            // huecos sin pisar lo que ya había.
            $identity->fillMissing($person);

            return static::create(array_merge($attributes, ['person_id' => $identity->id]));
        });
    }

    /** Busca por nombre o documento, que es como busca un cajero. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereHas('person', fn ($q) => $q
            ->where('full_name', 'ilike', "%{$term}%")
            ->orWhere('national_id', 'ilike', "%{$term}%")
            ->orWhere('tax_id', 'ilike', "%{$term}%"));
    }
}
