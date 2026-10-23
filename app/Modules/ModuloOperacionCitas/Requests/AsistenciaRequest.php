<?php

namespace App\Modules\ModuloOperacionCitas\Requests;

use App\Modules\ModuloOperacionCitas\Services\AsistenciaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/citas/{cita}/asistencia (CU15, E1). Si la transición está permitida
 * en ese momento lo decide AsistenciaService.
 */
class AsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in(AsistenciaService::ESTADOS_DESTINO)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'Indique el nuevo estado de asistencia.',
            'estado.in' => 'El estado debe ser Confirmada, En Sala de Espera o Inasistencia.',
        ];
    }
}
