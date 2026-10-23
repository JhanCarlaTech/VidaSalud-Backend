<?php

namespace App\Modules\ModuloSeguridad\Services;

use App\Modules\ModuloSeguridad\Models\Rol;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Repositories\UsuarioRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU02 – Gestionar Usuarios y Asignar Roles.
 */
class UsuarioService
{
    /** Campos de 'usuario' editables desde este caso de uso. */
    private const CAMPOS_EDITABLES = ['ci', 'nombre', 'apellido', 'correo', 'telefono', 'sexo'];

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly BitacoraService $bitacora,
    ) {}

    public function listar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->usuarios->paginar($filtros, $porPagina);
    }

    /**
     * Carga los datos que muestra el detalle: roles activos y extensiones.
     */
    public function detalle(Usuario $usuario): Usuario
    {
        return $usuario->load('rolesActivos')->loadExists(['medico', 'paciente']);
    }

    /**
     * @throws ValidationException
     */
    public function crear(array $datos, Usuario $actor): Usuario
    {
        $this->validarRolesAsignables(null, $datos['roles']);

        return DB::transaction(function () use ($datos, $actor) {
            $usuario = Usuario::create([
                ...Arr::only($datos, self::CAMPOS_EDITABLES),
                'contrasena' => $datos['contrasena'], // El cast 'hashed' aplica BCRYPT.
            ]);
            $usuario->refresh(); // Trae los valores por defecto de la BD (activo, fecha_creacion).

            $this->usuarios->sincronizarRoles($usuario, $datos['roles']);
            $this->bitacora->registrar('INSERT', 'usuario', $actor->id, datosNuevos: $this->instantanea($usuario));

            return $this->detalle($usuario);
        });
    }

    /**
     * @throws ValidationException
     */
    public function actualizar(Usuario $usuario, array $datos, Usuario $actor): Usuario
    {
        $this->validarRolesAsignables($usuario, $datos['roles']);
        $this->validarQueConservaAdministracion($usuario, $datos['roles'], $actor);

        return DB::transaction(function () use ($usuario, $datos, $actor) {
            $antes = $this->instantanea($usuario);

            $usuario->fill(Arr::only($datos, self::CAMPOS_EDITABLES));

            if (! empty($datos['contrasena'])) {
                $usuario->contrasena = $datos['contrasena'];
            }

            $usuario->save();
            $this->usuarios->sincronizarRoles($usuario, $datos['roles']);

            $despues = $this->instantanea($usuario);
            if (! empty($datos['contrasena'])) {
                $despues['contrasena'] = 'restablecida';
            }

            $this->bitacora->registrar('UPDATE', 'usuario', $actor->id, $antes, $despues);

            return $this->detalle($usuario);
        });
    }

    /**
     * Activa o desactiva la cuenta. Al desactivar se cierran sus sesiones (RN08).
     *
     * @throws ValidationException
     */
    public function cambiarEstado(Usuario $usuario, bool $activo, Usuario $actor): Usuario
    {
        if (! $activo) {
            if ($usuario->id === $actor->id) {
                throw ValidationException::withMessages(['activo' => 'No puede desactivar su propia cuenta.']);
            }

            if ($this->esAdministrador($usuario) && $this->usuarios->contarAdministradoresActivos($usuario->id) === 0) {
                throw ValidationException::withMessages(['activo' => 'Debe existir al menos un administrador activo.']);
            }
        }

        if ($usuario->activo === $activo) {
            return $this->detalle($usuario);
        }

        return DB::transaction(function () use ($usuario, $activo, $actor) {
            $usuario->update(['activo' => $activo]);

            if (! $activo) {
                $this->usuarios->cerrarSesiones($usuario);
            }

            $this->bitacora->registrar('UPDATE', 'usuario', $actor->id, ['id' => $usuario->id, 'activo' => ! $activo], ['id' => $usuario->id, 'activo' => $activo]);

            return $this->detalle($usuario);
        });
    }

    /**
     * RN04: los roles Médico y Paciente exigen su registro de extensión.
     *
     * @param  array<int>  $idsRoles
     *
     * @throws ValidationException
     */
    private function validarRolesAsignables(?Usuario $usuario, array $idsRoles): void
    {
        $nombres = Rol::query()->whereIn('id', $idsRoles)->pluck('nombre');

        if ($nombres->contains(Rol::MEDICO) && ! $usuario?->medico()->exists()) {
            throw ValidationException::withMessages([
                'roles' => 'El rol Médico solo se asigna a usuarios registrados como médicos (Gestionar Personal Médico).',
            ]);
        }

        if ($nombres->contains(Rol::PACIENTE) && ! $usuario?->paciente()->exists()) {
            throw ValidationException::withMessages([
                'roles' => 'El rol Paciente solo se asigna a usuarios registrados como pacientes (Gestionar Pacientes).',
            ]);
        }
    }

    /**
     * RN06 y RN07: nadie puede quitarse su propio rol de Administrador ni dejar
     * al sistema sin administradores activos.
     *
     * @param  array<int>  $idsRoles
     *
     * @throws ValidationException
     */
    private function validarQueConservaAdministracion(Usuario $usuario, array $idsRoles, Usuario $actor): void
    {
        $idRolAdmin = (int) Rol::query()->where('nombre', Rol::ADMINISTRADOR)->value('id');
        $quitaAdministrador = ! in_array($idRolAdmin, array_map('intval', $idsRoles), true)
            && $this->esAdministrador($usuario);

        if (! $quitaAdministrador) {
            return;
        }

        if ($usuario->id === $actor->id) {
            throw ValidationException::withMessages(['roles' => 'No puede quitarse su propio rol de Administrador.']);
        }

        if ($usuario->activo && $this->usuarios->contarAdministradoresActivos($usuario->id) === 0) {
            throw ValidationException::withMessages(['roles' => 'Debe existir al menos un administrador activo.']);
        }
    }

    private function esAdministrador(Usuario $usuario): bool
    {
        return $usuario->rolesActivos()->where('rol.nombre', Rol::ADMINISTRADOR)->exists();
    }

    /**
     * Estado del usuario para la bitácora (RN09). Nunca incluye la contraseña.
     */
    private function instantanea(Usuario $usuario): array
    {
        return [
            ...Arr::only($usuario->attributesToArray(), ['id', ...self::CAMPOS_EDITABLES, 'activo']),
            'roles' => $usuario->rolesActivos()->pluck('nombre')->all(),
        ];
    }
}
