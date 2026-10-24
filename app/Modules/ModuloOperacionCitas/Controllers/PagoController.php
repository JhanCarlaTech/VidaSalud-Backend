<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Requests\PagoRequest;
use App\Modules\ModuloOperacionCitas\Services\PagoService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * CU24 – Gestionar Pasarela de Pago.
 */
class PagoController extends Controller
{
    public function __construct(
        private readonly PagoService $pagos
    ) {}

    /**
     * POST /api/pagos
     */
    public function store(PagoRequest $request): JsonResponse
    {
        $pago = $this->pagos->registrar($request->validated(), $request->user()->id);

        return ApiResponse::exito($pago, 'Pago procesado y registrado correctamente.', 201);
    }
}