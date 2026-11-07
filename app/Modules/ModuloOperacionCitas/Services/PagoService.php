<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Models\MetodoPago;
use App\Modules\ModuloOperacionCitas\Models\PagoCita;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU24 – Registro de pagos que no pasan por una pasarela en línea
 * (QR, transferencia y efectivo). El pago con tarjeta lo hace PagoStripeService.
 */
class PagoService
{
    public function __construct(
        private readonly BitacoraService $bitacora,
        private readonly PagoStripeService $pagosStripe,
        private readonly PrecioCitaService $precios,
    ) {}

    /**
     * @param  bool  $esPersonal  El personal registra pagos ya verificados; el paciente
     *                            solo deja constancia de cómo pagará (queda 'Pendiente').
     *
     * @throws ValidationException
     */
    public function registrar(array $datos, Usuario $actor, bool $esPersonal): PagoCita
    {
        $metodo = MetodoPago::query()->findOrFail($datos['id_metodo_pago']);
        if ($metodo->esEnLinea()) {
            throw ValidationException::withMessages([
                'id_metodo_pago' => 'El pago con tarjeta se realiza en línea con Stripe; no se registra a mano.',
            ]);
        }

        $cita = CitaMedica::query()->findOrFail($datos['id_cita']);
        if (! $esPersonal) {
            if ((int) $cita->id_paciente !== (int) $actor->id) {
                throw new AccessDeniedHttpException;
            }
            if (! in_array($cita->estado, CitaMedica::ESTADOS_PENDIENTES, true)) {
                throw ValidationException::withMessages(['id_cita' => "No se puede pagar una cita en estado {$cita->estado}."]);
            }
            // El paciente no fija el monto: es el precio de la especialidad de la cita.
            $datos['monto'] = (string) $this->precios->resolver($cita, $datos['id_especialidad'] ?? null)->precio_consulta;
        }

        // Un cobro con tarjeta a medio hacer se anula antes de elegir otro método.
        if (! $this->pagosStripe->liberarPendiente($cita->id, $actor->id)) {
            throw ValidationException::withMessages(['id_cita' => 'Esta cita ya fue pagada con tarjeta.']);
        }

        return DB::transaction(function () use ($datos, $actor, $esPersonal) {
            $existente = PagoCita::query()->where('id_cita', $datos['id_cita'])->lockForUpdate()->first();

            // Solo se reemplaza un pago que sigue pendiente (p. ej., cambió de tarjeta a efectivo).
            if ($existente && $existente->estado !== PagoCita::PENDIENTE) {
                throw ValidationException::withMessages([
                    'id_cita' => 'Esta cita ya tiene un pago registrado en el sistema.',
                ]);
            }

            $antes = $existente ? $this->instantanea($existente) : null;
            $pago = $existente ?? new PagoCita(['id_cita' => $datos['id_cita']]);

            $pago->fill([
                'id_metodo_pago' => $datos['id_metodo_pago'],
                'monto' => $datos['monto'],
                'moneda' => 'BOB',
                'codigo_transaccion' => $datos['codigo_transaccion'] ?? 'TXN-'.uniqid(),
                // El paciente no puede declarar pagado lo que nadie verificó.
                'estado' => $esPersonal ? ($datos['estado'] ?? PagoCita::COMPLETADO) : PagoCita::PENDIENTE,
                'detalles_pasarela' => $datos['detalles_pasarela'] ?? null,
            ])->save();

            $this->bitacora->registrar($antes ? 'UPDATE' : 'INSERT', 'pago_cita', $actor->id, $antes, $this->instantanea($pago));

            return $pago->load('metodoPago');
        });
    }

    /** @return array<string, mixed> */
    private function instantanea(PagoCita $pago): array
    {
        return [
            'id_pago' => $pago->id,
            'id_cita' => $pago->id_cita,
            'id_metodo_pago' => $pago->id_metodo_pago,
            'monto' => $pago->monto,
            'estado' => $pago->estado,
        ];
    }
}
