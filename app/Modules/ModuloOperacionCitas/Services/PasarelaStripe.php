<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Shared\Http\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * CU24 – Adaptador de la API de Stripe.
 *
 * Es la única clase que habla con Stripe: el resto del sistema trabaja con
 * montos en bolivianos y recibe errores seguros (sin detalles internos de Stripe).
 */
class PasarelaStripe
{
    private ?StripeClient $cliente = null;

    public function configurada(): bool
    {
        return filled(config('services.stripe.secret')) && filled(config('services.stripe.publicable'));
    }

    public function clavePublica(): ?string
    {
        return config('services.stripe.publicable');
    }

    public function moneda(): string
    {
        return config('services.stripe.moneda');
    }

    public function webhookConfigurado(): bool
    {
        return filled(config('services.stripe.webhook_secret'));
    }

    /** Bs 150,00 → 15000 (Stripe cobra en la unidad mínima de la moneda). */
    public static function aCentavos(string|float $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }

    /**
     * Crea el intento de cobro. Solo métodos sin redirección (tarjetas), para que
     * el pago se complete dentro de la página; 3-D Secure se resuelve en una ventana de Stripe.
     *
     * @param  array<string, string|int>  $metadatos
     */
    public function crearIntento(int $centavos, string $descripcion, array $metadatos, string $claveIdempotencia): PaymentIntent
    {
        return $this->llamar(fn (StripeClient $stripe) => $stripe->paymentIntents->create([
            'amount' => $centavos,
            'currency' => $this->moneda(),
            'description' => $descripcion,
            'metadata' => $metadatos,
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        ], ['idempotency_key' => $claveIdempotencia]));
    }

    /** Intento con el último cargo expandido (marca, últimos 4 dígitos y recibo). */
    public function obtenerIntento(string $id): PaymentIntent
    {
        return $this->llamar(fn (StripeClient $stripe) => $stripe->paymentIntents->retrieve($id, [
            'expand' => ['latest_charge'],
        ]));
    }

    /** Anula un intento que ya no se usará (p. ej., cambió el precio). Si ya no se puede, se ignora. */
    public function cancelarIntento(string $id): void
    {
        try {
            $this->llamar(fn (StripeClient $stripe) => $stripe->paymentIntents->cancel($id));
        } catch (HttpResponseException) {
            // Un intento ya cobrado o cancelado no se puede anular; quien llama vuelve a consultarlo.
        }
    }

    /**
     * Verifica la firma del webhook y devuelve el evento.
     *
     * @throws SignatureVerificationException|\UnexpectedValueException
     */
    public function eventoWebhook(string $contenido, string $firma): Event
    {
        return Webhook::constructEvent($contenido, $firma, config('services.stripe.webhook_secret'));
    }

    /**
     * Ejecuta una llamada y traduce los errores de Stripe a respuestas seguras.
     *
     * @template T
     *
     * @param  callable(StripeClient): T  $llamada
     * @return T
     */
    private function llamar(callable $llamada): mixed
    {
        if (! $this->configurada()) {
            throw new HttpResponseException(ApiResponse::error('El pago en línea no está disponible en este momento.', 503));
        }

        try {
            return $llamada($this->cliente());
        } catch (ApiErrorException $e) {
            Log::warning('Stripe rechazó la solicitud', [
                'tipo' => $e::class,
                'codigo' => $e->getStripeCode(),
                'http' => $e->getHttpStatus(),
                'mensaje' => $e->getMessage(),
                'request_id' => $e->getRequestId(),
            ]);

            throw new HttpResponseException(ApiResponse::error(
                'No se pudo comunicar con la pasarela de pago. Intente de nuevo en unos minutos.',
                502,
            ));
        }
    }

    private function cliente(): StripeClient
    {
        if ($this->cliente === null) {
            $http = CurlClient::instance();
            $http->setTimeout(config('services.stripe.timeout'));
            $http->setConnectTimeout(10);

            $this->cliente = new StripeClient([
                'api_key' => config('services.stripe.secret'),
                'max_network_retries' => 2,
            ]);
        }

        return $this->cliente;
    }
}
