<?php

namespace App\Modules\ModuloAtencionClinica\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Emisión de una receta (CU18, E1). Que la consulta sea del médico y esté
 * vigente lo verifica RecetaService.
 */
class RecetaRequest extends FormRequest
{
    /** RN02: medicamentos por receta. */
    public const MAXIMO_MEDICAMENTOS = 15;

    /** RN02: duración máxima de un tratamiento, en días. */
    public const DURACION_MAXIMA_DIAS = 365;

    public function authorize(): bool
    {
        return true;
    }

    /** Recorta los textos y unifica espacios; descarta las filas totalmente vacías del formulario. */
    protected function prepareForValidation(): void
    {
        $texto = fn ($valor) => is_string($valor) ? trim(preg_replace('/\s+/u', ' ', $valor)) : $valor;

        $medicamentos = collect(is_array($this->input('medicamentos')) ? $this->input('medicamentos') : [])
            ->map(fn ($fila) => is_array($fila) ? array_map($texto, $fila) : $fila)
            ->reject(fn ($fila) => is_array($fila) && collect($fila)->every(fn ($valor) => $valor === null || $valor === ''))
            ->values()
            ->all();

        $this->merge([
            'medicamentos' => $medicamentos,
            'indicaciones_generales' => $this->filled('indicaciones_generales') ? trim((string) $this->input('indicaciones_generales')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'medicamentos' => ['required', 'array', 'min:1', 'max:'.self::MAXIMO_MEDICAMENTOS],
            'medicamentos.*.medicamento' => ['required', 'string', 'max:150'],
            'medicamentos.*.dosis' => ['required', 'string', 'max:100'],
            'medicamentos.*.frecuencia' => ['required', 'string', 'max:100'],
            'medicamentos.*.duracion_dias' => ['required', 'integer', 'min:1', 'max:'.self::DURACION_MAXIMA_DIAS],
            'indicaciones_generales' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * RN02: un medicamento no se repite en la misma receta (sin distinguir mayúsculas);
     * si cambia la dosis, se indica en una sola línea.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $vistos = [];

                foreach ($this->input('medicamentos', []) as $indice => $fila) {
                    $nombre = mb_strtolower((string) ($fila['medicamento'] ?? ''));
                    if ($nombre === '') {
                        continue;
                    }

                    if (isset($vistos[$nombre])) {
                        $validator->errors()->add("medicamentos.{$indice}.medicamento", 'Este medicamento ya figura en la línea '.($vistos[$nombre] + 1).' de la receta.');
                    } else {
                        $vistos[$nombre] = $indice;
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'medicamentos.required' => 'Agregue al menos un medicamento.',
            'medicamentos.min' => 'Agregue al menos un medicamento.',
            'medicamentos.max' => 'Una receta admite como máximo '.self::MAXIMO_MEDICAMENTOS.' medicamentos.',
            'medicamentos.*.medicamento.required' => 'Indique el medicamento.',
            'medicamentos.*.medicamento.max' => 'El medicamento no debe superar los 150 caracteres.',
            'medicamentos.*.dosis.required' => 'Indique la dosis.',
            'medicamentos.*.dosis.max' => 'La dosis no debe superar los 100 caracteres.',
            'medicamentos.*.frecuencia.required' => 'Indique la frecuencia.',
            'medicamentos.*.frecuencia.max' => 'La frecuencia no debe superar los 100 caracteres.',
            'medicamentos.*.duracion_dias.required' => 'Indique la duración en días.',
            'medicamentos.*.duracion_dias.integer' => 'La duración debe ser un número entero de días.',
            'medicamentos.*.duracion_dias.min' => 'La duración debe ser de al menos 1 día.',
            'medicamentos.*.duracion_dias.max' => 'La duración no puede superar los '.self::DURACION_MAXIMA_DIAS.' días.',
            'indicaciones_generales.max' => 'Las indicaciones no deben superar los 2000 caracteres.',
        ];
    }
}
