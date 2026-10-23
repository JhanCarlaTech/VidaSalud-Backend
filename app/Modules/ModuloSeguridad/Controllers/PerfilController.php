<?php

namespace App\Modules\ModuloSeguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloSeguridad\Requests\CambiarContrasenaRequest;
use App\Modules\ModuloSeguridad\Requests\PerfilUpdateRequest;
use App\Modules\ModuloSeguridad\Requests\PreferenciasRequest;
use App\Modules\ModuloSeguridad\Resources\SesionResource;
use App\Modules\ModuloSeguridad\Resources\UsuarioResource;
use App\Modules\ModuloSeguridad\Services\PerfilService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU05 – Configurar Perfil Personal (siempre sobre el usuario autenticado).
 */
class PerfilController extends Controller
{
    public function __construct(private readonly PerfilService $perfil) {}

    /**
     * GET /api/perfil
     */
    public function show(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return ApiResponse::exito([
            'usuario' => new UsuarioResource($this->perfil->perfil($usuario)),
            'preferencias' => $this->perfil->preferencias($usuario),
        ], 'Perfil del usuario.');
    }

    /**
     * PUT /api/perfil
     */
    public function update(PerfilUpdateRequest $request): JsonResponse
    {
        $usuario = $this->perfil->actualizarDatos($request->user(), $request->validated());

        return ApiResponse::exito(new UsuarioResource($usuario), 'Datos personales actualizados correctamente.');
    }

    /**
     * PUT /api/perfil/contrasena
     */
    public function cambiarContrasena(CambiarContrasenaRequest $request): JsonResponse
    {
        $this->perfil->cambiarContrasena(
            $request->user(),
            $request->validated('contrasena_actual'),
            $request->validated('contrasena'),
            $request->session()->getId(),
        );

        return ApiResponse::exito(mensaje: 'Contraseña actualizada. Se cerraron sus sesiones en otros dispositivos.');
    }

    /**
     * PUT /api/perfil/preferencias
     */
    public function actualizarPreferencias(PreferenciasRequest $request): JsonResponse
    {
        $preferencias = $this->perfil->guardarPreferencias($request->user(), $request->validated());

        return ApiResponse::exito($preferencias, 'Preferencias de notificación guardadas.');
    }

    /**
     * GET /api/perfil/sesiones
     */
    public function sesiones(Request $request): JsonResponse
    {
        $sesiones = $this->perfil->sesiones($request->user(), $request->session()->getId());

        return ApiResponse::exito(SesionResource::collection($sesiones), 'Sesiones abiertas.');
    }

    /**
     * DELETE /api/perfil/sesiones
     */
    public function cerrarOtrasSesiones(Request $request): JsonResponse
    {
        $cerradas = $this->perfil->cerrarOtrasSesiones($request->user(), $request->session()->getId());

        $mensaje = match ($cerradas) {
            0 => 'No había otras sesiones abiertas.',
            1 => 'Se cerró 1 sesión en otro dispositivo.',
            default => "Se cerraron {$cerradas} sesiones en otros dispositivos.",
        };

        return ApiResponse::exito(['cerradas' => $cerradas], $mensaje);
    }

    /**
     * GET /api/perfil/accesos
     */
    public function accesos(Request $request): JsonResponse
    {
        $accesos = $this->perfil->accesosRecientes($request->user())->map(fn ($acceso) => [
            'accion' => $acceso->accion,
            'ip' => $acceso->ip_origen,
            'fecha' => $acceso->fecha_registro?->toIso8601String(),
        ]);

        return ApiResponse::exito($accesos, 'Accesos recientes.');
    }
}
