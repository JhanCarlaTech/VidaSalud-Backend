<?php

namespace App\Modules\ModuloPersonalMedico\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de una licencia o bloqueo de agenda (CU10, E1). Las fechas llegan en la
 * hora de la clínica con el formato 'AAAA-MM-DD HH:mm'. Las reglas que dependen
 * de otras licencias, del médico o de la hora actual las aplica LicenciaService.
 *
 * También valida la vista previa de citas afectadas (ruta 'licencias.citas-afectadas'),
 * donde el motivo no se pide.
 */
class LicenciaRequest extends FormRequest
{
    public const FORMATO_FECHA = 'Y-m-d H:i';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'motivo' => is_string($this->input('motivo')) ? trim(preg_replace('/\s+/', ' ', $this->input('motivo'))) : $this->input('motivo'),
        ]);
    }

    public function rules(): array
    {
        $esVistaPrevia = $this->routeIs('licencias.citas-afectadas');

        return [
            // Solo lo usa el Administrador: al Médico se le asignan siempre sus propias licencias.
            'id_medico' => ['nullable', 'integer'],
            'fecha_inicio' => ['required', 'date_format:'.self::FORMATO_FECHA],
            'fecha_fin' => ['required', 'date_format:'.self::FORMATO_FECHA, 'after:fecha_inicio'],
            'motivo' => $esVistaPrevia ? ['nullable'] : ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_medico.integer' => 'Seleccione un médico válido.',
            'fecha_inicio.required' => 'Indique cuándo empieza la licencia.',
            'fecha_inicio.date_format' => 'La fecha de inicio no es válida.',
            'fecha_fin.required' => 'Indique cuándo termina la licencia.',
            'fecha_fin.date_format' => 'La fecha de fin no es válida.',
            'fecha_fin.after' => 'La licencia debe terminar después de empezar.',
            'motivo.required' => 'Indique el motivo de la licencia.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
            'motivo.max' => 'El motivo no debe superar los 255 caracteres.',
        ];
    }

    /** Inicio y fin en la zona horaria de la clínica. */
    public function periodo(): array
    {
        $fecha = fn (string $campo) => CarbonImmutable::createFromFormat(self::FORMATO_FECHA, $this->input($campo))->startOfMinute();

        return [$fecha('fecha_inicio'), $fecha('fecha_fin')];
    }
}
