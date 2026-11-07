<?php

namespace App\Modules\ModuloInteligenciaReportes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloInteligenciaReportes\Requests\SuscripcionPushRequest;
use App\Modules\ModuloInteligenciaReportes\Services\PushService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU20 – Notificaciones push del usuario con sesión (sus propios dispositivos).
 */
class PushController extends Controller
{
    public function __construct(
        private readonly PushService $push
    ) {}

    /**
     * GET /api/push/configuracion
     */
    public function configuracion(Request $request): JsonResponse
    {
        return ApiResponse::exito([
            'habilitado' => $this->push->configurado(),
            'clave_publica' => $this->push->clavePublica(),
            'dispositivos' => $this->push->cantidadDispositivos($request->user()->id),
        ], 'Configuración de notificaciones push.');
    }

    /**
     * POST /api/push/suscripciones
     */
    public function suscribir(SuscripcionPushRequest $request): JsonResponse
    {
        if (! $this->push->configurado()) {
            return ApiResponse::error('Las notificaciones push no están disponibles en este momento.', 503);
        }

        $this->push->suscribir($request->user()->id, $request->validated(), $request->userAgent());

        return ApiResponse::exito(null, 'Avisos activados en este dispositivo.', 201);
    }

    /**
     * DELETE /api/push/suscripciones  { endpoint }
     */
    public function desuscribir(Request $request): JsonResponse
    {
        $datos = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $this->push->desuscribir($request->user()->id, $datos['endpoint']);

        return ApiResponse::exito(null, 'Avisos desactivados en este dispositivo.');
    }

    /**
     * POST /api/push/prueba – aviso de prueba a los dispositivos del propio usuario.
     */
    public function prueba(Request $request): JsonResponse
    {
        $entregados = $this->push->enviarAUsuario($request->user()->id, [
            'titulo' => 'Clínica Vida Salud',
            'cuerpo' => 'Las notificaciones están activadas. Así le avisaremos de sus citas.',
            'url' => '/',
            'etiqueta' => 'prueba',
        ], respetarPreferencia: false);

        return $entregados > 0
            ? ApiResponse::exito(['entregados' => $entregados], 'Aviso de prueba enviado.')
            : ApiResponse::error('No se pudo entregar el aviso. Vuelva a activar las notificaciones en este dispositivo.', 422);
    }
}
