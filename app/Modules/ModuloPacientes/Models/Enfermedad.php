<?php

namespace App\Modules\ModuloPacientes\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Enfermedad del catálogo clínico (tabla 'enfermedad', CU12).
 */
#[Table(name: 'enfermedad', timestamps: false)]
#[Fillable(['nombre', 'descripcion', 'nivel_riesgo', 'activo'])]
class Enfermedad extends Model
{
    /** RN02: niveles de riesgo admitidos, de menor a mayor. */
    public const NIVELES_RIESGO = ['Bajo', 'Moderado', 'Alto', 'Crítico'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * Pacientes que la tienen registrada como antecedente (tabla 'paciente_enfermedad').
     */
    public function pacientes(): BelongsToMany
    {
        return $this->belongsToMany(Paciente::class, 'paciente_enfermedad', 'id_enfermedad', 'id_paciente');
    }
}
