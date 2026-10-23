<?php

namespace App\Modules\ModuloSeguridad\Services;

use App\Modules\ModuloSeguridad\Models\Permiso;
use App\Modules\ModuloSeguridad\Models\Rol;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Repositories\RolRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU03 – Gestionar Roles y Asignar Permisos.
 */
class RolService
{
    public function __construct(
        private readonly RolRepository $roles,
        private readonly BitacoraService $bitacora,
    ) {}

    public function listar(bool $soloActivos): Collection
    {
        return $this->roles->listar($soloActivos);
    }

    public function crear(array $datos, Usuario $actor): Rol
    {
        return DB::transaction(function () use ($datos, $actor) {
            $rol = Rol::create(['nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion']]);
            $rol->refresh(); // Trae los valores por defecto de la BD (activo, fecha_creacion).

            $this->roles->sincronizarPermisos($rol, $datos['permisos']);
            $this->bitacora->registrar('INSERT', 'rol', $actor->id, datosNuevos: $this->instantanea($rol));

            return $this->roles->cargarDetalle($rol);
        });
    }

    /**
     * @throws ValidationException
     */
    public function actualizar(Rol $rol, array $datos, Usuario $actor): Rol
    {
        if ($rol->esDelSistema() && $datos['nombre'] !== $rol->nombre) {
            throw ValidationException::withMessages(['nombre' => 'Los roles del sistema no se pueden renombrar.']);
        }

        if ($rol->nombre === Rol::ADMINISTRADOR) {
            $this->validarPermisosObligatoriosAdmin($datos['permisos']);
        }

        return DB::transaction(function () use ($rol, $datos, $actor) {
            $antes = $this->instantanea($rol);

            $rol->update(['nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion']]);
            $this->roles->sincronizarPermisos($rol, $datos['permisos']);

            $this->bitacora->registrar('UPDATE', 'rol', $actor->id, $antes, $this->instantanea($rol));

            return $this->roles->cargarDetalle($rol);
        });
    }

    /**
     * @throws ValidationException
     */
    public function cambiarEstado(Rol $rol, bool $activo, Usuario $actor): Rol
    {
        if (! $activo && $rol->esDelSistema()) {
            throw ValidationException::withMessages(['activo' => 'Los roles del sistema no se pueden desactivar.']);
        }

        if ($rol->activo === $activo) {
            return $this->roles->cargarDetalle($rol);
        }

        return DB::transaction(function () use ($rol, $activo, $actor) {
            $rol->update(['activo' => $activo]);

            $this->bitacora->registrar('UPDATE', 'rol', $actor->id, ['id' => $rol->id, 'activo' => ! $activo], ['id' => $rol->id, 'activo' => $activo]);

            return $this->roles->cargarDetalle($rol);
        });
    }

    /**
     * RN03: el Administrador debe conservar los permisos de administración.
     *
     * @param  array<int>  $idsPermisos
     *
     * @throws ValidationException
     */
    private function validarPermisosObligatoriosAdmin(array $idsPermisos): void
    {
        $obligatorios = Permiso::query()
            ->whereIn('accion', Rol::PERMISOS_OBLIGATORIOS_ADMIN)
            ->pluck('id', 'accion');

        $faltantes = $obligatorios->reject(fn ($id) => in_array($id, array_map('intval', $idsPermisos), true));

        if ($faltantes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permisos' => 'El rol Administrador debe conservar los permisos: '.$faltantes->keys()->implode(', ').'.',
            ]);
        }
    }

    /**
     * Estado del rol para la bitácora (RN07).
     */
    private function instantanea(Rol $rol): array
    {
        return [
            'id' => $rol->id,
            'nombre' => $rol->nombre,
            'descripcion' => $rol->descripcion,
            'activo' => $rol->activo,
            'permisos' => $rol->permisosActivos()->orderBy('permiso.accion')->pluck('accion')->all(),
        ];
    }
}
