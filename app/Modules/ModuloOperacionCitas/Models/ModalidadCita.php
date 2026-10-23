<?php

namespace App\Modules\ModuloOperacionCitas\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Modalidad de atención de una cita: Presencial, Virtual (Telemedicina), Domiciliaria
 * (tabla 'modalidad_cita', CU13).
 */
#[Table(name: 'modalidad_cita', timestamps: false)]
class ModalidadCita extends Model
{
}
