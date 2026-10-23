<?php

namespace App\Modules\ModuloPacientes\Resources;

use App\Modules\ModuloSeguridad\Resources\UsuarioResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ficha del paciente (CU11).
 *
 * @mixin \App\Modules\ModuloPacientes\Models\Paciente
 */
class PacienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $contacto = $this->whenLoaded('contactoEmergencia');

        return [
            'id' => $this->id_usuario,
            'codigo' => $this->codigo,
            'fecha_nacimiento' => $this->fecha_nacimiento?->toDateString(),
            'edad' => $this->edad(),
            'tipo_sangre' => $this->tipo_sangre,
            'activo' => $this->activo,
            'usuario' => new UsuarioResource($this->whenLoaded('usuario')),
            'contacto' => $this->when($this->relationLoaded('contactoEmergencia'), fn () => $contacto ? [
                'nombre' => $contacto->nombre,
                'apellido' => $contacto->apellido,
                'parentesco' => $contacto->parentesco,
                'telefono' => $contacto->telefono,
            ] : null),
            'citas_pendientes' => $this->whenCounted('citasPendientes'),
        ];
    }
}
