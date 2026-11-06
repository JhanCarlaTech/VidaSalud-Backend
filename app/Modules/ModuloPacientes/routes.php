<?php

use App\Modules\ModuloPacientes\Controllers\AntecedenteController;
use App\Modules\ModuloPacientes\Controllers\AutoservicioPacienteController;
use App\Modules\ModuloPacientes\Controllers\EnfermedadController;
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

// CU12 – Catálogo de enfermedades y antecedentes del paciente (Administrador y Médico)
Route::middleware(['auth:sanctum', 'permiso:enfermedades_gestionar'])->whereNumber(['enfermedad', 'paciente'])->group(function () {
    Route::get('enfermedades', [EnfermedadController::class, 'index']);
    Route::post('enfermedades', [EnfermedadController::class, 'store']);
    Route::put('enfermedades/{enfermedad}', [EnfermedadController::class, 'update']);
    Route::patch('enfermedades/{enfermedad}/estado', [EnfermedadController::class, 'cambiarEstado']);

    Route::get('antecedentes/pacientes', [AntecedenteController::class, 'buscarPacientes']);
    Route::get('pacientes/{paciente}/antecedentes', [AntecedenteController::class, 'index']);
    Route::post('pacientes/{paciente}/antecedentes', [AntecedenteController::class, 'store']);
    Route::put('pacientes/{paciente}/antecedentes/{enfermedad}', [AntecedenteController::class, 'update']);
    Route::delete('pacientes/{paciente}/antecedentes/{enfermedad}', [AntecedenteController::class, 'destroy']);
});

// CU11 – Gestión de pacientes (Administrador y Recepcionista)
Route::middleware(['auth:sanctum', 'permiso:pacientes_gestionar'])->whereNumber('paciente')->group(function () {
    Route::get('pacientes', [PacienteController::class, 'index']);
    Route::post('pacientes', [PacienteController::class, 'store']);
    Route::get('pacientes/{paciente}', [PacienteController::class, 'show']);
    Route::put('pacientes/{paciente}', [PacienteController::class, 'update']);
    Route::patch('pacientes/{paciente}/estado', [PacienteController::class, 'cambiarEstado']);
});
