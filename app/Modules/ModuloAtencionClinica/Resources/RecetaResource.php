<?php

namespace App\Modules\ModuloAtencionClinica\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Receta médica completa, lista para mostrarse e imprimirse (CU18).
 *
 * @mixin \App\Modules\ModuloAtencionClinica\Models\RecetaMedica
 */
class RecetaResource extends JsonResource
{
    /** Número visible del documento: 2 → 'REC-000002'. */
    public static function numero(int $id): string
    {
        return 'REC-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function toArray(Request $request): array
    {
        $consulta = $this->consulta;
        $paciente = $consulta->cita->paciente;
        $medico = $consulta->cita->medico;
        $zona = config('app.timezone');

        return [
            'id' => $this->id,
            'numero' => self::numero($this->id),
            'fecha_emision' => $this->fecha_emision->setTimezone($zona)->toIso8601String(),
            'indicaciones_generales' => $this->indicaciones_generales,
            'medicamentos' => $this->detalles->sortBy('id')->values()->map(fn ($detalle) => [
                'medicamento' => $detalle->medicamento,
                'dosis' => $detalle->dosis,
                'frecuencia' => $detalle->frecuencia,
                'duracion_dias' => $detalle->duracion_dias,
            ]),
            'consulta' => [
                'id' => $consulta->id,
                'nro_expediente' => $consulta->nro_expediente,
                'fecha_atencion' => $consulta->fecha_atencion->setTimezone($zona)->toIso8601String(),
                'codigo_cita' => $consulta->cita->codigo_cita,
            ],
            'paciente' => [
                'codigo' => $paciente->codigo,
                'nombre_completo' => "{$paciente->usuario->nombre} {$paciente->usuario->apellido}",
                'ci' => $paciente->usuario->ci,
                'edad' => $paciente->fecha_nacimiento ? $paciente->edad() : null,
            ],
            'medico' => [
                'codigo' => $medico->codigo,
                'nombre_completo' => "{$medico->usuario->nombre} {$medico->usuario->apellido}",
                'especialidades' => $medico->especialidades->pluck('nombre')->sort()->values(),
            ],
            'clinica' => config('clinica.datos'),
        ];
    }
}
