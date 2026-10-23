<?php

namespace App\Modules\ModuloPersonalMedico\Requests;

/**
 * PUT /api/medicos/{medico} – la contraseña es opcional: si se envía, se restablece.
 */
class MedicoUpdateRequest extends MedicoRequest
{
    protected function idUsuarioActual(): ?int
    {
        return $this->route('medico')->id_usuario;
    }

    public function rules(): array
    {
        return [
            ...$this->reglasMedico(),
            'contrasena' => ['nullable', ...self::REGLAS_CONTRASENA],
        ];
    }
}
