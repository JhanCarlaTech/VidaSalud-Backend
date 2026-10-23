<?php

namespace App\Modules\ModuloSeguridad\Requests;

use Illuminate\Support\Arr;

/**
 * PUT /api/perfil – datos personales del propio usuario (CU05).
 * Reutiliza las reglas de CU02, sin el CI ni los roles (RN01).
 */
class PerfilUpdateRequest extends UsuarioRequest
{
    protected function idUsuarioActual(): ?int
    {
        return $this->user()->id;
    }

    public function rules(): array
    {
        return [
            ...Arr::except($this->reglasDatosPersonales(), ['ci', 'roles', 'roles.*']),
            // Obligatoria solo si cambia el correo (RN02); se verifica en PerfilService.
            'contrasena_actual' => ['nullable', 'string', 'max:72'],
        ];
    }
}
