<?php

namespace App\Modules\ModuloSeguridad\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Reglas comunes para crear y modificar usuarios (CU02, excepciones E1–E2).
 * La autorización la realiza el middleware 'permiso:usuarios_gestionar'.
 */
abstract class UsuarioRequest extends FormRequest
{
    /** RN02: mínimo 8 caracteres con letras y números (72 es el límite de BCRYPT). Se reutiliza en CU05. */
    public const REGLAS_CONTRASENA =['string', 'min:8', 'max:72', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/', 'confirmed'];

    /**
     * ID del usuario a ignorar en las reglas de unicidad (null al crear).
     */
    abstract protected function idUsuarioActual(): ?int;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ci' => trim((string) $this->input('ci')),
            'nombre' => trim((string) $this->input('nombre')),
            'apellido' => trim((string) $this->input('apellido')),
            'correo' => Str::lower(trim((string) $this->input('correo'))),
            'telefono' => $this->filled('telefono') ? trim((string) $this->input('telefono')) : null,
            'sexo' => $this->filled('sexo') ? $this->input('sexo') : null,
        ]);
    }

    protected function reglasDatosPersonales(): array
    {
        $id = $this->idUsuarioActual();

        return [
            'ci' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z-]+$/', Rule::unique('usuario', 'ci')->ignore($id)],
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'correo' => ['required', 'email', 'max:150', Rule::unique('usuario', 'correo')->ignore($id)],
            'telefono' => ['nullable', 'string', 'regex:/^[0-9+\-\s]{6,20}$/'],
            'sexo' => ['nullable', Rule::in(['M', 'F', 'O'])],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', 'distinct', Rule::exists('rol', 'id')->where('activo', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'ci.required' => 'El CI es obligatorio.',
            'ci.max' => 'El CI no puede superar los 20 caracteres.',
            'ci.regex' => 'El CI solo puede contener letras, números y guiones.',
            'ci.unique' => 'El CI ya está registrado.',
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 100 caracteres.',
            'apellido.required' => 'El apellido es obligatorio.',
            'apellido.max' => 'El apellido no puede superar los 100 caracteres.',
            'correo.required' => 'El correo es obligatorio.',
            'correo.email' => 'Ingrese un correo electrónico válido.',
            'correo.max' => 'El correo no puede superar los 150 caracteres.',
            'correo.unique' => 'El correo ya está registrado.',
            'telefono.regex' => 'Ingrese un teléfono válido (6 a 20 dígitos).',
            'sexo.in' => 'Seleccione un sexo válido.',
            'contrasena.required' => 'La contraseña es obligatoria.',
            'contrasena.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'contrasena.max' => 'La contraseña no puede superar los 72 caracteres.',
            'contrasena.regex' => 'La contraseña debe incluir letras y números.',
            'contrasena.confirmed' => 'La confirmación de la contraseña no coincide.',
            'roles.required' => 'Asigne al menos un rol.',
            'roles.min' => 'Asigne al menos un rol.',
            'roles.*.distinct' => 'Hay roles repetidos.',
            'roles.*.exists' => 'Uno de los roles seleccionados no existe o está inactivo.',
            'roles.*.integer' => 'Uno de los roles seleccionados no es válido.',
        ];
    }
}
