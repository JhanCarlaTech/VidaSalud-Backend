<?php

namespace App\Modules\ModuloPersonalMedico\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPersonalMedico\Models\IndisponibilidadMedico;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Modules\ModuloPersonalMedico\Repositories\LicenciaRepository;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU10 – Gestionar Licencias y Bloqueos de Agenda.
 */
class LicenciaService
{
    /** RN01: duración máxima de una licencia y anticipación máxima para registrarla. */
    public const DURACION_MAXIMA_DIAS = 180;

    public const ANTICIPACION_MAXIMA_DIAS = 365;

    public function __construct(
        private readonly LicenciaRepository $licencias,
        private readonly BitacoraService $bitacora,
    ) {}

    public function listar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->licencias->paginar($filtros, $porPagina);
    }

    /**
     * Vista previa antes de guardar: citas pendientes que quedarían dentro del periodo (RN05).
     *
     * @return Collection<int, CitaMedica>
     */
    public function citasAfectadas(int $idMedico, CarbonImmutable $inicio, CarbonImmutable $fin): Collection
    {
        return $this->licencias->citasAfectadas($idMedico, $inicio, $fin);
    }

    /**
     * Citas pendientes que siguen dentro de una licencia ya registrada.
     *
     * @return Collection<int, CitaMedica>
     */
    public function citasDeLicencia(IndisponibilidadMedico $licencia): Collection
    {
        return $this->licencias->citasAfectadas($licencia->id_medico, $licencia->fecha_inicio, $licencia->fecha_fin);
    }

    /**
     * @return array{0: IndisponibilidadMedico, 1: Collection<int, CitaMedica>}
     *
     * @throws ValidationException
     */
    public function crear(Medico $medico, CarbonImmutable $inicio, CarbonImmutable $fin, string $motivo, Usuario $actor): array
    {
        // RN08: no se registran licencias de médicos inactivos (no tienen agenda).
        if (! $medico->activo) {
            throw ValidationException::withMessages(['id_medico' => 'El médico está inactivo: no tiene agenda que bloquear.']);
        }

        $this->validarPeriodo($inicio, $fin, nueva: true);

        return DB::transaction(function () use ($medico, $inicio, $fin, $motivo, $actor) {
            $this->licencias->bloquearMedico($medico->id_usuario);
            $this->validarSuperposicion($medico->id_usuario, $inicio, $fin);

            $licencia = IndisponibilidadMedico::create([
                'id_medico' => $medico->id_usuario,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
                'motivo' => $motivo,
            ]);

            $citas = $this->licencias->citasAfectadas($medico->id_usuario, $inicio, $fin);

            $this->bitacora->registrar('INSERT', 'indisponibilidad_medico', $actor->id,
                datosNuevos: [...$this->instantanea($licencia), 'citas_afectadas' => $citas->count()]);

            return [$licencia, $citas];
        });
    }

    /**
     * RN04: una licencia finalizada no se modifica; una que ya empezó conserva su inicio
     * (solo se puede adelantar o extender su fin, por ejemplo si el médico vuelve antes).
     *
     * @return array{0: IndisponibilidadMedico, 1: Collection<int, CitaMedica>}
     *
     * @throws ValidationException
     */
    public function actualizar(IndisponibilidadMedico $licencia, CarbonImmutable $inicio, CarbonImmutable $fin, string $motivo, Usuario $actor): array
    {
        if ($licencia->haTerminado()) {
            throw ValidationException::withMessages(['licencia' => 'La licencia ya terminó: queda en el historial y no se puede modificar.']);
        }

        if ($licencia->haEmpezado() && ! $inicio->equalTo($licencia->fecha_inicio)) {
            throw ValidationException::withMessages(['fecha_inicio' => 'La licencia ya empezó: no se puede cambiar su inicio, solo su fin.']);
        }

        $this->validarPeriodo($inicio, $fin, nueva: ! $licencia->haEmpezado());

        return DB::transaction(function () use ($licencia, $inicio, $fin, $motivo, $actor) {
            $this->licencias->bloquearMedico($licencia->id_medico);
            $this->validarSuperposicion($licencia->id_medico, $inicio, $fin, $licencia->id);

            $antes = $this->instantanea($licencia);
            $licencia->update(['fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'motivo' => $motivo]);

            $citas = $this->licencias->citasAfectadas($licencia->id_medico, $inicio, $fin);

            $this->bitacora->registrar('UPDATE', 'indisponibilidad_medico', $actor->id, $antes,
                [...$this->instantanea($licencia), 'citas_afectadas' => $citas->count()]);

            return [$licencia, $citas];
        });
    }

    /**
     * RN04: solo se eliminan las licencias que aún no empezaron; el historial queda en la bitácora.
     *
     * @throws ValidationException
     */
    public function eliminar(IndisponibilidadMedico $licencia, Usuario $actor): void
    {
        if ($licencia->haEmpezado()) {
            throw ValidationException::withMessages(['licencia' => $licencia->haTerminado()
                ? 'La licencia ya terminó: queda en el historial y no se puede eliminar.'
                : 'La licencia ya empezó: en lugar de eliminarla, adelante su fecha de fin.']);
        }

        DB::transaction(function () use ($licencia, $actor) {
            $antes = $this->instantanea($licencia);
            $licencia->delete();

            $this->bitacora->registrar('DELETE', 'indisponibilidad_medico', $actor->id, datosAnteriores: $antes);
        });
    }

    /**
     * RN01: el periodo debe terminar en el futuro, durar como máximo 180 días y empezar
     * dentro del próximo año. Una licencia nueva puede empezar hoy aunque la hora ya
     * haya pasado (por ejemplo, una baja por enfermedad avisada a media mañana).
     *
     * @throws ValidationException
     */
    private function validarPeriodo(CarbonImmutable $inicio, CarbonImmutable $fin, bool $nueva): void
    {
        $ahora = CarbonImmutable::now();

        if ($nueva && $inicio < $ahora->startOfDay()) {
            throw ValidationException::withMessages(['fecha_inicio' => 'La licencia no puede empezar en un día pasado.']);
        }

        if ($fin <= $ahora) {
            throw ValidationException::withMessages(['fecha_fin' => 'La licencia debe terminar en el futuro.']);
        }

        if ($inicio > $ahora->addDays(self::ANTICIPACION_MAXIMA_DIAS)) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Solo se pueden registrar licencias que empiecen dentro del próximo año.']);
        }

        if ($inicio->diffInDays($fin) > self::DURACION_MAXIMA_DIAS) {
            throw ValidationException::withMessages(['fecha_fin' => 'Una licencia puede durar como máximo '.self::DURACION_MAXIMA_DIAS.' días.']);
        }
    }

    /**
     * RN03: las licencias de un mismo médico no se superponen (sí pueden ser contiguas).
     *
     * @throws ValidationException
     */
    private function validarSuperposicion(int $idMedico, CarbonImmutable $inicio, CarbonImmutable $fin, ?int $excluirId = null): void
    {
        $choque = $this->licencias->superpuesta($idMedico, $inicio, $fin, $excluirId);

        if ($choque) {
            throw ValidationException::withMessages([
                'fecha_inicio' => sprintf(
                    'Se superpone con la licencia del %s al %s (%s).',
                    $choque->fecha_inicio->format('d/m/Y H:i'),
                    $choque->fecha_fin->format('d/m/Y H:i'),
                    $choque->motivo ?? 'sin motivo',
                ),
            ]);
        }
    }

    /** Datos de la licencia para la bitácora (RN07), en la hora de la clínica. */
    private function instantanea(IndisponibilidadMedico $licencia): array
    {
        return [
            'id' => $licencia->id,
            'id_medico' => $licencia->id_medico,
            'fecha_inicio' => $licencia->fecha_inicio->format('Y-m-d H:i'),
            'fecha_fin' => $licencia->fecha_fin->format('Y-m-d H:i'),
            'motivo' => $licencia->motivo,
        ];
    }
}
