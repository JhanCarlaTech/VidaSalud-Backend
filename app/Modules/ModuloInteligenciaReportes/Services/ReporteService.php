<?php

namespace App\Modules\ModuloInteligenciaReportes\Services;

use App\Modules\ModuloInteligenciaReportes\Requests\ReporteRequest;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU21 – Generar Reportes Operativos Tradicionales.
 *
 * Cada reporte parte de una consulta base con columnas planas (una fila por cita,
 * consulta o pago). Sobre ella se calculan el resumen, la tabla agrupada y el detalle.
 * Las columnas y expresiones salen de constantes de esta clase; los valores del
 * usuario viajan siempre como parámetros (RN06).
 */
class ReporteService
{
    /** Columna de la consulta base para cada agrupación (claves de CatalogoReportes::AGRUPACIONES). */
    private const COLUMNA_AGRUPACION = [
        'estado' => 'estado',
        'medico' => 'medico',
        'especialidad' => 'especialidad',
        'modalidad' => 'modalidad',
        'metodo' => 'metodo',
        'estado_pago' => 'estado_pago',
        'dia' => 'fecha',
        'mes' => 'substr(fecha, 1, 7)',
    ];

    /** Métricas de cada reporte: clave => [expresión SQL fija, etiqueta, formato]. */
    private const METRICAS = [
        CatalogoReportes::CITAS => [
            'total' => ['count(*)', 'Citas', 'numero'],
            'atendidas' => ["count(*) filter (where estado = 'Atendida')", 'Atendidas', 'numero'],
            'pendientes' => ["count(*) filter (where estado in ('Programada', 'Confirmada', 'En Sala de Espera'))", 'Pendientes', 'numero'],
            'canceladas' => ["count(*) filter (where estado = 'Cancelada')", 'Canceladas', 'numero'],
            'inasistencias' => ["count(*) filter (where estado = 'Inasistencia')", 'Inasistencias', 'numero'],
        ],
        CatalogoReportes::ATENCIONES => [
            'atenciones' => ['count(*)', 'Atenciones', 'numero'],
            'pacientes' => ['count(distinct paciente_id)', 'Pacientes distintos', 'numero'],
            'recetas' => ['coalesce(sum(recetas), 0)', 'Recetas emitidas', 'numero'],
        ],
        CatalogoReportes::INASISTENCIAS => [
            'evaluadas' => ['count(*)', 'Citas cerradas', 'numero'],
            'atendidas' => ["count(*) filter (where estado = 'Atendida')", 'Atendidas', 'numero'],
            'inasistencias' => ["count(*) filter (where estado = 'Inasistencia')", 'Inasistencias', 'numero'],
            'tasa' => ["round(100.0 * count(*) filter (where estado = 'Inasistencia') / nullif(count(*), 0), 1)", 'Tasa de inasistencia', 'porcentaje'],
        ],
        CatalogoReportes::INGRESOS => [
            'pagos' => ['count(*)', 'Pagos', 'numero'],
            'monto' => ['coalesce(sum(monto), 0)', 'Monto total', 'moneda'],
            'promedio' => ['round(coalesce(avg(monto), 0), 2)', 'Pago promedio', 'moneda'],
        ],
    ];

    /** Columnas del detalle de cada reporte: clave de la consulta base => [etiqueta, formato]. */
    private const DETALLE = [
        CatalogoReportes::CITAS => [
            'fecha' => ['Fecha', 'fecha'], 'hora' => ['Hora', 'texto'], 'codigo' => ['Cita', 'texto'],
            'paciente' => ['Paciente', 'texto'], 'medico' => ['Médico', 'texto'], 'especialidad' => ['Especialidad', 'texto'],
            'modalidad' => ['Modalidad', 'texto'], 'estado' => ['Estado', 'texto'],
        ],
        CatalogoReportes::ATENCIONES => [
            'fecha' => ['Fecha', 'fecha'], 'hora' => ['Hora', 'texto'], 'expediente' => ['Expediente', 'texto'],
            'paciente' => ['Paciente', 'texto'], 'medico' => ['Médico', 'texto'], 'especialidad' => ['Especialidad', 'texto'],
            'recetas' => ['Recetas', 'numero'],
        ],
        CatalogoReportes::INASISTENCIAS => [
            'fecha' => ['Fecha', 'fecha'], 'hora' => ['Hora', 'texto'], 'codigo' => ['Cita', 'texto'],
            'paciente' => ['Paciente', 'texto'], 'telefono' => ['Teléfono', 'texto'], 'medico' => ['Médico', 'texto'],
            'especialidad' => ['Especialidad', 'texto'], 'modalidad' => ['Modalidad', 'texto'],
        ],
        CatalogoReportes::INGRESOS => [
            'fecha' => ['Fecha', 'fecha'], 'transaccion' => ['Transacción', 'texto'], 'cita' => ['Cita', 'texto'],
            'paciente' => ['Paciente', 'texto'], 'medico' => ['Médico', 'texto'], 'metodo' => ['Método', 'texto'],
            'estado_pago' => ['Estado', 'texto'], 'monto' => ['Monto', 'moneda'],
        ],
    ];

