<?php

namespace App\Modules\ModuloSeguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloSeguridad\Requests\LoginRequest;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU01 – Autenticar y Gestionar Sesión.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $usuario = $this->auth->iniciarSesion(
            $request->validated('correo'),
            $request->validated('contrasena'),
            $request,
        );

        return ApiResponse::exito($this->auth->contexto($usuario), 'Sesión iniciada correctamente.');
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::exito($this->auth->sesionActual($request), 'Sesión activa.');
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->cerrarSesion($request);

        return ApiResponse::exito(mensaje: 'Sesión cerrada correctamente.');
    }
}
