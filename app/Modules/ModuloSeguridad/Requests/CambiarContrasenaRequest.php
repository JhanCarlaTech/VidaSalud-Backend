<?php

namespace App\Modules\ModuloSeguridad\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/perfil/contrasena (CU05, RN03).
 */
class CambiarContrasenaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contrasena_actual' => ['required', 'string', 'max:72'],
            'contrasena' => ['required', ...UsuarioRequest::REGLAS_CONTRASENA, 'different:contrasena_actual'],
        ];
    }

    public function messages(): array
    {
        return [
            'contrasena_actual.required' => 'Ingrese su contraseña actual.',
            'contrasena.required' => 'Ingrese la nueva contraseña.',
            'contrasena.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'contrasena.max' => 'La contraseña no puede superar los 72 caracteres.',
            'contrasena.regex' => 'La contraseña debe incluir letras y números.',
            'contrasena.confirmed' => 'La confirmación de la contraseña no coincide.',
            'contrasena.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ];
    }
}
