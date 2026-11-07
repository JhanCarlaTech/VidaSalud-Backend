<?php

namespace App\Modules\ModuloInteligenciaReportes\Services;

use App\Modules\ModuloInteligenciaReportes\Models\SuscripcionPush;
use App\Modules\ModuloSeguridad\Models\PreferenciaNotificacion;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * CU20 – Notificaciones push (Web Push).
 *
 * El navegador del usuario se suscribe con la clave pública VAPID; el servidor
 * firma cada envío con la clave privada y cifra el contenido con las claves de
 * la suscripción. El servicio push del navegador (Google, Mozilla, Apple) lo
 * entrega aunque la página esté cerrada.
 */
class PushService
{
    public function configurado(): bool
    {
        return filled(config('services.webpush.publica')) && filled(config('services.webpush.privada'));
    }

    public function clavePublica(): ?string
    {
        return $this->configurado() ? config('services.webpush.publica') : null;
    }

    /**
     * Registra (o actualiza) la suscripción de este navegador. Un mismo endpoint
     * pasa al último usuario que inició sesión en ese navegador.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding?: string}  $datos
     */
    public function suscribir(int $idUsuario, array $datos, ?string $agente): SuscripcionPush
    {
        return SuscripcionPush::query()->updateOrCreate(
            ['endpoint' => $datos['endpoint']],
            [
                'id_usuario' => $idUsuario,
                'clave_p256dh' => $datos['keys']['p256dh'],
                'clave_auth' => $datos['keys']['auth'],
                'codificacion' => $datos['content_encoding'] ?? 'aes128gcm',
                'agente' => $agente ? mb_substr($agente, 0, 255) : null,
            ],
        );
    }

    public function desuscribir(int $idUsuario, string $endpoint): bool
    {
        return SuscripcionPush::query()->where('id_usuario', $idUsuario)->where('endpoint', $endpoint)->delete() > 0;
    }

    public function cantidadDispositivos(int $idUsuario): int
    {
        return SuscripcionPush::query()->where('id_usuario', $idUsuario)->count();
    }

    /**
     * Envía un aviso a todos los dispositivos del usuario, si tiene el push activado.
     * Las suscripciones vencidas (el usuario revocó el permiso o desinstaló) se borran.
     *
     * @param  array{titulo: string, cuerpo: string, url?: string, etiqueta?: string}  $aviso
     * @return int Dispositivos a los que se entregó.
     */
    public function enviarAUsuario(int $idUsuario, array $aviso, bool $respetarPreferencia = true): int
    {
        if (! $this->configurado()) {
            return 0;
        }

        if ($respetarPreferencia) {
            $quierePush = PreferenciaNotificacion::query()->where('id_usuario', $idUsuario)->value('notificar_push')
                ?? PreferenciaNotificacion::POR_DEFECTO['notificar_push'];
            if (! $quierePush) {
                return 0;
            }
        }

        $suscripciones = SuscripcionPush::query()->where('id_usuario', $idUsuario)->get();
        if ($suscripciones->isEmpty()) {
            return 0;
        }

        try {
            $webPush = $this->cliente();
            $contenido = json_encode([
                'titulo' => $aviso['titulo'],
                'cuerpo' => $aviso['cuerpo'],
                'url' => $aviso['url'] ?? '/',
                'etiqueta' => $aviso['etiqueta'] ?? null,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($suscripciones as $suscripcion) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $suscripcion->endpoint,
                    'publicKey' => $suscripcion->clave_p256dh,
                    'authToken' => $suscripcion->clave_auth,
                    'contentEncoding' => $suscripcion->codificacion,
                ]), $contenido);
            }

            $entregados = 0;
            foreach ($webPush->flush() as $reporte) {
                if ($reporte->isSuccess()) {
                    $entregados++;
                    SuscripcionPush::query()->where('endpoint', $reporte->getEndpoint())->update(['fecha_ultimo_envio' => now()]);
                } elseif ($reporte->isSubscriptionExpired()) {
                    // 404/410: el usuario revocó el permiso o el navegador descartó la suscripción.
                    SuscripcionPush::query()->where('endpoint', $reporte->getEndpoint())->delete();
                    Log::info('Suscripción push vencida eliminada.', [
                        'usuario' => $idUsuario,
                        'http' => $reporte->getResponse()?->getStatusCode(),
                        'motivo' => mb_substr($reporte->getReason(), 0, 200),
                    ]);
                } else {
                    Log::warning('No se pudo entregar una notificación push.', ['usuario' => $idUsuario, 'motivo' => $reporte->getReason()]);
                }
            }

            return $entregados;
        } catch (Throwable $error) {
            // Un fallo del push nunca deshace la operación que lo originó (agendar, cancelar…).
            Log::warning('Error al enviar notificaciones push.', ['usuario' => $idUsuario, 'error' => $error->getMessage()]);

            return 0;
        }
    }

    private function cliente(): WebPush
    {
        $http = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'verify' => config('services.webpush.ca_bundle') ?: true,
        ]);

        return new WebPush(
            auth: ['VAPID' => [
                'subject' => config('services.webpush.asunto'),
                'publicKey' => config('services.webpush.publica'),
                'privateKey' => config('services.webpush.privada'),
            ]],
            defaultOptions: ['TTL' => config('services.webpush.ttl'), 'urgency' => 'high'],
            client: $http,
        );
    }
}
