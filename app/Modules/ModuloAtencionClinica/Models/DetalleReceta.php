<?php

namespace App\Modules\ModuloAtencionClinica\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medicamento de una receta: dosis, frecuencia y duración (tabla 'detalle_receta', CU18).
 */
#[Table(name: 'detalle_receta', timestamps: false)]
#[Fillable(['id_receta', 'medicamento', 'dosis', 'frecuencia', 'duracion_dias'])]
class DetalleReceta extends Model
{
    protected function casts(): array
    {
        return [
            'duracion_dias' => 'integer',
        ];
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(RecetaMedica::class, 'id_receta');
    }
}
