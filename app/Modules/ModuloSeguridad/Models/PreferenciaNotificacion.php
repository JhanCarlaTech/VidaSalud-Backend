<?php

namespace App\Modules\ModuloSeguridad\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Preferencias de notificación de un usuario (tabla 'preferencia_notificacion', CU05).
 */
#[Table(name: 'preferencia_notificacion', key: 'id_usuario', incrementing: false, timestamps: false)]
#[Fillable(['id_usuario', 'notificar_email', 'notificar_push', 'horas_anticipacion', 'fecha_actualizacion'])]
class PreferenciaNotificacion extends Model
{
    /** Anticipación permitida para los recordatorios, en horas (RN06). */
    public const HORAS_PERMITIDAS = [2, 12, 24, 48];

    /** Valores que se usan mientras el usuario no configure sus preferencias (RN06). */
    public const POR_DEFECTO = [
        'notificar_email' => true,
        'notificar_push' => true,
        'horas_anticipacion' => 24,
    ];

    protected function casts(): array
    {
        return [
            'notificar_email' => 'boolean',
            'notificar_push' => 'boolean',
            'horas_anticipacion' => 'integer',
            'fecha_actualizacion' => 'datetime',
        ];
    }
}
