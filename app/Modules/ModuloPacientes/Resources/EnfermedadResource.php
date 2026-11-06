<?php

namespace App\Modules\ModuloPacientes\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\ModuloPacientes\Models\Enfermedad
 */
class EnfermedadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'nivel_riesgo' => $this->nivel_riesgo,
            'activo' => $this->activo,
            // Pacientes que la tienen como antecedente.
            'pacientes' => $this->whenCounted('pacientes'),
        ];
    }
}
