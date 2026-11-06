<?php

namespace App\Modules\ModuloInteligenciaReportes\Services\Voz;

/**
 * CU22 – Traduce una consulta en lenguaje natural a una intención de reporte.
 *
 * El intérprete NUNCA genera SQL ni elige columnas: solo devuelve valores tomados de
 * las listas del contexto (tipos de reporte, ids de médicos, especialidades, etc.).
 * ConsultaVozService vuelve a validar todo contra la lista blanca de CU21.
 */
interface InterpreteConsulta
{
    /**
     * @param  array{hoy: \Carbon\CarbonImmutable, reportes: array, medicos: array, especialidades: array,
     *   modalidades: array, metodos: array, estados: array, estados_pago: array}  $contexto
     * @return array{entendido: bool, tipo: ?string, desde: ?string, hasta: ?string, medico: ?int,
     *   especialidad: ?int, modalidad: ?int, metodo: ?int, estado: ?string, estado_pago: ?string,
     *   agrupar: ?string, notas: string[]}
     */
    public function interpretar(string $consulta, array $contexto): array;

    /** 'reglas' o 'ia', para informar al usuario qué intérprete respondió. */
    public function nombre(): string;
}
