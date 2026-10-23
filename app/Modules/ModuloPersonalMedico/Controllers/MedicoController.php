<?php

namespace App\Modules\ModuloPersonalMedico\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Modules\ModuloPersonalMedico\Requests\MedicoCreateRequest;
use App\Modules\ModuloPersonalMedico\Requests\MedicoUpdateRequest;
use App\Modules\ModuloPersonalMedico\Resources\MedicoResource;
use App\Modules\ModuloPersonalMedico\Services\MedicoService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\Requests\CambiarEstadoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU08 – Gestionar Personal Médico.
 */
class MedicoController extends Controller
{
    private const POR_PAGINA = 10;

    public function __construct(private readonly MedicoService $medicos) {}

    /**
     * GET /api/medicos?buscar=&especialidad=&activo=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'especialidad' => ['nullable', 'integer'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $pagina = $this->medicos->listar([
            'buscar' => $request->filled('buscar') ? trim($request->input('buscar')) : null,
            'especialidad' => $request->filled('especialidad') ? $request->integer('especialidad') : null,
            'activo' => $request->filled('activo') ? $request->boolean('activo') : null,
        ], self::POR_PAGINA);

        return ApiResponse::exito([
            'items' => MedicoResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ], 'Listado de médicos.');
    }

    /**
     * GET /api/medicos/{medico}
     */
    public function show(Medico $medico): JsonResponse
    {
        return ApiResponse::exito(new MedicoResource($this->medicos->detalle($medico)), 'Detalle del médico.');
    }

    /**
     * POST /api/medicos
     */
    public function store(MedicoCreateRequest $request): JsonResponse
    {
        $medico = $this->medicos->crear($request->validated(), $request->user());

        return ApiResponse::exito(new MedicoResource($medico), "Médico registrado correctamente con el código {$medico->codigo}.", 201);
    }

    /**
     * PUT /api/medicos/{medico}
     */
    public function update(MedicoUpdateRequest $request, Medico $medico): JsonResponse
    {
        $medico = $this->medicos->actualizar($medico, $request->validated(), $request->user());

        return ApiResponse::exito(new MedicoResource($medico), 'Médico actualizado correctamente.');
    }

    /**
     * PATCH /api/medicos/{medico}/estado
     */
    public function cambiarEstado(CambiarEstadoRequest $request, Medico $medico): JsonResponse
    {
        $activo = $request->boolean('activo');
        $medico = $this->medicos->cambiarEstado($medico, $activo, $request->user());

        return ApiResponse::exito(
            new MedicoResource($medico),
            $activo ? 'Médico activado correctamente.' : 'Médico desactivado correctamente.',
        );
    }
}
