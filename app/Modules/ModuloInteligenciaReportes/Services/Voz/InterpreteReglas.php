<?php

namespace App\Modules\ModuloInteligenciaReportes\Services\Voz;

use App\Modules\ModuloInteligenciaReportes\Services\CatalogoReportes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * CU22 – Intérprete por reglas (decisión D6: funciona sin clave de IA ni conexión).
 *
 * Reconoce palabras clave en español, sin distinguir tildes ni mayúsculas. Lo que no
 * reconoce lo completa con valores por defecto y lo explica en 'notas'.
 */
class InterpreteReglas implements InterpreteConsulta
{
    /** Palabras que eligen el reporte, en orden de prioridad. */
    private const TIPOS = [
        CatalogoReportes::INGRESOS => ['ingreso', 'pago', 'cobr', 'recaud', 'factur', 'dinero', 'ganancia', 'plata', 'reembols', 'devoluci'],
        CatalogoReportes::INASISTENCIAS => ['inasist', 'no asist', 'falta', 'faltaron', 'ausen', 'no vin', 'no se present'],
        CatalogoReportes::CITAS => ['cita'],
        CatalogoReportes::ATENCIONES => ['atencion', 'atendi', 'consulta', 'receta', 'pacientes atendidos'],
    ];

    private const ESTADOS = [
        'cancelad' => 'Cancelada',
        'atendid' => 'Atendida',
        'programad' => 'Programada',
        'confirmad' => 'Confirmada',
        'en sala' => 'En Sala de Espera',
        'inasistencia' => 'Inasistencia',
    ];

    private const ESTADOS_PAGO = [
        'reembols' => 'Reembolsado',
        'devoluci' => 'Reembolsado',
        'rechazad' => 'Rechazado',
        'pendiente' => 'Pendiente',
        'todos los pagos' => 'todos',
    ];

    private const MODALIDADES = ['presencial' => 'presencial', 'virtual' => 'virtual', 'telemedicina' => 'virtual', 'en linea' => 'virtual', 'domicili' => 'domicili'];

    private const METODOS = ['qr' => 'qr', 'tarjeta' => 'tarjeta', 'transferencia' => 'transferencia', 'efectivo' => 'efectivo'];

    /** Frase => agrupación. */
    private const AGRUPACIONES = [
        'por medico' => 'medico', 'por doctor' => 'medico', 'por doctora' => 'medico', 'por cada medico' => 'medico',
        'por especialidad' => 'especialidad',
        'por dia' => 'dia', 'diari' => 'dia', 'cada dia' => 'dia',
        'por mes' => 'mes', 'mensual' => 'mes', 'cada mes' => 'mes',
        'por estado' => 'estado',
        'por metodo' => 'metodo', 'por forma de pago' => 'metodo', 'por medio de pago' => 'metodo',
        'por modalidad' => 'modalidad',
    ];

