<?php

use App\Modules\ModuloOperacionCitas\Controllers\AsistenciaController;
use App\Modules\ModuloOperacionCitas\Controllers\CitaController;
use App\Modules\ModuloOperacionCitas\Controllers\ModalidadController;
use Illuminate\Support\Facades\Route;
use App\Modules\ModuloOperacionCitas\Controllers\AgendaController;
use App\Modules\ModuloOperacionCitas\Controllers\ConsultaController;
use App\Modules\ModuloOperacionCitas\Controllers\PagoController;


/*
|--------------------------------------------------------------------------
| Módulo 4: Operación de Citas Médicas, Agenda y Pagos
|--------------------------------------------------------------------------
*/

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

// CU17 – Registrar Consulta Médica (Solo médicos con el permiso)
Route::post('consultas', [ConsultaController::class, 'store'])->middleware(['auth:sanctum', 'permiso:citas_atender']);

// CU24 – Gestionar Pasarela de Pago (Solo usuarios autenticados)
Route::post('pagos', [PagoController::class, 'store'])->middleware(['auth:sanctum']);