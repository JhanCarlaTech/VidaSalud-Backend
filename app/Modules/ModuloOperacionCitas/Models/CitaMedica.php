<?php

namespace App\Modules\ModuloOperacionCitas\Models;

use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cita médica (tabla 'cita_medica', CU13–CU17).
 */
#[Table(name: 'cita_medica', timestamps: false)]
#[Fillable(['codigo_cita', 'id_paciente', 'id_medico', 'id_modalidad', 'fecha', 'hra_inicio', 'hra_fin', 'motivo', 'estado', 'motivo_cancelacion'])]
class CitaMedica extends Model
{
    /** Valores de 'estado_cita_enum'. */
    public const PROGRAMADA = 'Programada';

    public const CONFIRMADA = 'Confirmada';

    /** Agregado en CU15 (el paciente llegó a la clínica). */
    public const EN_SALA_DE_ESPERA = 'En Sala de Espera';

    public const ATENDIDA = 'Atendida';

    public const CANCELADA = 'Cancelada';

    public const INASISTENCIA = 'Inasistencia';

    public const ESTADOS = [self::PROGRAMADA, self::CONFIRMADA, self::EN_SALA_DE_ESPERA, self::ATENDIDA, self::CANCELADA, self::INASISTENCIA];

    /**
     * Citas que todavía deben atenderse: ocupan el turno e impiden desactivar
     * al médico o al paciente y quitar el bloque de horario (CU08, CU09, CU11).
     */
    public const ESTADOS_PENDIENTES = [self::PROGRAMADA, self::CONFIRMADA, self::EN_SALA_DE_ESPERA];

    /** Citas que se pueden reprogramar o cancelar: el paciente aún no llegó (CU14, CU15 RN04). */
    public const ESTADOS_MODIFICABLES = [self::PROGRAMADA, self::CONFIRMADA];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_creacion' => 'datetime',
        ];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadCita::class, 'id_modalidad');
    }

    /**
     * Relaciones que muestran las pantallas de citas (paciente, médico con sus
     * especialidades y días de atención, y modalidad).
     */
    #[Scope]
    protected function conDetalle(Builder $query): void
    {
        $query->with([
            'modalidad',
            'paciente.usuario',
            'medico.usuario',
            'medico.especialidades' => fn ($especialidades) => $especialidades->orderBy('especialidad.nombre'),
            'medico.horarios' => fn ($horarios) => $horarios->where('activo', true),
        ]);
    }

    /**
     * Búsqueda por código de cita, paciente (código, CI o nombre) o médico (nombre).
     */
    #[Scope]
    protected function buscar(Builder $query, string $termino): void
    {
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $nombre = "(usuario.nombre || ' ' || usuario.apellido) ilike ?";

        $query->where(fn (Builder $query) => $query
            ->where('codigo_cita', 'ilike', $patron)
            ->orWhereHas('paciente', fn (Builder $paciente) => $paciente
                ->where('codigo', 'ilike', $patron)
                ->orWhereHas('usuario', fn (Builder $usuario) => $usuario->where('ci', 'ilike', $patron)->orWhereRaw($nombre, [$patron])))
            ->orWhereHas('medico.usuario', fn (Builder $usuario) => $usuario->whereRaw($nombre, [$patron])));
    }
}
