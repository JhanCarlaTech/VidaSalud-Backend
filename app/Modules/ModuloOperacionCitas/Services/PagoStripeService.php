<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Models\MetodoPago;
use App\Modules\ModuloOperacionCitas\Models\PagoCita;
use App\Modules\ModuloPersonalMedico\Models\Especialidad;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Charge;
use Stripe\PaymentIntent;

/**
 * CU24 – Cobro de la cita con tarjeta a través de Stripe.
 *
 * Flujo:
 *  1. iniciar(): el servidor calcula el monto (precio de la especialidad), crea el
 *     PaymentIntent y deja el pago 'Pendiente'. El navegador recibe solo el client_secret.
 *  2. El navegador envía la tarjeta directamente a Stripe (la API nunca la ve).
 *  3. confirmar() o el webhook consultan el PaymentIntent en Stripe y, si está cobrado,
 *     marcan el pago 'Completado'. El estado nunca se toma de lo que diga el navegador.
 */
class PagoStripeService
{
    /** Estados de Stripe en los que el intento todavía puede cobrarse. */
    private const INTENTO_REUTILIZABLE = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    public function __construct(
        private readonly PasarelaStripe $stripe,
        private readonly BitacoraService $bitacora,
    ) {}

    /**
     * Prepara el cobro de la cita y devuelve el pago pendiente con el client_secret.
     *
     * @return array{pago: PagoCita, client_secret: ?string} client_secret null: ya estaba cobrado.
     *
     * @throws ValidationException
     */
    public function iniciar(CitaMedica $cita, int $idEspecialidad, int $idUsuario): array
    {
        $metodo = $this->metodoStripe();
        $especialidad = $this->especialidadDeLaCita($cita, $idEspecialidad);
        $monto = (string) $especialidad->precio_consulta;
        $centavos = PasarelaStripe::aCentavos($monto);

        if ($centavos <= 0) {
            throw ValidationException::withMessages(['id_especialidad' => 'Esta consulta no tiene costo; no requiere pago en línea.']);
        }

        return DB::transaction(function () use ($cita, $metodo, $especialidad, $monto, $centavos, $idUsuario) {
            // Bloquea la cita: dos pestañas pagando a la vez no crean dos cobros.
            $cita = CitaMedica::query()->whereKey($cita->id)->lockForUpdate()->firstOrFail();
            $this->validarCitaCobrable($cita);

            $pago = PagoCita::query()->where('id_cita', $cita->id)->lockForUpdate()->first();
            if ($pago?->estado === PagoCita::COMPLETADO) {
                throw ValidationException::withMessages(['cita' => 'Esta cita ya está pagada.']);
            }

            // Si ya hay un intento de Stripe para este mismo monto, se reutiliza.
            $intento = $this->intentoReutilizable($pago, $centavos);
            // Se cobró pero la confirmación no llegó (p. ej., se cerró la pestaña): se registra ahora.
            if ($intento?->status === 'succeeded') {
                $this->aplicarIntento($pago, $intento, $idUsuario);

                return ['pago' => $pago->load('metodoPago'), 'client_secret' => null];
            }

            if ($intento === null) {
                $intento = $this->stripe->crearIntento(
                    $centavos,
                    "Consulta de {$especialidad->nombre} · Cita {$cita->codigo_cita}",
                    [
                        'id_cita' => $cita->id,
                        'codigo_cita' => $cita->codigo_cita,
                        'id_especialidad' => $especialidad->id,
                    ],
                    'cita-'.$cita->id.'-'.Str::uuid(),
                );
            }

            $antes = $pago ? $this->instantanea($pago) : null;
            $pago ??= new PagoCita(['id_cita' => $cita->id]);
            $pago->fill([
                'id_metodo_pago' => $metodo->id,
                'codigo_transaccion' => $intento->id,
                'monto' => $monto,
                'moneda' => strtoupper($this->stripe->moneda()),
                'estado' => PagoCita::PENDIENTE,
                'detalles_pasarela' => [
                    'gateway' => MetodoPago::PASARELA_STRIPE,
                    'payment_intent' => $intento->id,
                    'estado_stripe' => $intento->status,
                    'id_especialidad' => $especialidad->id,
                    'especialidad' => $especialidad->nombre,
                ],
            ])->save();

            // Reabrir el mismo intento no es un cambio que auditar.
            $despues = $this->instantanea($pago);
            if ($antes !== $despues) {
                $this->bitacora->registrar($antes ? 'UPDATE' : 'INSERT', 'pago_cita', $idUsuario, $antes, $despues);
            }

            return ['pago' => $pago->load('metodoPago'), 'client_secret' => $intento->client_secret];
        });
    }

