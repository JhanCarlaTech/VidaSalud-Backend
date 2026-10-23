<?php

namespace App\Modules\ModuloSeguridad\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\ModuloSeguridad\Models\Permiso
 */
class PermisoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accion' => $this->accion,
            'descripcion' => $this->descripcion,
        ];
    }
}
