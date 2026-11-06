<?php

namespace App\Modules\ModuloAtencionClinica\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloAtencionClinica\Models\ConsultaMedica;
use App\Modules\ModuloAtencionClinica\Models\RecetaMedica;
use App\Modules\ModuloAtencionClinica\Requests\RecetaRequest;
use App\Modules\ModuloAtencionClinica\Resources\RecetaResource;
use App\Modules\ModuloAtencionClinica\Services\RecetaService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU18 – Emitir Receta Médica.
 */
class RecetaController extends Controller
{
    private const POR_PAGINA = 10;

    public function __construct(private readonly RecetaService $recetas) {}

    /**
     * GET /api/recetas/consultas?buscar=&page=: consultas del médico autenticado.
     */
    public function consultas(Request $request): JsonResponse
    {
        $request->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        $pagina = $this->recetas->consultasDelMedico(
            $request->user(),
            $request->filled('buscar') ? trim($request->input('buscar')) : null,
            self::POR_PAGINA,
        );
        $limite = now()->subDays(RecetaService::DIAS_PARA_EMITIR)->startOfDay();

        return ApiResponse::exito([
            'items' => collect($pagina->items())->map(fn (ConsultaMedica $consulta) => [
                'id' => $consulta->id,
                'nro_expediente' => $consulta->nro_expediente,
                'fecha_atencion' => $consulta->fecha_atencion->setTimezone(config('app.timezone'))->toIso8601String(),
                'motivo' => $consulta->motivo_de_consultas,
                'codigo_cita' => $consulta->cita->codigo_cita,
                'paciente' => [
                    'codigo' => $consulta->cita->paciente->codigo,
                    'nombre_completo' => "{$consulta->cita->paciente->usuario->nombre} {$consulta->cita->paciente->usuario->apellido}",
                    'ci' => $consulta->cita->paciente->usuario->ci,
                ],
                'recetas' => $consulta->recetas_count,
                // RN03: todavía se puede emitir una receta.
                'puede_emitir' => $consulta->fecha_atencion->gte($limite),
            ]),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
            'dias_para_emitir' => RecetaService::DIAS_PARA_EMITIR,
        ], 'Consultas atendidas.');
    }

    /**
     * GET /api/consultas/{consulta}/recetas: recetas ya emitidas en la consulta (solo su médico).
     */
    public function deConsulta(Request $request, ConsultaMedica $consulta): JsonResponse
    {
        $recetas = $this->recetas->deConsulta($consulta, $request->user());

        return ApiResponse::exito(RecetaResource::collection($recetas), 'Recetas de la consulta.');
    }

    /**
     * POST /api/consultas/{consulta}/recetas
     */
    public function store(RecetaRequest $request, ConsultaMedica $consulta): JsonResponse
    {
        $receta = $this->recetas->emitir($consulta, $request->validated(), $request->user());

        return ApiResponse::exito(new RecetaResource($receta), 'Receta emitida correctamente.', 201);
    }

    /**
     * GET /api/recetas/{receta}: la ven el médico que la emitió y el propio paciente (RN05).
     */
    public function show(Request $request, RecetaMedica $receta): JsonResponse
    {
        return ApiResponse::exito(new RecetaResource($this->recetas->detalle($receta, $request->user())), 'Receta médica.');
    }
}
