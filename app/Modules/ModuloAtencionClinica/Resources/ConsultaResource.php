<?php

namespace App\Modules\ModuloAtencionClinica\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\ModuloAtencionClinica\Models\ConsultaMedica
 */
class ConsultaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo_cita' => $this->whenLoaded('cita', fn () => $this->cita->codigo_cita),
            'nro_expediente' => $this->nro_expediente,
            'peso' => $this->peso,
            'estatura' => $this->estatura,
            'imc' => $this->imc,
            'motivo_de_consultas' => $this->motivo_de_consultas,
            'orden_cronologico' => $this->orden_cronologico,
            'sintomas' => $this->sintomas,
            'tratamiento' => $this->tratamiento,
            'escala_del_dolor' => $this->escala_del_dolor,
            'fecha_atencion' => $this->fecha_atencion?->toIso8601String(),
        ];
    }
}
