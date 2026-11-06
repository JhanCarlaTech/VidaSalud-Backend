<?php

namespace App\Modules\ModuloSeguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloSeguridad\Requests\ImportLoteRequest;
use App\Modules\ModuloSeguridad\Services\ImportLoteService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CU04 – Cargar Usuarios por Lotes (CSV).
 */
class ImportUsuarioController extends Controller
{
    public function __construct(private readonly ImportLoteService $importacion) {}

    /**
     * GET /api/importaciones/plantilla?tipo=personal|pacientes
     */
    public function plantilla(Request $request): Response
    {
        $request->validate(
            ['tipo' => ['required', Rule::in(ImportLoteService::TIPOS)]],
            ['tipo.required' => 'Seleccione el tipo de carga.', 'tipo.in' => 'El tipo de carga debe ser personal o pacientes.'],
        );

        $tipo = $request->input('tipo');

        return response($this->importacion->plantilla($tipo), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"plantilla_{$tipo}.csv\"",
        ]);
    }

    /**
     * POST /api/importaciones/validar (flujo 3: no escribe nada)
     */
    public function validar(ImportLoteRequest $request): JsonResponse
    {
        $resultado = $this->importacion->analizar($request->file('archivo'), $request->validated('tipo'));
        $conErrores = $resultado['resumen']['con_errores'];

        return ApiResponse::exito($resultado, $conErrores === 0
            ? 'El archivo es válido y está listo para importar.'
            : "El archivo tiene {$conErrores} ".($conErrores === 1 ? 'fila' : 'filas').' con errores.');
    }

    /**
     * POST /api/importaciones (flujo 5: revalida e importa todo o nada)
     */
    public function importar(ImportLoteRequest $request): JsonResponse
    {
        $resultado = $this->importacion->importar($request->file('archivo'), $request->validated('tipo'), $request->user());
        $cuentas = $resultado['creados'] === 1 ? '1 cuenta' : "{$resultado['creados']} cuentas";

        return ApiResponse::exito($resultado, "Importación completada: se crearon {$cuentas}.", 201);
    }
}
