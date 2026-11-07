<?php

namespace App\Modules\ModuloOperacionCitas\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CU24 – Iniciar el pago con tarjeta (Stripe). El monto no se recibe: lo fija
 * el servidor con el precio de la especialidad.
 */
class IniciarPagoEnLineaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Quién puede pagar la cita lo decide PagoStripeController.
    }

    public function rules(): array
    {
        return [
            // Solo para citas antiguas sin especialidad guardada (ver PrecioCitaService).
            'id_especialidad' => ['nullable', 'integer', 'exists:especialidad,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_especialidad.exists' => 'La especialidad no existe.',
        ];
    }
}
