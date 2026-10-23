<?php

namespace App\Modules\ModuloPersonalMedico\Resources;

use App\Modules\ModuloPersonalMedico\Models\HorarioAtencion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\ModuloPersonalMedico\Models\HorarioAtencion
 */
class HorarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dia_semana' => $this->dia_semana,
            'dia' => $this->nombreDia(),
            'hora_inicio' => HorarioAtencion::horaCorta($this->hora_inicio),
            'hora_fin' => HorarioAtencion::horaCorta($this->hora_fin),
            'duracion_minutos' => $this->duracion_minutos,
            'turnos' => $this->cantidadTurnos(),
            'activo' => $this->activo,
        ];
    }
}
