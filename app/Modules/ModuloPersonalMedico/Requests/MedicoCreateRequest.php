<?php

namespace App\Modules\ModuloPersonalMedico\Requests;

/**
 * POST /api/medicos – la contraseña inicial es obligatoria.
 */
class MedicoCreateRequest extends MedicoRequest
{
    protected function idUsuarioActual(): ?int
    {
        return null;
    }

    public function rules(): array
    {
        return [
            ...$this->reglasMedico(),
            'contrasena' => ['required', ...self::REGLAS_CONTRASENA],
        ];
    }
}
