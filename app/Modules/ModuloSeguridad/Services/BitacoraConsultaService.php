<?php

namespace App\Modules\ModuloSeguridad\Services;

use App\Modules\ModuloSeguridad\Models\Bitacora;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Repositories\BitacoraRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CU06 – Consultar Bitácora de Auditoría. Solo lectura (RN01): el único
 * evento que agrega es el de la propia exportación (RN07).
 */
class BitacoraConsultaService
{
    /** RN06: máximo de eventos por archivo exportado. */
    public const MAXIMO_EXPORTACION = 5000;

    /** RN05: claves cuyo valor nunca se muestra, aunque algún caso de uso lo haya registrado. */
    private const CAMPOS_SENSIBLES = ['contrasena', 'contrasena_temporal', 'password', 'remember_token', 'token'];

    private const VALOR_OCULTO = '[oculto]';

    /** RN04: nombre legible de cada acción registrada por los casos de uso. */
    public const ETIQUETAS_ACCION = [
        'INSERT' => 'Registro',
        'UPDATE' => 'Modificación',
        'DELETE' => 'Eliminación',
        'LOGIN' => 'Inicio de sesión',
        'LOGOUT' => 'Cierre de sesión',
        'LOGIN_FALLIDO' => 'Inicio de sesión fallido',
        'LOGIN_RECHAZADO' => 'Acceso rechazado',
        'REGISTRO' => 'Autorregistro de paciente',
        'IMPORTACION' => 'Importación por lotes',
        'REPROGRAMACION' => 'Reprogramación de cita',
        'CANCELACION' => 'Cancelación de cita',
        'ASISTENCIA' => 'Control de asistencia',
        'EXPORTACION' => 'Exportación de bitácora',
        'ACCESO_HISTORIAL' => 'Consulta de historial clínico',
        'REENVIO' => 'Reenvío de notificación',
        'CONSULTA_VOZ' => 'Consulta de reporte por voz',
    ];

    /** RN04: nombre legible de cada tabla auditada. */
    public const ETIQUETAS_TABLA = [
        'usuario' => 'Usuarios',
        'rol' => 'Roles y permisos',
        'paciente' => 'Pacientes',
        'enfermedad' => 'Catálogo de enfermedades',
        'paciente_enfermedad' => 'Antecedentes de pacientes',
        'medico' => 'Médicos',
        'especialidad' => 'Especialidades',
        'horario_atencion' => 'Horarios de atención',
        'indisponibilidad_medico' => 'Licencias médicas',
        'cita_medica' => 'Citas médicas',
        'consulta_medica' => 'Consultas médicas',
        'receta_medica' => 'Recetas médicas',
        'notificacion' => 'Notificaciones',
        'reporte' => 'Reportes',
        'pago_cita' => 'Pagos',
        'bitacora' => 'Bitácora',
    ];

    public function __construct(
        private readonly BitacoraRepository $bitacoras,
        private readonly BitacoraService $bitacora,
    ) {}

