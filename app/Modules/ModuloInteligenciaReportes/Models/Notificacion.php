<?php

namespace App\Modules\ModuloInteligenciaReportes\Models;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPacientes\Models\Paciente;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación a un paciente (tabla 'notificacion', CU20). Se ve siempre en su
 * bandeja; además se envía por correo si sus preferencias (CU05) lo permiten.
 */
#[Table(name: 'notificacion', timestamps: false)]
#[Fillable(['id_paciente', 'id_cita', 'tipo', 'mensaje', 'enviado', 'estado_correo', 'leida', 'fecha_lectura'])]
class Notificacion extends Model
{
    /** RN01: tipos de notificación. */
    public const CITA_AGENDADA = 'CITA_AGENDADA';

    public const CITA_REPROGRAMADA = 'CITA_REPROGRAMADA';

    public const CITA_CANCELADA = 'CITA_CANCELADA';

    public const RECORDATORIO = 'RECORDATORIO';

    public const AVISO = 'AVISO';

    public const TIPOS = [self::CITA_AGENDADA, self::CITA_REPROGRAMADA, self::CITA_CANCELADA, self::RECORDATORIO, self::AVISO];

    /** Título visible de cada tipo. */
    public const TITULOS = [
        self::CITA_AGENDADA => 'Cita agendada',
        self::CITA_REPROGRAMADA => 'Cita reprogramada',
        self::CITA_CANCELADA => 'Cita cancelada',
        self::RECORDATORIO => 'Recordatorio de cita',
        self::AVISO => 'Aviso de la clínica',
    ];

    /** RN03: estado del envío por correo. */
    public const CORREO_PENDIENTE = 'pendiente';

    public const CORREO_ENVIADO = 'enviado';

    public const CORREO_OMITIDO = 'omitido';

    public const CORREO_FALLIDO = 'fallido';

    public const ESTADOS_CORREO = [self::CORREO_PENDIENTE, self::CORREO_ENVIADO, self::CORREO_OMITIDO, self::CORREO_FALLIDO];

    protected function casts(): array
    {
        return [
            'enviado' => 'boolean',
            'leida' => 'boolean',
            'fecha_envio' => 'datetime',
            'fecha_lectura' => 'datetime',
        ];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente');
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(CitaMedica::class, 'id_cita');
    }
}
