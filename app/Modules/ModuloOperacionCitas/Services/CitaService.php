<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPersonalMedico\Models\HorarioAtencion;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Modules\ModuloPersonalMedico\Services\DisponibilidadService;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU13 – Gestionar Citas Médicas y Modalidad.
 * CU14 – Reprogramar y Cancelar Citas Médicas.
 */
class CitaService
{
    private const PREFIJO_CODIGO = 'CM-';

    /** Clave del bloqueo de PostgreSQL que serializa las reservas de turnos (CU13 RN05, CU14 RN05). */
    private const BLOQUEO_REGISTRO_CITAS = 13013;

    /** SQLSTATE de violación de unicidad: alguien reservó el turno en paralelo. */
    private const UNICIDAD_VIOLADA = '23505';

    /** CU14 RN02: anticipación mínima con la que el paciente puede modificar su cita. */
    public const HORAS_MINIMAS_PACIENTE = 2;

    private const LIMITE_MIS_CITAS = 50;

    public function __construct(
        private readonly DisponibilidadService $disponibilidad,
        private readonly BitacoraService $bitacora,
    ) {}

    /**
     * CU14: búsqueda de citas para el personal.
     *
     * @param  array{buscar?: ?string, estado?: ?string, fecha?: ?string, periodo?: string}  $filtros
     */
    public function listar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        $periodo = $filtros['periodo'] ?? 'proximas';
        $ascendente = $periodo === 'proximas';

