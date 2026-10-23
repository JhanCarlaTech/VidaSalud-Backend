<?php

namespace App\Modules\ModuloSeguridad\Requests;

use App\Modules\ModuloSeguridad\Models\PreferenciaNotificacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/perfil/preferencias (CU05, RN06).
 */
class PreferenciasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notificar_email' => ['required', 'boolean'],
            'notificar_push' => ['required', 'boolean'],
            'horas_anticipacion' => ['required', 'integer', Rule::in(PreferenciaNotificacion::HORAS_PERMITIDAS)],
        ];
    }

    public function messages(): array
    {
        return [
            'notificar_email.required' => 'Indique si desea recibir correos.',
            'notificar_email.boolean' => 'El valor del correo no es válido.',
            'notificar_push.required' => 'Indique si desea recibir notificaciones push.',
            'notificar_push.boolean' => 'El valor de las notificaciones push no es válido.',
            'horas_anticipacion.required' => 'Seleccione la anticipación del recordatorio.',
            'horas_anticipacion.integer' => 'La anticipación no es válida.',
            'horas_anticipacion.in' => 'La anticipación debe ser de 2, 12, 24 o 48 horas.',
        ];
    }
}
