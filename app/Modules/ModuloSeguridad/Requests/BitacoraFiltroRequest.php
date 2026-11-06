<?php

namespace App\Modules\ModuloSeguridad\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Filtros de GET /api/bitacora y GET /api/bitacora/exportar (CU06, RN03 y E1).
 */
class BitacoraFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
            // Códigos como 'LOGIN_FALLIDO' o tablas como 'horario_atencion'.
            'accion' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z_]+$/'],
            'tabla' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z_]+$/'],
            'ip' => ['nullable', 'string', 'max:45', 'regex:/^[0-9A-Fa-f.:]+$/'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'por_pagina' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'buscar.max' => 'La búsqueda no debe superar los 100 caracteres.',
            'accion.regex' => 'La acción no es válida.',
            'tabla.regex' => 'El módulo no es válido.',
            'ip.regex' => 'Ingrese una IP o el inicio de una (por ejemplo, 192.168.).',
            'ip.max' => 'La IP no debe superar los 45 caracteres.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'por_pagina.*' => 'La cantidad por página debe estar entre 10 y 100.',
        ];
    }

    /**
     * Filtros normalizados. Las fechas son días completos en la zona horaria de la
     * clínica: 'hasta' se convierte en el inicio del día siguiente (límite exclusivo).
     *
     * @return array{buscar: ?string, accion: ?string, tabla: ?string, ip: ?string, desde: ?Carbon, hasta: ?Carbon}
     */
    public function filtros(): array
    {
        $texto = fn (string $campo) => $this->filled($campo) ? trim((string) $this->input($campo)) : null;

        return [
            'buscar' => $texto('buscar'),
            'accion' => $this->filled('accion') ? strtoupper($texto('accion')) : null,
            'tabla' => $this->filled('tabla') ? strtolower($texto('tabla')) : null,
            'ip' => $texto('ip'),
            'desde' => $this->filled('desde') ? Carbon::createFromFormat('Y-m-d', $this->input('desde'))->startOfDay() : null,
            'hasta' => $this->filled('hasta') ? Carbon::createFromFormat('Y-m-d', $this->input('hasta'))->addDay()->startOfDay() : null,
        ];
    }
}
