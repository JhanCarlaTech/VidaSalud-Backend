<?php

namespace App\Modules\ModuloPersonalMedico\Requests;

use App\Modules\ModuloPersonalMedico\Models\HorarioAtencion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y modificación de un bloque de la grilla semanal (CU09, RN01 / E1).
 * La autorización (administrador o el propio médico) la hace HorarioController.
 */
class HorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dia_semana' => ['required', 'integer', 'between:1,7'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'duracion_minutos' => ['required', 'integer', Rule::in(HorarioAtencion::DURACIONES)],
        ];
    }

    /**
     * RN01: el bloque debe alcanzar para al menos un turno.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['hora_inicio', 'hora_fin', 'duracion_minutos'])) {
                    return;
                }

                $minutos = HorarioAtencion::minutos($this->input('hora_fin')) - HorarioAtencion::minutos($this->input('hora_inicio'));
                $duracion = (int) $this->input('duracion_minutos');

                if ($minutos < $duracion) {
                    $validator->errors()->add('hora_fin', "El bloque debe durar al menos un turno de {$duracion} minutos.");
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'dia_semana.required' => 'Seleccione el día de la semana.',
            'dia_semana.integer' => 'El día de la semana no es válido.',
            'dia_semana.between' => 'El día de la semana no es válido.',
            'hora_inicio.required' => 'Ingrese la hora de inicio.',
            'hora_inicio.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'hora_fin.required' => 'Ingrese la hora de fin.',
            'hora_fin.date_format' => 'La hora de fin debe tener el formato HH:MM.',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio.',
            'duracion_minutos.required' => 'Seleccione la duración del turno.',
            'duracion_minutos.integer' => 'La duración no es válida.',
            'duracion_minutos.in' => 'La duración debe ser de 15, 20, 30, 45 o 60 minutos.',
        ];
    }
}
