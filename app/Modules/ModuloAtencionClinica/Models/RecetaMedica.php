<?php

namespace App\Modules\ModuloAtencionClinica\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receta emitida en una consulta (tabla 'receta_medica', CU18).
 */
#[Table(name: 'receta_medica', timestamps: false)]
#[Fillable(['id_consulta', 'indicaciones_generales'])]
class RecetaMedica extends Model
{
    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
        ];
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(ConsultaMedica::class, 'id_consulta');
    }

    /** Medicamentos prescritos. */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleReceta::class, 'id_receta');
    }
}
