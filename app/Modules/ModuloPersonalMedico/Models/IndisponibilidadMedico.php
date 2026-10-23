<?php

namespace App\Modules\ModuloPersonalMedico\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Licencia o bloqueo de agenda de un médico (tabla 'indisponibilidad_medico').
 * CU09 solo la lee para calcular la disponibilidad; su gestión es CU10.
 */
#[Table(name: 'indisponibilidad_medico', timestamps: false)]
#[Fillable(['id_medico', 'fecha_inicio', 'fecha_fin', 'motivo'])]
class IndisponibilidadMedico extends Model
{
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
        ];
    }
}
