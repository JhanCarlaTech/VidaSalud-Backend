<?php

namespace App\Modules\ModuloPersonalMedico\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque de la grilla semanal de atención de un médico (tabla 'horario_atencion', CU09).
 */
#[Table(name: 'horario_atencion', timestamps: false)]
#[Fillable(['id_medico', 'dia_semana', 'hora_inicio', 'hora_fin', 'duracion_minutos', 'activo'])]
class HorarioAtencion extends Model
{
    /** Día de la semana según ISO-8601 (1 = lunes … 7 = domingo). */
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /** Duraciones de turno permitidas, en minutos (RN01). */
    public const DURACIONES = [15, 20, 30, 45, 60];

    protected function casts(): array
    {
        return [
            'dia_semana' => 'integer',
            'duracion_minutos' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico');
    }

    /** 'Lunes', 'Martes', … */
    public function nombreDia(): string
    {
        return self::DIAS[$this->dia_semana];
    }

    /**
     * Turnos completos que caben en el bloque (el tramo final incompleto no cuenta).
     */
    public function cantidadTurnos(): int
    {
        return intdiv(self::minutos($this->hora_fin) - self::minutos($this->hora_inicio), $this->duracion_minutos);
    }

    /** '08:30' o '08:30:00' → 510 */
    public static function minutos(string $hora): int
    {
        [$horas, $minutos] = explode(':', $hora);

        return (int) $horas * 60 + (int) $minutos;
    }

    /** '08:30:00' → '08:30' */
    public static function horaCorta(string $hora): string
    {
        return substr($hora, 0, 5);
    }
}
