<?php

namespace App\Modules\ModuloPersonalMedico\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPersonalMedico\Models\Especialidad;
use App\Modules\ModuloPersonalMedico\Requests\EspecialidadRequest;
use App\Modules\ModuloPersonalMedico\Resources\EspecialidadResource;
use App\Modules\ModuloPersonalMedico\Services\EspecialidadService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\Requests\CambiarEstadoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU07 – Gestionar Especialidades Médicas.
 */
class EspecialidadController extends Controller
{
    public function __construct(private readonly EspecialidadService $especialidades) {}

    /**
     * GET /api/especialidades?buscar=&activo=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $especialidades = $this->especialidades->listar(
            $request->filled('buscar') ? trim($request->input('buscar')) : null,
            $request->filled('activo') ? $request->boolean('activo') : null,
        );

        return ApiResponse::exito(EspecialidadResource::collection($especialidades), 'Listado de especialidades.');
    }

    /**
     * GET /api/publico/especialidades (sin sesión): catálogo activo para la
     * reserva en línea de la página principal, con el precio de la consulta.
     */
    public function publicas(): JsonResponse
    {
        $especialidades = $this->especialidades->listar(null, true)
            ->map(fn (Especialidad $especialidad) => [
                'id' => $especialidad->id,
                'nombre' => $especialidad->nombre,
                'descripcion' => $especialidad->descripcion,
                'precio_consulta' => (float) $especialidad->precio_consulta,
                'medicos_activos' => $especialidad->medicos_count,
            ]);

        return ApiResponse::exito($especialidades, 'Especialidades de la clínica.');
    }

    /**
     * POST /api/especialidades
     */
    public function store(EspecialidadRequest $request): JsonResponse
    {
        $especialidad = $this->especialidades->crear($request->validated(), $request->user());

        return ApiResponse::exito(new EspecialidadResource($especialidad), 'Especialidad registrada correctamente.', 201);
    }

    /**
     * PUT /api/especialidades/{especialidad}
     */
    public function update(EspecialidadRequest $request, Especialidad $especialidad): JsonResponse
    {
        $especialidad = $this->especialidades->actualizar($especialidad, $request->validated(), $request->user());

        return ApiResponse::exito(new EspecialidadResource($especialidad), 'Especialidad actualizada correctamente.');
    }

    /**
     * PATCH /api/especialidades/{especialidad}/estado
     */
    public function cambiarEstado(CambiarEstadoRequest $request, Especialidad $especialidad): JsonResponse
    {
        $activo = $request->boolean('activo');
        $especialidad = $this->especialidades->cambiarEstado($especialidad, $activo, $request->user());

        return ApiResponse::exito(
            new EspecialidadResource($especialidad),
            $activo ? 'Especialidad activada correctamente.' : 'Especialidad desactivada correctamente.',
        );
    }
}
