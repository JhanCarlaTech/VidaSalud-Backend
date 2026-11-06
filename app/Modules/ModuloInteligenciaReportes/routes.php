<?php

use App\Modules\ModuloInteligenciaReportes\Controllers\ConsultaVozController;
use App\Modules\ModuloInteligenciaReportes\Controllers\DashboardController;
use App\Modules\ModuloInteligenciaReportes\Controllers\MisNotificacionesController;
use App\Modules\ModuloInteligenciaReportes\Controllers\NotificacionController;
use App\Modules\ModuloInteligenciaReportes\Controllers\ReporteController;
use App\Modules\ModuloInteligenciaReportes\Services\CatalogoReportes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo 6: Inteligencia Operativa, Notificaciones y Reportes (CU20–CU23)
|--------------------------------------------------------------------------
*/

// CU20 – Bandeja del propio paciente (sin permiso adicional, como "Mi ficha" de CU11)
Route::prefix('mis-notificaciones')->middleware('auth:sanctum')->whereNumber('notificacion')->group(function () {
    Route::get('/', [MisNotificacionesController::class, 'index']);
    Route::get('resumen', [MisNotificacionesController::class, 'resumen']);
    Route::patch('leer-todas', [MisNotificacionesController::class, 'marcarTodas']);
    Route::patch('{notificacion}/leida', [MisNotificacionesController::class, 'marcarLeida']);
});

// CU21 – Reportes operativos (permiso del script 'reportes_ver'; el alcance lo resuelve ReporteController)
Route::prefix('reportes')->middleware(['auth:sanctum', 'permiso:reportes_ver'])
    ->whereIn('tipo', array_keys(CatalogoReportes::REPORTES))
    ->group(function () {
        Route::get('catalogo', [ReporteController::class, 'catalogo']);
        // CU22 – consulta por voz (máx. 20 por minuto: con IA cada consulta tiene costo)
        Route::post('voz', [ConsultaVozController::class, 'consultar'])->middleware('throttle:20,1');
        Route::get('{tipo}', [ReporteController::class, 'generar']);
        Route::get('{tipo}/exportar', [ReporteController::class, 'exportar']);
    });

// CU23 – Dashboard administrativo (incluye los ingresos de toda la clínica: solo Administración)
Route::get('dashboard', [DashboardController::class, 'show'])->middleware(['auth:sanctum', 'permiso:dashboard_ver']);

// CU20 – Gestión por Recepción y Administración
Route::prefix('notificaciones')->middleware(['auth:sanctum', 'permiso:notificaciones_gestionar'])->whereNumber('notificacion')->group(function () {
    Route::get('/', [NotificacionController::class, 'index']);
    Route::post('/', [NotificacionController::class, 'store']);
    Route::post('recordatorios', [NotificacionController::class, 'recordatorios']);
    Route::post('{notificacion}/reenviar', [NotificacionController::class, 'reenviar']);
});
