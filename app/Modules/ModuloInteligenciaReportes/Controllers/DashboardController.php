<?php

namespace App\Modules\ModuloInteligenciaReportes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloInteligenciaReportes\Services\CatalogoReportes;
use App\Modules\ModuloInteligenciaReportes\Services\DashboardService;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU23 – Visualizar Dashboard Administrativo.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * GET /api/dashboard?desde=&hasta= (por defecto, los últimos 30 días)
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d', 'required_with:hasta'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'required_with:desde', 'after_or_equal:desde'],
        ], [
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'desde.required_with' => 'Indique la fecha inicial.',
            'hasta.required_with' => 'Indique la fecha final.',
            'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
        ]);

        $hasta = $request->filled('hasta') ? CarbonImmutable::parse($request->input('hasta')) : CarbonImmutable::today();
        $desde = $request->filled('desde') ? CarbonImmutable::parse($request->input('desde')) : $hasta->subDays(29);

        // RN05: mismo límite que los reportes de CU21.
        if ($desde->diffInDays($hasta) + 1 > CatalogoReportes::DIAS_MAXIMOS) {
            return ApiResponse::error('Los datos enviados no son válidos.', 422, [
                'hasta' => ['El periodo no puede superar los '.CatalogoReportes::DIAS_MAXIMOS.' días.'],
            ]);
        }

        return ApiResponse::exito($this->dashboard->tablero($desde->startOfDay(), $hasta->startOfDay()), 'Tablero administrativo.');
    }
}
