<?php

namespace App\Modules\ModuloAtencionClinica\Services;

use App\Modules\ModuloAtencionClinica\Models\ConsultaMedica;
use App\Modules\ModuloAtencionClinica\Models\RecetaMedica;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU18 – Emitir Receta Médica.
 */
class RecetaService
{
    /** RN03: días desde la atención en los que todavía se puede emitir una receta. */
    public const DIAS_PARA_EMITIR = 7;

    /** Relaciones que muestra la receta impresa. */
    private const RELACIONES_RECETA = [
        'detalles',
        'consulta.cita.paciente.usuario',
        'consulta.cita.medico.usuario',
        'consulta.cita.medico.especialidades',
    ];

    public function __construct(
        private readonly BitacoraService $bitacora,
        private readonly AccesoClinicoService $acceso,
    ) {}

    /**
     * Consultas atendidas por el médico, de la más reciente a la más antigua, con la
     * cantidad de recetas emitidas en cada una (RN01: solo ve las suyas).
     */
    public function consultasDelMedico(Usuario $medico, ?string $buscar, int $porPagina): LengthAwarePaginator
    {
        return ConsultaMedica::query()
            ->with(['cita:id,codigo_cita,id_paciente,id_medico,fecha', 'cita.paciente:id_usuario,codigo,fecha_nacimiento', 'cita.paciente.usuario:id,ci,nombre,apellido'])
            ->withCount('recetas')
            ->whereHas('cita', fn (Builder $cita) => $cita->where('id_medico', $medico->id))
            ->when($buscar, function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('nro_expediente', 'ilike', $patron)
                    ->orWhereHas('cita.paciente', fn (Builder $paciente) => $paciente
                        ->where('codigo', 'ilike', $patron)
                        ->orWhereHas('usuario', fn (Builder $usuario) => $usuario
                            ->where('ci', 'ilike', $patron)
                            ->orWhereRaw("(nombre || ' ' || apellido) ilike ?", [$patron]))));
            })
            ->orderByDesc('fecha_atencion')
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    /**
     * Recetas ya emitidas en una consulta, para el médico que la atendió (RN01).
     *
     * @return Collection<int, RecetaMedica>
     *
     * @throws AccessDeniedHttpException
     */
    public function deConsulta(ConsultaMedica $consulta, Usuario $medico): Collection
    {
        if ((int) $consulta->cita()->value('id_medico') !== (int) $medico->id) {
            throw new AccessDeniedHttpException;
        }

        return $consulta->recetas()->with(self::RELACIONES_RECETA)->orderBy('id')->get();
    }

    /**
     * Emite una receta con sus medicamentos en una sola transacción (RN04).
     *
     * @throws ValidationException|AccessDeniedHttpException
     */
    public function emitir(ConsultaMedica $consulta, array $datos, Usuario $medico): RecetaMedica
    {
        return DB::transaction(function () use ($consulta, $datos, $medico) {
            // Bloquea la consulta: dos envíos simultáneos no deben duplicar la receta sin que el médico lo note.
            $consulta = ConsultaMedica::query()->with('cita')->lockForUpdate()->findOrFail($consulta->id);

            // RN01: solo el médico que atendió la consulta.
            if ((int) $consulta->cita->id_medico !== (int) $medico->id) {
                throw new AccessDeniedHttpException('Solo el médico que atendió la consulta puede emitir su receta.');
            }

            // RN03: la receta se emite poco después de la atención, no meses más tarde.
            if ($consulta->fecha_atencion->lt(now()->subDays(self::DIAS_PARA_EMITIR)->startOfDay())) {
                throw ValidationException::withMessages([
                    'consulta' => 'La consulta se atendió hace más de '.self::DIAS_PARA_EMITIR.' días: ya no se pueden emitir recetas para ella.',
                ]);
            }

            $receta = RecetaMedica::create([
                'id_consulta' => $consulta->id,
                'indicaciones_generales' => $datos['indicaciones_generales'] ?? null,
            ]);

            $receta->detalles()->createMany(array_map(fn (array $fila) => [
                'medicamento' => $fila['medicamento'],
                'dosis' => $fila['dosis'],
                'frecuencia' => $fila['frecuencia'],
                'duracion_dias' => (int) $fila['duracion_dias'],
            ], $datos['medicamentos']));

            $receta->refresh(); // Trae la fecha de emisión que asigna la BD.

            $this->bitacora->registrar('INSERT', 'receta_medica', $medico->id, datosNuevos: [
                'id' => $receta->id,
                'id_consulta' => $consulta->id,
                'expediente' => $consulta->nro_expediente,
                'medicamentos' => array_column($datos['medicamentos'], 'medicamento'),
            ]);

            return $receta->load(self::RELACIONES_RECETA);
        });
    }

    /**
     * Receta completa para verla o imprimirla. RN05: la ven el médico que la emitió, el
     * propio paciente y los médicos que lo atienden (CU19, regla D4); nadie la modifica
     * después de emitida (RN06).
     *
     * @throws AccessDeniedHttpException
     */
    /**
     * "Mis recetas" del paciente: todas las emitidas en sus consultas, la más reciente
     * primero. Se puede buscar por medicamento, médico o código de cita.
     */
    public function delPaciente(int $idPaciente, ?string $buscar, int $porPagina): LengthAwarePaginator
    {
        return RecetaMedica::query()
            ->with(self::RELACIONES_RECETA)
            ->whereHas('consulta.cita', fn (Builder $cita) => $cita->where('id_paciente', $idPaciente))
            ->when($buscar, function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '%_\\').'%';
                $query->where(fn (Builder $o) => $o
                    ->whereHas('detalles', fn (Builder $d) => $d->where('medicamento', 'ilike', $patron))
                    ->orWhereHas('consulta.cita', fn (Builder $c) => $c->where('codigo_cita', 'ilike', $patron)
                        ->orWhereHas('medico.usuario', fn (Builder $u) => $u->whereRaw("(nombre || ' ' || apellido) ilike ?", [$patron]))));
            })
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    public function detalle(RecetaMedica $receta, Usuario $usuario): RecetaMedica
    {
        $receta->load(self::RELACIONES_RECETA);
        $cita = $receta->consulta->cita;

        $permitido = (int) $usuario->id === (int) $cita->id_medico
            || (int) $usuario->id === (int) $cita->id_paciente
            || $this->acceso->medicoAtiendeAPaciente($usuario->id, $cita->id_paciente);

        if (! $permitido) {
            throw new AccessDeniedHttpException;
        }

        return $receta;
    }
}
