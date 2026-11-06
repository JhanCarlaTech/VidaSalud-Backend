<?php

namespace App\Modules\ModuloAtencionClinica\Services;

use App\Modules\ModuloAtencionClinica\Models\ConsultaMedica;
use App\Modules\ModuloAtencionClinica\Models\RecetaMedica;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPacientes\Services\AntecedenteService;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU19 – Consultar Historial Clínico del Paciente.
 */
class HistorialService
{
    public function __construct(
        private readonly AccesoClinicoService $acceso,
        private readonly AntecedenteService $antecedentes,
        private readonly BitacoraService $bitacora,
    ) {}

    /**
     * Pacientes que atiende el médico (RN01), con su última atención en la clínica y su
     * próxima cita pendiente con este médico; primero los de cita más cercana.
     */
    public function pacientesDelMedico(Usuario $medico, ?string $buscar, int $porPagina): LengthAwarePaginator
    {
        $consulta = Paciente::query()
            ->with('usuario:id,ci,nombre,apellido')
            ->select('paciente.*')
            ->selectSub(fn ($q) => $q->from('consulta_medica')
                ->join('cita_medica', 'cita_medica.id', '=', 'consulta_medica.id_cita_medica')
                ->whereColumn('cita_medica.id_paciente', 'paciente.id_usuario')
                ->selectRaw('max(consulta_medica.fecha_atencion)'), 'ultima_atencion')
            ->selectSub(fn ($q) => $q->from('cita_medica')
                ->whereColumn('cita_medica.id_paciente', 'paciente.id_usuario')
                ->where('cita_medica.id_medico', $medico->id)
                ->whereIn('cita_medica.estado', CitaMedica::ESTADOS_PENDIENTES)
                ->whereDate('cita_medica.fecha', '>=', today())
                ->selectRaw('min(cita_medica.fecha)'), 'proxima_cita')
            ->when($buscar, function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('paciente.codigo', 'ilike', $patron)
                    ->orWhereHas('usuario', fn (Builder $usuario) => $usuario->where(fn (Builder $usuario) => $usuario
                        ->where('ci', 'ilike', $patron)
                        ->orWhereRaw("(nombre || ' ' || apellido) ilike ?", [$patron]))));
            })
            ->orderByRaw('proxima_cita asc nulls last')
            ->orderByRaw('ultima_atencion desc nulls last')
            ->orderBy('paciente.codigo');

        return $this->acceso->soloPacientesDe($consulta, $medico->id)->paginate($porPagina);
    }

    /**
     * RN01: el médico solo accede a los pacientes que atiende.
     *
     * @throws AccessDeniedHttpException
     */
    public function autorizarMedico(Usuario $medico, Paciente $paciente): void
    {
        if (! $this->acceso->medicoAtiendeAPaciente($medico->id, $paciente->id_usuario)) {
            throw new AccessDeniedHttpException;
        }
    }

    /**
     * Historial completo: identificación y ficha, resumen, antecedentes (CU12) y la
     * página pedida de consultas (CU17) con sus recetas (CU18), de la más reciente a
     * la más antigua (RN02–RN03).
     */
    public function historial(Paciente $paciente, int $porPagina): array
    {
        $paciente->loadMissing('usuario:id,ci,nombre,apellido,sexo');

        $deEstePaciente = fn (Builder $cita) => $cita->where('id_paciente', $paciente->id_usuario);

        $consultas = ConsultaMedica::query()
            ->whereHas('cita', $deEstePaciente)
            ->with([
                'cita:id,codigo_cita,id_medico,id_paciente',
                'cita.medico:id_usuario,codigo',
                'cita.medico.usuario:id,nombre,apellido',
                'cita.medico.especialidades:id,nombre',
                'recetas' => fn ($recetas) => $recetas->orderBy('id'),
                'recetas.detalles' => fn ($detalles) => $detalles->orderBy('id'),
            ])
            ->orderByDesc('fecha_atencion')
            ->orderByDesc('id')
            ->paginate($porPagina);

        $fechas = ConsultaMedica::query()->whereHas('cita', $deEstePaciente)
            ->selectRaw('count(*) total, min(fecha_atencion) primera, max(fecha_atencion) ultima, min(nro_expediente) expediente')
            ->first();

        return [
            'paciente' => $paciente,
            'antecedentes' => $this->antecedentes->listar($paciente),
            'consultas' => $consultas,
            'resumen' => [
                'expediente' => $fechas->expediente,
                'consultas' => (int) $fechas->total,
                'recetas' => RecetaMedica::query()->whereHas('consulta.cita', $deEstePaciente)->count(),
                'primera_atencion' => $fechas->primera,
                'ultima_atencion' => $fechas->ultima,
            ],
        ];
    }

    /**
     * RN04: cada apertura del historial queda en la bitácora (datos de salud).
     */
    public function registrarAcceso(Paciente $paciente, Usuario $usuario, string $como): void
    {
        $this->bitacora->registrar('ACCESO_HISTORIAL', 'paciente', $usuario->id, datosNuevos: [
            'paciente' => $paciente->codigo,
            'id_paciente' => $paciente->id_usuario,
            'acceso' => $como,
        ]);
    }

    /**
     * Paciente del usuario autenticado, para "Mi historial".
     *
     * @throws AccessDeniedHttpException si el usuario no es paciente.
     */
    public function pacienteDe(Usuario $usuario): Paciente
    {
        return Paciente::query()->find($usuario->id) ?? throw new AccessDeniedHttpException;
    }
}
