<?php

use App\Modules\ModuloPersonalMedico\Controllers\DirectorioMedicoController;
use App\Modules\ModuloPersonalMedico\Controllers\EspecialidadController;
use App\Modules\ModuloPersonalMedico\Controllers\HorarioController;
use App\Modules\ModuloPersonalMedico\Controllers\MedicoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo 2: Gestión de Personal Médico y Especialidades
|--------------------------------------------------------------------------
*/

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
