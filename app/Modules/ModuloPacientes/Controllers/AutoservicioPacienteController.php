<?php

namespace App\Modules\ModuloPacientes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPacientes\Requests\FichaPacienteRequest;
use App\Modules\ModuloPacientes\Requests\PacienteCreateRequest;
use App\Modules\ModuloPacientes\Resources\PacienteResource;
use App\Modules\ModuloPacientes\Services\PacienteService;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU11 – Operaciones del propio paciente: autorregistro y "Mi ficha médica".
 */
class AutoservicioPacienteController extends Controller
{
    public function __construct(
        private readonly PacienteService $pacientes,
        private readonly AuthService $auth,
    ) {}

    /**
     * POST /api/auth/registro (público, RN07): crea la cuenta y abre la sesión.
     */
    public function registrarse(PacienteCreateRequest $request): JsonResponse
    {
        $paciente = $this->pacientes->crear($request->validated(), null);
        $this->auth->abrirSesion($paciente->usuario, $request);

        return ApiResponse::exito(
            $this->auth->contexto($paciente->usuario),
            "Cuenta creada correctamente. Su código de paciente es {$paciente->codigo}.",
            201,
        );
    }

    /**
     * GET /api/mi-ficha
     */
    public function miFicha(Request $request): JsonResponse
    {
        $paciente = $this->pacientes->detalle($this->pacienteDe($request->user()));

        return ApiResponse::exito(new PacienteResource($paciente), 'Ficha médica.');
    }

    /**
     * PUT /api/mi-ficha (RN08)
     */
    public function actualizarFicha(FichaPacienteRequest $request): JsonResponse
    {
        $paciente = $this->pacientes->actualizarFicha(
            $this->pacienteDe($request->user()),
            $request->validated(),
            $request->user(),
        );

        return ApiResponse::exito(new PacienteResource($paciente), 'Ficha médica actualizada correctamente.');
    }

    /**
     * E5: solo quien tiene registro de paciente tiene ficha médica.
     */
    private function pacienteDe(Usuario $usuario): Paciente
    {
        return $usuario->paciente
            ?? throw new HttpResponseException(ApiResponse::error('No tiene una ficha de paciente.', 404));
    }
}
