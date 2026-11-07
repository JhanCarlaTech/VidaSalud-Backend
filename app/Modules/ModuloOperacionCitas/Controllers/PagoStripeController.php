<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Models\PagoCita;
use App\Modules\ModuloOperacionCitas\Requests\IniciarPagoEnLineaRequest;
use App\Modules\ModuloOperacionCitas\Resources\PagoResource;
use App\Modules\ModuloOperacionCitas\Services\PagoStripeService;
use App\Modules\ModuloOperacionCitas\Services\PasarelaStripe;
use App\Modules\ModuloOperacionCitas\Services\PrecioCitaService;
use App\Modules\ModuloPersonalMedico\Models\Especialidad;
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
        private readonly PrecioCitaService $precios,
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
     * GET /api/citas/{cita}/pago
     * Lo necesario para pagar una cita desde "Mis citas": pago actual, si todavía
     * se puede pagar y las especialidades (precio) entre las que se cobra.
     */
    public function contexto(Request $request, CitaMedica $cita): JsonResponse
    {
        $this->autorizar($request->user(), $cita);
        $cita->load(['pago.metodoPago', 'medico']);

        return ApiResponse::exito([
            'pago' => $cita->pago ? new PagoResource($cita->pago) : null,
            'cobrable' => in_array($cita->estado, CitaMedica::ESTADOS_PENDIENTES, true)
                && $cita->pago?->estado !== PagoCita::COMPLETADO,
            'especialidades' => $this->precios->opciones($cita)->map(fn (Especialidad $especialidad) => [
                'id' => $especialidad->id,
                'nombre' => $especialidad->nombre,
                'precio_consulta' => (float) $especialidad->precio_consulta,
            ])->values(),
        ], 'Pago de la cita.');
    }

    /**
     * POST /api/citas/{cita}/pago-en-linea
     */
    public function iniciar(IniciarPagoEnLineaRequest $request, CitaMedica $cita): JsonResponse
    {
        $this->autorizar($request->user(), $cita);

        $idEspecialidad = $request->validated('id_especialidad');
        $resultado = $this->pagos->iniciar($cita, $idEspecialidad ? (int) $idEspecialidad : null, $request->user()->id);

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
