<?php

namespace App\Modules\ModuloOperacionCitas\Resources;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Services\CitaService;
use App\Modules\ModuloPersonalMedico\Models\HorarioAtencion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\ModuloOperacionCitas\Models\CitaMedica
 */
class CitaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo_cita,
            'fecha' => $this->fecha->toDateString(),
            'hora_inicio' => HorarioAtencion::horaCorta($this->hra_inicio),
            'hora_fin' => HorarioAtencion::horaCorta($this->hra_fin),
            // Momento exacto de inicio, para que el cliente aplique la anticipación de CU14.
            'inicio' => CitaService::inicio($this->resource)->toIso8601String(),
            'estado' => $this->estado,
            // Se puede reprogramar o cancelar (CU14): Programada o Confirmada.
            'pendiente' => in_array($this->estado, CitaMedica::ESTADOS_MODIFICABLES, true),
            'motivo' => $this->motivo,
            'motivo_cancelacion' => $this->motivo_cancelacion,
            'modalidad' => $this->whenLoaded('modalidad', fn () => [
                'id' => $this->modalidad->id,
                'nombre' => $this->modalidad->nombre,
            ]),
            'paciente' => $this->whenLoaded('paciente', fn () => [
                'id' => $this->paciente->id_usuario,
                'codigo' => $this->paciente->codigo,
                'nombre_completo' => "{$this->paciente->usuario->nombre} {$this->paciente->usuario->apellido}",
            ]),
            'medico' => $this->whenLoaded('medico', fn () => [
                'id' => $this->medico->id_usuario,
                'codigo' => $this->medico->codigo,
                'nombre_completo' => "{$this->medico->usuario->nombre} {$this->medico->usuario->apellido}",
                'especialidades' => $this->medico->especialidades->pluck('nombre'),
                'activo' => $this->medico->activo,
                // Días en que atiende (1 = lunes), para sugerir fechas al reprogramar (CU14).
                'dias_atencion' => $this->medico->relationLoaded('horarios')
                    ? $this->medico->horarios->pluck('dia_semana')->unique()->sort()->values()
                    : [],
            ]),
            'fecha_creacion' => $this->fecha_creacion?->toIso8601String(),
        ];
    }
}
