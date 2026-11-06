<?php

namespace App\Modules\ModuloAtencionClinica\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/consultas (CU17). Que la cita sea del médico, del día y esté
 * pendiente lo verifica ConsultaService.
 */
class ConsultaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cita_medica' => ['required', 'integer', 'exists:cita_medica,id'],
            'motivo_de_consultas' => ['required', 'string', 'max:2000'],
            'peso' => ['required', 'numeric', 'min:1', 'max:300'],
            'estatura' => ['required', 'numeric', 'min:30', 'max:250'],
            'escala_del_dolor' => ['nullable', 'integer', 'min:0', 'max:10'],
            'orden_cronologico' => ['nullable', 'string'],
            'sintomas' => ['nullable', 'string'],
            'tratamiento' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_cita_medica.required' => 'Indique la cita que se está atendiendo.',
            'id_cita_medica.exists' => 'La cita no existe.',
            'motivo_de_consultas.required' => 'El motivo de la consulta es obligatorio.',
            'motivo_de_consultas.max' => 'El motivo no puede superar los 2000 caracteres.',
            'peso.required' => 'El peso es obligatorio.',
            'peso.numeric' => 'El peso debe ser un número (kg).',
            'peso.min' => 'El peso debe ser de al menos 1 kg.',
            'peso.max' => 'El peso no puede superar los 300 kg.',
            'estatura.required' => 'La estatura es obligatoria.',
            'estatura.numeric' => 'La estatura debe ser un número (cm).',
            'estatura.min' => 'La estatura debe ser de al menos 30 cm.',
            'estatura.max' => 'La estatura no puede superar los 250 cm.',
            'escala_del_dolor.integer' => 'La escala del dolor debe ser un número entero.',
            'escala_del_dolor.min' => 'La escala del dolor va de 0 a 10.',
            'escala_del_dolor.max' => 'La escala del dolor va de 0 a 10.',
        ];
    }
}
