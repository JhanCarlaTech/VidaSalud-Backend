<?php

namespace App\Shared\Support;

use RuntimeException;

/**
 * Lector del script oficial de base de datos ('Base de Datos/script.sql').
 *
 * El script es la fuente de verdad del esquema. Esta clase lo separa en sus
 * secciones para que la migración ejecute solo la estructura (DDL) y el
 * seeder solo los datos iniciales, sin los SELECT de verificación finales.
 */
final class ScriptSqlClinico
{
    private const MARCA_DATOS = '-- 7. POBLACIÓN DE DATOS';

    private const MARCA_VERIFICACION = '-- CONSULTAS DE VERIFICACIÓN';

    /**
     * Sentencias de estructura: tipos ENUM, tablas e índices.
     */
    public static function estructura(): string
    {
        $script = self::leer();

        return substr($script, 0, self::posicion($script, self::MARCA_DATOS));
    }

    /**
     * Sentencias INSERT de los datos iniciales.
     */
    public static function datosIniciales(): string
    {
        $script = self::leer();
        $inicio = self::posicion($script, self::MARCA_DATOS);
        $fin = self::posicion($script, self::MARCA_VERIFICACION);

        return substr($script, $inicio, $fin - $inicio);
    }

    private static function leer(): string
    {
        $ruta = base_path('Base de Datos/script.sql');

        if (! is_file($ruta)) {
            throw new RuntimeException("No se encontró el script de base de datos en: {$ruta}");
        }

        // Se elimina el BOM UTF-8 si el editor lo agregó al guardar.
        return preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($ruta));
    }

    private static function posicion(string $script, string $marca): int
    {
        $posicion = strpos($script, $marca);

        if ($posicion === false) {
            throw new RuntimeException("El script.sql no contiene la sección '{$marca}'.");
        }

        return $posicion;
    }
}
