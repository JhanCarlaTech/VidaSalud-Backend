<?php

namespace App\Modules\ModuloPersonalMedico\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPersonalMedico\Models\IndisponibilidadMedico;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Modules\ModuloPersonalMedico\Repositories\LicenciaRepository;
use App\Modules\ModuloPersonalMedico\Requests\LicenciaRequest;
use App\Modules\ModuloPersonalMedico\Resources\LicenciaResource;
use App\Modules\ModuloPersonalMedico\Services\LicenciaService;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU10 – Gestionar Licencias y Bloqueos de Agenda.
 *
 * RN02: con el permiso 'medicos_gestionar' (Administrador) se gestionan las licencias
 * de cualquier médico; un Médico, solo las suyas (como CU09, RN05).
 */
class LicenciaController extends Controller
{
    private const POR_PAGINA = 10;

    public function __construct(
        private readonly LicenciaService $licencias,
        private readonly AuthService $auth,
    ) {}

    /**
     * GET /api/licencias?medico=&periodo=actuales|vigentes|proximas|finalizadas|todas&page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'medico' => ['nullable', 'integer'],
            'periodo' => ['nullable', Rule::in(LicenciaRepository::PERIODOS)],
        ], ['periodo.in' => 'El periodo no es válido.']);

        $usuario = $request->user();
        $filtros = [
            // El Médico siempre ve solo las suyas, aunque pida otro médico.
            'medico' => $this->esAdministrador($usuario) ? ($request->filled('medico') ? $request->integer('medico') : null) : $this->medicoPropio($usuario)->id_usuario,
            'periodo' => $request->input('periodo', LicenciaRepository::ACTUALES),
        ];

        $pagina = $this->licencias->listar($filtros, self::POR_PAGINA);

        return ApiResponse::exito([
            'items' => LicenciaResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ], 'Licencias y bloqueos de agenda.');
    }

    /**
     * GET /api/licencias/medicos: médicos para los selectores del Administrador
     * (los inactivos solo sirven para filtrar el historial: no se les registran licencias).
     */
    public function medicos(Request $request): JsonResponse
    {
        if (! $this->esAdministrador($request->user())) {
            throw new AccessDeniedHttpException;
        }

        $medicos = Medico::query()
            ->join('usuario', 'usuario.id', '=', 'medico.id_usuario')
            ->orderBy('usuario.apellido')
            ->orderBy('usuario.nombre')
            ->get(['medico.id_usuario', 'medico.codigo', 'medico.activo', 'usuario.nombre', 'usuario.apellido'])
            ->map(fn (Medico $medico) => [
                'id' => $medico->id_usuario,
                'codigo' => $medico->codigo,
                'nombre_completo' => "{$medico->nombre} {$medico->apellido}",
                'activo' => $medico->activo,
            ]);

        return ApiResponse::exito($medicos, 'Médicos.');
    }

    /**
     * GET /api/licencias/citas-afectadas?id_medico=&fecha_inicio=&fecha_fin=
     * Vista previa antes de guardar (RN05).
     */
    public function vistaPrevia(LicenciaRequest $request): JsonResponse
    {
        $medico = $this->medicoSolicitado($request);
        [$inicio, $fin] = $request->periodo();

        return ApiResponse::exito($this->citas($this->licencias->citasAfectadas($medico->id_usuario, $inicio, $fin)), 'Citas pendientes en el periodo.');
    }

    /**
     * GET /api/licencias/{licencia}/citas: citas pendientes que siguen dentro de la licencia.
     */
    public function citasDeLicencia(Request $request, IndisponibilidadMedico $licencia): JsonResponse
    {
        $this->autorizar($request->user(), $licencia);

        return ApiResponse::exito($this->citas($this->licencias->citasDeLicencia($licencia)), 'Citas pendientes dentro de la licencia.');
    }

    /**
     * POST /api/licencias
     */
    public function store(LicenciaRequest $request): JsonResponse
    {
        $medico = $this->medicoSolicitado($request);
        [$inicio, $fin] = $request->periodo();

        [$licencia, $citas] = $this->licencias->crear($medico, $inicio, $fin, $request->validated('motivo'), $request->user());

        return $this->respuesta($licencia, $citas, 'Licencia registrada.', 201);
    }

