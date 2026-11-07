<?php

namespace App\Modules\ModuloInteligenciaReportes\Requests;

use App\Modules\ModuloInteligenciaReportes\Models\SuscripcionPush;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Suscripción push que entrega el navegador (PushSubscription.toJSON()).
 */
class SuscripcionPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Los servicios push siempre usan HTTPS.
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=]+$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=]+$/'],
            'content_encoding' => ['nullable', Rule::in(SuscripcionPush::CODIFICACIONES)],
        ];
    }

    public function messages(): array
    {
        return [
            'endpoint.*' => 'La suscripción del navegador no es válida.',
            'keys.*' => 'La suscripción del navegador no es válida.',
            'keys.*.*' => 'La suscripción del navegador no es válida.',
        ];
    }
}
