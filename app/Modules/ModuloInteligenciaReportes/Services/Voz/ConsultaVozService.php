<?php

namespace App\Modules\ModuloInteligenciaReportes\Services\Voz;

use App\Modules\ModuloInteligenciaReportes\Requests\ReporteRequest;
use App\Modules\ModuloInteligenciaReportes\Services\AlcanceReportes;
use App\Modules\ModuloInteligenciaReportes\Services\CatalogoReportes;
use App\Modules\ModuloInteligenciaReportes\Services\ReporteService;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CU22 – Generar Reportes Generativos por Comando de Voz.
 *
 * Flujo: consulta → intérprete (Claude si hay clave, si no o si falla, reglas) → intención →
 * validación contra la lista blanca de CU21 y el alcance del usuario → ReporteService::generar()
 * → resumen en una frase para leerlo en voz alta.
 */
class ConsultaVozService
{
    /** Ejemplos que se muestran al usuario y cuando no se entiende la consulta. */
    public const EJEMPLOS = [
        'Citas canceladas del mes pasado por médico',
        'Ingresos de octubre por método de pago',
        'Inasistencias de los últimos 90 días por especialidad',
        'Atenciones de esta semana por día',
    ];

    public function __construct(
        private readonly ReporteService $reportes,
        private readonly AlcanceReportes $alcance,
        private readonly InterpreteReglas $reglas,
        private readonly BitacoraService $bitacora,
    ) {}

    /** 'ia' si hay clave de Anthropic configurada; si no, 'reglas'. */
    public function interpreteDisponible(): string
    {
        return config('services.anthropic.key') ? 'ia' : 'reglas';
    }

    public function consultar(string $consulta, Usuario $usuario): array
    {
        $contexto = $this->contexto($usuario);
        [$intencion, $interprete, $notaInterprete] = $this->interpretar($consulta, $contexto);

        if (! $intencion['entendido']) {
            $this->auditar($usuario, $consulta, $interprete, null);

            return [
                'entendido' => false,
                'consulta' => $consulta,
                'interprete' => $interprete,
                'mensaje' => 'No entendí qué reporte necesita. Diga qué datos quiere ver y de qué periodo.',
                // Las notas de valores por defecto no aplican si no se generó ningún reporte.
                'notas' => array_values(array_filter([$notaInterprete])),
                'ejemplos' => self::EJEMPLOS,
            ];
        }

        [$tipo, $filtros, $notas] = $this->validar($intencion, $contexto);
        $filtros = $this->alcance->aplicar($usuario, $tipo, $filtros);
        if ($this->alcance->medicoPersonal($usuario)) {
            $notas[] = 'Como médico, el reporte incluye solo sus propias citas y atenciones.';
        }
        $reporte = [...$this->reportes->generar($tipo, $filtros), 'alcance' => $this->alcance->medicoPersonal($usuario) ? 'personal' : 'clinica'];

        // Mismos parámetros que la pantalla de CU21, para abrir o exportar el reporte desde allí.
        $parametros = ['tipo' => $tipo, ...array_filter($filtros, fn ($v) => $v !== null)];
        if (array_key_exists('estado_pago', $filtros) && $filtros['estado_pago'] === null) {
            $parametros['estado_pago'] = ReporteRequest::TODOS;
        }

        $this->auditar($usuario, $consulta, $interprete, $parametros);

        return [
            'entendido' => true,
            'consulta' => $consulta,
            'interprete' => $interprete,
            'notas' => array_values(array_filter([$notaInterprete, ...$intencion['notas'], ...$notas])),
            'parametros' => $parametros,
            'reporte' => $reporte,
            'resumen_hablado' => $this->resumenHablado($reporte),
        ];
    }

