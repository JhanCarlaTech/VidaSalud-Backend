<?php

namespace App\Modules\ModuloPacientes\Requests;

use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloSeguridad\Requests\UsuarioRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Reglas comunes para registrar y modificar pacientes (CU11, E1–E2).
 * Reutiliza los datos personales de CU02 (RN01); el rol Paciente lo asigna el sistema.
 */
abstract class PacienteRequest extends UsuarioRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['contacto' => self::contactoNormalizado($this->input('contacto'))]);
    }

    protected function reglasPaciente(): array
    {
        return [
            ...Arr::except($this->reglasDatosPersonales(), ['roles', 'roles.*']),
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            ...self::reglasFichaMedica(),
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'fecha_nacimiento.required' => 'La fecha de nacimiento es obligatoria.',
            'fecha_nacimiento.date_format' => 'La fecha de nacimiento debe tener el formato AAAA-MM-DD.',
            'fecha_nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'fecha_nacimiento.after' => 'La fecha de nacimiento no es válida.',
            ...self::mensajesFichaMedica(),
        ];
    }

    /**
     * Tipo de sangre (RN04) y contacto de emergencia (RN05). También las usa
     * "Mi ficha médica" (FichaPacienteRequest).
     */
    public static function reglasFichaMedica(): array
    {
        return [
            'tipo_sangre' => ['nullable', Rule::in(Paciente::TIPOS_SANGRE)],
            'contacto' => ['nullable', 'array'],
            'contacto.nombre' => ['required_with:contacto', 'string', 'max:100'],
            'contacto.apellido' => ['required_with:contacto', 'string', 'max:100'],
            'contacto.parentesco' => ['required_with:contacto', 'string', 'max:50'],
            'contacto.telefono' => ['required_with:contacto', 'string', 'regex:/^[0-9+\-\s]{6,20}$/'],
        ];
    }

    public static function mensajesFichaMedica(): array
    {
        return [
            'tipo_sangre.in' => 'Seleccione un tipo de sangre válido.',
            'contacto.array' => 'El contacto de emergencia no es válido.',
            'contacto.nombre.required_with' => 'Ingrese el nombre del contacto.',
            'contacto.nombre.max' => 'El nombre del contacto no puede superar los 100 caracteres.',
            'contacto.apellido.required_with' => 'Ingrese el apellido del contacto.',
            'contacto.apellido.max' => 'El apellido del contacto no puede superar los 100 caracteres.',
            'contacto.parentesco.required_with' => 'Indique el parentesco.',
            'contacto.parentesco.max' => 'El parentesco no puede superar los 50 caracteres.',
            'contacto.telefono.required_with' => 'Ingrese el teléfono del contacto.',
            'contacto.telefono.regex' => 'Ingrese un teléfono válido (6 a 20 dígitos).',
        ];
    }

    /**
     * Recorta los campos del contacto; si todos están vacíos se considera que no hay contacto.
     */
    public static function contactoNormalizado(mixed $contacto): ?array
    {
        if (! is_array($contacto)) {
            return null;
        }

        $limpio = array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, $contacto);

        return array_filter($limpio, fn ($valor) => $valor !== null && $valor !== '') === [] ? null : $limpio;
    }
}
