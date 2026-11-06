<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\MetodoPago;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de métodos de pago activos (CU24).
 */
class MetodoPagoController extends Controller
{
    /**
     * GET /api/metodos-pago
     */
    public function index(): JsonResponse
    {
        $metodos = MetodoPago::query()->where('activo', true)->orderBy('id')->get(['id', 'nombre', 'descripcion']);

        return ApiResponse::exito($metodos, 'Métodos de pago.');
    }
}
