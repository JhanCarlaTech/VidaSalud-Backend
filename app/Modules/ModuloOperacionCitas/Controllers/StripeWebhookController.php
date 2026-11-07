<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Services\PagoStripeService;
use App\Modules\ModuloOperacionCitas\Services\PasarelaStripe;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

/**
 * CU24 – Webhook de Stripe (POST /api/stripe/webhook, sin sesión).
 *
 * Respaldo de la confirmación: si el paciente cierra la pestaña tras pagar,
 * Stripe avisa igual y el pago queda registrado. Solo se aceptan eventos con
 * firma válida (STRIPE_WEBHOOK_SECRET).
 */
class StripeWebhookController extends Controller
{
    private const EVENTOS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'payment_intent.processing',
    ];

    public function __construct(
        private readonly PasarelaStripe $stripe,
        private readonly PagoStripeService $pagos,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->stripe->webhookConfigurado()) {
            return ApiResponse::error('Webhook no configurado.', 503);
        }

        try {
            $evento = $this->stripe->eventoWebhook($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return ApiResponse::error('Firma inválida.', 400);
        }

        if (in_array($evento->type, self::EVENTOS, true)) {
            $this->pagos->sincronizarDesdeWebhook($evento->data->object->id);
        }

        return ApiResponse::exito(null, 'Evento recibido.');
    }
}
