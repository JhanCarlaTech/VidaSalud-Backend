<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | CU22 – Interpretación de consultas de reportes con IA (decisión D6).
    | Sin clave, el sistema usa el intérprete por reglas: la funcionalidad no depende de la IA.
    */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'modelo' => env('ANTHROPIC_MODELO', 'claude-opus-5-5'),
        'timeout' => (float) env('ANTHROPIC_TIMEOUT', 20),
    ],

    /*
    | CU24 – Pasarela de pago Stripe. La clave secreta nunca sale del servidor;
    | la pública la entrega GET /api/pagos/stripe/configuracion al frontend.
    | Sin webhook_secret, el pago se confirma cuando el frontend vuelve del formulario.
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'publicable' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'moneda' => strtolower(env('STRIPE_MONEDA', 'bob')),
        'timeout' => (int) env('STRIPE_TIMEOUT', 20),
    ],

    /*
    | CU20 – Notificaciones push (Web Push con claves VAPID).
    | Genere las claves una sola vez con 'php artisan push:claves'. Si se cambian,
    | todas las suscripciones existentes dejan de funcionar.
    | 'asunto': contacto para los servicios push (mailto: o https://).
    | 'ca_bundle': solo para PHP sin certificados raíz (desarrollo en Windows).
    */
    'webpush' => [
        'publica' => env('VAPID_PUBLIC_KEY'),
        'privada' => env('VAPID_PRIVATE_KEY'),
        'asunto' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'contacto@vidasalud.com')),
        'ttl' => (int) env('WEBPUSH_TTL', 86400), // segundos que el servicio guarda el aviso si el dispositivo está apagado
        'ca_bundle' => env('WEBPUSH_CA_BUNDLE'),
    ],

];
