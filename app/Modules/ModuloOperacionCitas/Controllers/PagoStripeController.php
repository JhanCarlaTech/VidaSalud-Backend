<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Requests\IniciarPagoEnLineaRequest;
use App\Modules\ModuloOperacionCitas\Resources\PagoResource;
use App\Modules\ModuloOperacionCitas\Services\PagoStripeService;
use App\Modules\ModuloOperacionCitas\Services\PasarelaStripe;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU24 – Pago con tarjeta mediante Stripe.
 */
class PagoStripeController extends Controller
{
    public function __construct(
        private readonly PagoStripeService $pagos,
        private readonly PasarelaStripe $stripe,
        private readonly AuthService $auth,
    ) {}

    /**
     * GET /api/pagos/stripe/configuracion
     * Clave pública para cargar el formulario de Stripe en el navegador.
     */
    public function configuracion(): JsonResponse
    {
        return ApiResponse::exito([
            'habilitado' => $this->stripe->configurada(),
            'clave_publica' => $this->stripe->configurada() ? $this->stripe->clavePublica() : null,
            'moneda' => strtoupper($this->stripe->moneda()),
        ], 'Configuración del pago en línea.');
    }

    /**
     * POST /api/citas/{cita}/pago-en-linea
     */
    public function iniciar(IniciarPagoEnLineaRequest $request, CitaMedica $cita): JsonResponse
    {
        $this->autorizar($request->user(), $cita);

        $resultado = $this->pagos->iniciar($cita, (int) $request->validated('id_especialidad'), $request->user()->id);

        if ($resultado['client_secret'] === null) {
            return ApiResponse::exito(['pago' => new PagoResource($resultado['pago']), 'client_secret' => null], 'Esta cita ya está pagada.');
        }

        return ApiResponse::exito([
            'pago' => new PagoResource($resultado['pago']),
            'client_secret' => $resultado['client_secret'],
        ], 'Pago en línea preparado.', 201);
    }

    /**
     * POST /api/citas/{cita}/pago-en-linea/confirmar
     */
    public function confirmar(Request $request, CitaMedica $cita): JsonResponse
    {
        $this->autorizar($request->user(), $cita);

        $pago = $this->pagos->confirmar($cita, $request->user()->id);

        $mensaje = match ($pago->estado) {
            'Completado' => 'Pago realizado correctamente.',
            'Rechazado' => 'El pago fue anulado.',
            default => 'El pago todavía no se completó.',
        };

        return ApiResponse::exito(new PagoResource($pago), $mensaje);
    }

    /** El paciente paga sus propias citas; el personal con 'citas_programar', cualquiera. */
    private function autorizar(Usuario $usuario, CitaMedica $cita): void
    {
        if ((int) $cita->id_paciente === (int) $usuario->id || $this->auth->permisos($usuario)->contains('citas_programar')) {
            return;
        }

        throw new AccessDeniedHttpException;
    }
}
