<?php

namespace App\Modules\ModuloInteligenciaReportes\Resources;

use App\Modules\ModuloInteligenciaReportes\Models\Notificacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Notificación para la bandeja del paciente y el listado de Recepción (CU20).
 *
 * @mixin Notificacion
 */
class NotificacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $zona = config('app.timezone');

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'titulo' => Notificacion::TITULOS[$this->tipo] ?? $this->tipo,
            'mensaje' => $this->mensaje,
            'fecha' => $this->fecha_envio->setTimezone($zona)->toIso8601String(),
            'leida' => $this->leida,
            'fecha_lectura' => $this->fecha_lectura?->setTimezone($zona)->toIso8601String(),
            'estado_correo' => $this->estado_correo,
            'cita' => $this->whenLoaded('cita', fn () => $this->cita ? [
                'id' => $this->cita->id,
                'codigo' => $this->cita->codigo_cita,
                'fecha' => $this->cita->fecha->toDateString(),
                'hora' => substr($this->cita->hra_inicio, 0, 5),
            ] : null),
            // Solo en el listado de Recepción.
            'paciente' => $this->whenLoaded('paciente', fn () => [
                'id' => $this->paciente->id_usuario,
                'codigo' => $this->paciente->codigo,
                'nombre_completo' => "{$this->paciente->usuario->nombre} {$this->paciente->usuario->apellido}",
                'ci' => $this->paciente->usuario->ci,
            ]),
        ];
    }
}
