<?php

namespace App\Modules\ModuloPersonalMedico\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPersonalMedico\Models\HorarioAtencion;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Modules\ModuloPersonalMedico\Requests\HorarioRequest;
use App\Modules\ModuloPersonalMedico\Resources\HorarioResource;
use App\Modules\ModuloPersonalMedico\Services\DisponibilidadService;
use App\Modules\ModuloPersonalMedico\Services\HorarioService;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU09 – Gestionar Horarios y Disponibilidad Médica.
 */
class HorarioController extends Controller
{
    /** RN06: alcance máximo de la consulta de disponibilidad. */
    private const DIAS_MAXIMOS_DISPONIBILIDAD = 90;

    public function __construct(
        private readonly HorarioService $horarios,
        private readonly DisponibilidadService $disponibilidad,
        private readonly AuthService $auth,
    ) {}

    /**
     * GET /api/medicos/{medico}/horarios
     */
    public function index(Request $request, Medico $medico): JsonResponse
    {
        $this->autorizar($request, $medico);
        $medico->load(['usuario', 'especialidades']);

        return ApiResponse::exito([
            'medico' => [
                'id' => $medico->id_usuario,
                'codigo' => $medico->codigo,
                'nombre_completo' => "{$medico->usuario->nombre} {$medico->usuario->apellido}",
                'activo' => $medico->activo,
                'especialidades' => $medico->especialidades->pluck('nombre'),
            ],
            'horarios' => HorarioResource::collection($this->horarios->listar($medico)),
        ], 'Grilla semanal de atención.');
    }

    /**
     * POST /api/medicos/{medico}/horarios
     */
    public function store(HorarioRequest $request, Medico $medico): JsonResponse
    {
        $this->autorizar($request, $medico);
        $horario = $this->horarios->crear($medico, $request->validated(), $request->user());

        return ApiResponse::exito(new HorarioResource($horario), 'Bloque de horario agregado.', 201);
    }

    /**
     * PUT /api/medicos/{medico}/horarios/{horario}
     */
    public function update(HorarioRequest $request, Medico $medico, HorarioAtencion $horario): JsonResponse
    {
        $this->autorizar($request, $medico);
        $horario = $this->horarios->actualizar($medico, $horario, $request->validated(), $request->user());

        return ApiResponse::exito(new HorarioResource($horario), 'Bloque de horario actualizado.');
    }

    /**
     * DELETE /api/medicos/{medico}/horarios/{horario}
     */
    public function destroy(Request $request, Medico $medico, HorarioAtencion $horario): JsonResponse
    {
        $this->autorizar($request, $medico);
        $this->horarios->eliminar($medico, $horario, $request->user());

        return ApiResponse::exito(mensaje: 'Bloque de horario eliminado.');
    }

    /**
     * GET /api/medicos/{medico}/disponibilidad?fecha=AAAA-MM-DD
     */
    public function disponibilidad(Request $request, Medico $medico): JsonResponse
    {
        $limite = today()->addDays(self::DIAS_MAXIMOS_DISPONIBILIDAD)->toDateString();

        $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', "before_or_equal:{$limite}"],
        ], [
            'fecha.required' => 'Indique la fecha a consultar.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha.after_or_equal' => 'No se puede consultar la disponibilidad de fechas pasadas.',
            'fecha.before_or_equal' => 'Solo se puede consultar hasta '.self::DIAS_MAXIMOS_DISPONIBILIDAD.' días adelante.',
        ]);

        $fecha = CarbonImmutable::createFromFormat('Y-m-d', $request->input('fecha'))->startOfDay();
        $turnos = $this->disponibilidad->turnos($medico, $fecha);

        return ApiResponse::exito([
            'fecha' => $fecha->toDateString(),
            'dia_semana' => $fecha->dayOfWeekIso,
            'medico_activo' => $medico->activo,
            'turnos' => $turnos,
            'resumen' => array_count_values(array_column($turnos, 'estado')),
        ], 'Disponibilidad del médico.');
    }

    /**
     * RN05: el Administrador gestiona cualquier grilla; el Médico, solo la suya.
     */
    private function autorizar(Request $request, Medico $medico): void
    {
        $usuario = $request->user();

        if ($usuario->id === $medico->id_usuario || $this->auth->permisos($usuario)->contains('medicos_gestionar')) {
            return;
        }

        throw new AccessDeniedHttpException;
    }
}
