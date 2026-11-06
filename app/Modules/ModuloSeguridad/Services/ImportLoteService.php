<?php

namespace App\Modules\ModuloSeguridad\Services;

use App\Modules\ModuloPacientes\Requests\PacienteRequest;
use App\Modules\ModuloPacientes\Services\PacienteService;
use App\Modules\ModuloSeguridad\Models\Rol;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Requests\UsuarioRequest;
use App\Shared\Support\LectorCsv;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CU04 – Cargar Usuarios por Lotes (CSV).
 * Reutiliza las validaciones y los servicios de alta de CU02 (personal) y CU11 (pacientes).
 */
class ImportLoteService
{
    public const PERSONAL = 'personal';

    public const PACIENTES = 'pacientes';

    public const TIPOS = [self::PERSONAL, self::PACIENTES];

    /** RN01: filas de datos por archivo (cada cuenta cifra su contraseña con BCRYPT). */
    public const MAX_FILAS = 200;

    /** RN02: columnas de cada tipo, en el orden de la plantilla. */
    public const COLUMNAS = [
        self::PERSONAL => ['ci', 'nombre', 'apellido', 'correo', 'telefono', 'sexo', 'roles', 'contrasena'],
        self::PACIENTES => ['ci', 'nombre', 'apellido', 'correo', 'telefono', 'sexo', 'fecha_nacimiento', 'tipo_sangre',
            'contacto_nombre', 'contacto_apellido', 'contacto_parentesco', 'contacto_telefono', 'contrasena'],
    ];

    private const COLUMNAS_OBLIGATORIAS = [
        self::PERSONAL => ['ci', 'nombre', 'apellido', 'correo', 'roles'],
        self::PACIENTES => ['ci', 'nombre', 'apellido', 'correo', 'fecha_nacimiento'],
    ];

    /** Fila de ejemplo de cada plantilla. */
    private const EJEMPLOS = [
        self::PERSONAL => ['1234567', 'Rosa', 'Mamani', 'rosa.mamani@vidasalud.com', '70012345', 'F', 'Recepcionista', ''],
        self::PACIENTES => ['7654321', 'Pedro', 'Quispe', 'pedro.quispe@gmail.com', '71234567', 'M', '1992-04-15', 'O+',
            'Ana', 'Quispe', 'Esposa', '72345678', ''],
    ];

    /** RN04: separador de varios roles en una celda. */
    private const SEPARADOR_ROLES = '|';

    /** RN06: largo de la contraseña temporal generada. */
    private const LARGO_CONTRASENA_TEMPORAL = 12;

    /** Tiempo máximo de la importación, en segundos (ver "Rendimiento" en CICLO2.md). */
    private const TIEMPO_MAXIMO_SEGUNDOS = 300;

    public function __construct(
        private readonly LectorCsv $lector,
        private readonly UsuarioService $usuarios,
        private readonly PacienteService $pacientes,
        private readonly BitacoraService $bitacora,
    ) {}

    /**
     * Plantilla CSV para Excel: UTF-8 con BOM (para que muestre las tildes) y separador ';'.
     */
    public function plantilla(string $tipo): string
    {
        $flujo = fopen('php://temp', 'r+');
        fputcsv($flujo, self::COLUMNAS[$tipo], ';', '"', '');
        fputcsv($flujo, self::EJEMPLOS[$tipo], ';', '"', '');
        rewind($flujo);
        $contenido = stream_get_contents($flujo);
        fclose($flujo);

        return "\xEF\xBB\xBF".$contenido;
    }

    /**
     * Valida el archivo sin escribir nada (flujo 3): resumen y detalle por fila.
     *
     * @return array{tipo: string, resumen: array{total: int, validas: int, con_errores: int}, filas: array<int, array>}
     *
     * @throws ValidationException  Errores de todo el archivo (E2).
     */
    public function analizar(UploadedFile $archivo, string $tipo): array
    {
        $filas = $this->validarFilas($this->leer($archivo, $tipo), $tipo);
        $conErrores = $filas->filter(fn (array $fila) => $fila['errores'] !== [])->count();

        return [
            'tipo' => $tipo,
            'resumen' => ['total' => $filas->count(), 'validas' => $filas->count() - $conErrores, 'con_errores' => $conErrores],
            // Los datos se devuelven sin la contraseña.
            'filas' => $filas->map(fn (array $fila) => [
                'fila' => $fila['numero'],
                'datos' => [...collect($fila['datos'])->except(['contrasena', 'roles'])->all(), 'roles' => $fila['roles_nombres']],
                'errores' => $fila['errores'],
            ])->values()->all(),
        ];
    }

