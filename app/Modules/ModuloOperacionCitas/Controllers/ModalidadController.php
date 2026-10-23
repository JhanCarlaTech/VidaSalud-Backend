<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\ModalidadCita;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de modalidades de cita (CU13, RN06).
 */
class ModalidadController extends Controller
{
    /**
     * GET /api/modalidades
     */
    public function index(): JsonResponse
    {
        return ApiResponse::exito(ModalidadCita::query()->orderBy('id')->get(['id', 'nombre']), 'Modalidades de cita.');
    }
}