    /**
     * Opciones válidas para el intérprete, ya recortadas al alcance del usuario.
     */
    private function contexto(Usuario $usuario): array
    {
        $personal = $this->alcance->medicoPersonal($usuario);
        $reportes = [];

        foreach ($this->alcance->reportesPermitidos($usuario) as $tipo) {
            $definicion = CatalogoReportes::definicion($tipo);
            $reportes[$tipo] = [
                'titulo' => $definicion['titulo'],
                'filtros' => array_values(array_filter($definicion['filtros'], fn ($f) => ! ($personal && $f === 'medico'))),
                'agrupaciones' => $definicion['agrupaciones'],
            ];
        }

        return [
            'hoy' => CarbonImmutable::today(),
            'reportes' => $reportes,
            'medicos' => DB::table('medico as m')->join('usuario as u', 'u.id', '=', 'm.id_usuario')
                ->when($personal, fn ($q) => $q->where('m.id_usuario', $personal->id_usuario))
                ->orderBy('u.apellido')->get(['m.id_usuario as id', 'u.nombre', 'u.apellido', 'm.codigo'])
                ->map(fn ($fila) => (array) $fila)->all(),
            'especialidades' => $this->lista('especialidad'),
            'modalidades' => $this->lista('modalidad_cita'),
            'metodos' => $this->lista('metodo_pago'),
            'estados' => CitaMedica::ESTADOS,
            'estados_pago' => [...ReporteRequest::ESTADOS_PAGO, ReporteRequest::TODOS],
        ];
    }

    private function lista(string $tabla): array
    {
        return DB::table($tabla)->orderBy('id')->get(['id', 'nombre'])->map(fn ($fila) => (array) $fila)->all();
    }

    /**
     * RN02: Claude si hay clave; ante cualquier fallo, el intérprete por reglas.
     *
     * @return array{0: array, 1: string, 2: ?string} [intención, intérprete usado, nota]
     */
    private function interpretar(string $consulta, array $contexto): array
    {
        $clave = config('services.anthropic.key');

        if ($clave) {
            try {
                $claude = new InterpreteClaude($clave, config('services.anthropic.modelo'), config('services.anthropic.timeout'));

                return [$claude->interpretar($consulta, $contexto), $claude->nombre(), null];
            } catch (Throwable $error) {
                // Sin la consulta del usuario en el log (puede tener datos personales).
                Log::warning('CU22: no se pudo interpretar la consulta con IA; se usan reglas.', ['error' => $error->getMessage()]);

                return [$this->reglas->interpretar($consulta, $contexto), $this->reglas->nombre(), 'No se pudo usar la IA en este momento: la consulta se interpretó con reglas.'];
            }
        }

        return [$this->reglas->interpretar($consulta, $contexto), $this->reglas->nombre(), null];
    }