    /** Especialidades del médico en una sola columna: la cita no guarda la especialidad (RN07). */
    private const ESPECIALIDADES_DEL_MEDICO = "(select string_agg(e.nombre, ', ' order by e.nombre) from medico_especialidad me
        join especialidad e on e.id = me.id_especialidad where me.id_medico = c.id_medico)";

    private const MEDICO = "(um.nombre || ' ' || um.apellido || ' (' || m.codigo || ')')";

    public function __construct(private readonly BitacoraService $bitacora) {}

    /**
     * Reporte completo: resumen, tabla agrupada y detalle (hasta 500 filas en pantalla).
     */
    public function generar(string $tipo, array $filtros): array
    {
        $definicion = CatalogoReportes::definicion($tipo);
        $base = $this->base($tipo, $filtros);
        // Se cuentan las filas del detalle (en inasistencias, solo las inasistencias), no las de la base.
        $total = $this->cantidadDetalle($tipo, $base);

        return [
            'tipo' => $tipo,
            'titulo' => $definicion['titulo'],
            'descripcion' => $definicion['descripcion'],
            'periodo' => ['desde' => $filtros['desde'], 'hasta' => $filtros['hasta']],
            'filtros_aplicados' => $this->filtrosAplicados($filtros),
            'resumen' => $this->resumen($tipo, $base),
            'agrupado' => $this->agrupado($tipo, $base, $filtros['agrupar']),
            'detalle' => [
                'columnas' => $this->columnas(self::DETALLE[$tipo]),
                'filas' => $this->detalle($tipo, $base)->limit(CatalogoReportes::FILAS_DETALLE_EN_PANTALLA)->get(),
                'total' => $total,
                'truncado' => $total > CatalogoReportes::FILAS_DETALLE_EN_PANTALLA,
            ],
            'generado_en' => now()->toIso8601String(),
        ];
    }

    /**
     * Solo los indicadores de un reporte (CU23 los reutiliza sin calcular el detalle).
     *
     * @return array<string, float|null> clave => valor
     */
    public function indicadores(string $tipo, array $filtros): array
    {
        return array_column($this->resumen($tipo, $this->base($tipo, $filtros)), 'valor', 'clave');
    }

    /**
     * Solo la tabla agrupada de un reporte (CU23).
     */
    public function agrupar(string $tipo, array $filtros, string $agrupacion): array
    {
        return $this->agrupado($tipo, $this->base($tipo, $filtros), $agrupacion)['filas'];
    }

    /**
     * CSV de la tabla agrupada o del detalle completo (separador ';', BOM y decimales con
     * coma, como lo abre Excel en español). Registra la exportación (RN08).
     *
     * @throws ValidationException
     */
    public function exportar(string $tipo, array $filtros, string $seccion, Usuario $actor): Closure
    {
        $base = $this->base($tipo, $filtros);

        if ($seccion === 'detalle') {
            $cantidad = $this->cantidadDetalle($tipo, $base);
            if ($cantidad > CatalogoReportes::FILAS_MAXIMAS_EXPORTACION) {
                throw ValidationException::withMessages(['exportacion' => 'El detalle tiene '.number_format($cantidad, 0, ',', '.')
                    .' filas y se pueden exportar hasta '.number_format(CatalogoReportes::FILAS_MAXIMAS_EXPORTACION, 0, ',', '.').'. Acote el periodo o los filtros.']);
            }
            $columnas = $this->columnas(self::DETALLE[$tipo]);
            $filas = $this->detalle($tipo, $base)->get();
        } else {
            $agrupado = $this->agrupado($tipo, $base, $filtros['agrupar']);
            $columnas = $agrupado['columnas'];
            $filas = collect($agrupado['filas']);
        }

        $this->bitacora->registrar('EXPORTACION', 'reporte', $actor->id, datosNuevos: [
            'reporte' => $tipo,
            'seccion' => $seccion,
            'filtros' => array_filter($filtros, fn ($valor) => $valor !== null),
            'filas' => $filas->count(),
        ]);

        return function () use ($columnas, $filas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\u{FEFF}");
            fputcsv($salida, array_column($columnas, 'etiqueta'), ';', '"', '');

            foreach ($filas as $fila) {
                $fila = (array) $fila;
                fputcsv($salida, array_map(fn (array $columna) => $this->valorCsv($fila[$columna['clave']] ?? null, $columna['formato']), $columnas), ';', '"', '');
            }

            fclose($salida);
        };
    }

    /**
     * Opciones de los filtros. RN04: con alcance personal, el médico solo se ve a sí mismo.
     */
    public function opciones(?int $soloMedico): array
    {
        return [
            'medicos' => DB::table('medico as m')->join('usuario as um', 'um.id', '=', 'm.id_usuario')
                ->when($soloMedico, fn (Builder $query, int $id) => $query->where('m.id_usuario', $id))
                ->orderBy('um.apellido')->orderBy('um.nombre')
                ->get(['m.id_usuario as valor', DB::raw(self::MEDICO.' as etiqueta')]),
            'especialidades' => DB::table('especialidad')->orderBy('nombre')->get(['id as valor', 'nombre as etiqueta']),
            'modalidades' => DB::table('modalidad_cita')->orderBy('id')->get(['id as valor', 'nombre as etiqueta']),
            'metodos' => DB::table('metodo_pago')->orderBy('id')->get(['id as valor', 'nombre as etiqueta']),
            'estados' => collect(CitaMedica::ESTADOS)->map(fn ($estado) => ['valor' => $estado, 'etiqueta' => $estado]),
            'estados_pago' => collect(ReporteRequest::ESTADOS_PAGO)->map(fn ($estado) => ['valor' => $estado, 'etiqueta' => $estado]),
        ];
    }

    // --- Consultas base ----------------------------------------------------------

    private function base(string $tipo, array $filtros): Builder
    {
        $consulta = match ($tipo) {
            CatalogoReportes::CITAS => $this->baseCitas($filtros, soloCerradas: false),
            CatalogoReportes::INASISTENCIAS => $this->baseCitas($filtros, soloCerradas: true),
            CatalogoReportes::ATENCIONES => $this->baseAtenciones($filtros),
            CatalogoReportes::INGRESOS => $this->baseIngresos($filtros),
        };

        // Filtros comunes por médico y especialidad.
        return $consulta
            ->when($filtros['medico'] ?? null, fn (Builder $query, int $id) => $query->where('c.id_medico', $id))
            ->when($filtros['especialidad'] ?? null, fn (Builder $query, int $id) => $query->whereExists(fn (Builder $existe) => $existe
                ->selectRaw('1')->from('medico_especialidad as fe')
                ->whereColumn('fe.id_medico', 'c.id_medico')
                ->where('fe.id_especialidad', $id)));
    }

    /** Cita con paciente, médico y modalidad; con sus uniones comunes. */
    private function citasConDatos(): Builder
    {
        return DB::table('cita_medica as c')
            ->join('paciente as p', 'p.id_usuario', '=', 'c.id_paciente')
            ->join('usuario as up', 'up.id', '=', 'p.id_usuario')
            ->join('medico as m', 'm.id_usuario', '=', 'c.id_medico')
            ->join('usuario as um', 'um.id', '=', 'm.id_usuario')
            ->join('modalidad_cita as mo', 'mo.id', '=', 'c.id_modalidad');
    }

    /**
     * @param  bool  $soloCerradas  Inasistencias: solo citas Atendidas o con Inasistencia, para comparar unas con otras.
     */
    private function baseCitas(array $filtros, bool $soloCerradas): Builder
    {
        return $this->citasConDatos()
            ->selectRaw("c.id, c.codigo_cita as codigo, c.fecha::text as fecha, to_char(c.hra_inicio, 'HH24:MI') as hora, c.estado::text as estado,
                (up.nombre || ' ' || up.apellido || ' (' || p.codigo || ')') as paciente, up.telefono, c.id_paciente as paciente_id,
                ".self::MEDICO.' as medico, '.self::ESPECIALIDADES_DEL_MEDICO.' as especialidad, mo.nombre as modalidad')
            ->whereBetween('c.fecha', [$filtros['desde'], $filtros['hasta']])
            ->when($filtros['estado'] ?? null, fn (Builder $query, string $estado) => $query->where('c.estado', $estado))
            ->when($filtros['modalidad'] ?? null, fn (Builder $query, int $id) => $query->where('c.id_modalidad', $id))
            ->when($soloCerradas, fn (Builder $query) => $query->whereIn('c.estado', [CitaMedica::ATENDIDA, CitaMedica::INASISTENCIA]));
    }

    private function baseAtenciones(array $filtros): Builder
    {
        $zona = config('app.timezone');

        return DB::table('consulta_medica as k')
            ->join('cita_medica as c', 'c.id', '=', 'k.id_cita_medica')
            ->join('paciente as p', 'p.id_usuario', '=', 'c.id_paciente')
            ->join('usuario as up', 'up.id', '=', 'p.id_usuario')
            ->join('medico as m', 'm.id_usuario', '=', 'c.id_medico')
            ->join('usuario as um', 'um.id', '=', 'm.id_usuario')
            ->selectRaw("k.id, (k.fecha_atencion at time zone ?)::date::text as fecha, to_char(k.fecha_atencion at time zone ?, 'HH24:MI') as hora,
                k.nro_expediente as expediente, c.id_paciente as paciente_id, (up.nombre || ' ' || up.apellido || ' (' || p.codigo || ')') as paciente,
                ".self::MEDICO.' as medico, '.self::ESPECIALIDADES_DEL_MEDICO.' as especialidad,
                (select count(*) from receta_medica r where r.id_consulta = k.id) as recetas', [$zona, $zona])
            ->whereRaw('(k.fecha_atencion at time zone ?)::date between ? and ?', [$zona, $filtros['desde'], $filtros['hasta']]);
    }

    private function baseIngresos(array $filtros): Builder
    {
        $zona = config('app.timezone');

        return DB::table('pago_cita as pg')
            ->join('cita_medica as c', 'c.id', '=', 'pg.id_cita')
            ->join('paciente as p', 'p.id_usuario', '=', 'c.id_paciente')
            ->join('usuario as up', 'up.id', '=', 'p.id_usuario')
            ->join('medico as m', 'm.id_usuario', '=', 'c.id_medico')
            ->join('usuario as um', 'um.id', '=', 'm.id_usuario')
            ->join('metodo_pago as mp', 'mp.id', '=', 'pg.id_metodo_pago')
            ->selectRaw("pg.id, (pg.fecha_pago at time zone ?)::date::text as fecha, pg.codigo_transaccion as transaccion, c.codigo_cita as cita,
                (up.nombre || ' ' || up.apellido || ' (' || p.codigo || ')') as paciente, ".self::MEDICO.' as medico,
                '.self::ESPECIALIDADES_DEL_MEDICO.' as especialidad, mp.nombre as metodo, pg.estado::text as estado_pago, pg.monto', [$zona])
            ->whereRaw('(pg.fecha_pago at time zone ?)::date between ? and ?', [$zona, $filtros['desde'], $filtros['hasta']])
            ->when($filtros['metodo'] ?? null, fn (Builder $query, int $id) => $query->where('pg.id_metodo_pago', $id))
            ->when($filtros['estado_pago'] ?? null, fn (Builder $query, string $estado) => $query->where('pg.estado', $estado));
    }

    // --- Secciones del reporte -----------------------------------------------------

    private function resumen(string $tipo, Builder $base): array
    {
        $metricas = self::METRICAS[$tipo];
        $fila = (array) DB::query()->fromSub($base, 'b')
            ->selectRaw(implode(', ', array_map(fn ($clave, $metrica) => "{$metrica[0]} as {$clave}", array_keys($metricas), $metricas)))
            ->first();

        return array_map(fn ($clave, $metrica) => [
            'clave' => $clave,
            'etiqueta' => $metrica[1],
            'valor' => $fila[$clave] === null ? null : (float) $fila[$clave],
            'formato' => $metrica[2],
        ], array_keys($metricas), $metricas);
    }

    private function agrupado(string $tipo, Builder $base, string $agrupar): array
    {
        $metricas = self::METRICAS[$tipo];
        $expresion = self::COLUMNA_AGRUPACION[$agrupar];
        $esTiempo = in_array($agrupar, ['dia', 'mes'], true);

        $filas = DB::query()->fromSub($base, 'b')
            ->selectRaw("coalesce(({$expresion})::text, 'Sin dato') as grupo, ".implode(', ', array_map(fn ($clave, $metrica) => "{$metrica[0]} as {$clave}", array_keys($metricas), $metricas)))
            ->groupByRaw($expresion)
            // Las fechas en orden cronológico; el resto, de mayor a menor volumen.
            ->when($esTiempo, fn (Builder $query) => $query->orderByRaw($expresion), fn (Builder $query) => $query->orderByRaw(array_values($metricas)[0][0].' desc')->orderByRaw($expresion))
            ->get()
            ->map(fn ($fila) => collect((array) $fila)->map(fn ($valor, $clave) => $clave === 'grupo' || $valor === null ? $valor : (float) $valor)->all());

        return [
            'por' => $agrupar,
            'etiqueta' => CatalogoReportes::AGRUPACIONES[$agrupar],
            'columnas' => [
                ['clave' => 'grupo', 'etiqueta' => CatalogoReportes::AGRUPACIONES[$agrupar], 'formato' => $agrupar === 'dia' ? 'fecha' : ($agrupar === 'mes' ? 'mes' : 'texto')],
                ...array_map(fn ($clave, $metrica) => ['clave' => $clave, 'etiqueta' => $metrica[1], 'formato' => $metrica[2]], array_keys($metricas), $metricas),
            ],
            'filas' => $filas->all(),
        ];
    }

    private function detalle(string $tipo, Builder $base): Builder
    {
        $orden = $tipo === CatalogoReportes::INGRESOS ? 'fecha desc, id desc' : 'fecha desc, hora desc, id desc';

        return $this->filasDetalle($tipo, $base)->select(array_keys(self::DETALLE[$tipo]))->orderByRaw($orden);
    }

    /** Filas que forman el detalle, sin columnas ni orden, para contarlas. */
    private function cantidadDetalle(string $tipo, Builder $base): int
    {
        return $this->filasDetalle($tipo, $base)->count();
    }

    private function filasDetalle(string $tipo, Builder $base): Builder
    {
        return DB::query()->fromSub($base, 'b')
            // RN07: el detalle de inasistencias muestra solo las citas a las que el paciente no asistió.
            ->when($tipo === CatalogoReportes::INASISTENCIAS, fn (Builder $query) => $query->where('estado', CitaMedica::INASISTENCIA));
    }

    // --- Apoyo -----------------------------------------------------------------

    private function columnas(array $definicion): array
    {
        return array_map(fn ($clave, $columna) => ['clave' => $clave, 'etiqueta' => $columna[0], 'formato' => $columna[1]], array_keys($definicion), $definicion);
    }

    /** Filtros con nombre legible, para el encabezado del reporte y el PDF. */
    private function filtrosAplicados(array $filtros): array
    {
        $nombre = fn (string $tabla, string $columna, ?int $id) => $id ? DB::table($tabla)->where($tabla === 'medico' ? 'id_usuario' : 'id', $id)->value($columna) : null;
        $medico = ($filtros['medico'] ?? null) ? DB::table('medico as m')->join('usuario as um', 'um.id', '=', 'm.id_usuario')->where('m.id_usuario', $filtros['medico'])->value(DB::raw(self::MEDICO)) : null;

        return array_values(array_filter([
            $medico ? ['etiqueta' => 'Médico', 'valor' => $medico] : null,
            ($valor = $nombre('especialidad', 'nombre', $filtros['especialidad'] ?? null)) ? ['etiqueta' => 'Especialidad', 'valor' => $valor] : null,
            ($valor = $nombre('modalidad_cita', 'nombre', $filtros['modalidad'] ?? null)) ? ['etiqueta' => 'Modalidad', 'valor' => $valor] : null,
            ($valor = $nombre('metodo_pago', 'nombre', $filtros['metodo'] ?? null)) ? ['etiqueta' => 'Método de pago', 'valor' => $valor] : null,
            ($filtros['estado'] ?? null) ? ['etiqueta' => 'Estado', 'valor' => $filtros['estado']] : null,
            array_key_exists('estado_pago', $filtros) ? ['etiqueta' => 'Estado del pago', 'valor' => $filtros['estado_pago'] ?? 'Todos'] : null,
        ]));
    }

    /** Valor para Excel en español: decimales con coma y fechas DD/MM/AAAA. */
    private function valorCsv(mixed $valor, string $formato): string
    {
        if ($valor === null) {
            return '';
        }

        return match ($formato) {
            'moneda' => number_format((float) $valor, 2, ',', ''),
            'porcentaje' => number_format((float) $valor, 1, ',', ''),
            'numero' => (string) (int) $valor,
            'fecha' => CarbonImmutable::parse($valor)->format('d/m/Y'),
            default => (string) $valor,
        };
    }
}
