<?php

namespace App\Modules\ModuloPersonalMedico\Resources;

use App\Modules\ModuloPersonalMedico\Requests\LicenciaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Licencia o bloqueo de agenda (CU10). Las fechas se devuelven en la hora de la
 * clínica con el mismo formato con el que se reciben ('AAAA-MM-DD HH:mm').
 */
class LicenciaResource extends JsonResource
{
    /** Estados según la hora actual (RN06). */
    public const VIGENTE = 'vigente';

    public const PROXIMA = 'proxima';

    public const FINALIZADA = 'finalizada';

    public function toArray(Request $request): array
    {
        $zona = config('app.timezone');
        $inicio = $this->fecha_inicio->setTimezone($zona);
        $fin = $this->fecha_fin->setTimezone($zona);

        return [
            'id' => $this->id,
            'medico' => $this->whenLoaded('medico', fn () => [
                'id' => $this->medico->id_usuario,
                'codigo' => $this->medico->codigo,
                'nombre_completo' => "{$this->medico->usuario->nombre} {$this->medico->usuario->apellido}",
            ]),
            'fecha_inicio' => $inicio->format(LicenciaRequest::FORMATO_FECHA),
            'fecha_fin' => $fin->format(LicenciaRequest::FORMATO_FECHA),
            'motivo' => $this->motivo,
            // Días completos: de las 00:00 del primer día a las 23:59 del último.
            'dias_completos' => $inicio->format('H:i') === '00:00' && $fin->format('H:i') === '23:59',
            'estado' => match (true) {
                $this->haTerminado() => self::FINALIZADA,
                $this->haEmpezado() => self::VIGENTE,
                default => self::PROXIMA,
            },
            'citas_afectadas' => $this->when($this->citas_afectadas !== null, fn () => $this->citas_afectadas),
        ];
    }
}
