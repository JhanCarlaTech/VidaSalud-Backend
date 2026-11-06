<?php

namespace App\Modules\ModuloPacientes\Services;

use App\Modules\ModuloPacientes\Models\Enfermedad;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * CU12 – Catálogo de enfermedades.
 */
class EnfermedadService
{
    private const CAMPOS = ['nombre', 'descripcion', 'nivel_riesgo'];

    public function __construct(private readonly BitacoraService $bitacora) {}

    /**
     * Catálogo ordenado por nombre con búsqueda y filtros (RN05). Es pequeño, por eso
     * no se pagina. Incluye cuántos pacientes tienen cada enfermedad como antecedente.
     *
     * @param  array{buscar: ?string, activo: ?bool, nivel_riesgo: ?string}  $filtros
     */
    public function listar(array $filtros): Collection
    {
        return Enfermedad::query()
            ->withCount('pacientes')
            ->when($filtros['buscar'], function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('nombre', 'ilike', $patron)
                    ->orWhere('descripcion', 'ilike', $patron));
            })
            ->when($filtros['activo'] !== null, fn (Builder $query) => $query->where('activo', $filtros['activo']))
            ->when($filtros['nivel_riesgo'], fn (Builder $query, string $nivel) => $query->where('nivel_riesgo', $nivel))
            ->orderBy('nombre')
            ->get();
    }

    public function crear(array $datos, Usuario $actor): Enfermedad
    {
        return DB::transaction(function () use ($datos, $actor) {
            $enfermedad = Enfermedad::create(Arr::only($datos, self::CAMPOS));
            $enfermedad->refresh(); // Trae 'activo' por defecto de la BD.

            $this->bitacora->registrar('INSERT', 'enfermedad', $actor->id, datosNuevos: $this->instantanea($enfermedad));

            return $this->detalle($enfermedad);
        });
    }

    public function actualizar(Enfermedad $enfermedad, array $datos, Usuario $actor): Enfermedad
    {
        return DB::transaction(function () use ($enfermedad, $datos, $actor) {
            $antes = $this->instantanea($enfermedad);
            $enfermedad->update(Arr::only($datos, self::CAMPOS));

            $this->bitacora->registrar('UPDATE', 'enfermedad', $actor->id, $antes, $this->instantanea($enfermedad));

            return $this->detalle($enfermedad);
        });
    }

    /**
     * RN03: no hay borrado físico. Desactivar solo impide registrarla en antecedentes
     * nuevos; los pacientes que ya la tienen la conservan.
     */
    public function cambiarEstado(Enfermedad $enfermedad, bool $activo, Usuario $actor): Enfermedad
    {
        if ($enfermedad->activo === $activo) {
            return $this->detalle($enfermedad);
        }

        return DB::transaction(function () use ($enfermedad, $activo, $actor) {
            $enfermedad->update(['activo' => $activo]);

            $this->bitacora->registrar(
                'UPDATE', 'enfermedad', $actor->id,
                ['id' => $enfermedad->id, 'activo' => ! $activo],
                ['id' => $enfermedad->id, 'activo' => $activo],
            );

            return $this->detalle($enfermedad);
        });
    }

    private function detalle(Enfermedad $enfermedad): Enfermedad
    {
        return Enfermedad::query()->withCount('pacientes')->findOrFail($enfermedad->id);
    }

    /** Datos de la enfermedad para la bitácora (RN07). */
    private function instantanea(Enfermedad $enfermedad): array
    {
        return Arr::only($enfermedad->attributesToArray(), ['id', ...self::CAMPOS, 'activo']);
    }
}