    public function listar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->bitacoras->paginar($filtros, $porPagina);
    }

    /**
     * Acciones y tablas que existen en la bitácora, para los filtros de la pantalla.
     *
     * @return array{acciones: array<array{valor: string, etiqueta: string}>, tablas: array<array{valor: string, etiqueta: string}>}
     */
    public function catalogo(): array
    {
        $opciones = fn (string $columna, callable $etiqueta) => $this->bitacoras->valoresDistintos($columna)
            ->map(fn (string $valor) => ['valor' => $valor, 'etiqueta' => $etiqueta($valor)])
            ->sortBy('etiqueta', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'acciones' => $opciones('accion', self::etiquetaAccion(...)),
            'tablas' => $opciones('tabla_afectada', self::etiquetaTabla(...)),
        ];
    }

    /**
     * Datos del evento sin valores sensibles (RN05) y la comparación campo por campo (RN04).
     *
     * @return array{datos_anteriores: ?array, datos_nuevos: ?array, cambios: array<array{campo: string, anterior: mixed, nuevo: mixed, modificado: bool}>}
     */
    public function detalle(Bitacora $evento): array
    {
        $anteriores = self::ocultarSensibles($evento->datos_anteriores);
        $nuevos = self::ocultarSensibles($evento->datos_nuevos);

        return [
            'datos_anteriores' => $anteriores,
            'datos_nuevos' => $nuevos,
            'cambios' => $this->comparar($anteriores, $nuevos),
        ];
    }

    /**
     * Verifica el límite (RN06), registra la exportación (RN07) y devuelve la función
     * que escribe el CSV en la salida: separador ';' y BOM, como lo abre Excel en español.
     *
     * @throws ValidationException
     */
    public function exportar(array $filtros, Usuario $actor): \Closure
    {
        $cantidad = $this->bitacoras->contar($filtros);

        if ($cantidad === 0) {
            throw ValidationException::withMessages(['exportacion' => 'No hay eventos que coincidan con los filtros.']);
        }

        if ($cantidad > self::MAXIMO_EXPORTACION) {
            throw ValidationException::withMessages(['exportacion' => sprintf(
                'Hay %s eventos con estos filtros y se pueden exportar hasta %s. Acote el rango de fechas o los filtros.',
                number_format($cantidad, 0, ',', '.'),
                number_format(self::MAXIMO_EXPORTACION, 0, ',', '.'),
            )]);
        }

        $this->bitacora->registrar('EXPORTACION', 'bitacora', $actor->id, datosNuevos: [
            'filtros' => array_filter([
                ...Arr::except($filtros, ['desde', 'hasta']),
                'desde' => $filtros['desde']?->toDateString(),
                // copy(): Carbon es mutable y el mismo objeto filtra la exportación.
                'hasta' => $filtros['hasta']?->copy()->subDay()->toDateString(),
            ]),
            'eventos' => $cantidad,
        ]);

        return function () use ($filtros) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\u{FEFF}");
            fputcsv($salida, ['id', 'fecha', 'usuario', 'correo', 'accion', 'modulo', 'ip', 'datos_anteriores', 'datos_nuevos'], ';', '"', '');

            foreach ($this->bitacoras->recorrer($filtros) as $evento) {
                fputcsv($salida, [
                    $evento->id,
                    $evento->fecha_registro?->format('Y-m-d H:i:s'),
                    $evento->usuario ? "{$evento->usuario->nombre} {$evento->usuario->apellido}" : '',
                    $evento->usuario?->correo ?? ($evento->datos_nuevos['correo'] ?? ''),
                    self::etiquetaAccion($evento->accion),
                    self::etiquetaTabla($evento->tabla_afectada),
                    $evento->ip_origen,
                    self::json(self::ocultarSensibles($evento->datos_anteriores)),
                    self::json(self::ocultarSensibles($evento->datos_nuevos)),
                ], ';', '"', '');
            }

            fclose($salida);
        };
    }

    /** 'LOGIN_FALLIDO' → 'Inicio de sesión fallido'; una acción nueva se muestra legible igual. */
    public static function etiquetaAccion(string $accion): string
    {
        return self::ETIQUETAS_ACCION[$accion] ?? Str::ucfirst(Str::lower(str_replace('_', ' ', $accion)));
    }

    /** 'horario_atencion' → 'Horarios de atención'. */
    public static function etiquetaTabla(string $tabla): string
    {
        return self::ETIQUETAS_TABLA[$tabla] ?? Str::ucfirst(str_replace('_', ' ', $tabla));
    }

    /**
     * Reemplaza, en cualquier nivel, el valor de las claves sensibles (RN05).
     */
    public static function ocultarSensibles(?array $datos): ?array
    {
        if ($datos === null) {
            return null;
        }

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && in_array(Str::lower($clave), self::CAMPOS_SENSIBLES, true)) {
                $datos[$clave] = self::VALOR_OCULTO;
            } elseif (is_array($valor)) {
                $datos[$clave] = self::ocultarSensibles($valor);
            }
        }

        return $datos;
    }

    /**
     * Une los campos de ambos estados en una lista. Los objetos anidados se aplanan
     * ('contacto.nombre'); las listas (roles, especialidades) se comparan completas.
     */
    private function comparar(?array $anteriores, ?array $nuevos): array
    {
        $antes = self::aplanar($anteriores ?? []);
        $despues = self::aplanar($nuevos ?? []);
        $campos = array_unique([...array_keys($despues), ...array_keys($antes)]);

        return array_map(fn (string $campo) => [
            'campo' => $campo,
            'anterior' => $antes[$campo] ?? null,
            'nuevo' => $despues[$campo] ?? null,
            // Solo hay "modificación" si existen ambos estados y el valor cambió.
            'modificado' => $anteriores !== null && $nuevos !== null && ($antes[$campo] ?? null) != ($despues[$campo] ?? null),
        ], $campos);
    }

    private static function aplanar(array $datos, string $prefijo = ''): array
    {
        $plano = [];

        foreach ($datos as $clave => $valor) {
            if (is_array($valor) && $valor !== [] && ! array_is_list($valor)) {
                $plano += self::aplanar($valor, "{$prefijo}{$clave}.");
            } else {
                $plano["{$prefijo}{$clave}"] = $valor;
            }
        }

        return $plano;
    }

    private static function json(?array $datos): string
    {
        return $datos === null ? '' : json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
