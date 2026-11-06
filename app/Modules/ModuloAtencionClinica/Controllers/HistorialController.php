<?php

namespace App\Modules\ModuloAtencionClinica\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloAtencionClinica\Resources\HistorialConsultaResource;
use App\Modules\ModuloAtencionClinica\Services\HistorialService;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPacientes\Resources\AntecedenteResource;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * CU19 – Consultar Historial Clínico del Paciente.
 */
class HistorialController extends Controller
{
    private const PACIENTES_POR_PAGINA = 10;

    /** RN03: consultas por página del historial. */
    private const CONSULTAS_POR_PAGINA = 5;

    public function __construct(private readonly HistorialService $historial) {}

    /**
     * GET /api/historial/pacientes?buscar=&page=: pacientes que atiende el médico (RN01).
     */
    public function pacientes(Request $request): JsonResponse
    {
        $request->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        $pagina = $this->historial->pacientesDelMedico(
            $request->user(),
            $request->filled('buscar') ? trim($request->input('buscar')) : null,
            self::PACIENTES_POR_PAGINA,
        );

        return ApiResponse::exito([
            'items' => collect($pagina->items())->map(fn (Paciente $paciente) => [
                ...$this->identificacion($paciente),
                'ultima_atencion' => $this->fecha($paciente->ultima_atencion),
                'proxima_cita' => $paciente->proxima_cita ? Carbon::parse($paciente->proxima_cita)->toDateString() : null,
            ]),
            'meta' => $this->meta($pagina),
        ], 'Pacientes que atiende.');
    }

    /**
     * GET /api/pacientes/{paciente}/historial?page=: historial para el médico que lo atiende.
     */
    public function show(Request $request, Paciente $paciente): JsonResponse
    {
        $this->historial->autorizarMedico($request->user(), $paciente);

        return $this->responder($request, $paciente, 'medico');
    }

    /**
     * GET /api/mi-historial?page=: el propio paciente.
     */
    public function miHistorial(Request $request): JsonResponse
    {
        return $this->responder($request, $this->historial->pacienteDe($request->user()), 'paciente');
    }

    private function responder(Request $request, Paciente $paciente, string $como): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $datos = $this->historial->historial($paciente, self::CONSULTAS_POR_PAGINA);

        // RN04: se registra al abrir el historial, no al pasar de página.
        if ($request->integer('page', 1) === 1) {
            $this->historial->registrarAcceso($paciente, $request->user(), $como);
        }

        $consultas = $datos['consultas'];
        $paciente = $datos['paciente'];

        return ApiResponse::exito([
            'paciente' => [
                ...$this->identificacion($paciente),
                'sexo' => $paciente->usuario->sexo,
                'tipo_sangre' => $paciente->tipo_sangre,
            ],
            'resumen' => [
                ...$datos['resumen'],
                'primera_atencion' => $this->fecha($datos['resumen']['primera_atencion']),
                'ultima_atencion' => $this->fecha($datos['resumen']['ultima_atencion']),
            ],
            'antecedentes' => AntecedenteResource::collection($datos['antecedentes']),
            'consultas' => [
                'items' => HistorialConsultaResource::collection($consultas->items()),
                'meta' => $this->meta($consultas),
            ],
        ], 'Historial clínico.');
    }

    private function identificacion(Paciente $paciente): array
    {
        return [
            'id' => $paciente->id_usuario,
            'codigo' => $paciente->codigo,
            'ci' => $paciente->usuario->ci,
            'nombre_completo' => "{$paciente->usuario->nombre} {$paciente->usuario->apellido}",
            'fecha_nacimiento' => $paciente->fecha_nacimiento?->toDateString(),
            'edad' => $paciente->fecha_nacimiento ? $paciente->edad() : null,
            'activo' => $paciente->activo,
        ];
    }

    /** Fecha de la base (timestamptz) en ISO-8601, en la hora de la clínica. */
    private function fecha(?string $valor): ?string
    {
        return $valor ? Carbon::parse($valor)->setTimezone(config('app.timezone'))->toIso8601String() : null;
    }

    private function meta($pagina): array
    {
        return [
            'pagina_actual' => $pagina->currentPage(),
            'ultima_pagina' => $pagina->lastPage(),
            'por_pagina' => $pagina->perPage(),
            'total' => $pagina->total(),
        ];
    }
}
