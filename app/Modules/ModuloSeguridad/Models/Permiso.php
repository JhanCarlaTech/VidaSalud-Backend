<?php

namespace App\Modules\ModuloSeguridad\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Permiso granular identificado por su 'accion' (tabla 'permiso').
 */
#[Table(name: 'permiso', timestamps: false)]
#[Fillable(['accion', 'descripcion', 'activo'])]
class Permiso extends Model
{
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'fecha_creacion' => 'datetime',
        ];
    }
}
