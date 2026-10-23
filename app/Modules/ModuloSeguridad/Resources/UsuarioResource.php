<?php

namespace App\Modules\ModuloSeguridad\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Datos públicos de un usuario. Nunca incluye la contraseña (CU01, RN07).
 * Los roles y las extensiones se incluyen solo si fueron cargados.
 *
 * @mixin \App\Modules\ModuloSeguridad\Models\Usuario
 */
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ci' => $this->ci,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'nombre_completo' => "{$this->nombre} {$this->apellido}",
            'correo' => $this->correo,
            'telefono' => $this->telefono,
            'sexo' => $this->sexo,
            'activo' => $this->activo,
            'fecha_creacion' => $this->fecha_creacion?->toIso8601String(),
            'roles' => $this->whenLoaded('rolesActivos', fn () => $this->rolesActivos
                ->map(fn ($rol) => ['id' => $rol->id, 'nombre' => $rol->nombre])
                ->values()),
            // Extensiones exigidas por los roles Médico y Paciente (CU02, RN04).
            'es_medico' => $this->whenHas('medico_exists'),
            'es_paciente' => $this->whenHas('paciente_exists'),
        ];
    }
}
