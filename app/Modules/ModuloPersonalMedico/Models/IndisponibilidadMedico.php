<?php

namespace App\Modules\ModuloPersonalMedico\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Licencia o bloqueo de agenda de un médico (tabla 'indisponibilidad_medico').
 * La gestiona CU10; CU09 la usa para marcar los turnos bloqueados.
 */
#[Table(name: 'indisponibilidad_medico', timestamps: false)]
#[Fillable(['id_medico', 'fecha_inicio', 'fecha_fin', 'motivo'])]
class IndisponibilidadMedico extends Model
{
    /**
     * Las columnas son timestamptz: se guardan con el desfase horario explícito
     * para no depender de la zona horaria de la conexión.
     */
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'immutable_datetime',
            'fecha_fin' => 'immutable_datetime',
            'citas_afectadas' => 'integer',
        ];
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico');
    }

    public function haTerminado(): bool
    {
        return $this->fecha_fin <= now();
    }

    public function haEmpezado(): bool
    {
        return $this->fecha_inicio <= now();
    }
}
