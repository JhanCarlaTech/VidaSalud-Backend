<?php

namespace App\Modules\ModuloSeguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloSeguridad\Models\Bitacora;
use App\Modules\ModuloSeguridad\Requests\BitacoraFiltroRequest;
use App\Modules\ModuloSeguridad\Resources\BitacoraResource;
use App\Modules\ModuloSeguridad\Services\BitacoraConsultaService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CU06 – Consultar Bitácora de Auditoría (solo lectura).
 */
class BitacoraController extends Controller
{
    private const POR_PAGINA = 20;

    public function __construct(private readonly BitacoraConsultaService $bitacora) {}

    /**
     * GET /api/bitacora?buscar=&accion=&tabla=&ip=&desde=&hasta=&page=&por_pagina=
     */
    public function index(BitacoraFiltroRequest $request): JsonResponse
    {
        $pagina = $this->bitacora->listar($request->filtros(), $request->integer('por_pagina', self::POR_PAGINA));

        return ApiResponse::exito([
            'items' => BitacoraResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ], 'Eventos de la bitácora.');
    }

    /**
     * GET /api/bitacora/catalogo: acciones y módulos registrados, para los filtros.
     */
    public function catalogo(): JsonResponse
    {
        return ApiResponse::exito([
            ...$this->bitacora->catalogo(),
            'maximo_exportacion' => BitacoraConsultaService::MAXIMO_EXPORTACION,
        ], 'Catálogo de la bitácora.');
    }

    /**
     * GET /api/bitacora/{bitacora}: evento con sus datos y los cambios campo por campo.
     */
    public function show(Bitacora $bitacora): JsonResponse
    {
        $bitacora->load('usuario:id,ci,nombre,apellido,correo');

        return ApiResponse::exito([
            ...(new BitacoraResource($bitacora))->resolve(),
            ...$this->bitacora->detalle($bitacora),
        ], 'Detalle del evento.');
    }

    /**
     * GET /api/bitacora/exportar?<mismos filtros>: CSV con los eventos filtrados.
     */
    public function exportar(BitacoraFiltroRequest $request): StreamedResponse
    {
        $escribir = $this->bitacora->exportar($request->filtros(), $request->user());

        return response()->streamDownload($escribir, 'bitacora_'.now()->format('Y-m-d_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
