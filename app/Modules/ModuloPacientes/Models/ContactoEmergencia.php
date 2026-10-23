<?php

namespace App\Modules\ModuloPacientes\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Contacto de emergencia de un paciente (tabla 'contacto_de_emergencia', CU11).
 */
#[Table(name: 'contacto_de_emergencia', timestamps: false)]
#[Fillable(['nombre', 'apellido', 'parentesco', 'telefono'])]
class ContactoEmergencia extends Model
{
}
