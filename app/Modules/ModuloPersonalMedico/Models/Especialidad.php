<?php

namespace App\Modules\ModuloPersonalMedico\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Especialidad médica de la clínica (tabla 'especialidad', CU07).
 */
#[Table(name: 'especialidad', timestamps: false)]
#[Fillable(['nombre', 'descripcion', 'activo'])]
class Especialidad extends Model
{
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'medico_especialidad', 'id_especialidad', 'id_medico');
    }
}
