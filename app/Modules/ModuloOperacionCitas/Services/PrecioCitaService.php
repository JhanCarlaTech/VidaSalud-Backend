<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPersonalMedico\Models\Especialidad;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * CU24 – Qué se cobra por una cita: el precio de la especialidad con la que se reservó.
 *
 * Las citas nuevas guardan su especialidad (CU13). En las antiguas cuyo médico
 * tiene varias, el paciente la indica al pagar y queda guardada en la cita.
 */
class PrecioCitaService
{
    /**
     * Especialidades entre las que se puede cobrar la cita: la reservada o,
     * si no se sabe, las especialidades activas de su médico.
     *
     * @return Collection<int, Especialidad>
     */
    public function opciones(CitaMedica $cita): Collection
    {
        if ($cita->id_especialidad) {
            return collect([$cita->especialidad()->firstOrFail()]);
        }

        return $cita->medico->especialidades()
            ->where('especialidad.activo', true)
            ->orderBy('especialidad.nombre')
            ->get();
    }

    /**
     * Especialidad que se cobra. Con una especialidad ya reservada, esa (el
     * cliente no puede cambiarla); si no, la indicada, que debe ejercer el médico.
     *
     * @throws ValidationException
     */
    public function resolver(CitaMedica $cita, ?int $idEspecialidad): Especialidad
    {
        if ($cita->id_especialidad) {
            $especialidad = $cita->especialidad()->firstOrFail();
            if ($idEspecialidad && $idEspecialidad !== $especialidad->id) {
                throw ValidationException::withMessages([
                    'id_especialidad' => "La cita se reservó para {$especialidad->nombre}.",
                ]);
            }

            return $especialidad;
        }

        $opciones = $this->opciones($cita);
        $idElegido = $idEspecialidad ?? ($opciones->count() === 1 ? $opciones->first()->id : null);

        if (! $idElegido) {
            throw ValidationException::withMessages(['id_especialidad' => 'Elija la especialidad de la consulta.']);
        }

        $especialidad = $opciones->firstWhere('id', $idElegido);
        if (! $especialidad) {
            throw ValidationException::withMessages(['id_especialidad' => 'El médico de la cita no atiende esa especialidad.']);
        }

        // Queda registrada: los siguientes pagos de la cita usan el mismo precio.
        $cita->update(['id_especialidad' => $especialidad->id]);

        return $especialidad;
    }
}
