<?php

namespace App\Modules\ModuloInteligenciaReportes\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CU23 – Visualizar Dashboard Administrativo.
 *
 * Reutiliza las métricas de CU21 (ReporteService) para que el tablero y los reportes
 * den siempre los mismos números, y agrega la ocupación de agenda por médico.
 */
class DashboardService
{
    public function __construct(private readonly ReporteService $reportes) {}

    /**
     * Indicadores del periodo con su variación frente al periodo anterior de igual
     * duración (RN02), y las series de los gráficos (RN03).
     */
    public function tablero(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $dias = $desde->diffInDays($hasta) + 1;
        $anteriorHasta = $desde->subDay();
        $anteriorDesde = $anteriorHasta->subDays($dias - 1);

        $ocupacion = $this->ocupacionPorMedico($desde, $hasta);
        $actual = $this->indicadores($desde, $hasta, $ocupacion);
        $anterior = $this->indicadores($anteriorDesde, $anteriorHasta, $this->ocupacionPorMedico($anteriorDesde, $anteriorHasta));
        $periodo = $this->periodo($desde, $hasta);

        return [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'dias' => $dias],
            'anterior' => ['desde' => $anteriorDesde->toDateString(), 'hasta' => $anteriorHasta->toDateString()],
            'indicadores' => array_map(fn (array $indicador) => [
                ...$indicador,
                'valor' => $actual[$indicador['clave']],
                'anterior' => $anterior[$indicador['clave']],
                'variacion' => $this->variacion($actual[$indicador['clave']], $anterior[$indicador['clave']], $indicador['formato']),
            ], self::INDICADORES),
            'atenciones_por_dia' => $this->atencionesPorDia($desde, $hasta, $periodo),
            'ocupacion' => $ocupacion,
            'ingresos_por_metodo' => array_map(fn (array $fila) => ['grupo' => $fila['grupo'], 'valor' => $fila['monto'], 'pagos' => $fila['pagos']],
                $this->reportes->agrupar(CatalogoReportes::INGRESOS, [...$periodo, 'estado_pago' => 'Completado'], 'metodo')),
            'citas_por_estado' => array_map(fn (array $fila) => ['grupo' => $fila['grupo'], 'valor' => $fila['total']],
                $this->reportes->agrupar(CatalogoReportes::CITAS, $periodo, 'estado')),
            'generado_en' => now()->toIso8601String(),
        ];
    }

    /**
     * RN01: indicadores del tablero. 'mejor' indica si un aumento es bueno (sube) o malo
     * (baja), para que el frontend lea la variación con su sentido correcto.
     */
    private const INDICADORES = [
        ['clave' => 'atenciones', 'etiqueta' => 'Atenciones', 'formato' => 'numero', 'mejor' => 'sube'],
        ['clave' => 'pacientes', 'etiqueta' => 'Pacientes atendidos', 'formato' => 'numero', 'mejor' => 'sube'],
        ['clave' => 'tasa_inasistencia', 'etiqueta' => 'Tasa de inasistencia', 'formato' => 'porcentaje', 'mejor' => 'baja'],
        ['clave' => 'ocupacion', 'etiqueta' => 'Ocupación de agenda', 'formato' => 'porcentaje', 'mejor' => 'sube'],
        ['clave' => 'ingresos', 'etiqueta' => 'Ingresos', 'formato' => 'moneda', 'mejor' => 'sube'],
    ];

    /**
     * @param  array  $ocupacionPorMedico  Resultado de ocupacionPorMedico() para el mismo periodo.
     * @return array<string, float|null>
     */
    private function indicadores(CarbonImmutable $desde, CarbonImmutable $hasta, array $ocupacionPorMedico): array
    {
        $periodo = $this->periodo($desde, $hasta);
        $atenciones = $this->reportes->indicadores(CatalogoReportes::ATENCIONES, $periodo);
        $inasistencias = $this->reportes->indicadores(CatalogoReportes::INASISTENCIAS, $periodo);
        $ingresos = $this->reportes->indicadores(CatalogoReportes::INGRESOS, [...$periodo, 'estado_pago' => 'Completado']);
        $ocupacion = collect($ocupacionPorMedico);
        $capacidad = $ocupacion->sum('capacidad');

        return [
            'atenciones' => $atenciones['atenciones'],
            'pacientes' => $atenciones['pacientes'],
            'tasa_inasistencia' => $inasistencias['tasa'],
            'ocupacion' => $capacidad > 0 ? round(100 * $ocupacion->sum('ocupados') / $capacidad, 1) : null,
            'ingresos' => $ingresos['monto'],
        ];
    }

    /**
     * Serie diaria completa: los días sin atenciones aparecen con 0 para no unir puntos
     * lejanos en la línea.
     */
    private function atencionesPorDia(CarbonImmutable $desde, CarbonImmutable $hasta, array $periodo): array
    {
        $porDia = collect($this->reportes->agrupar(CatalogoReportes::ATENCIONES, $periodo, 'dia'))->pluck('atenciones', 'grupo');
        $serie = [];

        for ($dia = $desde; $dia <= $hasta; $dia = $dia->addDay()) {
            $serie[] = ['fecha' => $dia->toDateString(), 'valor' => (int) ($porDia[$dia->toDateString()] ?? 0)];
        }

        return $serie;
    }

    /**
     * RN04 – Ocupación de agenda por médico activo: citas no canceladas del periodo
     * frente a los turnos que ofrece su grilla semanal (CU09), sin contar los que caen
     * en una licencia (CU10). La capacidad se calcula turno a turno en una sola consulta.
     */
    private function ocupacionPorMedico(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $zona = config('app.timezone');

        $filas = DB::select(<<<'SQL'
            with turnos as (
                select h.id_medico, t.inicio, t.inicio + make_interval(mins => h.duracion_minutos) as fin
                from generate_series(?::date, ?::date, interval '1 day') as d(dia)
                join horario_atencion h on h.activo and h.dia_semana = extract(isodow from d.dia)
                cross join lateral generate_series(
                    d.dia::date + h.hora_inicio,
                    d.dia::date + h.hora_fin - make_interval(mins => h.duracion_minutos),
                    make_interval(mins => h.duracion_minutos)
                ) as t(inicio)
            ),
            disponibles as (
                select t.id_medico, count(*) as capacidad
                from turnos t
                where not exists (
                    select 1 from indisponibilidad_medico i
                    where i.id_medico = t.id_medico
                      and i.fecha_inicio < (t.fin at time zone ?)
                      and i.fecha_fin > (t.inicio at time zone ?)
                )
                group by t.id_medico
            ),
            ocupados as (
                select c.id_medico, count(*) as ocupados
                from cita_medica c
                where c.fecha between ? and ? and c.estado <> ?
                group by c.id_medico
            )
            select m.id_usuario as id, (u.nombre || ' ' || u.apellido) as medico, m.codigo,
                   coalesce(d.capacidad, 0) as capacidad, coalesce(o.ocupados, 0) as ocupados
            from medico m
            join usuario u on u.id = m.id_usuario
            left join disponibles d on d.id_medico = m.id_usuario
            left join ocupados o on o.id_medico = m.id_usuario
            where m.activo
            order by u.apellido, u.nombre
            SQL, [$desde->toDateString(), $hasta->toDateString(), $zona, $zona, $desde->toDateString(), $hasta->toDateString(), CitaMedica::CANCELADA]);

        return collect($filas)
            ->map(fn ($fila) => [
                'id' => (int) $fila->id,
                'grupo' => "{$fila->medico} ({$fila->codigo})",
                'capacidad' => (int) $fila->capacidad,
                'ocupados' => (int) $fila->ocupados,
                // Una cita fuera de grilla (agendada antes de un cambio de horario) no supera el 100 %.
                'valor' => $fila->capacidad > 0 ? min(100, round(100 * $fila->ocupados / $fila->capacidad, 1)) : null,
            ])
            ->sortByDesc(fn (array $fila) => $fila['valor'] ?? -1)
            ->values()
            ->all();
    }

    /**
     * Variación frente al periodo anterior: en puntos para los porcentajes y en % para el
     * resto. Null si no hay base de comparación (periodo anterior en cero o sin dato).
     */
    private function variacion(?float $actual, ?float $anterior, string $formato): ?array
    {
        if ($actual === null || $anterior === null) {
            return null;
        }

        if ($formato === 'porcentaje') {
            return ['valor' => round($actual - $anterior, 1), 'unidad' => 'puntos'];
        }

        return $anterior == 0.0 ? null : ['valor' => round(100 * ($actual - $anterior) / $anterior, 1), 'unidad' => 'porcentaje'];
    }

    /** Filtros de CU21 para un periodo, sin más restricciones. */
    private function periodo(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        return ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()];
    }
}
