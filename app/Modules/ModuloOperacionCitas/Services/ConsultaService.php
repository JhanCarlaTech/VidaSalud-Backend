<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Models\ConsultaMedica;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultaService
{
    public function __construct(
        private readonly BitacoraService $bitacora
    ) {}

    public function registrar(array $datos, int $idMedico): ConsultaMedica
    {
        return DB::transaction(function () use ($datos, $idMedico) {
            $cita = CitaMedica::findOrFail($datos['id_cita_medica']);

            // Validar que la cita le pertenezca al médico logueado
            if ($cita->id_medico !== $idMedico) {
                throw ValidationException::withMessages(['id_cita_medica' => 'Esta cita no le pertenece.']);
            }

            // Validar estado de la cita
            if (!in_array($cita->estado, ['Programada', 'Confirmada', 'En Sala de Espera'])) {
                throw ValidationException::withMessages(['id_cita_medica' => 'La cita no se encuentra en un estado válido para ser atendida.']);
            }

            // 1. Crear la consulta (No enviamos IMC, PostgreSQL lo calcula solo)
            $consulta = ConsultaMedica::create([
                'id_cita_medica' => $cita->id,
                'nro_expediente' => 'EXP-' . date('Y') . '-' . str_pad($cita->id, 3, '0', STR_PAD_LEFT),
                'peso' => $datos['peso'],
                'estatura' => $datos['estatura'],
                'motivo_de_consultas' => $datos['motivo_de_consultas'],
                'orden_cronologico' => $datos['orden_cronologico'] ?? null,
                'sintomas' => $datos['sintomas'] ?? null,
                'tratamiento' => $datos['tratamiento'] ?? null,
                'escala_del_dolor' => $datos['escala_del_dolor'] ?? null,
            ]);

            // 2. Actualizar estado de la cita a Atendida (CU15 RN01)
            $cita->update(['estado' => CitaMedica::ATENDIDA]);

            // 3. Registrar en bitácora
            $this->bitacora->registrar('INSERT', 'consulta_medica', $idMedico, datosNuevos: [
                'id_cita' => $cita->id,
                'expediente' => $consulta->nro_expediente
            ]);

            return $consulta;
        });
    }
}