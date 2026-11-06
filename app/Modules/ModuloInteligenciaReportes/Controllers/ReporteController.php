<?php

namespace App\Modules\ModuloInteligenciaReportes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloInteligenciaReportes\Requests\ReporteRequest;
use App\Modules\ModuloInteligenciaReportes\Services\AlcanceReportes;
use App\Modules\ModuloInteligenciaReportes\Services\CatalogoReportes;
use App\Modules\ModuloInteligenciaReportes\Services\ReporteService;
use App\Modules\ModuloInteligenciaReportes\Services\Voz\ConsultaVozService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CU21 – Generar Reportes Operativos Tradicionales.
 * El alcance por rol (RN04) lo resuelve AlcanceReportes, compartido con CU22.
 */
class ReporteController extends Controller
{
    public function __construct(
        private readonly ReporteService $reportes,
        private readonly AlcanceReportes $alcance,
    ) {}

    /**
     * GET /api/reportes/catalogo: reportes disponibles y opciones de sus filtros.
     */
    public function catalogo(Request $request, ConsultaVozService $voz): JsonResponse
    {
        $personal = $this->alcance->medicoPersonal($request->user());
        $permitidos = $this->alcance->reportesPermitidos($request->user());

        $reportes = collect(CatalogoReportes::REPORTES)
            ->only($permitidos)
            ->map(fn (array $definicion, string $tipo) => [
                'tipo' => $tipo,
                'titulo' => $definicion['titulo'],
                'descripcion' => $definicion['descripcion'],
                // El médico con alcance personal no elige médico: siempre es él.
                'filtros' => array_values(array_filter($definicion['filtros'], fn ($filtro) => ! ($personal && $filtro === 'medico'))),
                'agrupaciones' => array_map(fn ($clave) => ['valor' => $clave, 'etiqueta' => CatalogoReportes::AGRUPACIONES[$clave]], $definicion['agrupaciones']),
                'agrupacion_por_defecto' => $definicion['agrupacion_por_defecto'],
            ])
            ->values();

        return ApiResponse::exito([
            'reportes' => $reportes,
            'opciones' => $this->reportes->opciones($personal?->id_usuario),
            'alcance' => $personal ? 'personal' : 'clinica',
            'dias_maximos' => CatalogoReportes::DIAS_MAXIMOS,
            // CU22: qué intérprete atiende las consultas por voz.
            'interprete' => $voz->interpreteDisponible(),
        ], 'Catálogo de reportes.');
    }

    /**
     * GET /api/reportes/{tipo}?desde=&hasta=&<filtros>&agrupar=
     */
    public function generar(ReporteRequest $request, string $tipo): JsonResponse
    {
        $filtros = $this->alcance->aplicar($request->user(), $tipo, $request->filtros());

        return ApiResponse::exito([
            ...$this->reportes->generar($tipo, $filtros),
            'alcance' => $this->alcance->medicoPersonal($request->user()) ? 'personal' : 'clinica',
        ], 'Reporte generado.');
    }

    /**
     * GET /api/reportes/{tipo}/exportar?<mismos filtros>&seccion=agrupado|detalle
     */
    public function exportar(ReporteRequest $request, string $tipo): StreamedResponse
    {
        $seccion = $request->input('seccion', 'agrupado');
        $filtros = $this->alcance->aplicar($request->user(), $tipo, $request->filtros());
        $escribir = $this->reportes->exportar($tipo, $filtros, $seccion, $request->user());

        return response()->streamDownload($escribir, "reporte_{$tipo}_{$seccion}_".now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
