<?php

namespace App\Modules\ModuloInteligenciaReportes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloInteligenciaReportes\Services\Voz\ConsultaVozService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU22 – Generar Reportes Generativos por Comando de Voz.
 * El dictado y la lectura en voz alta ocurren en el navegador (Web Speech API);
 * aquí llega el texto ya transcrito o escrito.
 */
class ConsultaVozController extends Controller
{
    public function __construct(private readonly ConsultaVozService $voz) {}

    /**
     * POST /api/reportes/voz  { consulta: string }
     */
    public function consultar(Request $request): JsonResponse
    {
        $request->validate(
            ['consulta' => ['required', 'string', 'min:3', 'max:300']],
            [
                'consulta.required' => 'Diga o escriba qué reporte necesita.',
                'consulta.min' => 'La consulta es demasiado corta.',
                'consulta.max' => 'La consulta no debe superar los 300 caracteres.',
            ],
        );

        $resultado = $this->voz->consultar(trim($request->input('consulta')), $request->user());

        return ApiResponse::exito($resultado, $resultado['entendido'] ? 'Reporte generado.' : 'No se entendió la consulta.');
    }
}