    /**
     * Revalida e importa todo o nada (RN07).
     *
     * @return array{tipo: string, creados: int, credenciales: array<int, array{fila: int, nombre_completo: string, correo: string, contrasena_temporal: string}>}
     *
     * @throws ValidationException  Si el archivo tiene errores (E2, E4).
     */
    public function importar(UploadedFile $archivo, string $tipo, Usuario $actor): array
    {
        $filas = $this->validarFilas($this->leer($archivo, $tipo), $tipo);
        $conErrores = $filas->filter(fn (array $fila) => $fila['errores'] !== [])->count();

        if ($conErrores > 0) {
            throw ValidationException::withMessages(['archivo' => $conErrores === 1
                ? 'El archivo tiene 1 fila con errores. Valídelo y corríjalo antes de importar.'
                : "El archivo tiene {$conErrores} filas con errores. Valídelo y corríjalo antes de importar."]);
        }

        set_time_limit(self::TIEMPO_MAXIMO_SEGUNDOS);

        return DB::transaction(function () use ($filas, $tipo, $actor, $archivo) {
            $credenciales = [];

            foreach ($filas as $fila) {
                $datos = $fila['datos'];

                // RN06: sin contraseña en el archivo, se genera una temporal que se muestra una sola vez.
                if (empty($datos['contrasena'])) {
                    $datos['contrasena'] = Str::password(self::LARGO_CONTRASENA_TEMPORAL, symbols: false);
                    $credenciales[] = [
                        'fila' => $fila['numero'],
                        'nombre_completo' => "{$datos['nombre']} {$datos['apellido']}",
                        'correo' => $datos['correo'],
                        'contrasena_temporal' => $datos['contrasena'],
                    ];
                }

                $tipo === self::PERSONAL
                    ? $this->usuarios->crear($datos, $actor)
                    : $this->pacientes->crear($datos, $actor);
            }

            $this->bitacora->registrar('IMPORTACION', $tipo === self::PERSONAL ? 'usuario' : 'paciente', $actor->id, datosNuevos: [
                'tipo' => $tipo,
                'archivo' => $archivo->getClientOriginalName(),
                'cuentas_creadas' => $filas->count(),
                'ci' => $filas->pluck('datos.ci')->all(),
            ]);

            return ['tipo' => $tipo, 'creados' => $filas->count(), 'credenciales' => $credenciales];
        });
    }

    /**
     * Lee el archivo y verifica que tenga filas, no exceda el límite y traiga
     * las columnas obligatorias (E2).
     *
     * @throws ValidationException
     */
    private function leer(UploadedFile $archivo, string $tipo): array
    {
        $contenido = $this->lector->leer($archivo->getRealPath());

        if ($contenido['encabezados'] === []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo está vacío.']);
        }

        $faltantes = array_diff(self::COLUMNAS_OBLIGATORIAS[$tipo], $contenido['encabezados']);
        if ($faltantes !== []) {
            throw ValidationException::withMessages(['archivo' => 'Faltan las columnas: '.implode(', ', $faltantes).'. Use la plantilla.']);
        }

        if ($contenido['filas'] === []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no tiene filas de datos.']);
        }

        if (count($contenido['filas']) > self::MAX_FILAS) {
            throw ValidationException::withMessages(['archivo' => 'El archivo tiene '.count($contenido['filas']).' filas; el máximo es '.self::MAX_FILAS.'. Divídalo en varios archivos.']);
        }

        return $contenido['filas'];
    }

    /**
     * Normaliza y valida cada fila con las reglas de CU02/CU11 (RN03–RN06) y
     * detecta CI y correos repetidos dentro del archivo.
     *
     * @return Collection<int, array{numero: int, datos: array, roles_nombres: array, errores: array<string, string>}>
     */
    private function validarFilas(array $filas, string $tipo): Collection
    {
        $roles = $tipo === self::PERSONAL ? $this->rolesAsignables() : collect();
        $vistos = ['ci' => [], 'correo' => []];

        return collect($filas)->map(function (array $fila) use ($tipo, $roles, &$vistos) {
            $valores = $fila['valores'];
            $errores = [];

            $datos = [
                ...UsuarioRequest::normalizarCuenta($valores),
                'sexo' => $this->normalizarSexo($valores['sexo'] ?? ''),
                'contrasena' => ($valores['contrasena'] ?? '') !== '' ? $valores['contrasena'] : null,
            ];
            $nombresRoles = [];

            if ($tipo === self::PERSONAL) {
                [$datos['roles'], $nombresRoles, $errorRoles] = $this->resolverRoles($valores['roles'] ?? '', $roles);
                if ($errorRoles) {
                    $errores['roles'] = $errorRoles;
                }
            } else {
                $datos['fecha_nacimiento'] = $this->normalizarFecha($valores['fecha_nacimiento'] ?? '');
                $datos['tipo_sangre'] = ($valores['tipo_sangre'] ?? '') !== '' ? Str::upper(str_replace(' ', '', $valores['tipo_sangre'])) : null;
                $datos['contacto'] = PacienteRequest::contactoNormalizado([
                    'nombre' => $valores['contacto_nombre'] ?? '',
                    'apellido' => $valores['contacto_apellido'] ?? '',
                    'parentesco' => $valores['contacto_parentesco'] ?? '',
                    'telefono' => $valores['contacto_telefono'] ?? '',
                ]);
            }

            $validador = Validator::make($datos, $this->reglas($tipo), $this->mensajes($tipo));
            foreach ($validador->errors()->toArray() as $campo => $mensajes) {
                // 'roles.0' → 'roles'; los campos del contacto conservan su nombre.
                $errores[preg_replace('/\.\d+$/', '', $campo)] ??= $mensajes[0];
            }

            // RN03: el CI y el correo no se pueden repetir dentro del archivo.
            foreach (['ci', 'correo'] as $campo) {
                $clave = Str::lower($datos[$campo]);
                if ($clave === '') {
                    continue;
                }
                if (isset($vistos[$campo][$clave])) {
                    $errores[$campo] ??= ($campo === 'ci' ? 'El CI' : 'El correo')." se repite en la fila {$vistos[$campo][$clave]}.";
                } else {
                    $vistos[$campo][$clave] = $fila['numero'];
                }
            }

            return ['numero' => $fila['numero'], 'datos' => $datos, 'roles_nombres' => $nombresRoles, 'errores' => $errores];
        });
    }

