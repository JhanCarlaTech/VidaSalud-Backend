<?php

namespace App\Modules\ModuloAtencionClinica\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloAtencionClinica\Requests\ConsultaRequest;
use App\Modules\ModuloAtencionClinica\Resources\ConsultaResource;
use App\Modules\ModuloAtencionClinica\Services\ConsultaService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * CU17 – Registrar Consulta Médica.
 */
class ConsultaController extends Controller
{
    public function __construct(
        private readonly ConsultaService $consultas
    ) {}

    /**
     * POST /api/consultas
     */
    public function store(ConsultaRequest $request): JsonResponse
    {
        $consulta = $this->consultas->registrar($request->validated(), $request->user());

        return ApiResponse::exito(new ConsultaResource($consulta), 'Consulta médica registrada correctamente.', 201);
    }
}