    /**
     * Consulta el intento en Stripe y actualiza el pago (vuelta del formulario de pago).
     *
     * @throws ValidationException
     */
    public function confirmar(CitaMedica $cita, int $idUsuario): PagoCita
    {
        return DB::transaction(function () use ($cita, $idUsuario) {
            $pago = PagoCita::query()->where('id_cita', $cita->id)->lockForUpdate()->first();

            if (! $pago || ! $this->esDeStripe($pago)) {
                throw ValidationException::withMessages(['cita' => 'La cita no tiene un pago en línea iniciado.']);
            }

            if ($pago->estado !== PagoCita::COMPLETADO) {
                $this->aplicarIntento($pago, $this->stripe->obtenerIntento($pago->codigo_transaccion), $idUsuario);
            }

            return $pago->load('metodoPago');
        });
    }

    /**
     * Antes de cambiar a otro método un pago pendiente de Stripe: si ya se cobró,
     * se registra y devuelve false; si no, se anula el intento y devuelve true.
     */
    public function liberarPendiente(int $idCita, int $idUsuario): bool
    {
        return DB::transaction(function () use ($idCita, $idUsuario) {
            $pago = PagoCita::query()->where('id_cita', $idCita)->lockForUpdate()->first();

            if (! $pago || ! $this->esDeStripe($pago) || $pago->estado !== PagoCita::PENDIENTE) {
                return true;
            }

            $intento = $this->stripe->obtenerIntento($pago->codigo_transaccion);
            if ($intento->status === 'succeeded') {
                $this->aplicarIntento($pago, $intento, $idUsuario);

                return false;
            }

            $this->stripe->cancelarIntento($intento->id);

            return true;
        });
    }

    /**
     * Webhook: Stripe avisa que un intento cambió de estado. Se vuelve a consultar
     * el intento (no se confía en el cuerpo del evento) y se aplica.
     */
    public function sincronizarDesdeWebhook(string $idIntento): void
    {
        DB::transaction(function () use ($idIntento) {
            $pago = PagoCita::query()->where('codigo_transaccion', $idIntento)->lockForUpdate()->first();

            // Intentos de otras aplicaciones de la misma cuenta, o reemplazados: se ignoran.
            if (! $pago || ! $this->esDeStripe($pago) || $pago->estado === PagoCita::COMPLETADO) {
                return;
            }

            $this->aplicarIntento($pago, $this->stripe->obtenerIntento($idIntento), null);
        });
    }

    /**
     * Pasa el estado del intento de Stripe al pago. Es idempotente: confirmar() y el
     * webhook pueden llegar en cualquier orden.
     */
    private function aplicarIntento(PagoCita $pago, PaymentIntent $intento, ?int $idUsuario): void
    {
        $this->verificarCorrespondencia($pago, $intento);

        $nuevoEstado = match ($intento->status) {
            'succeeded' => PagoCita::COMPLETADO,
            'canceled' => PagoCita::RECHAZADO,
            default => PagoCita::PENDIENTE, // processing, requires_payment_method (tarjeta rechazada), etc.
        };

        $detalles = [
            ...($pago->detalles_pasarela ?? []),
            'estado_stripe' => $intento->status,
            'ultimo_error' => $intento->last_payment_error?->message,
        ];

        $cargo = $intento->latest_charge;
        // Solo el cargo cobrado describe la tarjeta con que se pagó (no la rechazada).
        if ($cargo instanceof Charge && $cargo->status === 'succeeded') {
            $tarjeta = $cargo->payment_method_details?->card;
            $detalles = [
                ...$detalles,
                'charge' => $cargo->id,
                'tarjeta_marca' => $tarjeta?->brand,
                'tarjeta_ultimos4' => $tarjeta?->last4,
                'recibo_url' => $cargo->receipt_url,
            ];
        }

        $antes = $this->instantanea($pago);
        $pago->fill(['estado' => $nuevoEstado, 'detalles_pasarela' => $detalles]);
        if ($nuevoEstado === PagoCita::COMPLETADO) {
            $pago->fecha_pago = now();
        }

        if (! $pago->isDirty()) {
            return;
        }

        $pago->save();

        if ($antes['estado'] !== $nuevoEstado) {
            $this->bitacora->registrar('UPDATE', 'pago_cita', $idUsuario, $antes, $this->instantanea($pago));
        }
    }

