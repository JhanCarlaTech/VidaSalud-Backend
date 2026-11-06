<?php

namespace App\Modules\ModuloPacientes\Requests;

use App\Modules\ModuloPacientes\Models\Enfermedad;
use App\Shared\Validation\UnicoSinMayusculas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y modificación de enfermedades del catálogo (CU12, E1 y E2).
 * La autorización la realiza el middleware 'permiso:enfermedades_gestionar'.
 */
class EnfermedadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Se normalizan los espacios del nombre para comparar la unicidad. */
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
                // Letras, números y signos habituales: "Diabetes Mellitus Tipo 2", "COVID-19", "Enfermedad de Chagas (crónica)".
                'required', 'string', 'min:3', 'max:150', "regex:/^[\\pL\\pN\\s\\-(),.'\\/]+$/u",
                // RN01: único sin distinguir mayúsculas.
                new UnicoSinMayusculas('enfermedad', 'nombre', 'Ya existe una enfermedad con ese nombre.', $this->route('enfermedad')?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'nivel_riesgo' => ['required', Rule::in(Enfermedad::NIVELES_RIESGO)],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la enfermedad es obligatorio.',
            'nombre.min' => 'El nombre debe tener al menos 3 caracteres.',
            'nombre.max' => 'El nombre no puede superar los 150 caracteres.',
            'nombre.regex' => 'El nombre solo puede contener letras, números, espacios y los signos - ( ) , . \' /',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
            'nivel_riesgo.required' => 'Seleccione el nivel de riesgo.',
            'nivel_riesgo.in' => 'El nivel de riesgo debe ser '.implode(', ', Enfermedad::NIVELES_RIESGO).'.',
        ];
    }
}
