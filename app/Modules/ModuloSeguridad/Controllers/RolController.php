<?php

namespace App\Modules\ModuloSeguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloSeguridad\Models\Rol;
use App\Modules\ModuloSeguridad\Requests\RolRequest;
use App\Modules\ModuloSeguridad\Resources\RolResource;
use App\Modules\ModuloSeguridad\Services\RolService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\Requests\CambiarEstadoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU03 – Gestionar Roles y Asignar Permisos.
 */
class RolController extends Controller
{
    public function __construct(private readonly RolService $roles) {}

    /**
     * GET /api/roles?activos=1
     */
    public function index(Request $request): JsonResponse
    {
        $roles = $this->roles->listar($request->boolean('activos'));

        return ApiResponse::exito(RolResource::collection($roles), 'Listado de roles.');
    }

    /**
     * POST /api/roles
     */
    public function store(RolRequest $request): JsonResponse
    {
        $rol = $this->roles->crear($request->validated(), $request->user());

        return ApiResponse::exito(new RolResource($rol), 'Rol registrado correctamente.', 201);
    }

    /**
     * PUT /api/roles/{rol}
     */
    public function update(RolRequest $request, Rol $rol): JsonResponse
    {
        $rol = $this->roles->actualizar($rol, $request->validated(), $request->user());

        return ApiResponse::exito(new RolResource($rol), 'Rol actualizado correctamente.');
    }

    /**
     * PATCH /api/roles/{rol}/estado
     */
    public function cambiarEstado(CambiarEstadoRequest $request, Rol $rol): JsonResponse
    {
        $activo = $request->boolean('activo');
        $rol = $this->roles->cambiarEstado($rol, $activo, $request->user());

        return ApiResponse::exito(
            new RolResource($rol),
            $activo ? 'Rol activado correctamente.' : 'Rol desactivado correctamente.',
        );
    }
}
