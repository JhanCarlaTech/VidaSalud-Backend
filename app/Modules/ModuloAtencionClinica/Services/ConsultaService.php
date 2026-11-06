<?php

namespace App\Modules\ModuloAtencionClinica\Services;

use App\Modules\ModuloAtencionClinica\Models\ConsultaMedica;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU17 – Registrar Consulta Médica.
 */
class ConsultaService
{
    private const PREFIJO_EXPEDIENTE = 'EXP-';

    public function __construct(
        private readonly BitacoraService $bitacora
    ) {}

    /**
     * Registra la consulta de una cita del médico y la marca como Atendida
     * (CU15 RN01: 'Atendida' solo se asigna aquí).
     *
     * @throws ValidationException
     */
    public function registrar(array $datos, Usuario $medico): ConsultaMedica
    {
        return DB::transaction(function () use ($datos, $medico) {
            // Bloquea la cita para que dos envíos simultáneos no registren dos consultas.
            $cita = CitaMedica::query()->lockForUpdate()->findOrFail($datos['id_cita_medica']);

            if ((int) $cita->id_medico !== (int) $medico->id) {
                throw ValidationException::withMessages(['id_cita_medica' => 'Esta cita no le pertenece.']);
            }

            if (! in_array($cita->estado, CitaMedica::ESTADOS_PENDIENTES, true)) {
                throw ValidationException::withMessages(['id_cita_medica' => 'La cita no se encuentra en un estado válido para ser atendida.']);
            }

            if (! $cita->fecha->isToday()) {
                throw ValidationException::withMessages(['id_cita_medica' => 'Solo se pueden registrar consultas de citas del día.']);
            }

            // El IMC no se envía: PostgreSQL lo calcula (columna generada).
            $consulta = ConsultaMedica::create([
                'id_cita_medica' => $cita->id,
                'nro_expediente' => $this->expedienteDelPaciente($cita->id_paciente),
                'peso' => $datos['peso'],
                'estatura' => $datos['estatura'],
                'motivo_de_consultas' => $datos['motivo_de_consultas'],
                'orden_cronologico' => $datos['orden_cronologico'] ?? null,
                'sintomas' => $datos['sintomas'] ?? null,
                'tratamiento' => $datos['tratamiento'] ?? null,
                'escala_del_dolor' => $datos['escala_del_dolor'] ?? null,
            ]);
            $consulta->refresh(); // Trae el IMC y la fecha de atención que calcula la BD.

            $cita->update(['estado' => CitaMedica::ATENDIDA]);

            $this->bitacora->registrar('INSERT', 'consulta_medica', $medico->id, datosNuevos: [
                'id' => $consulta->id,
                'codigo_cita' => $cita->codigo_cita,
                'expediente' => $consulta->nro_expediente,
                'imc' => $consulta->imc,
            ]);

            return $consulta->load('cita');
        });
    }

    /**
     * Un expediente por paciente: se reutiliza el de sus consultas anteriores
     * o, en la primera, se forma con su código (EXP-PAC-0001).
     */
    private function expedienteDelPaciente(int $idPaciente): string
    {
        $existente = ConsultaMedica::query()
            ->whereHas('cita', fn (Builder $cita) => $cita->where('id_paciente', $idPaciente))
            ->orderBy('id')
            ->value('nro_expediente');

        return $existente ?? self::PREFIJO_EXPEDIENTE.Paciente::query()->whereKey($idPaciente)->value('codigo');
    }
}
