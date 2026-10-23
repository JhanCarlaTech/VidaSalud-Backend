<?php

namespace App\Modules\ModuloPersonalMedico\Models;

use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloSeguridad\Models\Usuario;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Extensión de 'usuario' para el personal médico (tabla 'medico').
 */
#[Table(name: 'medico', key: 'id_usuario', incrementing: false, timestamps: false)]
#[Fillable(['id_usuario', 'codigo', 'salario', 'activo'])]
class Medico extends Model
{
    protected function casts(): array
    {
        return [
            'salario' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'medico_especialidad', 'id_medico', 'id_especialidad');
    }

    /**
     * Grilla semanal de atención (CU09).
     */
    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioAtencion::class, 'id_medico');
    }

    /**
     * Citas Programadas o Confirmadas de hoy en adelante (CU08, RN06).
     */
    public function citasPendientes(): HasMany
    {
        return $this->hasMany(CitaMedica::class, 'id_medico')
            ->whereIn('estado', CitaMedica::ESTADOS_PENDIENTES)
            ->whereDate('fecha', '>=', today());
    }
}
