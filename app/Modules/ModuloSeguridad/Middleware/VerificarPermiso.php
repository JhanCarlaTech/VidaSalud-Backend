<?php

namespace App\Modules\ModuloSeguridad\Middleware;

use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza la petición si el usuario tiene al menos uno de los permisos indicados.
 *
 * Uso en rutas: ->middleware('permiso:usuarios_gestionar,reportes_ver')
 */
class VerificarPermiso
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next, string ...$permisosRequeridos): Response
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return ApiResponse::error('Sesión expirada o no iniciada.', 401);
        }

        $tienePermiso = $usuario->activo
            && $this->auth->permisos($usuario)->intersect($permisosRequeridos)->isNotEmpty();

        if (! $tienePermiso) {
            return ApiResponse::error('No tiene permisos para esta acción.', 403);
        }

        return $next($request);
    }
}
