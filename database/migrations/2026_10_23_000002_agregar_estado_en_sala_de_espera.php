<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU15 – Agrega el estado 'En Sala de Espera' a las citas (RN03).
 *
 * El turno de un paciente que ya llegó debe seguir ocupado, por eso también se
 * recrea el índice único parcial que evita las citas dobles incluyendo ese estado.
 */
return new class extends Migration
{
    /**
     * PostgreSQL no permite usar un valor nuevo de un ENUM dentro de la misma
     * transacción en la que se agregó, y el índice lo necesita.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TYPE estado_cita_enum ADD VALUE IF NOT EXISTS 'En Sala de Espera' AFTER 'Confirmada'");

        DB::statement('DROP INDEX IF EXISTS idx_cita_medico_no_solapada');
        DB::statement("CREATE UNIQUE INDEX idx_cita_medico_no_solapada ON cita_medica (id_medico, fecha, hra_inicio)
            WHERE estado IN ('Programada', 'Confirmada', 'En Sala de Espera')");
    }

    /**
     * PostgreSQL no permite quitar valores de un ENUM: solo se restaura el índice original.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_cita_medico_no_solapada');
        DB::statement("CREATE UNIQUE INDEX idx_cita_medico_no_solapada ON cita_medica (id_medico, fecha, hra_inicio)
            WHERE estado IN ('Programada', 'Confirmada')");
    }
};