    /**
     * RN03: nada de la intención se usa sin validar contra la lista blanca y el contexto.
     *
     * @return array{0: string, 1: array, 2: string[]} [tipo, filtros de CU21, notas de lo corregido]
     */
    private function validar(array $intencion, array $contexto): array
    {
        $notas = [];
        $tipo = isset($contexto['reportes'][$intencion['tipo'] ?? '']) ? $intencion['tipo'] : array_key_first($contexto['reportes']);
        $definicion = CatalogoReportes::definicion($tipo);

        // Periodo: fechas válidas, en orden y de hasta 366 días.
        $fecha = fn (?string $valor) => $valor && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))
            ? CarbonImmutable::parse($valor) : null;
        $desde = $fecha($intencion['desde'] ?? null);
        $hasta = $fecha($intencion['hasta'] ?? null);

        if (! $desde || ! $hasta) {
            [$desde, $hasta] = [$contexto['hoy']->subDays(29), $contexto['hoy']];
            $notas[] = 'El periodo no era válido: se usan los últimos 30 días.';
        }
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        if ($desde->diffInDays($hasta) + 1 > CatalogoReportes::DIAS_MAXIMOS) {
            $desde = $hasta->subDays(CatalogoReportes::DIAS_MAXIMOS - 1);
            $notas[] = 'El periodo se acotó a los últimos '.CatalogoReportes::DIAS_MAXIMOS.' días.';
        }

        $ids = fn (string $clave) => array_column($contexto[$clave], 'id');
        $valores = [
            'medico' => in_array($intencion['medico'] ?? null, $ids('medicos'), true) ? $intencion['medico'] : null,
            'especialidad' => in_array($intencion['especialidad'] ?? null, $ids('especialidades'), true) ? $intencion['especialidad'] : null,
            'modalidad' => in_array($intencion['modalidad'] ?? null, $ids('modalidades'), true) ? $intencion['modalidad'] : null,
            'metodo' => in_array($intencion['metodo'] ?? null, $ids('metodos'), true) ? $intencion['metodo'] : null,
            'estado' => in_array($intencion['estado'] ?? null, CitaMedica::ESTADOS, true) ? $intencion['estado'] : null,
            // RN05 de CU21: los ingresos cuentan por defecto solo los pagos completados.
            'estado_pago' => match ($intencion['estado_pago'] ?? null) {
                ReporteRequest::TODOS => null,
                null => 'Completado',
                default => in_array($intencion['estado_pago'], ReporteRequest::ESTADOS_PAGO, true) ? $intencion['estado_pago'] : 'Completado',
            },
        ];

        $filtros = [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'agrupar' => in_array($intencion['agrupar'] ?? null, $definicion['agrupaciones'], true) ? $intencion['agrupar'] : $definicion['agrupacion_por_defecto'],
        ];

        foreach ($definicion['filtros'] as $filtro) {
            $filtros[$filtro] = $valores[$filtro];
        }

        if (($intencion['agrupar'] ?? null) && $filtros['agrupar'] !== $intencion['agrupar']) {
            $notas[] = 'Ese reporte no se puede agrupar así: se agrupa por '.mb_strtolower(CatalogoReportes::AGRUPACIONES[$filtros['agrupar']]).'.';
        }

        return [$tipo, $filtros, $notas];
    }

    /**
     * RN04: una o dos frases para leer en voz alta: periodo, indicadores y el grupo principal.
     */
    private function resumenHablado(array $reporte): string
    {
        $desde = CarbonImmutable::parse($reporte['periodo']['desde'])->locale('es');
        $hasta = CarbonImmutable::parse($reporte['periodo']['hasta'])->locale('es');
        // "del 1 de octubre al 31 de octubre de 2026": el año se dice una sola vez si coincide.
        $periodo = $desde->isSameDay($hasta)
            ? 'del '.$hasta->translatedFormat('j \d\e F \d\e Y')
            : 'del '.$desde->translatedFormat($desde->year === $hasta->year ? 'j \d\e F' : 'j \d\e F \d\e Y').' al '.$hasta->translatedFormat('j \d\e F \d\e Y');

        $valor = fn ($v, string $formato) => match ($formato) {
            'moneda' => number_format((float) $v, 2, ',', '.').' bolivianos',
            // 50,0 → "50 por ciento"; 33,3 → "33,3 por ciento".
            'porcentaje' => rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',').' por ciento',
            default => number_format((float) $v, 0, ',', '.'),
        };

        // Sin registros, se dice directamente en lugar de enumerar ceros.
        if ((float) ($reporte['resumen'][0]['valor'] ?? 0) === 0.0) {
            return "{$reporte['titulo']} {$periodo}: no hay registros con esos filtros.";
        }

        $indicadores = array_map(
            fn (array $i) => mb_strtolower($i['etiqueta']).': '.($i['valor'] === null ? 'sin datos' : $valor($i['valor'], $i['formato'])),
            $reporte['resumen'],
        );

        $frase = "{$reporte['titulo']} {$periodo}. ".ucfirst(implode('; ', $indicadores)).'.';

        // El grupo con más volumen, sin el código entre paréntesis (no se lee bien en voz alta).
        $agrupado = $reporte['agrupado'];
        $primero = $agrupado['filas'][0] ?? null;
        if ($primero && in_array($agrupado['por'], ['medico', 'especialidad', 'metodo', 'modalidad', 'estado', 'estado_pago'], true) && count($agrupado['filas']) > 1) {
            $metrica = $agrupado['columnas'][1];
            $grupo = trim(preg_replace('/\s*\([^)]*\)$/', '', $primero['grupo']));
            $frase .= ' Por '.mb_strtolower($agrupado['etiqueta']).", el primero es {$grupo} (".mb_strtolower($metrica['etiqueta']).': '.$valor($primero[$metrica['clave']], $metrica['formato']).').';
        }

        return $frase;
    }

    /** RN06: cada consulta queda en la bitácora con el intérprete usado. */
    private function auditar(Usuario $usuario, string $consulta, string $interprete, ?array $resultado): void
    {
        $this->bitacora->registrar('CONSULTA_VOZ', 'reporte', $usuario->id, datosNuevos: [
            'consulta' => mb_substr($consulta, 0, 300),
            'interprete' => $interprete,
            'entendida' => $resultado !== null,
            ...($resultado ?? []),
        ]);
    }
}
