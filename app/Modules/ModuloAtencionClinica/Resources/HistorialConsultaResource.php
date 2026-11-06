<?php

namespace App\Modules\ModuloAtencionClinica\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Consulta del historial clínico (CU19): quién atendió, signos vitales, evolución
 * y las recetas emitidas en ella.
 *
 * @mixin \App\Modules\ModuloAtencionClinica\Models\ConsultaMedica
 */
class HistorialConsultaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $zona = config('app.timezone');
        $medico = $this->cita->medico;

        return [
            'id' => $this->id,
            'fecha_atencion' => $this->fecha_atencion->setTimezone($zona)->toIso8601String(),
            'nro_expediente' => $this->nro_expediente,
            'codigo_cita' => $this->cita->codigo_cita,
            'medico' => [
                'codigo' => $medico->codigo,
                'nombre_completo' => "{$medico->usuario->nombre} {$medico->usuario->apellido}",
                'especialidades' => $medico->especialidades->pluck('nombre')->sort()->values(),
            ],
            // La atendió quien consulta el historial.
            'es_propia' => (int) $this->cita->id_medico === (int) $request->user()->id,
            'signos_vitales' => [
                'peso' => $this->peso !== null ? (float) $this->peso : null,
                'estatura' => $this->estatura !== null ? (float) $this->estatura : null,
                'imc' => $this->imc !== null ? (float) $this->imc : null,
                'escala_del_dolor' => $this->escala_del_dolor,
            ],
            'motivo' => $this->motivo_de_consultas,
            'orden_cronologico' => $this->orden_cronologico,
            'sintomas' => $this->sintomas,
            'tratamiento' => $this->tratamiento,
            'recetas' => $this->recetas->map(fn ($receta) => [
                'id' => $receta->id,
                'numero' => RecetaResource::numero($receta->id),
                'fecha_emision' => $receta->fecha_emision->setTimezone($zona)->toIso8601String(),
                'indicaciones_generales' => $receta->indicaciones_generales,
                'medicamentos' => $receta->detalles->map(fn ($detalle) => [
                    'medicamento' => $detalle->medicamento,
                    'dosis' => $detalle->dosis,
                    'frecuencia' => $detalle->frecuencia,
                    'duracion_dias' => $detalle->duracion_dias,
                ]),
            ]),
        ];
    }
}
