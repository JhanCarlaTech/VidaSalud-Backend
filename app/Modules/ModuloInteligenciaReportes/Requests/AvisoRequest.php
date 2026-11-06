<?php

namespace App\Modules\ModuloInteligenciaReportes\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Aviso manual de Recepción a un paciente (CU20, RN05 / E1).
 */
class AvisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['mensaje' => is_string($this->input('mensaje')) ? trim($this->input('mensaje')) : $this->input('mensaje')]);
    }

    public function rules(): array
    {
        return [
            'id_paciente' => ['required', 'integer', 'exists:paciente,id_usuario'],
            'id_cita' => ['nullable', 'integer'],
            'mensaje' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_paciente.required' => 'Seleccione el paciente.',
            'id_paciente.exists' => 'El paciente seleccionado no existe.',
            'id_cita.integer' => 'Seleccione una cita válida.',
            'mensaje.required' => 'Escriba el mensaje del aviso.',
            'mensaje.min' => 'El mensaje debe tener al menos 5 caracteres.',
            'mensaje.max' => 'El mensaje no debe superar los 1000 caracteres.',
        ];
    }
}
