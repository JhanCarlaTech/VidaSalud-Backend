<?php

namespace App\Modules\ModuloSeguridad\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Sesión abierta del usuario (fila de 'sessions'). Nunca expone el
 * identificador de la sesión (CU05, RN07).
 */
class SesionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'dispositivo' => $this->describirDispositivo($this->user_agent),
            'ip' => $this->ip_address,
            'ultima_actividad' => Carbon::createFromTimestamp($this->last_activity)->toIso8601String(),
            'actual' => $this->actual,
        ];
    }

    /**
     * 'Mozilla/5.0 (Windows NT 10.0; ...) Chrome/...' → 'Chrome en Windows'.
     */
    private function describirDispositivo(?string $agente): string
    {
        if (! $agente) {
            return 'Dispositivo desconocido';
        }

        // El orden importa: Edge y Opera también contienen "Chrome", y Chrome contiene "Safari".
        $navegador = match (true) {
            str_contains($agente, 'Edg/') => 'Edge',
            str_contains($agente, 'OPR/') => 'Opera',
            str_contains($agente, 'Chrome/') => 'Chrome',
            str_contains($agente, 'Firefox/') => 'Firefox',
            str_contains($agente, 'Safari/') => 'Safari',
            default => 'Navegador',
        };

        // iPhone contiene "Mac OS X" y Android contiene "Linux".
        $sistema = match (true) {
            str_contains($agente, 'Windows') => 'Windows',
            str_contains($agente, 'iPhone'), str_contains($agente, 'iPad') => 'iOS',
            str_contains($agente, 'Android') => 'Android',
            str_contains($agente, 'Mac OS X') => 'macOS',
            str_contains($agente, 'Linux') => 'Linux',
            default => null,
        };

        return $sistema ? "{$navegador} en {$sistema}" : $navegador;
    }
}
