<?php

namespace App\Modules\ModuloPacientes\Requests;

/**
 * PUT /api/pacientes/{paciente} – la contraseña es opcional: si se envía, se restablece.
 */
class PacienteUpdateRequest extends PacienteRequest
{
    protected function idUsuarioActual(): ?int
    {
        return $this->route('paciente')->id_usuario;
    }

    public function rules(): array
    {
        return [
            ...$this->reglasPaciente(),
            'contrasena' => ['nullable', ...self::REGLAS_CONTRASENA],
        ];
    }
}
