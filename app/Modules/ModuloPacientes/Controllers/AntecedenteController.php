<?php

namespace App\Modules\ModuloPacientes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloPacientes\Models\Enfermedad;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPacientes\Requests\AntecedenteRequest;
use App\Modules\ModuloPacientes\Resources\AntecedenteResource;
use App\Modules\ModuloPacientes\Services\AntecedenteService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU12 – Antecedentes patológicos del paciente.
 */
class AntecedenteController extends Controller
{
    public function __construct(private readonly AntecedenteService $antecedentes) {}

    /**
     * GET /api/antecedentes/pacientes?buscar= (mínimo 2 caracteres)
     */
    public function buscarPacientes(Request $request): JsonResponse
    {
        $request->validate(
            ['buscar' => ['required', 'string', 'min:2', 'max:100']],
            ['buscar.required' => 'Ingrese el CI, el código o el nombre del paciente.', 'buscar.min' => 'Ingrese al menos 2 caracteres.'],
        );

        $pacientes = $this->antecedentes->buscarPacientes(trim($request->input('buscar')))
            ->map(fn (Paciente $paciente) => $this->identificacion($paciente));

        return ApiResponse::exito($pacientes, 'Pacientes encontrados.');
    }

    /**
     * GET /api/pacientes/{paciente}/antecedentes
     */
    public function index(Paciente $paciente): JsonResponse
    {
        $paciente->load('usuario:id,ci,nombre,apellido');

        return ApiResponse::exito([
            'paciente' => $this->identificacion($paciente),
            'antecedentes' => AntecedenteResource::collection($this->antecedentes->listar($paciente)),
        ], 'Antecedentes del paciente.');
    }

    /**
     * POST /api/pacientes/{paciente}/antecedentes
     */
    public function store(AntecedenteRequest $request, Paciente $paciente): JsonResponse
    {
        $antecedente = $this->antecedentes->registrar($paciente, $request->validated(), $request->user());

        return ApiResponse::exito(new AntecedenteResource($antecedente), 'Antecedente registrado correctamente.', 201);
    }

    /**
     * PUT /api/pacientes/{paciente}/antecedentes/{enfermedad}
     */
    public function update(AntecedenteRequest $request, Paciente $paciente, Enfermedad $enfermedad): JsonResponse
    {
        $antecedente = $this->antecedentes->actualizar($paciente, $enfermedad, $request->validated(), $request->user());

        return ApiResponse::exito(new AntecedenteResource($antecedente), 'Antecedente actualizado correctamente.');
    }

    /**
     * DELETE /api/pacientes/{paciente}/antecedentes/{enfermedad}
     */
    public function destroy(Request $request, Paciente $paciente, Enfermedad $enfermedad): JsonResponse
    {
        $this->antecedentes->eliminar($paciente, $enfermedad, $request->user());

        return ApiResponse::exito(mensaje: 'Antecedente eliminado.');
    }

    /**
     * Solo los datos para identificar al paciente (RN06): sin contacto ni ficha completa.
     */
    private function identificacion(Paciente $paciente): array
    {
        return [
            'id' => $paciente->id_usuario,
            'codigo' => $paciente->codigo,
            'ci' => $paciente->usuario->ci,
            'nombre_completo' => "{$paciente->usuario->nombre} {$paciente->usuario->apellido}",
            'fecha_nacimiento' => $paciente->fecha_nacimiento?->toDateString(),
            'edad' => $paciente->fecha_nacimiento ? $paciente->edad() : null,
            'activo' => $paciente->activo,
        ];
    }
}
