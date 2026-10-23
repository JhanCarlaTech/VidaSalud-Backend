<?php

namespace App\Modules\ModuloSeguridad\Requests;

/**
 * POST /api/usuarios – la contraseña inicial es obligatoria.
 */
class UsuarioCreateRequest extends UsuarioRequest
{
    protected function idUsuarioActual(): ?int
    {
        return null;
    }

    public function rules(): array
    {
        return [
            ...$this->reglasDatosPersonales(),
            'contrasena' => ['required', ...self::REGLAS_CONTRASENA],
        ];
    }
}
