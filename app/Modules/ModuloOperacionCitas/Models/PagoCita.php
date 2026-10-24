<?php

namespace App\Modules\ModuloOperacionCitas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(name: 'pago_cita', timestamps: false)]
#[Fillable(['id_cita', 'id_metodo_pago', 'codigo_transaccion', 'monto', 'moneda', 'estado', 'detalles_pasarela'])]
class PagoCita extends Model
{
    protected function casts(): array
    {
        return [
            'detalles_pasarela' => 'array', // Laravel maneja el JSONB como array automáticamente
            'fecha_pago' => 'datetime',
        ];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(CitaMedica::class, 'id_cita');
    }

    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'id_metodo_pago');
    }
}