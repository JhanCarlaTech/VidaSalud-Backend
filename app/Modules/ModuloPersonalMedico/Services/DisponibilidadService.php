<?php

namespace App\Modules\ModuloPersonalMedico\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPersonalMedico\Models\IndisponibilidadMedico;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use Carbon\CarbonImmutable;

/**
 * CU09, RN06 – Turnos de un médico en una fecha y su estado.
 * Lo reutilizarán CU13 (agendar) y CU14 (reprogramar) para ofrecer solo turnos disponibles.
 */
class DisponibilidadService
{
    public const DISPONIBLE = 'disponible';

    public const OCUPADO = 'ocupado';

    public const BLOQUEADO = 'bloqueado';

    public const PASADO = 'pasado';

    /** Una cita cancelada libera su turno; cualquier otro estado lo ocupa. */
    private const ESTADO_QUE_LIBERA = 'Cancelada';

    /**
     * @return array<int, array{hora_inicio: string, hora_fin: string, estado: string}>
     */
    public function turnos(Medico $medico, CarbonImmutable $fecha): array
    {
        if (! $medico->activo) {
            return [];
        }

        $bloques = $medico->horarios()
            ->where('activo', true)
            ->where('dia_semana', $fecha->dayOfWeekIso)
            ->orderBy('hora_inicio')
            ->get();

        if ($bloques->isEmpty()) {
            return [];
        }

        // Citas e indisponibilidades del día como intervalos [inicio, fin).
        $citas = CitaMedica::query()
            ->where('id_medico', $medico->id_usuario)
            ->whereDate('fecha', $fecha)
            ->where('estado', '!=', self::ESTADO_QUE_LIBERA)
            ->get(['hra_inicio', 'hra_fin'])
            ->map(fn (CitaMedica $cita) => [$fecha->setTimeFromTimeString($cita->hra_inicio), $fecha->setTimeFromTimeString($cita->hra_fin)]);

        $bloqueos = IndisponibilidadMedico::query()
            ->where('id_medico', $medico->id_usuario)
            ->where('fecha_inicio', '<', $fecha->endOfDay())
            ->where('fecha_fin', '>', $fecha->startOfDay())
            ->get(['fecha_inicio', 'fecha_fin'])
            ->map(fn (IndisponibilidadMedico $bloqueo) => [$bloqueo->fecha_inicio, $bloqueo->fecha_fin]);

        $ahora = CarbonImmutable::now();
        $turnos = [];

        foreach ($bloques as $bloque) {
            $inicio = $fecha->setTimeFromTimeString($bloque->hora_inicio);
            $finBloque = $fecha->setTimeFromTimeString($bloque->hora_fin);

            // El tramo final que no completa un turno no se ofrece (RN01).
            while ($inicio->addMinutes($bloque->duracion_minutos) <= $finBloque) {
                $fin = $inicio->addMinutes($bloque->duracion_minutos);

                $estado = match (true) {
                    $this->seSuperpone($inicio, $fin, $citas->all()) => self::OCUPADO,
                    $this->seSuperpone($inicio, $fin, $bloqueos->all()) => self::BLOQUEADO,
                    $inicio <= $ahora => self::PASADO,
                    default => self::DISPONIBLE,
                };

                $turnos[] = ['hora_inicio' => $inicio->format('H:i'), 'hora_fin' => $fin->format('H:i'), 'estado' => $estado];
                $inicio = $fin;
            }
        }

        return $turnos;
    }

    /**
     * @param  array<int, array{0: \DateTimeInterface, 1: \DateTimeInterface}>  $intervalos
     */
    private function seSuperpone(CarbonImmutable $inicio, CarbonImmutable $fin, array $intervalos): bool
    {
        foreach ($intervalos as [$desde, $hasta]) {
            if ($desde < $fin && $hasta > $inicio) {
                return true;
            }
        }

        return false;
    }
}
