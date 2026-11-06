<?php

namespace App\Modules\ModuloAtencionClinica\Models;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Consulta médica de una cita (tabla 'consulta_medica', CU17).
 * El IMC es una columna generada por PostgreSQL: nunca se escribe.
 */
#[Table(name: 'consulta_medica', timestamps: false)]
#[Fillable(['id_cita_medica', 'nro_expediente', 'peso', 'estatura', 'motivo_de_consultas', 'orden_cronologico', 'sintomas', 'tratamiento', 'escala_del_dolor'])]
class ConsultaMedica extends Model
{
    protected function casts(): array
    {
        return [
            'peso' => 'decimal:2',
            'estatura' => 'decimal:2',
            'imc' => 'decimal:2',
            'escala_del_dolor' => 'integer',
            'fecha_atencion' => 'datetime',
        ];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(CitaMedica::class, 'id_cita_medica');
    }

    /** Recetas emitidas en esta consulta (CU18). */
    public function recetas(): HasMany
    {
        return $this->hasMany(RecetaMedica::class, 'id_consulta');
    }
}
