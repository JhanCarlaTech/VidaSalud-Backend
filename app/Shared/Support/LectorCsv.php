<?php

namespace App\Shared\Support;

use Illuminate\Support\Str;

/**
 * Lector de archivos CSV tal como los genera Excel (CU04, RN01):
 * - quita el BOM de UTF-8 y convierte de Windows-1252 si el archivo no es UTF-8;
 * - detecta el separador (';', ',' o tabulación) en la fila de encabezados;
 * - normaliza los encabezados: minúsculas, sin tildes y con '_' en lugar de espacios.
 */
final class LectorCsv
{
    private const SEPARADORES = [';', ',', "\t"];

    /**
     * @return array{encabezados: array<int, string>, filas: array<int, array{numero: int, valores: array<string, string>}>}
     *         'numero' es el número de fila en el archivo (la 1 es la de encabezados).
     */
    public function leer(string $ruta): array
    {
        $contenido = $this->comoUtf8((string) file_get_contents($ruta));

        if (trim($contenido) === '') {
            return ['encabezados' => [], 'filas' => []];
        }

        $primeraLinea = strtok($contenido, "\r\n");
        $separador = $this->separador($primeraLinea);

        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $encabezados = array_map(fn ($titulo) => self::normalizarEncabezado((string) $titulo), fgetcsv($flujo, null, $separador, '"', '') ?: []);
        $filas = [];
        $numero = 1;

        while (($valores = fgetcsv($flujo, null, $separador, '"', '')) !== false) {
            $numero++;

            // Filas vacías (incluidas las que Excel deja solo con separadores): se ignoran.
            if (array_filter($valores, fn ($valor) => trim((string) $valor) !== '') === []) {
                continue;
            }

            $fila = [];
            foreach ($encabezados as $indice => $encabezado) {
                if ($encabezado !== '') {
                    $fila[$encabezado] = trim((string) ($valores[$indice] ?? ''));
                }
            }

            $filas[] = ['numero' => $numero, 'valores' => $fila];
        }

        fclose($flujo);

        return ['encabezados' => array_values(array_filter($encabezados)), 'filas' => $filas];
    }

    /** 'Fecha de Nacimiento ' → 'fecha_de_nacimiento'; 'Contraseña' → 'contrasena'. */
    public static function normalizarEncabezado(string $titulo): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($titulo)))), '_');
    }

    private function comoUtf8(string $contenido): string
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        return mb_check_encoding($contenido, 'UTF-8') ? $contenido : mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
    }

    /** El separador que más aparece en la fila de encabezados. */
    private function separador(string $linea): string
    {
        $conteos = array_map(fn ($separador) => substr_count($linea, $separador), self::SEPARADORES);

        return self::SEPARADORES[array_search(max($conteos), $conteos, true)];
    }
}
