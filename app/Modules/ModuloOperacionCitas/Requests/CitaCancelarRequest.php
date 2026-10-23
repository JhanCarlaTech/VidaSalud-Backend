<?php

namespace App\Modules\ModuloOperacionCitas\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/citas/{cita}/cancelar (CU14, RN06).
 */
class CitaCancelarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo_cancelacion' => trim((string) $this->input('motivo_cancelacion'))]);
    }

    public function rules(): array
    {
        return [
            'motivo_cancelacion' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo_cancelacion.required' => 'Indique el motivo de la cancelación.',
            'motivo_cancelacion.min' => 'El motivo debe tener al menos 5 caracteres.',
            'motivo_cancelacion.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
