<?php

namespace App\Modules\ModuloPersonalMedico\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\ModuloPersonalMedico\Models\Especialidad
 */
class EspecialidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'activo' => $this->activo,
            'medicos_activos' => $this->whenCounted('medicos'),
            'medicos' => $this->whenLoaded('medicos', fn () => $this->medicos
                ->map(fn ($medico) => [
                    'id' => $medico->id_usuario,
                    'codigo' => $medico->codigo,
                    'nombre_completo' => "{$medico->usuario->nombre} {$medico->usuario->apellido}",
                    'activo' => $medico->activo,
                ])
                ->sortBy('nombre_completo')
                ->values()),
        ];
    }
}
