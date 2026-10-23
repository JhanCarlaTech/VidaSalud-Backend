<?php

namespace App\Modules\ModuloSeguridad\Repositories;

use App\Modules\ModuloSeguridad\Models\Permiso;
use App\Modules\ModuloSeguridad\Models\Rol;
use App\Modules\ModuloSeguridad\Models\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de usuarios, roles y permisos (todas parametrizadas vía Eloquent / Query Builder).
 */
class UsuarioRepository
{
    public function buscarPorCorreo(string $correo): ?Usuario
    {
        return Usuario::query()->where('correo', $correo)->first();
    }

    /**
     * Listado paginado con búsqueda por CI, nombre completo o correo y
     * filtros por rol y estado (CU02).
     *
     * @param  array{buscar?: ?string, rol?: ?int, activo?: ?bool}  $filtros
     */
    public function paginar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return Usuario::query()
            ->with('rolesActivos')
            ->withExists(['medico', 'paciente'])
            ->when($filtros['buscar'] ?? null, function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->where(function (Builder $query) use ($patron) {
                    $query->where('ci', 'ilike', $patron)
                        ->orWhere('correo', 'ilike', $patron)
                        ->orWhereRaw("(nombre || ' ' || apellido) ilike ?", [$patron]);
                });
            })
            ->when($filtros['rol'] ?? null, function (Builder $query, int $idRol) {
                $query->whereHas('rolesActivos', fn (Builder $roles) => $roles->where('rol.id', $idRol));
            })
            ->when(isset($filtros['activo']), fn (Builder $query) => $query->where('activo', $filtros['activo']))
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->paginate($porPagina);
    }

    /**
     * Deja activos exactamente los roles indicados (RN05): reactiva o inserta los
     * nuevos y desactiva los que se quitaron, sin borrar el historial.
     *
     * @param  array<int>  $idsRoles
     */
    public function sincronizarRoles(Usuario $usuario, array $idsRoles): void
    {
        $asignaciones = DB::table('rol_usuario')->where('id_usuario', $usuario->id);

        $activos = (clone $asignaciones)->where('activo', true)->pluck('id_rol')->all();

        foreach (array_diff($idsRoles, $activos) as $idRol) {
            DB::table('rol_usuario')->updateOrInsert(
                ['id_rol' => $idRol, 'id_usuario' => $usuario->id],
                ['activo' => true, 'fecha_asignacion' => now(), 'fecha_expiracion' => null],
            );
        }

        (clone $asignaciones)
            ->where('activo', true)
            ->whereNotIn('id_rol', $idsRoles)
            ->update(['activo' => false]);

        $usuario->unsetRelation('rolesActivos');
    }

    /**
     * Administradores activos con el rol vigente, sin contar al usuario indicado (RN07).
     */
    public function contarAdministradoresActivos(?int $excluirId = null): int
    {
        return Usuario::query()
            ->where('activo', true)
            ->when($excluirId, fn (Builder $query) => $query->where('id', '!=', $excluirId))
            ->whereHas('rolesActivos', fn (Builder $roles) => $roles->where('rol.nombre', Rol::ADMINISTRADOR))
            ->count();
    }

    /**
     * Cierra todas las sesiones abiertas del usuario (RN08).
     */
    public function cerrarSesiones(Usuario $usuario): void
    {
        DB::table('sessions')->where('user_id', $usuario->id)->delete();
    }

    /**
     * Acciones permitidas por los roles indicados, considerando solo
     * permisos activos y asignaciones vigentes (CU01, RN02).
     *
     * @param  array<int>  $idsRoles
     * @return Collection<int, string>
     */
    public function accionesPermitidas(array $idsRoles): Collection
    {
        if ($idsRoles === []) {
            return collect();
        }

        return Permiso::query()
            ->join('rol_permiso', 'rol_permiso.id_permiso', '=', 'permiso.id')
            ->whereIn('rol_permiso.id_rol', $idsRoles)
            ->where('rol_permiso.activo', true)
            ->where('permiso.activo', true)
            ->where(function ($query) {
                $query->whereNull('rol_permiso.fecha_expiracion')
                    ->orWhere('rol_permiso.fecha_expiracion', '>', now());
            })
            ->distinct()
            ->orderBy('permiso.accion')
            ->pluck('permiso.accion');
    }
}
