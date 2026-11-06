<?php

namespace App\Modules\ModuloInteligenciaReportes\Services\Voz;

use Anthropic\Client;
use RuntimeException;

/**
 * CU22 – Intérprete con Claude (decisión D6), activo solo si ANTHROPIC_API_KEY está configurada.
 *
 * Claude no ve la base de datos ni genera SQL: recibe la consulta y las opciones válidas, y
 * devuelve una intención con salida estructurada (JSON Schema con enums de esas opciones).
 * Los nombres que devuelve se traducen a ids aquí, y ConsultaVozService vuelve a validar todo.
 * Cualquier fallo (red, límite, negativa, JSON inesperado) lanza una excepción para que el
 * servicio use el intérprete por reglas.
 */
class InterpreteClaude implements InterpreteConsulta
{
    /** Respuesta corta: un objeto JSON pequeño. */
    private const MAXIMO_TOKENS = 2048;

    private const INSTRUCCIONES = <<<'TXT'
        Eres el asistente de reportes de una clínica en Bolivia. Recibes una consulta en español,
        dictada por voz o escrita por personal administrativo, y la conviertes en los parámetros de
        un reporte. Solo puedes elegir valores de las listas que te da el usuario; si algo de la
        consulta no corresponde a ninguna opción, déjalo en null y explícalo en "notas".
        Las fechas van en formato AAAA-MM-DD y se calculan a partir de la fecha de hoy indicada
        (por ejemplo, "el mes pasado" es el mes calendario anterior completo). Si no se menciona un
        periodo, usa los últimos 30 días hasta hoy y anótalo. Un mes sin año es el más reciente que
        ya empezó. Si la consulta no pide un reporte de la clínica, responde entendido = false.
        Las notas son frases breves en español para el usuario.
        TXT;

    public function __construct(
        private readonly string $clave,
        private readonly string $modelo,
        private readonly float $timeout,
    ) {}

    public function nombre(): string
    {
        return 'ia';
    }

    public function interpretar(string $consulta, array $contexto): array
    {
        $nombres = [
            'medicos' => array_map(fn (array $m) => "{$m['nombre']} {$m['apellido']} ({$m['codigo']})", $contexto['medicos']),
            'especialidades' => array_column($contexto['especialidades'], 'nombre'),
            'modalidades' => array_column($contexto['modalidades'], 'nombre'),
            'metodos' => array_column($contexto['metodos'], 'nombre'),
        ];
        $agrupaciones = array_values(array_unique(array_merge(...array_column($contexto['reportes'], 'agrupaciones'))));

        $opciones = [
            'hoy' => $contexto['hoy']->toDateString(),
            'reportes' => array_map(fn (array $r, string $tipo) => ['tipo' => $tipo, 'titulo' => $r['titulo'], 'filtros' => $r['filtros'], 'agrupaciones' => $r['agrupaciones']],
                $contexto['reportes'], array_keys($contexto['reportes'])),
            ...$nombres,
            'estados_cita' => $contexto['estados'],
            'estados_pago' => $contexto['estados_pago'],
        ];

        $cliente = new Client(apiKey: $this->clave, requestOptions: ['timeout' => $this->timeout, 'maxRetries' => 1]);

        $mensaje = $cliente->messages->create(
            model: $this->modelo,
            maxTokens: self::MAXIMO_TOKENS,
            system: self::INSTRUCCIONES,
            messages: [[
                'role' => 'user',
                // La consulta va como dato, separada de las opciones.
                'content' => "Opciones válidas (JSON):\n".json_encode($opciones, JSON_UNESCAPED_UNICODE)
                    ."\n\nConsulta del usuario:\n<consulta>".$consulta.'</consulta>',
            ]],
            outputConfig: [
                // Clasificación corta: esfuerzo bajo.
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => $this->esquema($contexto, $nombres, $agrupaciones)],
            ],
        );

        if ($mensaje->stopReason !== 'end_turn') {
            throw new RuntimeException("Claude no completó la interpretación (stop_reason: {$mensaje->stopReason}).");
        }

        $datos = null;
        foreach ($mensaje->content as $bloque) {
            if ($bloque->type === 'text') {
                $datos = json_decode($bloque->text, true);
                break;
            }
        }

        if (! is_array($datos)) {
            throw new RuntimeException('Claude no devolvió una intención en JSON.');
        }

        // Nombres → ids, solo si coinciden exactamente con una opción del contexto.
        $id = fn (?string $nombre, array $lista, array $elementos) => $nombre !== null && ($indice = array_search($nombre, $lista, true)) !== false
            ? $elementos[$indice]['id'] : null;

        return [
            'entendido' => (bool) ($datos['entendido'] ?? false),
            'tipo' => $datos['tipo'] ?? null,
            'desde' => $datos['desde'] ?? null,
            'hasta' => $datos['hasta'] ?? null,
            'medico' => $id($datos['medico'] ?? null, $nombres['medicos'], $contexto['medicos']),
            'especialidad' => $id($datos['especialidad'] ?? null, $nombres['especialidades'], $contexto['especialidades']),
            'modalidad' => $id($datos['modalidad'] ?? null, $nombres['modalidades'], $contexto['modalidades']),
            'metodo' => $id($datos['metodo'] ?? null, $nombres['metodos'], $contexto['metodos']),
            'estado' => $datos['estado'] ?? null,
            'estado_pago' => $datos['estado_pago'] ?? null,
            'agrupar' => $datos['agrupar'] ?? null,
            'notas' => array_values(array_filter(array_map('strval', (array) ($datos['notas'] ?? [])))),
        ];
    }

    /**
     * Esquema de la intención: cada valor posible sale de las listas del contexto.
     */
    private function esquema(array $contexto, array $nombres, array $agrupaciones): array
    {
        $opcional = fn (array $valores) => ['anyOf' => [['type' => 'string', 'enum' => array_values($valores)], ['type' => 'null']]];
        // Formato AAAA-MM-DD pedido en las instrucciones; ConsultaVozService lo vuelve a validar.
        $fecha = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];

        $propiedades = [
            'entendido' => ['type' => 'boolean'],
            'tipo' => $opcional(array_keys($contexto['reportes'])),
            'desde' => $fecha,
            'hasta' => $fecha,
            'medico' => $nombres['medicos'] ? $opcional($nombres['medicos']) : ['type' => 'null'],
            'especialidad' => $nombres['especialidades'] ? $opcional($nombres['especialidades']) : ['type' => 'null'],
            'modalidad' => $nombres['modalidades'] ? $opcional($nombres['modalidades']) : ['type' => 'null'],
            'metodo' => $nombres['metodos'] ? $opcional($nombres['metodos']) : ['type' => 'null'],
            'estado' => $opcional($contexto['estados']),
            'estado_pago' => $opcional($contexto['estados_pago']),
            'agrupar' => $opcional($agrupaciones),
            'notas' => ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        return [
            'type' => 'object',
            'properties' => $propiedades,
            'required' => array_keys($propiedades),
            'additionalProperties' => false,
        ];
    }
}
