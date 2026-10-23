<?php

namespace App\Modules\ModuloSeguridad\Requests;

use App\Shared\Validation\UnicoSinMayusculas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y modificación de roles (CU03, excepciones E1, E2 y E5).
 * La autorización la realiza el middleware 'permiso:roles_gestionar'.
 */
class RolRequest extends FormRequest
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
                'required', 'string', 'max:50', 'regex:/^[\pL\s]+$/u',
                // RN01: único sin distinguir mayúsculas.
                new UnicoSinMayusculas('rol', 'nombre', 'Ya existe un rol con ese nombre.', $this->route('rol')?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'permisos' => ['present', 'array'],
            'permisos.*' => ['integer', 'distinct', Rule::exists('permiso', 'id')->where('activo', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del rol es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 50 caracteres.',
            'nombre.regex' => 'El nombre solo puede contener letras y espacios.',
            'descripcion.max' => 'La descripción no puede superar los 255 caracteres.',
            'permisos.present' => 'Envíe la lista de permisos (puede estar vacía).',
            'permisos.array' => 'La lista de permisos no es válida.',
            'permisos.*.integer' => 'Uno de los permisos seleccionados no es válido.',
            'permisos.*.distinct' => 'Hay permisos repetidos.',
            'permisos.*.exists' => 'Uno de los permisos seleccionados no existe o está inactivo.',
        ];
    }
}
