<?php

namespace App\Modules\ModuloSeguridad\Repositories;

use App\Modules\ModuloSeguridad\Models\Rol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de roles y de su asignación de permisos (CU03).
 */
class RolRepository
{
    /**
     * Roles con sus permisos activos y la cantidad de usuarios que los tienen.
     */
    public function listar(bool $soloActivos): Collection
    {
        return Rol::query()
            ->when($soloActivos, fn (Builder $query) => $query->where('activo', true))
            ->with(['permisosActivos' => fn ($query) => $query->orderBy('permiso.accion')])
            ->withCount($this->conteoUsuarios())
            ->orderBy('id')
            ->get();
    }

    public function cargarDetalle(Rol $rol): Rol
    {
        return $rol
            ->load(['permisosActivos' => fn ($query) => $query->orderBy('permiso.accion')])
            ->loadCount($this->conteoUsuarios());
    }

    /**
     * Deja activos exactamente los permisos indicados (RN05): reactiva o inserta
     * los nuevos y desactiva los que se quitaron, sin borrar el historial.
     *
     * @param  array<int>  $idsPermisos
     */
    public function sincronizarPermisos(Rol $rol, array $idsPermisos): void
    {
        $asignaciones = DB::table('rol_permiso')->where('id_rol', $rol->id);

        $activos = (clone $asignaciones)->where('activo', true)->pluck('id_permiso')->all();

        foreach (array_diff($idsPermisos, $activos) as $idPermiso) {
            DB::table('rol_permiso')->updateOrInsert(
                ['id_rol' => $rol->id, 'id_permiso' => $idPermiso],
                ['activo' => true, 'fecha_asignacion' => now(), 'fecha_expiracion' => null],
            );
        }

        (clone $asignaciones)
            ->where('activo', true)
            ->whereNotIn('id_permiso', $idsPermisos)
            ->update(['activo' => false]);

        $rol->unsetRelation('permisosActivos');
    }

    /**
     * Usuarios activos con la asignación del rol activa.
     */
    private function conteoUsuarios(): array
    {
        return [
            'usuarios' => fn (Builder $query) => $query
                ->where('rol_usuario.activo', true)
                ->where('usuario.activo', true),
        ];
    }
}
