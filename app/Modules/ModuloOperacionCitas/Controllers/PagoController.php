<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Requests\PagoRequest;
use App\Modules\ModuloOperacionCitas\Resources\PagoResource;
use App\Modules\ModuloOperacionCitas\Services\PagoService;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * CU24 – Gestionar Pasarela de Pago (pagos sin pasarela en línea).
 */
class PagoController extends Controller
{
    public function __construct(
        private readonly PagoService $pagos,
        private readonly AuthService $auth,
    ) {}

    /**
     * POST /api/pagos
     */
    public function store(PagoRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $esPersonal = $this->auth->permisos($usuario)->contains('citas_programar');

        $pago = $this->pagos->registrar($request->validated(), $usuario, $esPersonal);

        $mensaje = $pago->estado === 'Pendiente'
            ? 'Pago registrado como pendiente de verificación.'
            : 'Pago procesado y registrado correctamente.';

        return ApiResponse::exito(new PagoResource($pago), $mensaje, 201);
    }
}
