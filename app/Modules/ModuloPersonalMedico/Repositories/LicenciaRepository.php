<?php

namespace App\Modules\ModuloPersonalMedico\Repositories;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPersonalMedico\Models\IndisponibilidadMedico;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de licencias y bloqueos de agenda (CU10), todas parametrizadas.
 */
class LicenciaRepository
{
    /** Periodos del filtro del listado (RN06). */
    public const ACTUALES = 'actuales';

    public const VIGENTES = 'vigentes';

    public const PROXIMAS = 'proximas';

    public const FINALIZADAS = 'finalizadas';

    public const TODAS = 'todas';

    public const PERIODOS = [self::ACTUALES, self::VIGENTES, self::PROXIMAS, self::FINALIZADAS, self::TODAS];

    /**
     * @param  array{medico: ?int, periodo: string}  $filtros
     */
    public function paginar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        $ahora = CarbonImmutable::now()->toIso8601String();

        return IndisponibilidadMedico::query()
            ->with(['medico:id_usuario,codigo', 'medico.usuario:id,nombre,apellido'])
            ->select('indisponibilidad_medico.*')
            // Citas pendientes que siguen dentro de la licencia (RN05), sin cargarlas.
            ->selectSub(fn (QueryBuilder $citas) => $this->citasDentro($citas->from('cita_medica'), 'indisponibilidad_medico.id_medico',
                'indisponibilidad_medico.fecha_inicio', 'indisponibilidad_medico.fecha_fin')->selectRaw('count(*)'), 'citas_afectadas')
            ->when($filtros['medico'], fn (Builder $query, int $idMedico) => $query->where('id_medico', $idMedico))
            ->when($filtros['periodo'], fn (Builder $query, string $periodo) => match ($periodo) {
                self::ACTUALES => $query->where('fecha_fin', '>', $ahora),
                self::VIGENTES => $query->where('fecha_inicio', '<=', $ahora)->where('fecha_fin', '>', $ahora),
                self::PROXIMAS => $query->where('fecha_inicio', '>', $ahora),
                self::FINALIZADAS => $query->where('fecha_fin', '<=', $ahora),
                default => $query,
            })
            // Las actuales, de la más cercana a la más lejana; el historial, de la más reciente hacia atrás.
            ->when($filtros['periodo'] === self::FINALIZADAS || $filtros['periodo'] === self::TODAS,
                fn (Builder $query) => $query->orderByDesc('fecha_inicio'),
                fn (Builder $query) => $query->orderBy('fecha_inicio'))
            ->orderBy('id')
            ->paginate($porPagina);
    }

    /**
     * Otra licencia del mismo médico que se superpone con el periodo (RN03).
     */
    public function superpuesta(int $idMedico, CarbonImmutable $inicio, CarbonImmutable $fin, ?int $excluirId = null): ?IndisponibilidadMedico
    {
        return IndisponibilidadMedico::query()
            ->where('id_medico', $idMedico)
            ->where('fecha_inicio', '<', $fin->toIso8601String())
            ->where('fecha_fin', '>', $inicio->toIso8601String())
            ->when($excluirId, fn (Builder $query) => $query->whereKeyNot($excluirId))
            ->orderBy('fecha_inicio')
            ->first();
    }

    /**
     * Citas pendientes del médico cuyo horario se cruza con el periodo (RN05).
     *
     * @return Collection<int, CitaMedica>
     */
    public function citasAfectadas(int $idMedico, CarbonImmutable $inicio, CarbonImmutable $fin): Collection
    {
        $consulta = CitaMedica::query()
            ->with('paciente.usuario:id,nombre,apellido,telefono')
            ->orderBy('fecha')
            ->orderBy('hra_inicio');

        $this->citasDentro($consulta->getQuery(), '?', '?', '?', [$idMedico, $inicio->toIso8601String(), $fin->toIso8601String()]);

        return $consulta->get();
    }

    /**
     * Condición "cita pendiente del médico que se cruza con [inicio, fin)". La fecha y
     * las horas de la cita son locales: AT TIME ZONE las interpreta en la hora de la
     * clínica para compararlas con los timestamptz de la licencia.
     *
     * @param  string  $medico  Columna o '?' para un valor de $valores (igual $inicio y $fin).
     */
    private function citasDentro(QueryBuilder $query, string $medico, string $inicio, string $fin, array $valores = []): QueryBuilder
    {
        $zona = config('app.timezone');

        return $query
            ->whereIn('cita_medica.estado', CitaMedica::ESTADOS_PENDIENTES)
            ->whereRaw("cita_medica.id_medico = {$medico}", array_slice($valores, 0, 1))
            ->whereRaw("((cita_medica.fecha + cita_medica.hra_inicio) at time zone ?) < {$fin}", [$zona, ...array_slice($valores, 2, 1)])
            ->whereRaw("((cita_medica.fecha + cita_medica.hra_fin) at time zone ?) > {$inicio}", [$zona, ...array_slice($valores, 1, 1)]);
    }

    /**
     * Bloquea la fila del médico para que dos registros simultáneos no creen
     * licencias superpuestas (RN03).
     */
    public function bloquearMedico(int $idMedico): void
    {
        DB::table('medico')->where('id_usuario', $idMedico)->lockForUpdate()->first();
    }
}
