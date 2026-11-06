<?php

namespace App\Modules\ModuloAtencionClinica\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regla única de acceso a los datos clínicos de un paciente (decisión D4, CU19 RN01).
 *
 * Un médico accede a un paciente con el que tiene una relación de atención: al menos
 * una cita Atendida o pendiente (Programada, Confirmada o En Sala de Espera) con él.
 * Así ve el historial del paciente que va a atender, aunque sea su primera consulta,
 * pero no el de cualquier paciente de la clínica. Para cambiar el criterio basta con
 * modificar ESTADOS_CON_RELACION o este servicio.
 */
class AccesoClinicoService
{
    /** Estados de cita que crean la relación médico–paciente. */
    public const ESTADOS_CON_RELACION = [CitaMedica::ATENDIDA, ...CitaMedica::ESTADOS_PENDIENTES];

    public function medicoAtiendeAPaciente(int $idMedico, int $idPaciente): bool
    {
        return CitaMedica::query()
            ->where('id_medico', $idMedico)
            ->where('id_paciente', $idPaciente)
            ->whereIn('estado', self::ESTADOS_CON_RELACION)
            ->exists();
    }

    /**
     * Restringe una consulta de pacientes a los que atiende el médico.
     */
    public function soloPacientesDe(Builder $pacientes, int $idMedico): Builder
    {
        return $pacientes->whereExists(fn ($citas) => $citas->selectRaw('1')
            ->from('cita_medica')
            ->whereColumn('cita_medica.id_paciente', 'paciente.id_usuario')
            ->where('cita_medica.id_medico', $idMedico)
            ->whereIn('cita_medica.estado', self::ESTADOS_CON_RELACION));
    }
}
