<?php

namespace App\Modules\ModuloInteligenciaReportes\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Navegador o dispositivo suscrito a las notificaciones push de un usuario (CU20).
 */
#[Table(name: 'suscripcion_push', timestamps: false)]
#[Fillable(['id_usuario', 'endpoint', 'clave_p256dh', 'clave_auth', 'codificacion', 'agente', 'fecha_ultimo_envio'])]
class SuscripcionPush extends Model
{
    /** Codificaciones de contenido que admite la API Push (la moderna primero). */
    public const CODIFICACIONES = ['aes128gcm', 'aesgcm'];

    protected function casts(): array
    {
        return [
            'fecha_creacion' => 'datetime',
            'fecha_ultimo_envio' => 'datetime',
        ];
    }
}
