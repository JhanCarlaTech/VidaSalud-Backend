<?php

use App\Modules\ModuloPacientes\Controllers\AutoservicioPacienteController;
use App\Modules\ModuloPacientes\Controllers\PacienteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo 3: Gestión de Pacientes e Información Médica Base
|--------------------------------------------------------------------------
*/

// CU11 – Autorregistro público (RN07: 5 intentos por minuto por IP)
Route::post('auth/registro', [AutoservicioPacienteController::class, 'registrarse'])->middleware('throttle:5,1');

// CU11 – Mi ficha médica (el propio paciente, RN08)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('mi-ficha', [AutoservicioPacienteController::class, 'miFicha']);
    Route::put('mi-ficha', [AutoservicioPacienteController::class, 'actualizarFicha']);
});

// CU11 – Gestión de pacientes (Administrador y Recepcionista)
Route::middleware(['auth:sanctum', 'permiso:pacientes_gestionar'])->whereNumber('paciente')->group(function () {
    Route::get('pacientes', [PacienteController::class, 'index']);
    Route::post('pacientes', [PacienteController::class, 'store']);
    Route::get('pacientes/{paciente}', [PacienteController::class, 'show']);
    Route::put('pacientes/{paciente}', [PacienteController::class, 'update']);
    Route::patch('pacientes/{paciente}/estado', [PacienteController::class, 'cambiarEstado']);
});
