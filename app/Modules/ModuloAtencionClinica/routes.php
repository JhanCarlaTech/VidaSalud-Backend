<?php

use App\Modules\ModuloAtencionClinica\Controllers\ConsultaController;
use App\Modules\ModuloAtencionClinica\Controllers\HistorialController;
use App\Modules\ModuloAtencionClinica\Controllers\RecetaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo 5: Atención Clínica, Expediente e Historial (CU17–CU19)
|--------------------------------------------------------------------------
*/

// CU17 – Registrar Consulta Médica (médicos con 'citas_atender'; la cita debe ser suya)
Route::post('consultas', [ConsultaController::class, 'store'])->middleware(['auth:sanctum', 'permiso:citas_atender']);

// CU18 – Emitir Receta Médica (el médico de la consulta; RecetaService verifica que sea suya)
Route::middleware(['auth:sanctum', 'permiso:citas_atender'])->whereNumber('consulta')->group(function () {
    Route::get('recetas/consultas', [RecetaController::class, 'consultas']);
    Route::get('consultas/{consulta}/recetas', [RecetaController::class, 'deConsulta']);
    Route::post('consultas/{consulta}/recetas', [RecetaController::class, 'store']);
});

// CU19 – Historial clínico: el médico, de los pacientes que atiende (regla D4, AccesoClinicoService).
Route::middleware(['auth:sanctum', 'permiso:historial_consultar'])->whereNumber('paciente')->group(function () {
    Route::get('historial/pacientes', [HistorialController::class, 'pacientes']);
    Route::get('pacientes/{paciente}/historial', [HistorialController::class, 'show']);
});

// CU19 – Mi historial: el propio paciente (sin permiso adicional, como "Mi ficha" de CU11).
Route::get('mi-historial', [HistorialController::class, 'miHistorial'])->middleware('auth:sanctum');

// CU18 – Mis recetas: el propio paciente (sin permiso adicional, como "Mi historial").
Route::get('mis-recetas', [RecetaController::class, 'misRecetas'])->middleware('auth:sanctum');

// CU18 – Ver o imprimir una receta: el médico que la emitió, el paciente o un médico que lo atiende (RN05).
Route::get('recetas/{receta}', [RecetaController::class, 'show'])->middleware('auth:sanctum')->whereNumber('receta');