    private function reglas(string $tipo): array
    {
        $contrasena = ['nullable', ...UsuarioRequest::POLITICA_CONTRASENA];

        return $tipo === self::PERSONAL
            ? [...UsuarioRequest::reglasCuenta(), 'roles' => ['required', 'array', 'min:1'], 'contrasena' => $contrasena]
            : [...UsuarioRequest::reglasCuenta(), ...PacienteRequest::reglasDatosPaciente(), 'contrasena' => $contrasena];
    }

    private function mensajes(string $tipo): array
    {
        return $tipo === self::PERSONAL
            ? UsuarioRequest::mensajesCuenta()
            : [
                ...UsuarioRequest::mensajesCuenta(),
                ...PacienteRequest::mensajesPaciente(),
                // RN05: en el archivo se admiten dos formatos (p. ej., 31/02/2000 no existe).
                'fecha_nacimiento.date_format' => 'La fecha de nacimiento no es válida (use AAAA-MM-DD o DD/MM/AAAA).',
            ];
    }

    /**
     * RN04: roles activos que se pueden asignar desde aquí (no Médico ni Paciente), por nombre en minúsculas.
     *
     * @return Collection<string, Rol>
     */
    private function rolesAsignables(): Collection
    {
        return Rol::query()
            ->where('activo', true)
            ->whereNotIn('nombre', [Rol::MEDICO, Rol::PACIENTE])
            ->get()
            ->keyBy(fn (Rol $rol) => Str::lower(Str::ascii($rol->nombre)));
    }

    /**
     * 'Administrador|recepcionista' → ids, nombres y el primer error encontrado.
     *
     * @return array{0: array<int>, 1: array<int, string>, 2: ?string}
     */
    private function resolverRoles(string $celda, Collection $roles): array
    {
        $ids = [];
        $nombres = [];

        foreach (array_filter(array_map('trim', explode(self::SEPARADOR_ROLES, $celda))) as $nombre) {
            $clave = Str::lower(Str::ascii($nombre));

            if (in_array($clave, [Str::lower(Rol::MEDICO), Str::lower(Rol::PACIENTE)], true)) {
                return [[], [], "El rol {$nombre} no se carga aquí: registre médicos en Personal Médico y pacientes con la carga de pacientes."];
            }

            $rol = $roles->get($clave);
            if ($rol === null) {
                return [[], [], "El rol {$nombre} no existe o está inactivo."];
            }

            $ids[] = $rol->id;
            $nombres[] = $rol->nombre;
        }

        return [array_values(array_unique($ids)), array_values(array_unique($nombres)), null];
    }

    /** RN05: M/F/O o Masculino/Femenino/Otro; cualquier otro valor se valida como inválido. */
    private function normalizarSexo(string $valor): ?string
    {
        $limpio = Str::lower(Str::ascii(trim($valor)));

        return match (true) {
            $limpio === '' => null,
            in_array($limpio, ['m', 'masculino', 'hombre'], true) => 'M',
            in_array($limpio, ['f', 'femenino', 'mujer'], true) => 'F',
            in_array($limpio, ['o', 'otro'], true) => 'O',
            default => $valor,
        };
    }

    /** RN05: AAAA-MM-DD o DD/MM/AAAA (también con '-' o '.'); si no se reconoce, se deja tal cual para que falle la validación. */
    private function normalizarFecha(string $valor): string
    {
        $valor = trim($valor);

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $valor, $partes) && checkdate((int) $partes[2], (int) $partes[1], (int) $partes[3])) {
            return CarbonImmutable::create((int) $partes[3], (int) $partes[2], (int) $partes[1])->toDateString();
        }

        return $valor;
    }
}
