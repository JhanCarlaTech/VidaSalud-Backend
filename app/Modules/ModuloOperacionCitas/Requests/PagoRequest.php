<?php

namespace App\Modules\ModuloOperacionCitas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cita' => ['required', 'integer', 'exists:cita_medica,id'],
            'id_metodo_pago' => ['required', 'integer', 'exists:metodo_pago,id'],
            'monto' => ['required', 'numeric', 'min:0'],
            'codigo_transaccion' => ['nullable', 'string', 'max:100', 'unique:pago_cita,codigo_transaccion'],
            'detalles_pasarela' => ['nullable', 'array'], // Aquí se guarda la respuesta del banco (JSON)
            'estado' => ['nullable', Rule::in(['Pendiente', 'Completado', 'Rechazado', 'Reembolsado'])],
        ];
    }
}