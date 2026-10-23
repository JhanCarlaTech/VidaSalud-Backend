<?php

namespace App\Modules\ModuloSeguridad\Models;

use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloPersonalMedico\Models\Medico;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usuario autenticable del sistema (tabla 'usuario').
 */
#[Table(name: 'usuario', timestamps: false)]
#[Fillable(['ci', 'nombre', 'apellido', 'correo', 'contrasena', 'telefono', 'sexo', 'activo'])]
#[Hidden(['contrasena'])]
class Usuario extends Authenticatable
{
    /**
     * Columna que guarda el hash BCRYPT de la contraseña.
     */
    protected $authPasswordName = 'contrasena';

    /**
     * La tabla no tiene columna 'remember_token'.
     */
    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return [
            'contrasena' => 'hashed',
            'activo' => 'boolean',
            'fecha_creacion' => 'datetime',
        ];
    }

    /**
     * Roles asignados al usuario, incluidos los inactivos o expirados.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'rol_usuario', 'id_usuario', 'id_rol')
            ->withPivot(['fecha_asignacion', 'fecha_expiracion', 'activo']);
    }

    /**
     * Roles activos y vigentes: asignación activa, rol activo y sin expirar (RN02).
     */
    public function rolesActivos(): BelongsToMany
    {
        return $this->roles()
            ->wherePivot('activo', true)
            ->where('rol.activo', true)
            ->where(function ($query) {
                $query->whereNull('rol_usuario.fecha_expiracion')
                    ->orWhere('rol_usuario.fecha_expiracion', '>', now());
            });
    }

    public function medico(): HasOne
    {
        return $this->hasOne(Medico::class, 'id_usuario');
    }

    public function paciente(): HasOne
    {
        return $this->hasOne(Paciente::class, 'id_usuario');
    }

    public function preferenciaNotificacion(): HasOne
    {
        return $this->hasOne(PreferenciaNotificacion::class, 'id_usuario');
    }
}
