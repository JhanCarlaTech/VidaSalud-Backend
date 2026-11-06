<?php

use App\Modules\ModuloPersonalMedico\Controllers\DirectorioMedicoController;
use App\Modules\ModuloPersonalMedico\Controllers\EspecialidadController;
use App\Modules\ModuloPersonalMedico\Controllers\HorarioController;
use App\Modules\ModuloPersonalMedico\Controllers\LicenciaController;
use App\Modules\ModuloPersonalMedico\Controllers\MedicoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo 2: Gestión de Personal Médico y Especialidades
|--------------------------------------------------------------------------
*/

// Reserva en línea desde la página principal (sin sesión): solo lectura del
// catálogo, del directorio y de los turnos, sin datos personales ni del legajo.
Route::prefix('publico')->middleware('throttle:60,1')->whereNumber('medico')->group(function () {
    Route::get('especialidades', [EspecialidadController::class, 'publicas']);
    Route::get('medicos', [DirectorioMedicoController::class, 'index']);
    Route::get('medicos/{medico}/disponibilidad', [HorarioController::class, 'disponibilidad']);
});

// CU07 – Gestionar Especialidades Médicas
// La consulta la necesitan CU08 y CU13, por eso basta con tener sesión (RN05).
Route::get('especialidades', [EspecialidadController::class, 'index'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'permiso:medicos_gestionar'])->group(function () {
    Route::post('especialidades', [EspecialidadController::class, 'store']);
    Route::put('especialidades/{especialidad}', [EspecialidadController::class, 'update'])->whereNumber('especialidad');
    Route::patch('especialidades/{especialidad}/estado', [EspecialidadController::class, 'cambiarEstado'])->whereNumber('especialidad');
});

// CU13 – Directorio de médicos por especialidad (cualquier usuario autenticado, sin datos del legajo)
Route::get('medicos/directorio', [DirectorioMedicoController::class, 'index'])->middleware('auth:sanctum');

// CU08 – Gestionar Personal Médico
Route::middleware(['auth:sanctum', 'permiso:medicos_gestionar'])->group(function () {
    Route::get('medicos', [MedicoController::class, 'index']);
    Route::post('medicos', [MedicoController::class, 'store']);
    Route::get('medicos/{medico}', [MedicoController::class, 'show'])->whereNumber('medico');
    Route::put('medicos/{medico}', [MedicoController::class, 'update'])->whereNumber('medico');
    Route::patch('medicos/{medico}/estado', [MedicoController::class, 'cambiarEstado'])->whereNumber('medico');
});

// CU09 – Gestionar Horarios y Disponibilidad Médica
// La autorización por médico (administrador o el propio médico) la hace HorarioController (RN05).
Route::middleware('auth:sanctum')->whereNumber(['medico', 'horario'])->group(function () {
    Route::get('medicos/{medico}/disponibilidad', [HorarioController::class, 'disponibilidad']);

    // scopeBindings: el {horario} debe pertenecer al {medico}; si no, 404.
    Route::scopeBindings()->group(function () {
        Route::get('medicos/{medico}/horarios', [HorarioController::class, 'index']);
        Route::post('medicos/{medico}/horarios', [HorarioController::class, 'store']);
        Route::put('medicos/{medico}/horarios/{horario}', [HorarioController::class, 'update']);
        Route::delete('medicos/{medico}/horarios/{horario}', [HorarioController::class, 'destroy']);
    });
});

// CU10 – Gestionar Licencias y Bloqueos de Agenda
// La autorización (administrador: cualquier médico; médico: las suyas) la hace LicenciaController (RN02).
Route::prefix('licencias')->middleware('auth:sanctum')->whereNumber('licencia')->group(function () {
    Route::get('/', [LicenciaController::class, 'index']);
    Route::get('medicos', [LicenciaController::class, 'medicos']);
    Route::get('citas-afectadas',[LicenciaController::class, 'vistaPrevia'])->name('licencias.citas-afectadas');
    Route::post('/', [LicenciaController::class, 'store']);
    Route::get('{licencia}/citas', [LicenciaController::class, 'citasDeLicencia']);
    Route::put('{licencia}', [LicenciaController::class, 'update']);
    Route::delete('{licencia}', [LicenciaController::class, 'destroy']);
});
