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

    /*
    |--------------------------------------------------------------------------
    | Datos institucionales
    |--------------------------------------------------------------------------
    |
    | Encabezado de los documentos que se imprimen, como la receta médica (CU18).
    |
    */

    'datos' => [
        'nombre' => env('CLINICA_NOMBRE', 'Clínica Vida Salud'),
        'direccion' => env('CLINICA_DIRECCION', 'Av. Principal s/n, La Paz, Bolivia'),
        'telefono' => env('CLINICA_TELEFONO', '(2) 2000000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dirección de la aplicación web (SPA)
    |--------------------------------------------------------------------------
    |
    | Para los enlaces de los correos (CU20), que deben abrir el frontend y no la API.
    |
    */

    'url_frontend' => env('FRONTEND_URL', 'http://localhost:5173'),

];