    /**
     * PUT /api/licencias/{licencia} (el médico de una licencia no cambia)
     */
    public function update(LicenciaRequest $request, IndisponibilidadMedico $licencia): JsonResponse
    {
        $this->autorizar($request->user(), $licencia);
        [$inicio, $fin] = $request->periodo();

        [$licencia, $citas] = $this->licencias->actualizar($licencia, $inicio, $fin, $request->validated('motivo'), $request->user());

        return $this->respuesta($licencia, $citas, 'Licencia actualizada.');
    }

    /**
     * DELETE /api/licencias/{licencia}
     */
    public function destroy(Request $request, IndisponibilidadMedico $licencia): JsonResponse
    {
        $this->autorizar($request->user(), $licencia);
        $this->licencias->eliminar($licencia, $request->user());

        return ApiResponse::exito(mensaje: 'Licencia eliminada. Los turnos vuelven a estar disponibles.');
    }

    /**
     * Licencia guardada y las citas que quedaron dentro, para reprogramarlas (RN05).
     */
    private function respuesta(IndisponibilidadMedico $licencia, Collection $citas, string $mensaje, int $estado = 200): JsonResponse
    {
        $licencia->load(['medico:id_usuario,codigo', 'medico.usuario:id,nombre,apellido']);
        $licencia->citas_afectadas = $citas->count();

        if ($citas->isNotEmpty()) {
            $mensaje .= $citas->count() === 1
                ? ' Hay 1 cita pendiente en ese periodo: reprográmela.'
                : " Hay {$citas->count()} citas pendientes en ese periodo: reprográmelas.";
        }

        return ApiResponse::exito([
            'licencia' => new LicenciaResource($licencia),
            'citas_afectadas' => $this->citas($citas),
        ], $mensaje, $estado);
    }

    /**
     * Datos de las citas afectadas: lo necesario para avisar al paciente y reprogramar.
     */
    private function citas(Collection $citas): array
    {
        return $citas->map(fn (CitaMedica $cita) => [
            'id' => $cita->id,
            'codigo_cita' => $cita->codigo_cita,
            'fecha' => $cita->fecha->toDateString(),
            'hra_inicio' => substr($cita->hra_inicio, 0, 5),
            'hra_fin' => substr($cita->hra_fin, 0, 5),
            'estado' => $cita->estado,
            'paciente' => [
                'nombre_completo' => "{$cita->paciente->usuario->nombre} {$cita->paciente->usuario->apellido}",
                'telefono' => $cita->paciente->usuario->telefono,
            ],
        ])->all();
    }

    /**
     * Médico de una licencia nueva o de la vista previa: el Administrador lo elige;
     * el Médico siempre es él mismo.
     *
     * @throws ValidationException|AccessDeniedHttpException
     */
    private function medicoSolicitado(LicenciaRequest $request): Medico
    {
        $usuario = $request->user();

        if (! $this->esAdministrador($usuario)) {
            $propio = $this->medicoPropio($usuario);

            if ($request->filled('id_medico') && $request->integer('id_medico') !== $propio->id_usuario) {
                throw new AccessDeniedHttpException;
            }

            return $propio;
        }

        if (! $request->filled('id_medico')) {
            throw ValidationException::withMessages(['id_medico' => 'Seleccione el médico.']);
        }

        return Medico::query()->find($request->integer('id_medico'))
            ?? throw ValidationException::withMessages(['id_medico' => 'El médico seleccionado no existe.']);
    }

    private function autorizar(Usuario $usuario, IndisponibilidadMedico $licencia): void
    {
        if ($this->esAdministrador($usuario) || $this->medicoPropio($usuario)->id_usuario === $licencia->id_medico) {
            return;
        }

        throw new AccessDeniedHttpException;
    }

    private function esAdministrador(Usuario $usuario): bool
    {
        return $this->auth->permisos($usuario)->contains('medicos_gestionar');
    }

    /**
     * @throws AccessDeniedHttpException si el usuario no es médico (por ejemplo, una recepcionista).
     */
    private function medicoPropio(Usuario $usuario): Medico
    {
        return Medico::query()->find($usuario->id) ?? throw new AccessDeniedHttpException;
    }
}
