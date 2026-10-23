<?php

namespace App\Modules\ModuloPersonalMedico\Resources;

use App\Modules\ModuloSeguridad\Resources\UsuarioResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Legajo del médico (CU08). Incluye el salario: solo se expone a quien
 * tiene el permiso 'medicos_gestionar'.
 *
 * @mixin \App\Modules\ModuloPersonalMedico\Models\Medico
 */
class MedicoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id_usuario,
            'codigo' => $this->codigo,
            'salario' => $this->salario,
            'activo' => $this->activo,
            'usuario' => new UsuarioResource($this->whenLoaded('usuario')),
            'especialidades' => $this->whenLoaded('especialidades', fn () => $this->especialidades
                ->map(fn ($especialidad) => [
                    'id' => $especialidad->id,
                    'nombre' => $especialidad->nombre,
                    'activo' => $especialidad->activo,
                ])
                ->values()),
            'citas_pendientes' => $this->whenCounted('citasPendientes'),
        ];
    }
}
