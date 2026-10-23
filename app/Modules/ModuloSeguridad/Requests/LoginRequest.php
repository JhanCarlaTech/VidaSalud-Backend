<?php

namespace App\Modules\ModuloSeguridad\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validación de entrada del inicio de sesión (CU01, excepción E1).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El correo se normaliza para que la búsqueda no dependa de mayúsculas o espacios.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'correo' => Str::lower(trim((string) $this->input('correo'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'correo' => ['required', 'email', 'max:150'],
            'contrasena' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'correo.required' => 'El correo es obligatorio.',
            'correo.email' => 'Ingrese un correo electrónico válido.',
            'correo.max' => 'El correo no puede superar los 150 caracteres.',
            'contrasena.required' => 'La contraseña es obligatoria.',
            'contrasena.max' => 'La contraseña no puede superar los 255 caracteres.',
        ];
    }
}
