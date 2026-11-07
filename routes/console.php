<?php

use App\Modules\ModuloInteligenciaReportes\Services\NotificacionService;
use Database\Seeders\DatosPruebaSeeder;
use Database\Seeders\PermisosSistemaSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Minishlink\WebPush\VAPID;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| CU20 RN04 – Recordatorios de citas. Se ejecuta con el planificador
| ('php artisan schedule:work' en desarrollo o un cron con 'schedule:run' en producción),
| o a mano: 'php artisan notificaciones:recordatorios'.
*/
Artisan::command('notificaciones:recordatorios', function (NotificacionService $notificaciones) {
    $cantidad = $notificaciones->enviarRecordatorios();
    $this->info($cantidad === 1 ? 'Se envió 1 recordatorio.' : "Se enviaron {$cantidad} recordatorios.");
})->purpose('Envía los recordatorios de las próximas citas según la anticipación de cada paciente');

Schedule::command('notificaciones:recordatorios')->everyFifteenMinutes()->withoutOverlapping();

/*
| CU20 – Claves VAPID para las notificaciones push. Se generan UNA sola vez y se
| guardan en el .env (y en Railway): si cambian, los dispositivos ya suscritos
| dejan de recibir avisos hasta volver a activarlos.
*/
Artisan::command('push:claves', function () {
    $claves = VAPID::createVapidKeys();
    $this->line('Agregue estas líneas al .env (y a las variables del Backend en Railway):');
    $this->newLine();
    $this->line('VAPID_PUBLIC_KEY='.$claves['publicKey']);
    $this->line('VAPID_PRIVATE_KEY='.$claves['privateKey']);
})->purpose('Genera el par de claves VAPID para las notificaciones push');

/*
| Despliegue (docker/entrypoint.sh): con la base vacía carga los datos iniciales
| del script.sql y el administrador (ADMIN_CORREO / ADMIN_CONTRASENA). Si ya hay
| usuarios no toca los datos (DatabaseSeeder reinicia contraseñas): solo agrega
| los permisos nuevos, que es idempotente.
*/
Artisan::command('app:preparar-datos', function () {
    if (DB::table('usuario')->exists()) {
        $this->call('db:seed', ['--class' => PermisosSistemaSeeder::class, '--force' => true]);
        $this->info('Base con datos: permisos del sistema actualizados.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
    $this->info('Base vacía: datos iniciales y administrador cargados.');
})->purpose('Carga los datos iniciales si la base está vacía; si no, solo actualiza los permisos');

/*
| Datos de prueba de reportes y tablero: se crean con
| 'php artisan db:seed --class=DatosPruebaSeeder' y se borran con este comando.
| Solo afecta a usuarios con correo @demo.vidasalud.com y a sus datos.
*/
Artisan::command('datos-prueba:eliminar', function () {
    $usuarios = DatosPruebaSeeder::eliminar();
    $this->info($usuarios === 0 ? 'No había datos de prueba.' : "Se eliminaron {$usuarios} usuarios de demostración con sus citas, consultas, recetas y pagos.");
})->purpose('Borra los datos de prueba generados por DatosPruebaSeeder');
