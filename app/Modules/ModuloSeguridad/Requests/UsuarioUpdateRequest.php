<?php

namespace App\Modules\ModuloSeguridad\Requests;

/**
 * PUT /api/usuarios/{usuario} – la contraseña es opcional: si se envía, se restablece.
 */
class UsuarioUpdateRequest extends UsuarioRequest
{
    protected function idUsuarioActual(): ?int
    {
        return $this->route('usuario')->id;
    }

    public function rules(): array
    {
        return [
            ...$this->reglasDatosPersonales(),
            'contrasena' => ['nullable', ...self::REGLAS_CONTRASENA],
        ];
    }
}
