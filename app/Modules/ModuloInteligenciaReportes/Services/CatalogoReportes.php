<?php

namespace App\Modules\ModuloInteligenciaReportes\Services;

/**
 * Lista blanca de reportes de CU21 (también la usa CU22): qué reportes existen, qué
 * filtros admite cada uno y por qué columnas se puede agrupar. Ningún nombre de columna
 * ni expresión SQL llega desde el usuario: solo se eligen claves de esta lista.
 */
final class CatalogoReportes
{
    public const CITAS = 'citas';

    public const ATENCIONES = 'atenciones';

    public const INASISTENCIAS = 'inasistencias';

    public const INGRESOS = 'ingresos';

    /** RN02: filtros admitidos por reporte (además del rango de fechas). */
    public const REPORTES = [
        self::CITAS => [
            'titulo' => 'Citas por estado',
            'descripcion' => 'Citas agendadas en el periodo según su estado, médico, especialidad y modalidad.',
            'filtros' => ['medico', 'especialidad', 'estado', 'modalidad'],
            'agrupaciones' => ['estado', 'medico', 'especialidad', 'modalidad', 'dia', 'mes'],
            'agrupacion_por_defecto' => 'estado',
            'personal' => true,
        ],
        self::ATENCIONES => [
            'titulo' => 'Atenciones médicas',
            'descripcion' => 'Consultas registradas (CU17), pacientes atendidos y recetas emitidas (CU18).',
            'filtros' => ['medico', 'especialidad'],
            'agrupaciones' => ['medico', 'especialidad', 'dia', 'mes'],
            'agrupacion_por_defecto' => 'medico',
            'personal' => true,
        ],
        self::INASISTENCIAS => [
            'titulo' => 'Inasistencias',
            'descripcion' => 'Pacientes que no asistieron frente a las citas atendidas, y la tasa de inasistencia.',
            'filtros' => ['medico', 'especialidad', 'modalidad'],
            'agrupaciones' => ['medico', 'especialidad', 'modalidad', 'dia', 'mes'],
            'agrupacion_por_defecto' => 'medico',
            'personal' => true,
        ],
        self::INGRESOS => [
            'titulo' => 'Ingresos',
            'descripcion' => 'Pagos de citas (CU24) por método, médico y especialidad.',
            'filtros' => ['medico', 'especialidad', 'metodo', 'estado_pago'],
            'agrupaciones' => ['metodo', 'medico', 'especialidad', 'estado_pago', 'dia', 'mes'],
            'agrupacion_por_defecto' => 'metodo',
            // RN04: los ingresos de la clínica no forman parte del alcance personal del médico.
            'personal' => false,
        ],
    ];

    /** Nombre visible de cada agrupación (la columna la decide ReporteService). */
    public const AGRUPACIONES = [
        'estado' => 'Estado',
        'medico' => 'Médico',
        'especialidad' => 'Especialidad',
        'modalidad' => 'Modalidad',
        'metodo' => 'Método de pago',
        'estado_pago' => 'Estado del pago',
        'dia' => 'Día',
        'mes' => 'Mes',
    ];

    /** RN03: límites de los reportes. */
    public const DIAS_MAXIMOS = 366;

    public const FILAS_DETALLE_EN_PANTALLA = 500;

    public const FILAS_MAXIMAS_EXPORTACION = 10000;

    public static function existe(string $tipo): bool
    {
        return isset(self::REPORTES[$tipo]);
    }

    public static function definicion(string $tipo): array
    {
        return self::REPORTES[$tipo];
    }
}
