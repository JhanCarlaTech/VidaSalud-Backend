<?php

namespace App\Modules\ModuloSeguridad\Services;

use App\Modules\ModuloSeguridad\Exceptions\AutenticacionException;
use App\Modules\ModuloSeguridad\Models\Rol;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Repositories\UsuarioRepository;
use App\Modules\ModuloSeguridad\Resources\UsuarioResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * CU01 – Autenticar y Gestionar Sesión.
 */
class AuthService
{
    /** RN05: intentos fallidos permitidos por correo + IP. */
    private const MAX_INTENTOS = 5;

    /** RN05: duración del bloqueo tras superar los intentos. */
    private const SEGUNDOS_BLOQUEO = 60;

    /** Panel inicial de cada rol, ordenado por jerarquía (el primero tiene prioridad). */
    private const RUTAS_INICIO = [
        Rol::ADMINISTRADOR => '/admin/dashboard',
        Rol::MEDICO => '/medico/agenda',
        Rol::RECEPCIONISTA => '/recepcion/agenda',
        Rol::PACIENTE => '/paciente/inicio',
    ];

    /** Panel para roles creados posteriormente que no figuran en RUTAS_INICIO. */
    private const RUTA_INICIO_GENERICA = '/inicio';

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly BitacoraService $bitacora,
    ) {}

    /**
     * Valida las credenciales e inicia una sesión segura.
     *
     * @throws AutenticacionException
     */
    public function iniciarSesion(string $correo, string $contrasena, Request $request): Usuario
    {
        $this->verificarSesionDisponible($request);

        $claveIntentos = "login:{$correo}|{$request->ip()}";

        if (RateLimiter::tooManyAttempts($claveIntentos, self::MAX_INTENTOS)) {
            throw AutenticacionException::demasiadosIntentos(RateLimiter::availableIn($claveIntentos));
        }

        $usuario = $this->usuarios->buscarPorCorreo($correo);

        if (! $this->contrasenaValida($usuario, $contrasena)) {
            RateLimiter::hit($claveIntentos, self::SEGUNDOS_BLOQUEO);
            $this->bitacora->registrar('LOGIN_FALLIDO', 'usuario', $usuario?->id, datosNuevos: ['correo' => $correo]);

            throw AutenticacionException::credencialesInvalidas();
        }

        RateLimiter::clear($claveIntentos);

        // El estado de la cuenta solo se revela a quien conoce la contraseña (RN04).
        if (! $usuario->activo) {
            $this->bitacora->registrar('LOGIN_RECHAZADO', 'usuario', $usuario->id, datosNuevos: ['motivo' => 'Cuenta inactiva']);

            throw AutenticacionException::cuentaInactiva();
        }

        if ($this->rolesHabilitados($usuario)->isEmpty()) {
            $this->bitacora->registrar('LOGIN_RECHAZADO', 'usuario', $usuario->id, datosNuevos: ['motivo' => 'Sin roles habilitados']);

            throw AutenticacionException::sinAcceso();
        }

        $this->abrirSesion($usuario, $request);

        return $usuario;
    }

    /**
     * Inicia la sesión de un usuario ya verificado: tras el login o tras el
     * autorregistro de un paciente (CU11).
     */
    public function abrirSesion(Usuario $usuario, Request $request): void
    {
        $this->verificarSesionDisponible($request);

        Auth::guard('web')->login($usuario);
        $request->session()->regenerate(); // Previene la fijación de sesión.

        $this->bitacora->registrar('LOGIN', 'usuario', $usuario->id);
    }

    /**
     * Sin sesión no se puede autenticar: Sanctum no reconoció el origen del frontend.
     */
    private function verificarSesionDisponible(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new RuntimeException(
                "La petición no tiene sesión. Agregue el origen del frontend ({$request->headers->get('origin', 'desconocido')}) a SANCTUM_STATEFUL_DOMAINS en el .env."
            );
        }
    }

    /**
     * Devuelve el contexto de la sesión activa. Si desde el login la cuenta fue
     * desactivada o se quedó sin roles, la sesión se cierra.
     *
     * @throws AutenticacionException
     */
    public function sesionActual(Request $request): array
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if (! $this->tieneAcceso($usuario)) {
            $this->cerrarSesion($request);

            throw AutenticacionException::sinAcceso();
        }

        return $this->contexto($usuario);
    }

    /**
     * ¿Puede el usuario usar el sistema? Cuenta activa y al menos un rol habilitado.
     * También lo usa CU08 para decidir si cerrar las sesiones de un médico desactivado.
     */
    public function tieneAcceso(Usuario $usuario): bool
    {
        return $usuario->activo && $this->rolesHabilitados($usuario)->isNotEmpty();
    }

    public function cerrarSesion(Request $request): void
    {
        $this->bitacora->registrar('LOGOUT', 'usuario', $request->user()?->id);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Datos que el frontend necesita tras autenticarse: usuario, roles,
     * permisos y el panel al que debe redirigir.
     */
    public function contexto(Usuario $usuario): array
    {
        $roles = $this->rolesHabilitados($usuario);
        $rolPrincipal = $roles->first()?->nombre;

        return [
            'usuario' => new UsuarioResource($usuario),
            'roles' => $roles->pluck('nombre')->values(),
            'rol_principal' => $rolPrincipal,
            'permisos' => $this->usuarios->accionesPermitidas($roles->pluck('id')->all()),
            'ruta_inicio' => self::RUTAS_INICIO[$rolPrincipal] ?? self::RUTA_INICIO_GENERICA,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    public function permisos(Usuario $usuario): Collection
    {
        return $this->usuarios->accionesPermitidas($this->rolesHabilitados($usuario)->pluck('id')->all());
    }

    /**
     * Roles activos y vigentes (RN02) cuyo registro de extensión también está
     * activo: el Médico requiere 'medico' y el Paciente requiere 'paciente'.
     * Se devuelven ordenados por jerarquía.
     *
     * @return Collection<int, Rol>
     */
    private function rolesHabilitados(Usuario $usuario): Collection
    {
        $jerarquia = array_keys(self::RUTAS_INICIO);

        return $usuario->rolesActivos()->get()
            ->filter(fn (Rol $rol) => match ($rol->nombre) {
                Rol::MEDICO => (bool) $usuario->medico?->activo,
                Rol::PACIENTE => (bool) $usuario->paciente?->activo,
                default => true,
            })
            ->sortBy(function (Rol $rol) use ($jerarquia) {
                $posicion = array_search($rol->nombre, $jerarquia, true);

                return $posicion === false ? PHP_INT_MAX : $posicion;
            })
            ->values();
    }

    /**
     * Si el correo no existe se calcula igualmente un hash, para que el tiempo
     * de respuesta no revele qué correos están registrados.
     */
    private function contrasenaValida(?Usuario $usuario, string $contrasena): bool
    {
        if ($usuario === null) {
            Hash::make($contrasena);

            return false;
        }

        return Hash::check($contrasena, $usuario->getAuthPassword());
    }
}
