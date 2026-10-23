<?php

namespace App\Modules\ModuloPacientes\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/mi-ficha – el paciente solo modifica su tipo de sangre y su
 * contacto de emergencia (CU11, RN08).
 */
class FichaPacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tipo_sangre' => $this->filled('tipo_sangre') ? $this->input('tipo_sangre') : null,
            'contacto' => PacienteRequest::contactoNormalizado($this->input('contacto')),
        ]);
    }

    public function rules(): array
    {
        return PacienteRequest::reglasFichaMedica();
    }

    public function messages(): array
    {
        return PacienteRequest::mensajesFichaMedica();
    }
}
