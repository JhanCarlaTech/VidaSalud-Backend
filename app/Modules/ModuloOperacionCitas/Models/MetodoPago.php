<?php

namespace App\Modules\ModuloOperacionCitas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Método de pago de la pasarela (tabla 'metodo_pago', CU24).
 */
#[Table(name: 'metodo_pago', timestamps: false)]
#[Fillable(['nombre', 'descripcion', 'activo'])]
class MetodoPago extends Model
{
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }
}
