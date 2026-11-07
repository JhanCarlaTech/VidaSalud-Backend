<?php

namespace App\Modules\ModuloInteligenciaReportes\Jobs;

use App\Modules\ModuloInteligenciaReportes\Models\Notificacion;
use App\Modules\ModuloInteligenciaReportes\Services\PushService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Envía como notificación push (al celular o la computadora del paciente) una
 * notificación ya guardada (CU20). Igual que el correo, se ejecuta después de
 * responder y un fallo nunca afecta a la operación que la originó.
 */
class EnviarPushNotificacion
{
    use Dispatchable;

    /** Página que se abre al tocar el aviso (bandeja del paciente en el frontend). */
    private const URL_BANDEJA = '/paciente/notificaciones';

    public function __construct(public readonly int $idNotificacion) {}

    public function handle(PushService $push): void
    {
        $notificacion = Notificacion::query()->with('paciente')->find($this->idNotificacion);
        if (! $notificacion) {
            return;
        }

        $push->enviarAUsuario($notificacion->paciente->id_usuario, [
            'titulo' => Notificacion::TITULOS[$notificacion->tipo] ?? 'Clínica Vida Salud',
            'cuerpo' => $notificacion->mensaje,
            'url' => self::URL_BANDEJA,
            // La misma cita reemplaza su aviso anterior en lugar de apilar varios.
            'etiqueta' => $notificacion->id_cita ? "cita-{$notificacion->id_cita}" : "notificacion-{$notificacion->id}",
        ]);
    }
}
