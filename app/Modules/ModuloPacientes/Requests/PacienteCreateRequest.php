<?php

namespace App\Modules\ModuloPacientes\Requests;

/**
 * POST /api/pacientes (personal) y POST /api/auth/registro (autorregistro, RN07).
 * La contraseña inicial es obligatoria.
 */
class PacienteCreateRequest extends PacienteRequest
{
    protected function idUsuarioActual(): ?int
    {
        return null;
    }

    public function rules(): array
    {
        return [
            ...$this->reglasPaciente(),
            'contrasena' => ['required', ...self::REGLAS_CONTRASENA],
        ];
    }
}
