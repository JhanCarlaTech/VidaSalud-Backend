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
#[Fillable(['nombre', 'descripcion', 'precio_consulta', 'activo'])]
class Especialidad extends Model
{
    /** Precio de la consulta (Bs) de una especialidad nueva si no se indica otro. */
    public const PRECIO_POR_DEFECTO = 150;

    /** Precios (Bs) de las especialidades que carga el script.sql. */
    public const PRECIOS_INICIALES = [
        'Medicina General' => 120,
        'Pediatría' => 150,
        'Ginecología' => 180,
        'Odontología' => 140,
        'Cardiología' => 220,
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'precio_consulta' => 'decimal:2',
        ];
    }

    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'medico_especialidad', 'id_especialidad', 'id_medico');
    }
}
