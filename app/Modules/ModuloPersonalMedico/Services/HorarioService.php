<?php

namespace App\Modules\ModuloPersonalMedico\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPersonalMedico\Models\HorarioAtencion;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU09 – Gestión de la grilla semanal de atención.
 */
class HorarioService
{
    private const CAMPOS = ['dia_semana', 'hora_inicio', 'hora_fin', 'duracion_minutos'];

    public function __construct(private readonly BitacoraService $bitacora) {}

    public function listar(Medico $medico): Collection
    {
        return $medico->horarios()->orderBy('dia_semana')->orderBy('hora_inicio')->get();
    }

    /**
     * @throws ValidationException
     */
    public function crear(Medico $medico, array $datos, Usuario $actor): HorarioAtencion
    {
        $this->validarSuperposicion($medico, $datos);

        return DB::transaction(function () use ($medico, $datos, $actor) {
            $horario = $medico->horarios()->create(Arr::only($datos, self::CAMPOS));
            $horario->refresh(); // Trae 'activo' por defecto y las horas con el formato de la BD.

            $this->bitacora->registrar('INSERT', 'horario_atencion', $actor->id, datosNuevos: $this->instantanea($horario));

            return $horario;
        });
    }

    /**
     * @throws ValidationException
     */
    public function actualizar(Medico $medico, HorarioAtencion $horario, array $datos, Usuario $actor): HorarioAtencion
    {
        $this->validarSuperposicion($medico, $datos, $horario->id);
        $this->validarCitasAfectadas($medico, $horario, $datos);

        return DB::transaction(function () use ($horario, $datos, $actor) {
            $antes = $this->instantanea($horario);

            $horario->update(Arr::only($datos, self::CAMPOS));
            $horario->refresh();

            $this->bitacora->registrar('UPDATE', 'horario_atencion', $actor->id, $antes, $this->instantanea($horario));

            return $horario;
        });
    }

    /**
     * RN04: eliminación física; el historial queda en la bitácora.
     *
     * @throws ValidationException
     */
    public function eliminar(Medico $medico, HorarioAtencion $horario, Usuario $actor): void
    {
        $this->validarCitasAfectadas($medico, $horario, null);

        DB::transaction(function () use ($horario, $actor) {
            $antes = $this->instantanea($horario);
            $horario->delete();

            $this->bitacora->registrar('DELETE', 'horario_atencion', $actor->id, datosAnteriores: $antes);
        });
    }

    /**
     * RN02: los bloques del mismo médico y día no pueden superponerse (sí ser contiguos).
     *
     * @throws ValidationException
     */
    private function validarSuperposicion(Medico $medico, array $datos, ?int $excluirId = null): void
    {
        $choque = $medico->horarios()
            ->where('activo', true)
            ->where('dia_semana', $datos['dia_semana'])
            ->where('hora_inicio', '<', $datos['hora_fin'])
            ->where('hora_fin', '>', $datos['hora_inicio'])
            ->when($excluirId, fn ($query) => $query->where('id', '!=', $excluirId))
            ->first();

        if ($choque) {
            $rango = HorarioAtencion::horaCorta($choque->hora_inicio).'–'.HorarioAtencion::horaCorta($choque->hora_fin);
            $dia = mb_strtolower($choque->nombreDia());

            throw ValidationException::withMessages([
                'hora_inicio' => "Se superpone con el bloque {$rango} del {$dia}.",
            ]);
        }
    }

    /**
     * RN03: no se puede dejar fuera de horario citas pendientes que caen en el bloque.
     * Al modificar, se aceptan las citas que el bloque nuevo sigue cubriendo.
     *
     * @param  array|null  $nuevo  Datos del bloque modificado, o null si se elimina.
     *
     * @throws ValidationException
     */
    private function validarCitasAfectadas(Medico $medico, HorarioAtencion $horario, ?array $nuevo): void
    {
        $citas = CitaMedica::query()
            ->where('id_medico', $medico->id_usuario)
            ->whereIn('estado', CitaMedica::ESTADOS_PENDIENTES)
            ->whereDate('fecha', '>=', today())
            ->whereRaw('extract(isodow from fecha) = ?', [$horario->dia_semana])
            ->where('hra_inicio', '>=', $horario->hora_inicio)
            ->where('hra_inicio', '<', $horario->hora_fin)
            ->orderBy('fecha')
            ->orderBy('hra_inicio')
            ->get(['fecha', 'hra_inicio', 'hra_fin']);

        if ($nuevo !== null) {
            $citas = $citas->reject(fn (CitaMedica $cita) => (int) $nuevo['dia_semana'] === $horario->dia_semana
                && HorarioAtencion::minutos($cita->hra_inicio) >= HorarioAtencion::minutos($nuevo['hora_inicio'])
                && HorarioAtencion::minutos($cita->hra_fin) <= HorarioAtencion::minutos($nuevo['hora_fin']));
        }

        if ($citas->isEmpty()) {
            return;
        }

        $proxima = $citas->first();
        $cantidad = $citas->count() === 1 ? 'Hay 1 cita pendiente' : "Hay {$citas->count()} citas pendientes";

        throw ValidationException::withMessages([
            'horario' => "{$cantidad} en este bloque (próxima: {$proxima->fecha->format('d/m/Y')} a las "
                .HorarioAtencion::horaCorta($proxima->hra_inicio).'). Reprográmelas antes de modificar o eliminar el bloque.',
        ]);
    }

    /**
     * Datos del bloque para la bitácora (RN07).
     */
    private function instantanea(HorarioAtencion $horario): array
    {
        return Arr::only($horario->attributesToArray(), ['id', 'id_medico', ...self::CAMPOS]);
    }
}
