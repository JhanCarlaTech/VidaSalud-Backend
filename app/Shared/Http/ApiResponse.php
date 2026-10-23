<?php

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;

/**
 * Formato estándar de todas las respuestas de la API:
 * { success, message, data } en éxito y { success, message, errors } en error.
 */
final class ApiResponse
{
    public static function exito(mixed $data = null, string $mensaje = 'Operación realizada correctamente.', int $estado = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'data' => $data,
        ], $estado);
    }

    public static function error(string $mensaje, int $estado, ?array $errores = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $mensaje,
            'errors' => $errores,
        ], $estado);
    }
}
