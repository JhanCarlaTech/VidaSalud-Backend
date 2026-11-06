<?php

namespace App\Modules\ModuloPacientes\Services;

use App\Modules\ModuloPacientes\Models\Enfermedad;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU12 – Antecedentes patológicos del paciente (tabla 'paciente_enfermedad').
 */
class AntecedenteService
{
    /** Resultados máximos de la búsqueda de pacientes. */
    private const MAXIMO_BUSQUEDA = 10;

    public function __construct(private readonly BitacoraService $bitacora) {}

    /**
     * Búsqueda de pacientes por CI, código o nombre para elegir a quién registrar
     * antecedentes. Devuelve solo los datos de identificación (RN06).
     *
     * @return Collection<int, Paciente>
     */
    public function buscarPacientes(string $termino): Collection
    {
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return Paciente::query()
            ->with('usuario:id,ci,nombre,apellido')
            ->whereHas('usuario', fn (Builder $usuario) => $usuario->where(fn (Builder $usuario) => $usuario
                ->where('ci', 'ilike', $patron)
                ->orWhereRaw("(nombre || ' ' || apellido) ilike ?", [$patron])))
            ->orWhere('codigo', 'ilike', $patron)
            ->orderBy('codigo')
            ->limit(self::MAXIMO_BUSQUEDA)
            ->get();
    }

    /**
     * Antecedentes del paciente, del diagnóstico más reciente al más antiguo
     * (los que no tienen fecha, al final).
     *
     * @return Collection<int, Enfermedad>
     */
    public function listar(Paciente $paciente): Collection
    {
        return $paciente->enfermedades()
            ->orderByRaw('paciente_enfermedad.fecha_diagnostico desc nulls last')
            ->orderBy('enfermedad.nombre')
            ->get();
    }

    /**
     * @throws ValidationException
     */
    public function registrar(Paciente $paciente, array $datos, Usuario $actor): Enfermedad
    {
        $enfermedad = Enfermedad::query()->find($datos['id_enfermedad'])
            ?? throw ValidationException::withMessages(['id_enfermedad' => 'La enfermedad seleccionada no existe.']);

        // RN03: una enfermedad inactiva no se registra en antecedentes nuevos.
        if (! $enfermedad->activo) {
            throw ValidationException::withMessages(['id_enfermedad' => 'La enfermedad está inactiva en el catálogo.']);
        }

        $this->validarFecha($paciente, $datos['fecha_diagnostico'] ?? null);

        return DB::transaction(function () use ($paciente, $enfermedad, $datos, $actor) {
            // RN04: una enfermedad figura una sola vez por paciente. Se bloquea la fila del
            // paciente para que dos registros simultáneos no choquen con la clave primaria.
            Paciente::query()->whereKey($paciente->id_usuario)->lockForUpdate()->first();

            if ($paciente->enfermedades()->whereKey($enfermedad->id)->exists()) {
                throw ValidationException::withMessages([
                    'id_enfermedad' => "{$enfermedad->nombre} ya figura en los antecedentes del paciente: edite el registro existente.",
                ]);
            }

            $pivote = $this->pivote($datos);
            $paciente->enfermedades()->attach($enfermedad->id, $pivote);

            $this->bitacora->registrar('INSERT', 'paciente_enfermedad', $actor->id,
                datosNuevos: $this->instantanea($paciente, $enfermedad, $pivote));

            return $this->antecedente($paciente, $enfermedad);
        });
    }

    /**
     * @throws ValidationException
     */
    public function actualizar(Paciente $paciente, Enfermedad $enfermedad, array $datos, Usuario $actor): Enfermedad
    {
        $actual = $this->antecedente($paciente, $enfermedad);
        $this->validarFecha($paciente, $datos['fecha_diagnostico'] ?? null);

        return DB::transaction(function () use ($paciente, $enfermedad, $actual, $datos, $actor) {
            $antes = $this->instantanea($paciente, $enfermedad, $this->pivoteActual($actual));
            $pivote = $this->pivote($datos);

            $paciente->enfermedades()->updateExistingPivot($enfermedad->id, $pivote);

            $this->bitacora->registrar('UPDATE', 'paciente_enfermedad', $actor->id, $antes, $this->instantanea($paciente, $enfermedad, $pivote));

            return $this->antecedente($paciente, $enfermedad);
        });
    }

    /**
     * Quita un antecedente registrado por error. El registro queda en la bitácora.
     */
    public function eliminar(Paciente $paciente, Enfermedad $enfermedad, Usuario $actor): void
    {
        $actual = $this->antecedente($paciente, $enfermedad);

        DB::transaction(function () use ($paciente, $enfermedad, $actual, $actor) {
            $paciente->enfermedades()->detach($enfermedad->id);

            $this->bitacora->registrar('DELETE', 'paciente_enfermedad', $actor->id,
                datosAnteriores: $this->instantanea($paciente, $enfermedad, $this->pivoteActual($actual)));
        });
    }

    /**
     * Antecedente con los datos del pivote, o 404 si el paciente no lo tiene.
     */
    private function antecedente(Paciente $paciente, Enfermedad $enfermedad): Enfermedad
    {
        return $paciente->enfermedades()->whereKey($enfermedad->id)->firstOrFail();
    }

    /**
     * RN04: el diagnóstico no puede ser anterior al nacimiento ni futuro (lo último lo valida la petición).
     *
     * @throws ValidationException
     */
    private function validarFecha(Paciente $paciente, ?string $fecha): void
    {
        if ($fecha !== null && $paciente->fecha_nacimiento && $fecha < $paciente->fecha_nacimiento->toDateString()) {
            throw ValidationException::withMessages([
                'fecha_diagnostico' => 'La fecha de diagnóstico no puede ser anterior al nacimiento del paciente ('.$paciente->fecha_nacimiento->format('d/m/Y').').',
            ]);
        }
    }

    private function pivote(array $datos): array
    {
        return [
            'fecha_diagnostico' => $datos['fecha_diagnostico'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
        ];
    }

    private function pivoteActual(Enfermedad $antecedente): array
    {
        return [
            'fecha_diagnostico' => $antecedente->pivot->fecha_diagnostico,
            'observaciones' => $antecedente->pivot->observaciones,
        ];
    }

    /** Datos del antecedente para la bitácora (RN07). */
    private function instantanea(Paciente $paciente, Enfermedad $enfermedad, array $pivote): array
    {
        return [
            'paciente' => $paciente->codigo,
            'id_paciente' => $paciente->id_usuario,
            'id_enfermedad' => $enfermedad->id,
            'enfermedad' => $enfermedad->nombre,
            ...$pivote,
        ];
    }
}
