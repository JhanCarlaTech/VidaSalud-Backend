<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Requests\ConsultaRequest;
use App\Modules\ModuloOperacionCitas\Services\ConsultaService;
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
        $consulta = $this->consultas->registrar($request->validated(), $request->user()->id);

        return ApiResponse::exito($consulta, 'Consulta médica registrada correctamente.', 201);
    }
}