    private const MESES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    private const NUMEROS = ['un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6,
        'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12, 'quince' => 15, 'treinta' => 30, 'noventa' => 90];

    public function nombre(): string
    {
        return 'reglas';
    }

    public function interpretar(string $consulta, array $contexto): array
    {
        $texto = self::normalizar($consulta);
        $notas = [];

        $tipo = $this->tipo($texto, $contexto['reportes']);
        if ($tipo === null) {
            $tipo = array_key_first($contexto['reportes']);
            // Se pidió un reporte reconocible pero fuera del alcance del usuario (p. ej., ingresos para un médico).
            $pedido = $this->tipo($texto, CatalogoReportes::REPORTES);
            $notas[] = $pedido
                ? 'El reporte de '.mb_strtolower(CatalogoReportes::definicion($pedido)['titulo']).' no está disponible para su usuario: se muestra el de '.mb_strtolower($contexto['reportes'][$tipo]['titulo']).'.'
                : 'No se indicó qué reporte: se muestra el de '.mb_strtolower($contexto['reportes'][$tipo]['titulo']).'.';
        }

        [$desde, $hasta, $notaPeriodo] = $this->periodo($texto, $contexto['hoy']);
        if ($notaPeriodo) {
            $notas[] = $notaPeriodo;
        }

        [$medico, $notaMedico] = $this->medico($texto, $contexto['medicos']);
        if ($notaMedico) {
            $notas[] = $notaMedico;
        }

        return [
            // Se entendió algo si al menos se reconoció el reporte o un periodo.
            'entendido' => $this->tipo($texto, $contexto['reportes']) !== null || $notaPeriodo === null,
            'tipo' => $tipo,
            'desde' => $desde,
            'hasta' => $hasta,
            'medico' => $medico,
            'especialidad' => $this->porNombre($texto, $contexto['especialidades'], fn (string $nombre) => [$nombre, Str::substr($nombre, 0, 7)]),
            'modalidad' => $this->porPalabra($texto, self::MODALIDADES, $contexto['modalidades']),
            'metodo' => $this->porPalabra($texto, self::METODOS, $contexto['metodos']),
            'estado' => $tipo === CatalogoReportes::CITAS ? $this->primera($texto, self::ESTADOS) : null,
            'estado_pago' => $tipo === CatalogoReportes::INGRESOS ? $this->primera($texto, self::ESTADOS_PAGO) : null,
            'agrupar' => $this->primera($texto, self::AGRUPACIONES),
            'notas' => $notas,
        ];
    }

    /** 'Médicos de Cardiología' → 'medicos de cardiologia'. */
    public static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\-\/ ]/', ' ', Str::lower(Str::ascii($texto)))));
    }

    private function tipo(string $texto, array $permitidos): ?string
    {
        foreach (self::TIPOS as $tipo => $palabras) {
            if (isset($permitidos[$tipo]) && Str::contains($texto, $palabras)) {
                return $tipo;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [desde, hasta, nota si se usó el periodo por defecto]
     */
    private function periodo(string $texto, CarbonImmutable $hoy): array
    {
        $rango = fn (CarbonImmutable $desde, CarbonImmutable $hasta) => [$desde->toDateString(), $hasta->toDateString(), null];

        // "del 1 al 15 de octubre (de 2026)"
        if (preg_match('/del? (\d{1,2}) al (\d{1,2}) de ('.implode('|', array_keys(self::MESES)).')(?: (?:de )?(\d{4}))?/', $texto, $m)) {
            $anio = isset($m[4]) ? (int) $m[4] : $this->anioDelMes(self::MESES[$m[3]], $hoy);
            $desde = CarbonImmutable::create($anio, self::MESES[$m[3]], 1);
            $dia = fn (int $d) => $desde->day(min($d, $desde->daysInMonth));

            return $rango($dia((int) $m[1]), $dia((int) $m[2]));
        }

        // "últimos 7 días", "últimas dos semanas", "últimos 3 meses"
        if (preg_match('/ultim[oa]s? (\d+|'.implode('|', array_keys(self::NUMEROS)).') (dias?|semanas?|mes(?:es)?)/', $texto, $m)) {
            $n = is_numeric($m[1]) ? (int) $m[1] : self::NUMEROS[$m[1]];
            $desde = match (true) {
                str_starts_with($m[2], 'dia') => $hoy->subDays($n - 1),
                str_starts_with($m[2], 'semana') => $hoy->subWeeks($n)->addDay(),
                default => $hoy->subMonths($n)->addDay(),
            };

            return $rango($desde, $hoy);
        }

        $frases = [
            'hoy' => [$hoy, $hoy],
            'ayer' => [$hoy->subDay(), $hoy->subDay()],
            'semana pasada' => [$hoy->subWeek()->startOfWeek(), $hoy->subWeek()->endOfWeek()],
            'esta semana' => [$hoy->startOfWeek(), $hoy],
            'mes pasado' => [$hoy->subMonthNoOverflow()->startOfMonth(), $hoy->subMonthNoOverflow()->endOfMonth()],
            'mes anterior' => [$hoy->subMonthNoOverflow()->startOfMonth(), $hoy->subMonthNoOverflow()->endOfMonth()],
            'este mes' => [$hoy->startOfMonth(), $hoy->endOfMonth()],
            'ano pasado' => [$hoy->subYear()->startOfYear(), $hoy->subYear()->endOfYear()],
            'este ano' => [$hoy->startOfYear(), $hoy->endOfYear()],
        ];

        foreach ($frases as $frase => [$desde, $hasta]) {
            if (preg_match('/\b'.$frase.'\b/', $texto)) {
                return $rango($desde, $hasta);
            }
        }

        // "en octubre", "de marzo de 2026"
        if (preg_match('/\b('.implode('|', array_keys(self::MESES)).')(?: (?:de |del )?(\d{4}))?\b/', $texto, $m)) {
            $mes = self::MESES[$m[1]];
            $anio = isset($m[2]) ? (int) $m[2] : $this->anioDelMes($mes, $hoy);
            $desde = CarbonImmutable::create($anio, $mes, 1);

            return $rango($desde, $desde->endOfMonth());
        }

        // "en 2025"
        if (preg_match('/\b(20\d{2})\b/', $texto, $m)) {
            $desde = CarbonImmutable::create((int) $m[1], 1, 1);

            return $rango($desde, $desde->endOfYear());
        }

        return [$hoy->subDays(29)->toDateString(), $hoy->toDateString(), 'No se indicó el periodo: se usan los últimos 30 días.'];
    }

    /** Un mes sin año es el más reciente: en noviembre, "diciembre" es el del año pasado. */
    private function anioDelMes(int $mes, CarbonImmutable $hoy): int
    {
        return $mes > $hoy->month ? $hoy->year - 1 : $hoy->year;
    }

    /**
     * Médico por apellido, "nombre apellido" o código (MED-001).
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function medico(string $texto, array $medicos): array
    {
        $coincidencias = array_filter($medicos, function (array $medico) use ($texto) {
            $apellido = self::normalizar($medico['apellido']);
            $codigo = self::normalizar($medico['codigo']);

            return (strlen($apellido) >= 4 && preg_match('/\b'.preg_quote($apellido, '/').'\b/', $texto))
                || Str::contains($texto, [$codigo, str_replace('-', ' ', $codigo)]);
        });

        return match (count($coincidencias)) {
            0 => [null, null],
            1 => [array_values($coincidencias)[0]['id'], null],
            default => [null, 'Varios médicos coinciden con el nombre: se muestran todos. Diga el nombre completo o el código.'],
        };
    }

    /** Elemento del contexto cuyo nombre (o alguna de sus variantes) aparece en el texto. */
    private function porNombre(string $texto, array $elementos, \Closure $variantes): ?int
    {
        foreach ($elementos as $elemento) {
            $nombre = self::normalizar($elemento['nombre']);
            foreach ($variantes($nombre) as $variante) {
                if (strlen($variante) >= 5 && preg_match('/\b'.preg_quote($variante, '/').'/', $texto)) {
                    return $elemento['id'];
                }
            }
        }

        return null;
    }

    /** Palabra clave del texto → elemento del contexto cuyo nombre la contiene. */
    private function porPalabra(string $texto, array $palabras, array $elementos): ?int
    {
        foreach ($palabras as $palabra => $raiz) {
            if (preg_match('/\b'.preg_quote($palabra, '/').'/', $texto)) {
                foreach ($elementos as $elemento) {
                    if (Str::contains(self::normalizar($elemento['nombre']), $raiz)) {
                        return $elemento['id'];
                    }
                }
            }
        }

        return null;
    }

    /** Valor de la primera frase del mapa que aparece en el texto. */
    private function primera(string $texto, array $mapa): ?string
    {
        foreach ($mapa as $frase => $valor) {
            if (Str::contains($texto, $frase)) {
                return $valor;
            }
        }

        return null;
    }
}
