<?php

namespace App\Modules\ModuloSeguridad\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Registro de auditoría (tabla 'bitacora').
 *
 * Es de solo inserción (CU06, RN01): el modelo rechaza cualquier intento de
 * modificar o eliminar un evento ya registrado.
 */
#[Table(name: 'bitacora', timestamps: false)]
#[Fillable(['id_usuario', 'accion', 'tabla_afectada', 'ip_origen', 'datos_anteriores', 'datos_nuevos'])]
class Bitacora extends Model
{
    protected static function booted(): void
    {
        $rechazar = fn () => throw new LogicException('Los eventos de la bitácora no se pueden modificar ni eliminar.');

        static::updating($rechazar);
        static::deleting($rechazar);
    }

    protected function casts(): array
    {
        return [
            'datos_anteriores' => 'array',
            'datos_nuevos' => 'array',
            'fecha_registro' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
}