        return CitaMedica::query()
            ->conDetalle()
            ->when($filtros['buscar'] ?? null, fn (Builder $query, string $termino) => $query->buscar($termino))
            ->when($filtros['estado'] ?? null, fn (Builder $query, string $estado) => $query->where('estado', $estado))
            ->when($filtros['fecha'] ?? null, fn (Builder $query, string $fecha) => $query->whereDate('fecha', $fecha))
            ->when($periodo === 'proximas', fn (Builder $query) => $query->whereDate('fecha', '>=', today()))
            ->when($periodo === 'historial', fn (Builder $query) => $query->whereDate('fecha', '<', today()))
            ->orderBy('fecha', $ascendente ? 'asc' : 'desc')
            ->orderBy('hra_inicio', $ascendente ? 'asc' : 'desc')
            ->paginate($porPagina);
    }

    /**
     * CU13: registra una cita Programada verificando todas las reglas dentro de
     * una transacción con bloqueo, para que dos reservas simultáneas no tomen el mismo turno.
     *
     * @param  array{id_paciente: int, id_especialidad: int, id_medico: int, id_modalidad: int, fecha: string, hra_inicio: string, motivo: string}  $datos
     *
     * @throws ValidationException
     */
    public function agendar(array $datos, Usuario $actor): CitaMedica
    {
        $paciente = $this->pacienteActivo($datos['id_paciente']);
        $medico = $this->medicoQueEjerce($datos['id_medico'], $datos['id_especialidad']);
        $fecha = CarbonImmutable::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay();

        return $this->enTransaccionExclusiva(function () use ($datos, $paciente, $medico, $fecha, $actor) {
            $turno = $this->turnoDisponible($medico, $fecha, $datos['hra_inicio']);
            $this->validarSinSuperposicion($paciente->id_usuario, $fecha, $turno);

            $cita = CitaMedica::create([
                'codigo_cita' => $this->siguienteCodigo(),
                'id_paciente' => $paciente->id_usuario,
                'id_medico' => $medico->id_usuario,
                'id_modalidad' => $datos['id_modalidad'],
                'fecha' => $fecha->toDateString(),
                'hra_inicio' => $turno['hora_inicio'],
                'hra_fin' => $turno['hora_fin'], // RN02: la calcula el sistema.
                'motivo' => $datos['motivo'],
                'estado' => CitaMedica::PROGRAMADA,
            ]);

            $cita = $this->detalle($cita);
            $this->bitacora->registrar('INSERT', 'cita_medica', $actor->id, datosNuevos: [
                ...$this->instantanea($cita),
                'paciente' => $paciente->codigo,
                'medico' => $medico->codigo,
            ]);

            return $cita;
        });
    }

    /**
     * CU14: mueve la cita a otro turno disponible del mismo médico (RN03–RN05).
     *
     * @param  array{fecha: string, hra_inicio: string, id_modalidad?: ?int}  $datos
     *
     * @throws ValidationException
     */
    public function reprogramar(CitaMedica $cita, array $datos, Usuario $actor, bool $esPersonal): CitaMedica
    {
        $this->validarModificable($cita, $esPersonal, 'reprogramar');

        $medico = $cita->medico;
        if (! $medico->activo) {
            throw ValidationException::withMessages(['cita' => 'El médico ya no atiende; cancele la cita y agende una nueva.']);
        }

        $fecha = CarbonImmutable::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay();
        if ($fecha->isSameDay($cita->fecha) && $datos['hra_inicio'] === HorarioAtencion::horaCorta($cita->hra_inicio)) {
            throw ValidationException::withMessages(['hra_inicio' => 'Elija una fecha u hora distinta de la actual.']);
        }

        return $this->enTransaccionExclusiva(function () use ($cita, $datos, $medico, $fecha, $actor) {
            $antes = $this->instantanea($cita);

            $turno = $this->turnoDisponible($medico, $fecha, $datos['hra_inicio']);
            $this->validarSinSuperposicion($cita->id_paciente, $fecha, $turno, $cita->id);

            $cita->update([
                'fecha' => $fecha->toDateString(),
                'hra_inicio' => $turno['hora_inicio'],
                'hra_fin' => $turno['hora_fin'],
                'id_modalidad' => $datos['id_modalidad'] ?? $cita->id_modalidad,
            ]);

            $cita = $this->detalle($cita);
            $this->bitacora->registrar('REPROGRAMACION', 'cita_medica', $actor->id, $antes, $this->instantanea($cita));

            return $cita;
        });
    }

    /**
     * CU14: cancela la cita conservando el registro (RN06).
     *
     * @throws ValidationException
     */
    public function cancelar(CitaMedica $cita, string $motivo, Usuario $actor, bool $esPersonal): CitaMedica
    {
        $this->validarModificable($cita, $esPersonal, 'cancelar');

        return DB::transaction(function () use ($cita, $motivo, $actor) {
            $antes = $this->instantanea($cita);

            $cita->update(['estado' => CitaMedica::CANCELADA, 'motivo_cancelacion' => $motivo]);

            $cita = $this->detalle($cita);
            $this->bitacora->registrar('CANCELACION', 'cita_medica', $actor->id, $antes, [
                ...$this->instantanea($cita),
                'motivo_cancelacion' => $motivo,
            ]);

            return $cita;
        });
    }

    /**
     * Citas del paciente: 'proximas' (desde hoy, la más cercana primero) o
     * 'historial' (anteriores a hoy, la más reciente primero).
     */
    public function citasDelPaciente(Paciente $paciente, string $periodo): Collection
    {
        $proximas = $periodo === 'proximas';

        return CitaMedica::query()
            ->conDetalle()
            ->where('id_paciente', $paciente->id_usuario)
            ->whereDate('fecha', $proximas ? '>=' : '<', today())
            ->orderBy('fecha', $proximas ? 'asc' : 'desc')
            ->orderBy('hra_inicio', $proximas ? 'asc' : 'desc')
            ->limit(self::LIMITE_MIS_CITAS)
            ->get();
    }

    public function detalle(CitaMedica $cita): CitaMedica
    {
        return CitaMedica::query()->conDetalle()->findOrFail($cita->id);
    }

    /** Fecha y hora de inicio de la cita en la zona horaria de la clínica. */
    public static function inicio(CitaMedica $cita): CarbonImmutable
    {
        return CarbonImmutable::parse($cita->fecha->toDateString().' '.$cita->hra_inicio);
    }

    /**
     * CU14 RN01 y RN02: solo citas Programadas o Confirmadas que no comenzaron
     * (una cita En Sala de Espera ya no se mueve, CU15 RN04); el paciente, con
     * al menos HORAS_MINIMAS_PACIENTE de anticipación.
     *
     * @throws ValidationException
     */
    private function validarModificable(CitaMedica $cita, bool $esPersonal, string $accion): void
    {
        $inicio = self::inicio($cita);

        if (! in_array($cita->estado, CitaMedica::ESTADOS_MODIFICABLES, true) || $inicio <= now()) {
            throw ValidationException::withMessages(['cita' => 'Solo se pueden modificar citas pendientes que aún no comenzaron.']);
        }

        if (! $esPersonal && now()->diffInMinutes($inicio) < self::HORAS_MINIMAS_PACIENTE * 60) {
            throw ValidationException::withMessages([
                'cita' => "Las citas solo se pueden {$accion} hasta ".self::HORAS_MINIMAS_PACIENTE.' horas antes. Comuníquese con recepción.',
            ]);
        }
    }

    /**
     * Ejecuta la operación en una transacción con el bloqueo de reservas y
     * convierte una violación del índice único de turnos en un error legible.
     *
     * @throws ValidationException
     */
    private function enTransaccionExclusiva(Closure $operacion): mixed
    {
        try {
            return DB::transaction(function () use ($operacion) {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::BLOQUEO_REGISTRO_CITAS]);

                return $operacion();
            });
        } catch (QueryException $e) {
            // Última barrera: el índice idx_cita_medico_no_solapada.
            if ($e->getCode() === self::UNICIDAD_VIOLADA) {
                throw ValidationException::withMessages(['hra_inicio' => 'Ese turno acaba de ser reservado. Elija otro.']);
            }

            throw $e;
        }
    }

    /**
     * @throws ValidationException
     */
    private function pacienteActivo(int $idPaciente): Paciente
    {
        $paciente = Paciente::query()->find($idPaciente);

        if (! $paciente?->activo) {
            throw ValidationException::withMessages(['id_paciente' => 'El paciente no existe o está inactivo.']);
        }

        return $paciente;
    }

    /**
     * CU13 RN01: médico activo que ejerce la especialidad elegida, y esta activa.
     *
     * @throws ValidationException
     */
    private function medicoQueEjerce(int $idMedico, int $idEspecialidad): Medico
    {
        $medico = Medico::query()->find($idMedico);

        if (! $medico?->activo) {
            throw ValidationException::withMessages(['id_medico' => 'El médico no está disponible para nuevas citas.']);
        }

        $ejerce = $medico->especialidades()
            ->where('especialidad.id', $idEspecialidad)
            ->where('especialidad.activo', true)
            ->exists();

        if (! $ejerce) {
            throw ValidationException::withMessages(['id_medico' => 'El médico no atiende la especialidad seleccionada.']);
        }

        return $medico;
    }

    /**
     * El turno debe existir en la grilla y estar disponible (CU09 RN06).
     *
     * @return array{hora_inicio: string, hora_fin: string, estado: string}
     *
     * @throws ValidationException
     */
    private function turnoDisponible(Medico $medico, CarbonImmutable $fecha, string $horaInicio): array
    {
        $turno = collect($this->disponibilidad->turnos($medico, $fecha))->firstWhere('hora_inicio', $horaInicio);

        if ($turno === null) {
            throw ValidationException::withMessages(['hra_inicio' => 'El horario elegido no corresponde a un turno del médico.']);
        }

        $motivo = match ($turno['estado']) {
            DisponibilidadService::DISPONIBLE => null,
            DisponibilidadService::OCUPADO => 'Ese turno ya fue reservado. Elija otro.',
            DisponibilidadService::BLOQUEADO => 'El médico no atiende en ese horario por una licencia.',
            DisponibilidadService::PASADO => 'Ese turno ya pasó. Elija otro.',
        };

        if ($motivo !== null) {
            throw ValidationException::withMessages(['hra_inicio' => $motivo]);
        }

        return $turno;
    }

    /**
     * El paciente no puede tener dos citas pendientes superpuestas
     * (al reprogramar no se cuenta la cita que se mueve).
     *
     * @throws ValidationException
     */
    private function validarSinSuperposicion(int $idPaciente, CarbonImmutable $fecha, array $turno, ?int $excluirId = null): void
    {
        $choque = CitaMedica::query()
            ->where('id_paciente', $idPaciente)
            ->whereDate('fecha', $fecha)
            ->whereIn('estado', CitaMedica::ESTADOS_PENDIENTES)
            ->where('hra_inicio', '<', $turno['hora_fin'])
            ->where('hra_fin', '>', $turno['hora_inicio'])
            ->when($excluirId, fn (Builder $query) => $query->where('id', '!=', $excluirId))
            ->first();

        if ($choque) {
            $hora = HorarioAtencion::horaCorta($choque->hra_inicio);

            throw ValidationException::withMessages([
                'hra_inicio' => "El paciente ya tiene la cita {$choque->codigo_cita} a las {$hora} ese día.",
            ]);
        }
    }

    /**
     * CU13 RN07: siguiente código correlativo (CM-0001, CM-0002, …). Se calcula
     * dentro de la transacción bloqueada, por lo que no puede repetirse.
     */
    private function siguienteCodigo(): string
    {
        $ultimo = CitaMedica::query()
            ->where('codigo_cita', '~', '^'.self::PREFIJO_CODIGO.'[0-9]+$')
            ->max(DB::raw('CAST(SUBSTRING(codigo_cita FROM '.(strlen(self::PREFIJO_CODIGO) + 1).') AS INTEGER)'));

        return sprintf('%s%04d', self::PREFIJO_CODIGO, ((int) $ultimo) + 1);
    }

    /**
     * Datos de la cita para la bitácora.
     */
    private function instantanea(CitaMedica $cita): array
    {
        return [
            'codigo_cita' => $cita->codigo_cita,
            'fecha' => $cita->fecha->toDateString(),
            'hra_inicio' => HorarioAtencion::horaCorta($cita->hra_inicio),
            'hra_fin' => HorarioAtencion::horaCorta($cita->hra_fin),
            'id_modalidad' => $cita->id_modalidad,
            'estado' => $cita->estado,
        ];
    }
}
