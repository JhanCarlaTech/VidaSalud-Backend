<?php

namespace App\Modules\ModuloPacientes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPacientes\Models\Enfermedad;
use App\Modules\ModuloPacientes\Requests\EnfermedadRequest;
use App\Modules\ModuloPacientes\Resources\EnfermedadResource;
use App\Modules\ModuloPacientes\Services\EnfermedadService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\Requests\CambiarEstadoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU12 – Gestionar Catálogo de Enfermedades.
 */
class EnfermedadController extends Controller
{
    public function __construct(private readonly EnfermedadService $enfermedades) {}

    /**
     * GET /api/enfermedades?buscar=&activo=&nivel_riesgo=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'activo' => ['nullable', 'boolean'],
            'nivel_riesgo' => ['nullable', Rule::in(Enfermedad::NIVELES_RIESGO)],
        ], ['nivel_riesgo.in' => 'El nivel de riesgo no es válido.']);

        $enfermedades = $this->enfermedades->listar([
            'buscar' => $request->filled('buscar') ? trim($request->input('buscar')) : null,
            'activo' => $request->filled('activo') ? $request->boolean('activo') : null,
            'nivel_riesgo' => $request->input('nivel_riesgo'),
        ]);

        return ApiResponse::exito(EnfermedadResource::collection($enfermedades), 'Catálogo de enfermedades.');
    }

    /**
     * POST /api/enfermedades
     */
    public function store(EnfermedadRequest $request): JsonResponse
    {
        $enfermedad = $this->enfermedades->crear($request->validated(), $request->user());

        return ApiResponse::exito(new EnfermedadResource($enfermedad), 'Enfermedad registrada correctamente.', 201);
    }

    /**
     * PUT /api/enfermedades/{enfermedad}
     */
    public function update(EnfermedadRequest $request, Enfermedad $enfermedad): JsonResponse
    {
        $enfermedad = $this->enfermedades->actualizar($enfermedad, $request->validated(), $request->user());

        return ApiResponse::exito(new EnfermedadResource($enfermedad), 'Enfermedad actualizada correctamente.');
    }

    /**
     * PATCH /api/enfermedades/{enfermedad}/estado
     */
    public function cambiarEstado(CambiarEstadoRequest $request, Enfermedad $enfermedad): JsonResponse
    {
        $activo = $request->boolean('activo');
        $enfermedad = $this->enfermedades->cambiarEstado($enfermedad, $activo, $request->user());

        return ApiResponse::exito(
            new EnfermedadResource($enfermedad),
            $activo ? 'Enfermedad activada correctamente.' : 'Enfermedad desactivada correctamente.',
        );
    }
}
