<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Requests\AsistenciaRequest;
use App\Modules\ModuloOperacionCitas\Resources\CitaResource;
use App\Modules\ModuloOperacionCitas\Services\AsistenciaService;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU15 – Registrar Control de Asistencia.
 */
class AsistenciaController extends Controller
{
    public function __construct(private readonly AsistenciaService $asistencia) {}

    /**
     * GET /api/asistencia?fecha=AAAA-MM-DD&buscar=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ], ['fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.']);

        $fecha = $request->filled('fecha')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('fecha'))->startOfDay()
            : CarbonImmutable::today();

        $citas = $this->asistencia->citasDelDia($fecha, $request->filled('buscar') ? trim($request->input('buscar')) : null);

        return ApiResponse::exito([
            'fecha' => $fecha->toDateString(),
            'resumen' => $citas->countBy('estado'),
            'citas' => $citas->map(fn (CitaMedica $cita) => $this->conAcciones($cita, $request))->values(),
        ], 'Citas del día.');
    }

    /**
     * PATCH /api/citas/{cita}/asistencia
     */
    public function cambiarEstado(AsistenciaRequest $request, CitaMedica $cita): JsonResponse
    {
        $cita = $this->asistencia->cambiarEstado($cita, $request->validated('estado'), $request->user());

        return ApiResponse::exito($this->conAcciones($cita, $request), "Cita {$cita->codigo_cita}: {$cita->estado}.");
    }

    /**
     * Datos de la cita más las transiciones que la pantalla puede ofrecer ahora.
     */
    private function conAcciones(CitaMedica $cita, Request $request): array
    {
        return [
            ...(new CitaResource($cita))->resolve($request),
            'acciones_asistencia' => $this->asistencia->accionesPermitidas($cita),
        ];
    }
}
