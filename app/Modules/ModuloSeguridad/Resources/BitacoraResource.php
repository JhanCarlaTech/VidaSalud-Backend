<?php

namespace App\Modules\ModuloSeguridad\Resources;

use App\Modules\ModuloSeguridad\Services\BitacoraConsultaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento de la bitácora para el listado (CU06). Los datos registrados no se
 * incluyen aquí: se piden con el detalle del evento.
 */
class BitacoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha_registro?->toIso8601String(),
            'accion' => $this->accion,
            'accion_etiqueta' => BitacoraConsultaService::etiquetaAccion($this->accion),
            'tabla' => $this->tabla_afectada,
            'tabla_etiqueta' => BitacoraConsultaService::etiquetaTabla($this->tabla_afectada),
            'ip' => $this->ip_origen,
            'usuario' => $this->usuario ? [
                'id' => $this->usuario->id,
                'ci' => $this->usuario->ci,
                'nombre_completo' => "{$this->usuario->nombre} {$this->usuario->apellido}",
                'correo' => $this->usuario->correo,
            ] : null,
            // Un inicio de sesión fallido con un correo inexistente no tiene usuario (RN02).
            'correo_intento' => $this->usuario ? null : ($this->datos_nuevos['correo'] ?? null),
            'tiene_datos' => $this->datos_anteriores !== null || $this->datos_nuevos !== null,
        ];
    }
}
