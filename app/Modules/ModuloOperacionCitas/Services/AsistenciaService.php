<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU15 – Registrar Control de Asistencia: máquina de estados de la cita el día de la atención.
 */
class AsistenciaService
{
    /** RN02: minutos de espera antes de poder marcar la inasistencia. */
    public const TOLERANCIA_MINUTOS = 15;

    /** Estados destino que este CU puede registrar. 'Atendida' es de CU17 (RN01). */
    public const ESTADOS_DESTINO = [CitaMedica::CONFIRMADA, CitaMedica::EN_SALA_DE_ESPERA, CitaMedica::INASISTENCIA];

    /** Transiciones permitidas: estado actual => estados destino (incluye las correcciones). */
    private const TRANSICIONES = [
        CitaMedica::PROGRAMADA => [CitaMedica::CONFIRMADA, CitaMedica::EN_SALA_DE_ESPERA, CitaMedica::INASISTENCIA],
        CitaMedica::CONFIRMADA => [CitaMedica::EN_SALA_DE_ESPERA, CitaMedica::INASISTENCIA],
        CitaMedica::EN_SALA_DE_ESPERA => [CitaMedica::CONFIRMADA], // Deshacer llegada.
        CitaMedica::INASISTENCIA => [CitaMedica::EN_SALA_DE_ESPERA], // Llegó tarde.
    ];

    public function __construct(private readonly BitacoraService $bitacora) {}

    /**
     * Citas de una fecha ordenadas por hora, con búsqueda opcional.
     */
    public function citasDelDia(CarbonImmutable $fecha, ?string $buscar): Collection
    {
        return CitaMedica::query()
            ->conDetalle()
            ->whereDate('fecha', $fecha)
            ->when($buscar, fn (Builder $query, string $termino) => $query->buscar($termino))
            ->orderBy('hra_inicio')
            ->orderBy('id')
            ->get();
    }

    /**
     * Estados a los que la cita puede pasar ahora mismo; la pantalla solo muestra esas acciones.
     *
     * @return array<int, string>
     */
    public function accionesPermitidas(CitaMedica $cita, ?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now();

        return array_values(array_filter(
            self::TRANSICIONES[$cita->estado] ?? [],
            fn (string $destino) => $this->motivoRechazo($cita, $destino, $ahora) === null,
        ));
    }

    /**
     * @throws ValidationException
     */
    public function cambiarEstado(CitaMedica $cita, string $destino, Usuario $actor): CitaMedica
    {
        $motivo = $this->motivoRechazo($cita, $destino, CarbonImmutable::now());

        if ($motivo !== null) {
            throw ValidationException::withMessages(['estado' => $motivo]);
        }

        return DB::transaction(function () use ($cita, $destino, $actor) {
            $anterior = $cita->estado;
            $cita->update(['estado' => $destino]);

            $this->bitacora->registrar(
                'ASISTENCIA', 'cita_medica', $actor->id,
                ['codigo_cita' => $cita->codigo_cita, 'estado' => $anterior],
                ['codigo_cita' => $cita->codigo_cita, 'estado' => $destino],
            );

            return CitaMedica::query()->conDetalle()->findOrFail($cita->id);
        });
    }

    /**
     * Motivo por el que la transición no está permitida ahora, o null si lo está (RN01–RN02).
     */
    private function motivoRechazo(CitaMedica $cita, string $destino, CarbonImmutable $ahora): ?string
    {
        if (! in_array($destino, self::TRANSICIONES[$cita->estado] ?? [], true)) {
            return "No se puede pasar de {$cita->estado} a {$destino}.";
        }

        $inicio = CitaService::inicio($cita);
        $esDiaDeLaCita = $cita->fecha->isSameDay($ahora);

        return match (true) {
            // Corrección "deshacer llegada" (En Sala de Espera → Confirmada).
            $destino === CitaMedica::CONFIRMADA && $cita->estado === CitaMedica::EN_SALA_DE_ESPERA => $esDiaDeLaCita
                ? null : 'Solo se puede corregir el día de la cita.',

            $destino === CitaMedica::CONFIRMADA => $inicio > $ahora
                ? null : 'La cita ya comenzó: registre la llegada o la inasistencia.',

            $destino === CitaMedica::EN_SALA_DE_ESPERA => $esDiaDeLaCita
                ? null : 'La llegada solo se puede registrar el día de la cita.',

            $destino === CitaMedica::INASISTENCIA => $ahora >= $inicio->addMinutes(self::TOLERANCIA_MINUTOS)
                ? null : 'Podrá marcar la inasistencia desde las '.$inicio->addMinutes(self::TOLERANCIA_MINUTOS)->format('H:i').'.',
        };
    }
}
