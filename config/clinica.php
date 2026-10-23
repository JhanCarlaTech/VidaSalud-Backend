<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrador por defecto
    |--------------------------------------------------------------------------
    |
    | Credenciales con las que el seeder deja configurado al usuario
    | Administrador inicial del script de base de datos.
    |
    */

    'admin' => [
        'correo' => env('ADMIN_CORREO', 'admin@vidasalud.com'),
        'contrasena' => env('ADMIN_CONTRASENA'),
    ],

];
