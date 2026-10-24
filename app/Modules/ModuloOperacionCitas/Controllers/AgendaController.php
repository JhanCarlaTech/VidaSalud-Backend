<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloOperacionCitas\Resources\CitaResource;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU16 – Consultar Agenda Médica.
 */
class AgendaController extends Controller
{
    /**
     * GET /api/agenda?fecha=&id_medico=&estado=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'id_medico' => ['nullable', 'integer', 'exists:medico,id_usuario'],
            'estado' => ['nullable', Rule::in(CitaMedica::ESTADOS)],
        ]);

        $usuario = $request->user();
        
        // Usamos el scope 'conDetalle' del modelo que ya tienes definido
        $query = CitaMedica::conDetalle()->where('fecha', $request->fecha);

        // Si el usuario es Médico, solo ve su propia agenda
        $esMedico = \App\Modules\ModuloPersonalMedico\Models\Medico::where('id_usuario', $usuario->id)->exists();
        if ($esMedico) {  
            $query->where('id_medico', $usuario->id);
        } elseif ($request->filled('id_medico')) {
            // Si es Recepcionista/Admin, puede filtrar por médico
            $query->where('id_medico', $request->id_medico);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $agenda = $query->orderBy('hra_inicio', 'asc')->get();

        return ApiResponse::exito(
            ['citas' => CitaResource::collection($agenda)], 
            'Agenda consultada correctamente.'
        );
    }
}