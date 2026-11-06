<?php

namespace App\Modules\ModuloInteligenciaReportes\Mail;

use App\Modules\ModuloInteligenciaReportes\Models\Notificacion;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de una notificación al paciente (CU20). El transporte lo define MAIL_MAILER:
 * 'log' en desarrollo (el correo queda en storage/logs) o 'smtp' en producción (D5).
 */
class NotificacionPacienteMail extends Mailable
{
    public function __construct(
        public readonly Notificacion $notificacion,
        public readonly string $nombrePaciente,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: Notificacion::TITULOS[$this->notificacion->tipo].' – '.config('clinica.datos.nombre'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notificacion',
            with: [
                'titulo' => Notificacion::TITULOS[$this->notificacion->tipo],
                'mensaje' => $this->notificacion->mensaje,
                'nombre' => $this->nombrePaciente,
                'clinica' => config('clinica.datos'),
                'enlace' => rtrim((string) config('clinica.url_frontend'), '/').'/paciente/notificaciones',
            ],
        );
    }
}