    /**
     * El intento debe ser de esta cita y por el monto registrado; si no, algo se manipuló.
     */
    private function verificarCorrespondencia(PagoCita $pago, PaymentIntent $intento): void
    {
        $coincide = (int) ($intento->metadata['id_cita'] ?? 0) === (int) $pago->id_cita
            && $intento->amount === PasarelaStripe::aCentavos($pago->monto)
            && strtolower($intento->currency) === strtolower($pago->moneda);

        if (! $coincide) {
            Log::error('El PaymentIntent no corresponde al pago registrado', ['id_pago' => $pago->id, 'payment_intent' => $intento->id]);

            throw new HttpResponseException(ApiResponse::error('No se pudo verificar el pago. Comuníquese con recepción.', 409));
        }
    }

    /** Intento previo de esta cita que todavía sirve para el monto actual. */
    private function intentoReutilizable(?PagoCita $pago, int $centavos): ?PaymentIntent
    {
        if (! $pago || ! $this->esDeStripe($pago) || ! $pago->codigo_transaccion) {
            return null;
        }

        $intento = $this->stripe->obtenerIntento($pago->codigo_transaccion);

        if ($intento->status === 'succeeded') {
            return $intento;
        }

        if ($intento->amount === $centavos && in_array($intento->status, self::INTENTO_REUTILIZABLE, true)) {
            return $intento;
        }

        // Cambió el precio o el intento quedó inservible: se anula y se crea otro.
        if (in_array($intento->status, self::INTENTO_REUTILIZABLE, true)) {
            $this->stripe->cancelarIntento($intento->id);
        }

        return null;
    }

    private function metodoStripe(): MetodoPago
    {
        $metodo = MetodoPago::query()->where('pasarela', MetodoPago::PASARELA_STRIPE)->where('activo', true)->first();

        if (! $metodo || ! $this->stripe->configurada()) {
            throw new HttpResponseException(ApiResponse::error('El pago en línea no está disponible en este momento.', 503));
        }

        return $metodo;
    }

    /** RN: se cobra el precio de una especialidad activa que ejerza el médico de la cita. */
    private function especialidadDeLaCita(CitaMedica $cita, int $idEspecialidad): Especialidad
    {
        $especialidad = $cita->medico?->especialidades()
            ->where('especialidad.id', $idEspecialidad)
            ->where('especialidad.activo', true)
            ->first();

        if (! $especialidad) {
            throw ValidationException::withMessages(['id_especialidad' => 'El médico de la cita no atiende esa especialidad.']);
        }

        return $especialidad;
    }

    private function validarCitaCobrable(CitaMedica $cita): void
    {
        if (! in_array($cita->estado, CitaMedica::ESTADOS_PENDIENTES, true)) {
            throw ValidationException::withMessages(['cita' => "No se puede pagar una cita en estado {$cita->estado}."]);
        }
    }

    private function esDeStripe(PagoCita $pago): bool
    {
        return ($pago->detalles_pasarela['gateway'] ?? null) === MetodoPago::PASARELA_STRIPE;
    }

    /** @return array<string, mixed> */
    private function instantanea(PagoCita $pago): array
    {
        return [
            'id_pago' => $pago->id,
            'id_cita' => $pago->id_cita,
            'metodo' => 'Stripe',
            'monto' => $pago->monto,
            'estado' => $pago->estado,
            'codigo_transaccion' => $pago->codigo_transaccion,
        ];
    }
}
