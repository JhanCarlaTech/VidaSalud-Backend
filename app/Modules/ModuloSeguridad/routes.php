<?php

use App\Modules\ModuloSeguridad\Controllers\AuthController;
use App\Modules\ModuloSeguridad\Controllers\PerfilController;
use App\Modules\ModuloSeguridad\Controllers\PermisoController;
use App\Modules\ModuloSeguridad\Controllers\RolController;
use App\Modules\ModuloSeguridad\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo 1: Seguridad, Usuarios y Configuración del Sistema
|--------------------------------------------------------------------------
*/

// CU01 – Autenticar y Gestionar Sesión
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// CU05 – Configurar Perfil Personal (cualquier usuario autenticado, sobre sí mismo)
Route::prefix('perfil')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PerfilController::class, 'show']);
    Route::put('/', [PerfilController::class, 'update']);
    Route::put('contrasena', [PerfilController::class, 'cambiarContrasena']);
    Route::put('preferencias', [PerfilController::class, 'actualizarPreferencias']);
    Route::get('sesiones', [PerfilController::class, 'sesiones']);
    Route::delete('sesiones', [PerfilController::class, 'cerrarOtrasSesiones']);
    Route::get('accesos', [PerfilController::class, 'accesos']);
});

// CU02 – Gestionar Usuarios y Asignar Roles
Route::middleware(['auth:sanctum', 'permiso:usuarios_gestionar'])->group(function () {
    Route::get('usuarios', [UsuarioController::class, 'index']);
    Route::post('usuarios', [UsuarioController::class, 'store']);
    Route::get('usuarios/{usuario}', [UsuarioController::class, 'show'])->whereNumber('usuario');
    Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->whereNumber('usuario');
    Route::patch('usuarios/{usuario}/estado', [UsuarioController::class, 'cambiarEstado'])->whereNumber('usuario');
});

// CU03 – Gestionar Roles y Asignar Permisos
// El listado también lo usa CU02 para asignar roles a los usuarios.
Route::get('roles', [RolController::class, 'index'])
    ->middleware(['auth:sanctum', 'permiso:usuarios_gestionar,roles_gestionar']);

Route::middleware(['auth:sanctum', 'permiso:roles_gestionar'])->group(function () {
    Route::get('permisos', [PermisoController::class, 'index']);
    Route::post('roles', [RolController::class, 'store']);
    Route::put('roles/{rol}', [RolController::class, 'update'])->whereNumber('rol');
    Route::patch('roles/{rol}/estado', [RolController::class, 'cambiarEstado'])->whereNumber('rol');
});
