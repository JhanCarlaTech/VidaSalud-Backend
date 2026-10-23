<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Requests\CitaAgendarRequest;
use App\Modules\ModuloOperacionCitas\Requests\CitaCancelarRequest;
use App\Modules\ModuloOperacionCitas\Requests\CitaReprogramarRequest;
use App\Modules\ModuloOperacionCitas\Resources\CitaResource;
use App\Modules\ModuloOperacionCitas\Services\CitaService;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU13 – Gestionar Citas Médicas y Modalidad.
 * CU14 – Reprogramar y Cancelar Citas Médicas.
 */
class CitaController extends Controller
{
    private const POR_PAGINA = 10;

    public function __construct(
        private readonly CitaService $citas,
        private readonly AuthService $auth,
    ) {}

    /**
     * GET /api/citas?buscar=&estado=&fecha=&periodo=&page= (CU14, personal)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(CitaMedica::ESTADOS)],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'periodo' => ['nullable', Rule::in(['proximas', 'historial', 'todas'])],
        ]);

        $pagina = $this->citas->listar([
            'buscar' => $request->filled('buscar') ? trim($request->input('buscar')) : null,
            'estado' => $request->input('estado'),
            'fecha' => $request->input('fecha'),
            'periodo' => $request->input('periodo', 'proximas'),
        ], self::POR_PAGINA);

        return ApiResponse::exito([
            'items' => CitaResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ], 'Listado de citas.');
    }

    /**
     * POST /api/citas (CU13)
     *
     * RN08: con 'citas_programar' se agenda para el paciente indicado; el
     * Paciente agenda siempre para sí mismo (se ignora cualquier otro id).
     */
    public function store(CitaAgendarRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $datos = $request->validated();

        if ($this->esPersonal($usuario)) {
            if (empty($datos['id_paciente'])) {
                throw ValidationException::withMessages(['id_paciente' => 'Seleccione el paciente.']);
            }
        } elseif ($usuario->paciente?->activo) {
            $datos['id_paciente'] = $usuario->id;
        } else {
            throw new AccessDeniedHttpException;
        }

        $cita = $this->citas->agendar($datos, $usuario);

        return ApiResponse::exito(new CitaResource($cita), "Cita {$cita->codigo_cita} agendada correctamente.", 201);
    }

    /**
     * PATCH /api/citas/{cita}/reprogramar (CU14)
     */
    public function reprogramar(CitaReprogramarRequest $request, CitaMedica $cita): JsonResponse
    {
        $esPersonal = $this->autorizarModificacion($request->user(), $cita);
        $cita = $this->citas->reprogramar($cita, $request->validated(), $request->user(), $esPersonal);

        return ApiResponse::exito(new CitaResource($cita), "Cita {$cita->codigo_cita} reprogramada correctamente.");
    }

    /**
     * PATCH /api/citas/{cita}/cancelar (CU14)
     */
    public function cancelar(CitaCancelarRequest $request, CitaMedica $cita): JsonResponse
    {
        $esPersonal = $this->autorizarModificacion($request->user(), $cita);
        $cita = $this->citas->cancelar($cita, $request->validated('motivo_cancelacion'), $request->user(), $esPersonal);

        return ApiResponse::exito(new CitaResource($cita), "Cita {$cita->codigo_cita} cancelada.");
    }

    /**
     * GET /api/mis-citas?periodo=proximas|historial (CU13)
     */
    public function misCitas(Request $request): JsonResponse
    {
        $request->validate(['periodo' => ['nullable', Rule::in(['proximas', 'historial'])]]);

        $citas = $this->citas->citasDelPaciente(
            $this->pacienteDe($request->user()),
            $request->input('periodo', 'proximas'),
        );

        return ApiResponse::exito(CitaResource::collection($citas), 'Mis citas.');
    }

    /**
     * CU14: el personal modifica cualquier cita; el Paciente, solo las suyas (E6).
     *
     * @return bool  true si actúa como personal (sin la restricción de anticipación).
     */
    private function autorizarModificacion(Usuario $usuario, CitaMedica $cita): bool
    {
        if ($this->esPersonal($usuario)) {
            return true;
        }

        if ((int) $cita->id_paciente === (int) $usuario->id) {
            return false;
        }

        throw new AccessDeniedHttpException;
    }

    private function esPersonal(Usuario $usuario): bool
    {
        return $this->auth->permisos($usuario)->contains('citas_programar');
    }

    private function pacienteDe(Usuario $usuario): Paciente
    {
        return $usuario->paciente
            ?? throw new HttpResponseException(ApiResponse::error('No tiene una ficha de paciente.', 404));
    }
}
