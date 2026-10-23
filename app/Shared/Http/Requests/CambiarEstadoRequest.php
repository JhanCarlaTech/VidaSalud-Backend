<?php

namespace App\Shared\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH .../{id}/estado – activar o desactivar un registro
 * (usuarios en CU02, roles en CU03, especialidades en CU07).
 */
class CambiarEstadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'activo.required' => 'Indique el nuevo estado.',
            'activo.boolean' => 'El estado debe ser activo o inactivo.',
        ];
    }
}
