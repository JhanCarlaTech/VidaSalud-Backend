<?php

namespace App\Modules\ModuloOperacionCitas\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/citas (CU13, E1). Las reglas que dependen del estado de la base
 * (disponibilidad, superposición, estado de paciente y médico) las aplica CitaService.
 * Quién puede agendar para quién lo decide CitaController (RN08).
 */
class CitaAgendarRequest extends FormRequest
{
    /** RN03: alcance máximo de la reserva, igual que la consulta de disponibilidad. */
    private const DIAS_MAXIMOS = 90;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
    }

    public function rules(): array
    {
        $limite = today()->addDays(self::DIAS_MAXIMOS)->toDateString();

        return [
            'id_paciente' => ['nullable', 'integer'],
            'id_especialidad' => ['required', 'integer', 'exists:especialidad,id'],
            'id_medico' => ['required', 'integer', 'exists:medico,id_usuario'],
            'id_modalidad' => ['required', 'integer', 'exists:modalidad_cita,id'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', "before_or_equal:{$limite}"],
            'hra_inicio' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_paciente.integer' => 'El paciente no es válido.',
            'id_especialidad.required' => 'Seleccione la especialidad.',
            'id_especialidad.exists' => 'La especialidad no existe.',
            'id_medico.required' => 'Seleccione el médico.',
            'id_medico.exists' => 'El médico no existe.',
            'id_modalidad.required' => 'Seleccione la modalidad de atención.',
            'id_modalidad.exists' => 'La modalidad no existe.',
            'fecha.required' => 'Seleccione la fecha.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha.after_or_equal' => 'No se pueden agendar citas en fechas pasadas.',
            'fecha.before_or_equal' => 'Solo se pueden agendar citas hasta '.self::DIAS_MAXIMOS.' días adelante.',
            'hra_inicio.required' => 'Seleccione el turno.',
            'hra_inicio.date_format' => 'El turno no es válido.',
            'motivo.required' => 'Describa brevemente el motivo de la consulta.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
