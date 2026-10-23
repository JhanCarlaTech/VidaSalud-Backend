<?php

namespace App\Modules\ModuloSeguridad\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de auditoría (tabla 'bitacora').
 */
#[Table(name: 'bitacora', timestamps: false)]
#[Fillable(['id_usuario', 'accion', 'tabla_afectada', 'ip_origen', 'datos_anteriores', 'datos_nuevos'])]
class Bitacora extends Model
{
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
