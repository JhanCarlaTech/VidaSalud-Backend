<?php

namespace App\Modules\ModuloPersonalMedico\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directorio público (para usuarios autenticados) de médicos activos por
 * especialidad, con sus días de atención. Lo usa CU13 para agendar; no
 * expone datos del legajo como el salario.
 */
class DirectorioMedicoController extends Controller
{
    /**
     * GET /api/medicos/directorio?especialidad=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(
            ['especialidad' => ['required', 'integer']],
            ['especialidad.required' => 'Seleccione la especialidad.'],
        );

        $idEspecialidad = $request->integer('especialidad');

        $medicos = Medico::query()
            ->where('activo', true)
            ->whereHas('especialidades', fn (Builder $query) => $query
                ->where('especialidad.id', $idEspecialidad)
                ->where('especialidad.activo', true))
            ->with([
                'usuario',
                'especialidades' => fn ($query) => $query->where('especialidad.activo', true)->orderBy('especialidad.nombre'),
                'horarios' => fn ($query) => $query->where('activo', true),
            ])
            ->get()
            ->map(fn (Medico $medico) => [
                'id' => $medico->id_usuario,
                'codigo' => $medico->codigo,
                'nombre_completo' => "{$medico->usuario->nombre} {$medico->usuario->apellido}",
                'sexo' => $medico->usuario->sexo,
                'especialidades' => $medico->especialidades->pluck('nombre'),
                // Días de la semana (1 = lunes) en que tiene bloques de atención (CU09).
                'dias_atencion' => $medico->horarios->pluck('dia_semana')->unique()->sort()->values(),
            ])
            ->sortBy('nombre_completo')
            ->values();

        return ApiResponse::exito($medicos, 'Médicos de la especialidad.');
    }
}
