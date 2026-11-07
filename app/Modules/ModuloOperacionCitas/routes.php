<?php

use App\Modules\ModuloOperacionCitas\Controllers\AgendaController;
use App\Modules\ModuloOperacionCitas\Controllers\AsistenciaController;
use App\Modules\ModuloOperacionCitas\Controllers\CitaController;
use App\Modules\ModuloOperacionCitas\Controllers\MetodoPagoController;
use App\Modules\ModuloOperacionCitas\Controllers\ModalidadController;
use App\Modules\ModuloOperacionCitas\Controllers\PagoController;
use App\Modules\ModuloOperacionCitas\Controllers\PagoStripeController;
use App\Modules\ModuloOperacionCitas\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo 4: Operación de Citas Médicas, Agenda y Pagos
|--------------------------------------------------------------------------
*/

// Reserva en línea desde la página principal (sin sesión): catálogo de modalidades.
Route::get('publico/modalidades', [ModalidadController::class, 'index'])->middleware('throttle:60,1');

// CU13 y CU14 – Quién actúa sobre qué cita (personal con 'citas_programar' o el
// propio paciente) lo decide CitaController.
Route::middleware('auth:sanctum')->whereNumber('cita')->group(function () {
    Route::get('modalidades', [ModalidadController::class, 'index']);
    Route::post('citas', [CitaController::class, 'store']);
    Route::get('mis-citas', [CitaController::class, 'misCitas']);
    Route::patch('citas/{cita}/reprogramar', [CitaController::class, 'reprogramar']);
    Route::patch('citas/{cita}/cancelar', [CitaController::class, 'cancelar']);
});

// CU14 – Búsqueda de citas para el personal
Route::get('citas', [CitaController::class, 'index'])->middleware(['auth:sanctum', 'permiso:citas_programar']);

// CU15 – Registrar Control de Asistencia
Route::middleware(['auth:sanctum', 'permiso:asistencia_registrar'])->whereNumber('cita')->group(function () {
    Route::get('asistencia', [AsistenciaController::class, 'index']);
    Route::patch('citas/{cita}/asistencia', [AsistenciaController::class, 'cambiarEstado']);
});

// CU16 – Consultar Agenda Médica (Accesible para Recepcionista y Médico)
Route::get('agenda', [AgendaController::class, 'index'])->middleware(['auth:sanctum']);

// CU17 – Registrar Consulta Médica: ver app/Modules/ModuloAtencionClinica/routes.php

// CU24 – Gestionar Pasarela de Pago (Solo usuarios autenticados)
Route::post('pagos', [PagoController::class, 'store'])->middleware(['auth:sanctum']);
Route::get('metodos-pago', [MetodoPagoController::class, 'index'])->middleware(['auth:sanctum']);

// CU24 – Pago con tarjeta mediante Stripe. Quién paga qué cita lo decide PagoStripeController.
Route::middleware(['auth:sanctum'])->whereNumber('cita')->group(function () {
    Route::get('pagos/stripe/configuracion', [PagoStripeController::class, 'configuracion']);
    Route::post('citas/{cita}/pago-en-linea', [PagoStripeController::class, 'iniciar'])->middleware('throttle:20,1');
    Route::post('citas/{cita}/pago-en-linea/confirmar', [PagoStripeController::class, 'confirmar'])->middleware('throttle:30,1');
});

// Webhook de Stripe: sin sesión; lo autentica la firma del evento.
Route::post('stripe/webhook', StripeWebhookController::class)->middleware('throttle:120,1');
