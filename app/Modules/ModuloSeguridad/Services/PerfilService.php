<?php

namespace App\Modules\ModuloSeguridad\Services;

use App\Modules\ModuloSeguridad\Exceptions\AutenticacionException;
use App\Modules\ModuloSeguridad\Models\Bitacora;
use App\Modules\ModuloSeguridad\Models\PreferenciaNotificacion;
use App\Modules\ModuloSeguridad\Models\Usuario;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * CU05 – Configurar Perfil Personal. Todas las operaciones actúan sobre el
 * usuario autenticado, nunca sobre un id recibido del cliente.
 */
class PerfilService
{
    /** Campos de 'usuario' que el propio usuario puede modificar (RN01). */
    private const CAMPOS_EDITABLES = ['nombre', 'apellido', 'correo', 'telefono', 'sexo'];

    /** RN05: intentos de verificación de la contraseña actual por minuto. */
    private const MAX_INTENTOS = 5;

    private const SEGUNDOS_BLOQUEO = 60;

    /** Eventos de la bitácora que se muestran como accesos recientes. */
    private const ACCIONES_ACCESO = ['LOGIN', 'LOGIN_FALLIDO', 'LOGIN_RECHAZADO', 'LOGOUT'];

    private const CANTIDAD_ACCESOS = 10;

    public function __construct(private readonly BitacoraService $bitacora) {}

    public function perfil(Usuario $usuario): Usuario
    {
        return $usuario->load('rolesActivos');
    }

    /**
     * Preferencias guardadas o, si nunca se configuraron, las de por defecto (RN06).
     */
    public function preferencias(Usuario $usuario): array
    {
        $guardadas = $usuario->preferenciaNotificacion()->first();

        return $guardadas
            ? Arr::only($guardadas->toArray(), array_keys(PreferenciaNotificacion::POR_DEFECTO))
            : PreferenciaNotificacion::POR_DEFECTO;
    }

    /**
     * @throws ValidationException|AutenticacionException
     */
    public function actualizarDatos(Usuario $usuario, array $datos): Usuario
    {
        // RN02: cambiar el correo de inicio de sesión exige la contraseña actual.
        if ($datos['correo'] !== $usuario->correo) {
            $this->verificarContrasenaActual($usuario, $datos['contrasena_actual'] ?? null);
        }

        return DB::transaction(function () use ($usuario, $datos) {
            $antes = $this->instantanea($usuario);

            $usuario->update(Arr::only($datos, self::CAMPOS_EDITABLES));

            $this->bitacora->registrar('UPDATE', 'usuario', $usuario->id, $antes, $this->instantanea($usuario));

            return $this->perfil($usuario);
        });
    }

    /**
     * Cambia la contraseña y cierra las demás sesiones (RN03, RN04).
     * Se modifica la misma instancia del usuario autenticado para que Sanctum
     * guarde el nuevo hash en la sesión actual y esta siga siendo válida.
     *
     * @throws ValidationException|AutenticacionException
     */
    public function cambiarContrasena(Usuario $usuario, string $actual, string $nueva, string $idSesionActual): void
    {
        $this->verificarContrasenaActual($usuario, $actual);

        DB::transaction(function () use ($usuario, $nueva, $idSesionActual) {
            $usuario->contrasena = $nueva; // El cast 'hashed' aplica BCRYPT.
            $usuario->save();

            $this->cerrarOtrasSesiones($usuario, $idSesionActual);
            $this->bitacora->registrar('UPDATE', 'usuario', $usuario->id, datosNuevos: ['id' => $usuario->id, 'contrasena' => 'cambiada']);
        });
    }

    public function guardarPreferencias(Usuario $usuario, array $datos): array
    {
        PreferenciaNotificacion::updateOrCreate(
            ['id_usuario' => $usuario->id],
            [...$datos, 'fecha_actualizacion' => now()],
        );

        return $this->preferencias($usuario);
    }

    /**
     * Sesiones vigentes del usuario, la más reciente primero (RN07).
     *
     * @return Collection<int, object>
     */
    public function sesiones(Usuario $usuario, string $idSesionActual): Collection
    {
        $limite = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        return DB::table('sessions')
            ->where('user_id', $usuario->id)
            ->where('last_activity', '>=', $limite)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function ($sesion) use ($idSesionActual) {
                $sesion->actual = $sesion->id === $idSesionActual;

                return $sesion;
            });
    }

    /**
     * @return int  Cantidad de sesiones cerradas.
     */
    public function cerrarOtrasSesiones(Usuario $usuario, string $idSesionActual): int
    {
        return DB::table('sessions')
            ->where('user_id', $usuario->id)
            ->where('id', '!=', $idSesionActual)
            ->delete();
    }

    /**
     * @return Collection<int, Bitacora>
     */
    public function accesosRecientes(Usuario $usuario): Collection
    {
        return Bitacora::query()
            ->where('id_usuario', $usuario->id)
            ->whereIn('accion', self::ACCIONES_ACCESO)
            ->orderByDesc('id')
            ->limit(self::CANTIDAD_ACCESOS)
            ->get(['accion', 'ip_origen', 'fecha_registro']);
    }

    /**
     * Verifica la contraseña actual con límite de intentos (RN05).
     *
     * @throws ValidationException|AutenticacionException
     */
    private function verificarContrasenaActual(Usuario $usuario, ?string $contrasena): void
    {
        $clave = "perfil-contrasena:{$usuario->id}";

        if (RateLimiter::tooManyAttempts($clave, self::MAX_INTENTOS)) {
            throw AutenticacionException::demasiadosIntentos(RateLimiter::availableIn($clave));
        }

        if (! $contrasena || ! Hash::check($contrasena, $usuario->getAuthPassword())) {
            RateLimiter::hit($clave, self::SEGUNDOS_BLOQUEO);

            throw ValidationException::withMessages([
                'contrasena_actual' => $contrasena ? 'La contraseña actual no es correcta.' : 'Confirme su contraseña actual para cambiar el correo.',
            ]);
        }

        RateLimiter::clear($clave);
    }

    /**
     * Datos del usuario para la bitácora (RN08).
     */
    private function instantanea(Usuario $usuario): array
    {
        return Arr::only($usuario->attributesToArray(), ['id', ...self::CAMPOS_EDITABLES]);
    }
}
