<?php

namespace App\Modules\ModuloSeguridad\Services;

use App\Modules\ModuloSeguridad\Models\Bitacora;

/**
 * Registro centralizado de eventos de auditoría en la tabla 'bitacora'.
 */
class BitacoraService
{
    public function registrar(
        string $accion,
        string $tablaAfectada,
        ?int $idUsuario,
        ?array $datosAnteriores = null,
        ?array $datosNuevos = null,
    ): void {
        Bitacora::create([
            'id_usuario' => $idUsuario,
            'accion' => $accion,
            'tabla_afectada' => $tablaAfectada,
            'ip_origen' => request()->ip() ?? '0.0.0.0',
            'datos_anteriores' => $datosAnteriores,
            'datos_nuevos' => $datosNuevos,
        ]);
    }
}
