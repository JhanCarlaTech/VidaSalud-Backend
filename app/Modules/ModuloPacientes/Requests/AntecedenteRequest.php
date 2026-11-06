<?php

namespace App\Modules\ModuloPacientes\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro y modificación de un antecedente del paciente (CU12, E1).
 * Al modificar no se cambia la enfermedad: va en la ruta. Las reglas que dependen
 * del catálogo y de la fecha de nacimiento las aplica AntecedenteService.
 */
class AntecedenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'observaciones' => $this->filled('observaciones') ? trim((string) $this->input('observaciones')) : null,
        ]);
    }

    public function rules(): array
    {
        $esAlta = $this->isMethod('post');

        return [
            'id_enfermedad' => $esAlta ? ['required', 'integer'] : ['prohibited'],
            'fecha_diagnostico' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_enfermedad.required' => 'Seleccione la enfermedad.',
            'id_enfermedad.integer' => 'Seleccione una enfermedad válida.',
            'id_enfermedad.prohibited' => 'La enfermedad de un antecedente no se cambia: elimínelo y registre otro.',
            'fecha_diagnostico.date_format' => 'La fecha de diagnóstico no es válida.',
            'fecha_diagnostico.before_or_equal' => 'La fecha de diagnóstico no puede ser futura.',
            'observaciones.max' => 'Las observaciones no pueden superar los 1000 caracteres.',
        ];
    }
}
