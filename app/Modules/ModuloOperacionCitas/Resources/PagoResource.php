<?php

namespace App\Modules\ModuloOperacionCitas\Resources;

use App\Modules\ModuloOperacionCitas\Models\PagoCita;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pago de una cita (CU24). De la respuesta de la pasarela solo se exponen los
 * datos que ve el paciente en su comprobante (nunca identificadores internos de Stripe).
 *
 * @mixin PagoCita
 */
class PagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detalles = $this->detalles_pasarela ?? [];

        return [
            'id' => $this->id,
            'id_cita' => $this->id_cita,
            'metodo' => $this->metodoPago?->nombre,
            'en_linea' => (bool) $this->metodoPago?->esEnLinea(),
            'monto' => (float) $this->monto,
            'moneda' => $this->moneda,
            'estado' => $this->estado,
            'codigo_transaccion' => $this->codigo_transaccion,
            'fecha_pago' => $this->estado === PagoCita::COMPLETADO ? $this->fecha_pago?->toIso8601String() : null,
            'tarjeta' => isset($detalles['tarjeta_ultimos4'])
                ? ['marca' => $detalles['tarjeta_marca'] ?? null, 'ultimos4' => $detalles['tarjeta_ultimos4']]
                : null,
            'recibo_url' => $detalles['recibo_url'] ?? null,
            'ultimo_error' => $detalles['ultimo_error'] ?? null,
        ];
    }
}
