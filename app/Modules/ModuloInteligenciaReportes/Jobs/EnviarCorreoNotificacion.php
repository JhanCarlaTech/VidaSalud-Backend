<?php

namespace App\Modules\ModuloInteligenciaReportes\Jobs;

use App\Modules\ModuloInteligenciaReportes\Mail\NotificacionPacienteMail;
use App\Modules\ModuloInteligenciaReportes\Models\Notificacion;
use App\Modules\ModuloSeguridad\Models\PreferenciaNotificacion;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía por correo una notificación ya guardada y actualiza su estado (CU20, RN03).
 *
 * Se ejecuta después de responder al usuario (dispatchAfterResponse) para que agendar
 * o cancelar una cita no espere al servidor de correo, y sin necesitar 'queue:work'.
 * Un fallo del correo nunca deshace la operación: la notificación queda "fallido" y
 * se puede reenviar.
 */
class EnviarCorreoNotificacion
{
    use Dispatchable;

    public function __construct(public readonly int $idNotificacion) {}

    public function handle(): void
    {
        $notificacion = Notificacion::query()->with('paciente.usuario.preferenciaNotificacion')->find($this->idNotificacion);

        if (! $notificacion || $notificacion->estado_correo === Notificacion::CORREO_ENVIADO) {
            return;
        }

        $usuario = $notificacion->paciente->usuario;
        $quiereCorreo = $usuario->preferenciaNotificacion?->notificar_email ?? PreferenciaNotificacion::POR_DEFECTO['notificar_email'];

        // RN03: sin correo o con los correos desactivados, queda solo en la bandeja.
        if (! $quiereCorreo || ! $usuario->correo) {
            $notificacion->update(['estado_correo' => Notificacion::CORREO_OMITIDO, 'enviado' => false]);

            return;
        }

        try {
            Mail::to($usuario->correo)->send(new NotificacionPacienteMail($notificacion, $usuario->nombre));
            $notificacion->update(['estado_correo' => Notificacion::CORREO_ENVIADO, 'enviado' => true]);
        } catch (Throwable $error) {
            // No se registra el correo del paciente en el log (dato personal).
            Log::warning('No se pudo enviar la notificación por correo.', ['notificacion' => $notificacion->id, 'error' => $error->getMessage()]);
            $notificacion->update(['estado_correo' => Notificacion::CORREO_FALLIDO, 'enviado' => false]);
        }
    }
}
