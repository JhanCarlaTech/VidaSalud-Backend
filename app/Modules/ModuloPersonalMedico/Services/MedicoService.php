<?php

namespace App\Modules\ModuloPersonalMedico\Services;

use App\Modules\ModuloPersonalMedico\Models\Especialidad;
use App\Modules\ModuloPersonalMedico\Models\Medico;
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
 * CU08 – Gestionar Personal Médico.
 */
class MedicoService
{
    /** Datos de 'usuario' que se registran y modifican desde el legajo del médico. */
    private const CAMPOS_USUARIO = ['ci', 'nombre', 'apellido', 'correo', 'telefono', 'sexo'];

    private const PREFIJO_CODIGO = 'MED-';

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly AuthService $auth,
        private readonly BitacoraService $bitacora,
    ) {}

    /**
     * @param  array{buscar?: ?string, especialidad?: ?int, activo?: ?bool}  $filtros
     */
    public function listar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->consultaDetallada()
            ->join('usuario', 'usuario.id', '=', 'medico.id_usuario')
            ->select('medico.*')
            ->when($filtros['buscar'] ?? null, function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('usuario.ci', 'ilike', $patron)
                    ->orWhere('usuario.correo', 'ilike', $patron)
                    ->orWhere('medico.codigo', 'ilike', $patron)
                    ->orWhereRaw("(usuario.nombre || ' ' || usuario.apellido) ilike ?", [$patron]));
            })
            ->when($filtros['especialidad'] ?? null, function (Builder $query, int $idEspecialidad) {
                $query->whereHas('especialidades', fn (Builder $especialidades) => $especialidades->where('especialidad.id', $idEspecialidad));
            })
            ->when(isset($filtros['activo']), fn (Builder $query) => $query->where('medico.activo', $filtros['activo']))
            ->orderBy('usuario.apellido')
            ->orderBy('usuario.nombre')
            ->paginate($porPagina);
    }

    public function detalle(Medico $medico): Medico
    {
        return $this->consultaDetallada()->findOrFail($medico->id_usuario);
    }

    /**
     * Registra usuario + médico + especialidades + rol Medico en una transacción (RN05).
     *
     * @throws ValidationException
     */
    public function crear(array $datos, Usuario $actor): Medico
    {
        $this->validarEspecialidadesNuevas($datos['especialidades'], []);

        return DB::transaction(function () use ($datos, $actor) {
            $usuario = Usuario::create([
                ...Arr::only($datos, self::CAMPOS_USUARIO),
                'contrasena' => $datos['contrasena'], // El cast 'hashed' aplica BCRYPT.
            ]);

            $medico = Medico::create([
                'id_usuario' => $usuario->id,
                'codigo' => $this->siguienteCodigo(),
                'salario' => $datos['salario'],
            ]);

            $medico->especialidades()->sync($datos['especialidades']);
            $this->usuarios->sincronizarRoles($usuario, [$this->idRolMedico()]);

            $medico = $this->detalle($medico);
            $this->bitacora->registrar('INSERT', 'medico', $actor->id, datosNuevos: $this->instantanea($medico));

            return $medico;
        });
    }

    /**
     * @throws ValidationException
     */
    public function actualizar(Medico $medico, array $datos, Usuario $actor): Medico
    {
        $actuales = $medico->especialidades()->pluck('especialidad.id')->all();
        $this->validarEspecialidadesNuevas($datos['especialidades'], $actuales);

        return DB::transaction(function () use ($medico, $datos, $actor) {
            $antes = $this->instantanea($this->detalle($medico));

            $usuario = $medico->usuario;
            $usuario->fill(Arr::only($datos, self::CAMPOS_USUARIO));
            if (! empty($datos['contrasena'])) {
                $usuario->contrasena = $datos['contrasena'];
            }
            $usuario->save();

            $medico->update(['salario' => $datos['salario']]);
            $medico->especialidades()->sync($datos['especialidades']);

            $medico = $this->detalle($medico);
            $despues = $this->instantanea($medico);
            if (! empty($datos['contrasena'])) {
                $despues['contrasena'] = 'restablecida';
            }

            $this->bitacora->registrar('UPDATE', 'medico', $actor->id, $antes, $despues);

            return $medico;
        });
    }

    /**
     * RN06 y RN07: no se desactiva con citas pendientes; si se queda sin
     * ningún rol habilitado, se cierran sus sesiones.
     *
     * @throws ValidationException
     */
    public function cambiarEstado(Medico $medico, bool $activo, Usuario $actor): Medico
    {
        if (! $activo) {
            $pendientes = $medico->citasPendientes()->count();

            if ($pendientes > 0) {
                $mensaje = $pendientes === 1
                    ? 'Tiene 1 cita pendiente. Reprográmela o cancélela antes de desactivarlo.'
                    : "Tiene {$pendientes} citas pendientes. Reprográmelas o cancélelas antes de desactivarlo.";

                throw ValidationException::withMessages(['activo' => $mensaje]);
            }
        }

        if ($medico->activo === $activo) {
            return $this->detalle($medico);
        }

        return DB::transaction(function () use ($medico, $activo, $actor) {
            $medico->update(['activo' => $activo]);

            // Se recarga el usuario para que la verificación vea el nuevo estado del médico.
            $usuario = $medico->usuario->fresh();
            if (! $activo && ! $this->auth->tieneAcceso($usuario)) {
                $this->usuarios->cerrarSesiones($usuario);
            }

            $this->bitacora->registrar(
                'UPDATE', 'medico', $actor->id,
                ['id' => $medico->id_usuario, 'activo' => ! $activo],
                ['id' => $medico->id_usuario, 'activo' => $activo],
            );

            return $this->detalle($medico);
        });
    }

    /**
     * RN04: solo se pueden agregar especialidades activas; las ya asignadas se conservan.
     *
     * @param  array<int>  $idsSolicitados
     * @param  array<int>  $idsActuales
     *
     * @throws ValidationException
     */
    private function validarEspecialidadesNuevas(array $idsSolicitados, array $idsActuales): void
    {
        $inactivas = Especialidad::query()
            ->whereIn('id', array_diff($idsSolicitados, $idsActuales))
            ->where('activo', false)
            ->pluck('nombre');

        if ($inactivas->isNotEmpty()) {
            throw ValidationException::withMessages([
                'especialidades' => 'No se puede asignar una especialidad inactiva: '.$inactivas->implode(', ').'.',
            ]);
        }
    }

    /**
     * RN02: siguiente código correlativo (MED-001, MED-002, …).
     */
    private function siguienteCodigo(): string
    {
        $ultimo = Medico::query()
            ->where('codigo', '~', '^'.self::PREFIJO_CODIGO.'[0-9]+$')
            ->max(DB::raw('CAST(SUBSTRING(codigo FROM '.(strlen(self::PREFIJO_CODIGO) + 1).') AS INTEGER)'));

        return sprintf('%s%03d', self::PREFIJO_CODIGO, ((int) $ultimo) + 1);
    }

    private function idRolMedico(): int
    {
        return (int) Rol::query()->where('nombre', Rol::MEDICO)->value('id');
    }

    /**
     * Usuario, especialidades y cantidad de citas pendientes.
     */
    private function consultaDetallada(): Builder
    {
        return Medico::query()
            ->with(['usuario', 'especialidades' => fn ($query) => $query->orderBy('especialidad.nombre')])
            ->withCount('citasPendientes');
    }

    /**
     * Estado del legajo para la bitácora (RN08). Nunca incluye la contraseña.
     */
    private function instantanea(Medico $medico): array
    {
        return [
            'id' => $medico->id_usuario,
            'codigo' => $medico->codigo,
            'salario' => $medico->salario,
            'activo' => $medico->activo,
            ...Arr::only($medico->usuario->attributesToArray(), self::CAMPOS_USUARIO),
            'especialidades' => $medico->especialidades->pluck('nombre')->all(),
        ];
    }
}
