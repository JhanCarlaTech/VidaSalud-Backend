<?php

namespace App\Modules\ModuloOperacionCitas\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/citas/{cita}/reprogramar (CU14, E1). La disponibilidad del turno y
 * quién puede modificar la cita los verifican CitaService y CitaController.
 */
class CitaReprogramarRequest extends FormRequest
{
    /** Mismo alcance que la reserva (CU13 RN03). */
    private const DIAS_MAXIMOS = 90;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $limite = today()->addDays(self::DIAS_MAXIMOS)->toDateString();

        return [
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', "before_or_equal:{$limite}"],
            'hra_inicio' => ['required', 'date_format:H:i'],
            'id_modalidad' => ['nullable', 'integer', 'exists:modalidad_cita,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'Seleccione la nueva fecha.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha.after_or_equal' => 'No se puede reprogramar a una fecha pasada.',
            'fecha.before_or_equal' => 'Solo se puede reprogramar hasta '.self::DIAS_MAXIMOS.' días adelante.',
            'hra_inicio.required' => 'Seleccione el nuevo turno.',
            'hra_inicio.date_format' => 'El turno no es válido.',
            'id_modalidad.exists' => 'La modalidad no existe.',
        ];
    }
}
