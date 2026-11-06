<?php

namespace App\Modules\ModuloPacientes\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Antecedente del paciente: enfermedad del catálogo con los datos de
 * 'paciente_enfermedad' (CU12).
 *
 * @mixin \App\Modules\ModuloPacientes\Models\Enfermedad
 */
class AntecedenteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'enfermedad' => [
                'id' => $this->id,
                'nombre' => $this->nombre,
                'nivel_riesgo' => $this->nivel_riesgo,
                // Una enfermedad desactivada después sigue en los antecedentes (RN03).
                'activo' => $this->activo,
            ],
            'fecha_diagnostico' => $this->pivot->fecha_diagnostico,
            'observaciones' => $this->pivot->observaciones,
        ];
    }
}
