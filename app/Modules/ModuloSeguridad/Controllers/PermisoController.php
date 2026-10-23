<?php

namespace App\Modules\ModuloSeguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloSeguridad\Models\Permiso;
use App\Modules\ModuloSeguridad\Resources\PermisoResource;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de permisos (solo lectura): los define el equipo de desarrollo
 * porque cada uno corresponde a un control del backend (CU03).
 */
class PermisoController extends Controller
{
    /**
     * GET /api/permisos
     */
    public function index(): JsonResponse
    {
        $permisos = Permiso::query()->where('activo', true)->orderBy('accion')->get();

        return ApiResponse::exito(PermisoResource::collection($permisos), 'Catálogo de permisos.');
    }
}
