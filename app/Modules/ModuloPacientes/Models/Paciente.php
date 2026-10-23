<?php

namespace App\Modules\ModuloPacientes\Models;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloSeguridad\Models\Usuario;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Extensión de 'usuario' para los pacientes (tabla 'paciente').
 */
#[Table(name: 'paciente', key: 'id_usuario', incrementing: false, timestamps: false)]
#[Fillable(['id_usuario', 'codigo', 'fecha_nacimiento', 'tipo_sangre', 'id_contacto_emergencia', 'activo'])]
class Paciente extends Model
{
    /** Grupos sanguíneos admitidos (CU11, RN04). */
    public const TIPOS_SANGRE = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function contactoEmergencia(): BelongsTo
    {
        return $this->belongsTo(ContactoEmergencia::class, 'id_contacto_emergencia');
    }

    /**
     * Citas Programadas o Confirmadas de hoy en adelante (CU11, RN09).
     */
    public function citasPendientes(): HasMany
    {
        return $this->hasMany(CitaMedica::class, 'id_paciente')
            ->whereIn('estado', CitaMedica::ESTADOS_PENDIENTES)
            ->whereDate('fecha', '>=', today());
    }

    /** Edad en años cumplidos (RN03: se calcula, no se guarda). */
    public function edad(): int
    {
        return $this->fecha_nacimiento->age;
    }
}
