<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU20 – Bandeja de notificaciones y estado del envío por correo (decisión D2).
 *
 * - 'leida' y 'fecha_lectura': la bandeja del paciente distingue lo nuevo.
 * - 'estado_correo': pendiente, enviado, omitido (el paciente no quiere correos o no
 *   tiene correo) o fallido. La columna 'enviado' del script se mantiene y equivale a
 *   estado_correo = 'enviado'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notificacion ADD COLUMN IF NOT EXISTS leida BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE notificacion ADD COLUMN IF NOT EXISTS fecha_lectura TIMESTAMPTZ NULL');
        DB::statement("ALTER TABLE notificacion ADD COLUMN IF NOT EXISTS estado_correo VARCHAR(20) NOT NULL DEFAULT 'pendiente'
            CONSTRAINT chk_notificacion_estado_correo CHECK (estado_correo IN ('pendiente', 'enviado', 'omitido', 'fallido'))");
        DB::statement("UPDATE notificacion SET estado_correo = 'enviado' WHERE enviado");

        // Bandeja del paciente y búsqueda del recordatorio de cada cita.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_notificacion_paciente ON notificacion (id_paciente, fecha_envio DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_notificacion_cita ON notificacion (id_cita, tipo)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_notificacion_cita');
        DB::statement('DROP INDEX IF EXISTS idx_notificacion_paciente');
        DB::statement('ALTER TABLE notificacion DROP COLUMN IF EXISTS estado_correo');
        DB::statement('ALTER TABLE notificacion DROP COLUMN IF EXISTS fecha_lectura');
        DB::statement('ALTER TABLE notificacion DROP COLUMN IF EXISTS leida');
    }
};
