<?php

namespace App\Modules\ModuloPacientes\Services;

use App\Modules\ModuloPacientes\Models\ContactoEmergencia;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloSeguridad\Models\Rol;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Repositories\UsuarioRepository;
use App\Modules\ModuloSeguridad\Services\AuthService;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU11 – Gestionar Pacientes.
 */
class PacienteService
{
    /** Datos de 'usuario' que se registran y modifican desde la ficha del paciente. */
    private const CAMPOS_USUARIO = ['ci', 'nombre', 'apellido', 'correo', 'telefono', 'sexo'];

    private const PREFIJO_CODIGO = 'PAC-';

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly AuthService $auth,
        private readonly BitacoraService $bitacora,
    ) {}

    /**
     * @param  array{buscar?: ?string, activo?: ?bool}  $filtros
     */
    public function listar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->consultaDetallada()
            ->join('usuario', 'usuario.id', '=', 'paciente.id_usuario')
            ->select('paciente.*')
            ->when($filtros['buscar'] ?? null, function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('usuario.ci', 'ilike', $patron)
                    ->orWhere('usuario.correo', 'ilike', $patron)
                    ->orWhere('paciente.codigo', 'ilike', $patron)
                    ->orWhereRaw("(usuario.nombre || ' ' || usuario.apellido) ilike ?", [$patron]));
            })
            ->when(isset($filtros['activo']), fn (Builder $query) => $query->where('paciente.activo', $filtros['activo']))
            ->orderBy('usuario.apellido')
            ->orderBy('usuario.nombre')
            ->paginate($porPagina);
    }

    public function detalle(Paciente $paciente): Paciente
    {
        return $this->consultaDetallada()->findOrFail($paciente->id_usuario);
    }

    /**
     * Registra usuario + paciente + contacto + rol Paciente en una transacción (RN06).
     *
     * @param  Usuario|null  $actor  null en el autorregistro (RN07): el autor es el propio paciente.
     */
    public function crear(array $datos, ?Usuario $actor): Paciente
    {
        return DB::transaction(function () use ($datos, $actor) {
            $usuario = Usuario::create([
                ...Arr::only($datos, self::CAMPOS_USUARIO),
                'contrasena' => $datos['contrasena'], // El cast 'hashed' aplica BCRYPT.
            ]);

            $paciente = Paciente::create([
                'id_usuario' => $usuario->id,
                'codigo' => $this->siguienteCodigo(),
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'tipo_sangre' => $datos['tipo_sangre'] ?? null,
            ]);

            $this->guardarContacto($paciente, $datos['contacto'] ?? null);
            $this->usuarios->sincronizarRoles($usuario, [$this->idRolPaciente()]);

            $paciente = $this->detalle($paciente);
            $this->bitacora->registrar(
                $actor ? 'INSERT' : 'REGISTRO', 'paciente', $actor?->id ?? $usuario->id,
                datosNuevos: $this->instantanea($paciente),
            );

            return $paciente;
        });
    }

    public function actualizar(Paciente $paciente, array $datos, Usuario $actor): Paciente
    {
        return DB::transaction(function () use ($paciente, $datos, $actor) {
            $antes = $this->instantanea($this->detalle($paciente));

            $usuario = $paciente->usuario;
            $usuario->fill(Arr::only($datos, self::CAMPOS_USUARIO));
            if (! empty($datos['contrasena'])) {
                $usuario->contrasena = $datos['contrasena'];
            }
            $usuario->save();

            $paciente->update(Arr::only($datos, ['fecha_nacimiento', 'tipo_sangre']));
            $this->guardarContacto($paciente, $datos['contacto'] ?? null);

            $paciente = $this->detalle($paciente);
            $despues = $this->instantanea($paciente);
            if (! empty($datos['contrasena'])) {
                $despues['contrasena'] = 'restablecida';
            }

            $this->bitacora->registrar('UPDATE', 'paciente', $actor->id, $antes, $despues);

            return $paciente;
        });
    }

    /**
     * Mi ficha médica: el paciente solo cambia su tipo de sangre y su contacto (RN08).
     */
    public function actualizarFicha(Paciente $paciente, array $datos, Usuario $actor): Paciente
    {
        return DB::transaction(function () use ($paciente, $datos, $actor) {
            $antes = $this->instantanea($this->detalle($paciente));

            $paciente->update(['tipo_sangre' => $datos['tipo_sangre'] ?? null]);
            $this->guardarContacto($paciente, $datos['contacto'] ?? null);

            $paciente = $this->detalle($paciente);
            $this->bitacora->registrar('UPDATE', 'paciente', $actor->id, $antes, $this->instantanea($paciente));

            return $paciente;
        });
    }

    /**
     * RN09: no se desactiva con citas pendientes; si se queda sin ningún
     * rol habilitado, se cierran sus sesiones.
     *
     * @throws ValidationException
     */
    public function cambiarEstado(Paciente $paciente, bool $activo, Usuario $actor): Paciente
    {
        if (! $activo) {
            $pendientes = $paciente->citasPendientes()->count();

            if ($pendientes > 0) {
                throw ValidationException::withMessages(['activo' => $pendientes === 1
                    ? 'Tiene 1 cita pendiente. Reprográmela o cancélela antes de desactivarlo.'
                    : "Tiene {$pendientes} citas pendientes. Reprográmelas o cancélelas antes de desactivarlo.",
                ]);
            }
        }

        if ($paciente->activo === $activo) {
            return $this->detalle($paciente);
        }

        return DB::transaction(function () use ($paciente, $activo, $actor) {
            $paciente->update(['activo' => $activo]);

            // Se recarga el usuario para que la verificación vea el nuevo estado del paciente.
            $usuario = $paciente->usuario->fresh();
            if (! $activo && ! $this->auth->tieneAcceso($usuario)) {
                $this->usuarios->cerrarSesiones($usuario);
            }

            $this->bitacora->registrar(
                'UPDATE', 'paciente', $actor->id,
                ['id' => $paciente->id_usuario, 'activo' => ! $activo],
                ['id' => $paciente->id_usuario, 'activo' => $activo],
            );

            return $this->detalle($paciente);
        });
    }

    /**
     * RN05: crea, actualiza o elimina el contacto de emergencia del paciente.
     *
     * @param  array{nombre: string, apellido: string, parentesco: string, telefono: string}|null  $datos
     */
    private function guardarContacto(Paciente $paciente, ?array $datos): void
    {
        $actual = $paciente->contactoEmergencia;

        if ($datos === null) {
            if ($actual) {
                $paciente->update(['id_contacto_emergencia' => null]);
                $actual->delete();
            }
        } elseif ($actual) {
            $actual->update(Arr::only($datos, ['nombre', 'apellido', 'parentesco', 'telefono']));
        } else {
            $contacto = ContactoEmergencia::create(Arr::only($datos, ['nombre', 'apellido', 'parentesco', 'telefono']));
            $paciente->update(['id_contacto_emergencia' => $contacto->id]);
        }

        $paciente->unsetRelation('contactoEmergencia');
    }

    /**
     * RN02: siguiente código correlativo (PAC-0001, PAC-0002, …).
     */
    private function siguienteCodigo(): string
    {
        $ultimo = Paciente::query()
            ->where('codigo', '~', '^'.self::PREFIJO_CODIGO.'[0-9]+$')
            ->max(DB::raw('CAST(SUBSTRING(codigo FROM '.(strlen(self::PREFIJO_CODIGO) + 1).') AS INTEGER)'));

        return sprintf('%s%04d', self::PREFIJO_CODIGO, ((int) $ultimo) + 1);
    }

    private function idRolPaciente(): int
    {
        return (int) Rol::query()->where('nombre', Rol::PACIENTE)->value('id');
    }

    /**
     * Usuario, contacto de emergencia y cantidad de citas pendientes.
     */
    private function consultaDetallada(): Builder
    {
        return Paciente::query()
            ->with(['usuario', 'contactoEmergencia'])
            ->withCount('citasPendientes');
    }

    /**
     * Estado del paciente para la bitácora (RN10). Nunca incluye la contraseña.
     */
    private function instantanea(Paciente $paciente): array
    {
        return [
            'id' => $paciente->id_usuario,
            'codigo' => $paciente->codigo,
            'fecha_nacimiento' => $paciente->fecha_nacimiento?->toDateString(),
            'tipo_sangre' => $paciente->tipo_sangre,
            'activo' => $paciente->activo,
            ...Arr::only($paciente->usuario->attributesToArray(), self::CAMPOS_USUARIO),
            'contacto' => $paciente->contactoEmergencia
                ? Arr::only($paciente->contactoEmergencia->attributesToArray(), ['nombre', 'apellido', 'parentesco', 'telefono'])
                : null,
        ];
    }
}
