<?php

namespace App\Modules\ModuloPersonalMedico\Services;

use App\Modules\ModuloPersonalMedico\Models\Especialidad;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * CU07 – Gestionar Especialidades Médicas.
 */
class EspecialidadService
{
    public function __construct(private readonly BitacoraService $bitacora) {}

    /**
     * Catálogo ordenado por nombre, con búsqueda y filtro por estado.
     * El catálogo es pequeño, por eso no se pagina.
     */
    public function listar(?string $buscar, ?bool $activo): Collection
    {
        return $this->consultaConMedicos()
            ->when($buscar, function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('nombre', 'ilike', $patron)
                    ->orWhere('descripcion', 'ilike', $patron));
            })
            ->when($activo !== null, fn (Builder $query) => $query->where('activo', $activo))
            ->orderBy('nombre')
            ->get();
    }

    public function crear(array $datos, Usuario $actor): Especialidad
    {
        return DB::transaction(function () use ($datos, $actor) {
            $especialidad = Especialidad::create(Arr::only($datos, ['nombre', 'descripcion']));
            $especialidad->refresh(); // Trae 'activo' por defecto de la BD.

            $this->bitacora->registrar('INSERT', 'especialidad', $actor->id, datosNuevos: $this->instantanea($especialidad));

            return $this->detalle($especialidad);
        });
    }

    public function actualizar(Especialidad $especialidad, array $datos, Usuario $actor): Especialidad
    {
        return DB::transaction(function () use ($especialidad, $datos, $actor) {
            $antes = $this->instantanea($especialidad);

            $especialidad->update(Arr::only($datos, ['nombre', 'descripcion']));

            $this->bitacora->registrar('UPDATE', 'especialidad', $actor->id, $antes, $this->instantanea($especialidad));

            return $this->detalle($especialidad);
        });
    }

    /**
     * RN02/RN03: desactivar no borra la especialidad ni la quita a los médicos que la tienen.
     */
    public function cambiarEstado(Especialidad $especialidad, bool $activo, Usuario $actor): Especialidad
    {
        if ($especialidad->activo === $activo) {
            return $this->detalle($especialidad);
        }

        return DB::transaction(function () use ($especialidad, $activo, $actor) {
            $especialidad->update(['activo' => $activo]);

            $this->bitacora->registrar(
                'UPDATE', 'especialidad', $actor->id,
                ['id' => $especialidad->id, 'activo' => ! $activo],
                ['id' => $especialidad->id, 'activo' => $activo],
            );

            return $this->detalle($especialidad);
        });
    }

    private function detalle(Especialidad $especialidad): Especialidad
    {
        return $this->consultaConMedicos()->findOrFail($especialidad->id);
    }

    /**
     * Incluye los médicos (con su nombre) y el conteo de médicos activos (RN04).
     */
    private function consultaConMedicos(): Builder
    {
        return Especialidad::query()
            ->with(['medicos.usuario:id,nombre,apellido'])
            ->withCount(['medicos' => fn (Builder $query) => $query->where('medico.activo', true)]);
    }

    /**
     * Datos de la especialidad para la bitácora (RN06).
     */
    private function instantanea(Especialidad $especialidad): array
    {
        return Arr::only($especialidad->attributesToArray(), ['id', 'nombre', 'descripcion', 'activo']);
    }
}
