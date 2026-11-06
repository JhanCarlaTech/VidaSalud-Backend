<?php

namespace App\Modules\ModuloInteligenciaReportes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloInteligenciaReportes\Models\Notificacion;
use App\Modules\ModuloInteligenciaReportes\Resources\NotificacionResource;
use App\Modules\ModuloInteligenciaReportes\Services\NotificacionService;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU20 – Bandeja de notificaciones del propio paciente (RN07).
 */
class MisNotificacionesController extends Controller
{
    private const POR_PAGINA = 10;

    public function __construct(private readonly NotificacionService $notificaciones) {}

    /**
     * GET /api/mis-notificaciones?no_leidas=1&page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['no_leidas' => ['nullable', 'boolean']]);
        $paciente = $this->pacienteDe($request->user());

        $pagina = $this->notificaciones->bandeja($paciente, $request->boolean('no_leidas'), self::POR_PAGINA);

        return ApiResponse::exito([
            'items' => NotificacionResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
            'no_leidas' => $this->notificaciones->noLeidas($paciente),
        ], 'Mis notificaciones.');
    }

    /**
     * GET /api/mis-notificaciones/resumen: contador para el aviso del menú.
     */
    public function resumen(Request $request): JsonResponse
    {
        return ApiResponse::exito(['no_leidas' => $this->notificaciones->noLeidas($this->pacienteDe($request->user()))], 'Resumen de notificaciones.');
    }

    /**
     * PATCH /api/mis-notificaciones/{notificacion}/leida
     */
    public function marcarLeida(Request $request, Notificacion $notificacion): JsonResponse
    {
        // Una notificación ajena responde 404, para no revelar que existe.
        if ((int) $notificacion->id_paciente !== (int) $this->pacienteDe($request->user())->id_usuario) {
            abort(404);
        }

        return ApiResponse::exito(new NotificacionResource($this->notificaciones->marcarLeida($notificacion)->load('cita')), 'Notificación marcada como leída.');
    }

    /**
     * PATCH /api/mis-notificaciones/leer-todas
     */
    public function marcarTodas(Request $request): JsonResponse
    {
        $cantidad = $this->notificaciones->marcarTodasLeidas($this->pacienteDe($request->user()));

        return ApiResponse::exito(['marcadas' => $cantidad], $cantidad === 0 ? 'No había notificaciones sin leer.' : 'Todas las notificaciones quedaron como leídas.');
    }

    /**
     * @throws AccessDeniedHttpException si el usuario no es paciente.
     */
    private function pacienteDe(Usuario $usuario): Paciente
    {
        return Paciente::query()->find($usuario->id) ?? throw new AccessDeniedHttpException;
    }
}
