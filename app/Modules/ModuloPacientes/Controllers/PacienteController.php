<?php

namespace App\Modules\ModuloPacientes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPacientes\Requests\PacienteCreateRequest;
use App\Modules\ModuloPacientes\Requests\PacienteUpdateRequest;
use App\Modules\ModuloPacientes\Resources\PacienteResource;
use App\Modules\ModuloPacientes\Services\PacienteService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\Requests\CambiarEstadoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU11 – Gestionar Pacientes (Administrador y Recepcionista).
 */
class PacienteController extends Controller
{
    private const POR_PAGINA = 10;

    public function __construct(private readonly PacienteService $pacientes) {}

    /**
     * GET /api/pacientes?buscar=&activo=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $pagina = $this->pacientes->listar([
            'buscar' => $request->filled('buscar') ? trim($request->input('buscar')) : null,
            'activo' => $request->filled('activo') ? $request->boolean('activo') : null,
        ], self::POR_PAGINA);

        return ApiResponse::exito([
            'items' => PacienteResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ], 'Listado de pacientes.');
    }

    /**
     * GET /api/pacientes/{paciente}
     */
    public function show(Paciente $paciente): JsonResponse
    {
        return ApiResponse::exito(new PacienteResource($this->pacientes->detalle($paciente)), 'Detalle del paciente.');
    }

    /**
     * POST /api/pacientes
     */
    public function store(PacienteCreateRequest $request): JsonResponse
    {
        $paciente = $this->pacientes->crear($request->validated(), $request->user());

        return ApiResponse::exito(new PacienteResource($paciente), "Paciente registrado correctamente con el código {$paciente->codigo}.", 201);
    }

    /**
     * PUT /api/pacientes/{paciente}
     */
    public function update(PacienteUpdateRequest $request, Paciente $paciente): JsonResponse
    {
        $paciente = $this->pacientes->actualizar($paciente, $request->validated(), $request->user());

        return ApiResponse::exito(new PacienteResource($paciente), 'Paciente actualizado correctamente.');
    }

    /**
     * PATCH /api/pacientes/{paciente}/estado
     */
    public function cambiarEstado(CambiarEstadoRequest $request, Paciente $paciente): JsonResponse
    {
        $activo = $request->boolean('activo');
        $paciente = $this->pacientes->cambiarEstado($paciente, $activo, $request->user());

        return ApiResponse::exito(
            new PacienteResource($paciente),
            $activo ? 'Paciente activado correctamente.' : 'Paciente desactivado correctamente.',
        );
    }
}
