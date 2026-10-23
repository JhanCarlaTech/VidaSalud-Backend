<?php

namespace App\Modules\ModuloPersonalMedico\Requests;

use App\Shared\Validation\UnicoSinMayusculas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y modificación de especialidades (CU07, excepciones E1 y E2).
 * La autorización la realiza el middleware 'permiso:medicos_gestionar'.
 */
class EspecialidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Se normalizan los espacios del nombre para comparar la unicidad.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => preg_replace('/\s+/u', ' ', trim((string) $this->input('nombre'))),
            'descripcion' => $this->filled('descripcion') ? trim((string) $this->input('descripcion')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre' => [
                'required', 'string', 'max:100', 'regex:/^[\pL\s]+$/u',
                // RN01: único sin distinguir mayúsculas.
                new UnicoSinMayusculas('especialidad', 'nombre', 'Ya existe una especialidad con ese nombre.', $this->route('especialidad')?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la especialidad es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 100 caracteres.',
            'nombre.regex' => 'El nombre solo puede contener letras y espacios.',
            'descripcion.max' => 'La descripción no puede superar los 500 caracteres.',
        ];
    }
}
