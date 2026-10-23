<?php

/*
|--------------------------------------------------------------------------
| Rutas de la API (prefijo /api)
|--------------------------------------------------------------------------
|
| Cada módulo define sus rutas en 'app/Modules/<Modulo>/routes.php'.
|
*/

foreach (glob(app_path('Modules/*/routes.php')) as $rutasModulo) {
    require $rutasModulo;
}
