<?php

namespace App\Modules\ModuloPersonalMedico\Requests;

use App\Modules\ModuloSeguridad\Requests\UsuarioRequest;
use Illuminate\Support\Arr;

/**
 * Reglas comunes para registrar y modificar médicos (CU08, E1–E3).
 * Reutiliza los datos personales de CU02 (RN01); los roles no se envían porque
 * el rol Medico lo asigna el sistema.
 * La autorización la realiza el middleware 'permiso:medicos_gestionar'.
 */
abstract class MedicoRequest extends UsuarioRequest
{
    protected function reglasMedico(): array
    {
        return [
            ...Arr::except($this->reglasDatosPersonales(), ['roles', 'roles.*']),
            'salario' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'especialidades' => ['required', 'array', 'min:1'],
            // Que las nuevas estén activas se verifica en MedicoService (RN04).
            'especialidades.*' => ['integer', 'distinct', 'exists:especialidad,id'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'salario.required' => 'El salario es obligatorio.',
            'salario.numeric' => 'El salario debe ser un número.',
            'salario.min' => 'El salario no puede ser negativo.',
            'salario.max' => 'El salario es demasiado alto.',
            'salario.decimal' => 'El salario admite como máximo 2 decimales.',
            'especialidades.required' => 'Asigne al menos una especialidad.',
            'especialidades.min' => 'Asigne al menos una especialidad.',
            'especialidades.*.integer' => 'Una de las especialidades no es válida.',
            'especialidades.*.distinct' => 'Hay especialidades repetidas.',
            'especialidades.*.exists' => 'Una de las especialidades seleccionadas no existe.',
        ];
    }
}
