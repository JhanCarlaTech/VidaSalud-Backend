<?php

namespace App\Modules\ModuloSeguridad\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\ModuloSeguridad\Models\Rol
 */
class RolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'activo' => $this->activo,
            'es_sistema' => $this->esDelSistema(),
            'usuarios_count' => $this->whenCounted('usuarios'),
            'permisos' => PermisoResource::collection($this->whenLoaded('permisosActivos')),
        ];
    }
}
