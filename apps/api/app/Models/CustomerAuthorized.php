<?php

namespace App\Models;

use App\Models\Concerns\HasPerson;
use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Persona autorizada a consumir contra una cuenta. Hasta tres (G-10).
 *
 * Es una persona con todas las letras (B-11): el día que abra su propia cuenta
 * o entre a trabajar, ya está identificada y no hay que cargarla de nuevo.
 */
class CustomerAuthorized extends Model
{
    use HasPerson, UsesUuid;

    protected $table = 'crm_customer_authorized';

    protected $fillable = ['customer_id', 'person_id', 'relationship', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $appends = ['name'];
}
