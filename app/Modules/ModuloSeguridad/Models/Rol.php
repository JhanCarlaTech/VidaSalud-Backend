<?php

namespace App\Modules\ModuloSeguridad\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Rol del sistema (tabla 'rol').
 */
#[Table(name: 'rol', timestamps: false)]
#[Fillable(['nombre', 'descripcion', 'activo'])]
class Rol extends Model
{
    public const ADMINISTRADOR = 'Administrador';

    public const MEDICO = 'Medico';

    public const RECEPCIONISTA = 'Recepcionista';

    public const PACIENTE = 'Paciente';

    /** Roles de los que depende la lógica del sistema: no se renombran ni desactivan (CU03, RN02). */
    public const ROLES_SISTEMA = [self::ADMINISTRADOR, self::MEDICO, self::RECEPCIONISTA, self::PACIENTE];

    /** Permisos que el Administrador debe conservar siempre (CU03, RN03). */
    public const PERMISOS_OBLIGATORIOS_ADMIN = ['usuarios_gestionar', 'roles_gestionar'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'fecha_creacion' => 'datetime',
        ];
    }

    public function esDelSistema(): bool
    {
        return in_array($this->nombre, self::ROLES_SISTEMA, true);
    }

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'rol_permiso', 'id_rol', 'id_permiso')
            ->withPivot(['fecha_asignacion', 'fecha_expiracion', 'activo']);
    }

    /**
     * Permisos activos y vigentes del rol.
     */
    public function permisosActivos(): BelongsToMany
    {
        return $this->permisos()
            ->wherePivot('activo', true)
            ->where('permiso.activo', true)
            ->where(function ($query) {
                $query->whereNull('rol_permiso.fecha_expiracion')
                    ->orWhere('rol_permiso.fecha_expiracion', '>', now());
            });
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'rol_usuario', 'id_rol', 'id_usuario')
            ->withPivot(['fecha_asignacion', 'fecha_expiracion', 'activo']);
    }
}
