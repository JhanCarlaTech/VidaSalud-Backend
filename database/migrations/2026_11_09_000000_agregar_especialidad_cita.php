<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU13 y CU24 – Especialidad con la que se reservó la cita.
 *
 * El precio de la consulta depende de la especialidad, y un médico puede tener
 * varias. Guardarla en la cita permite cobrarla más tarde (desde "Mis citas")
 * con el precio correcto. Las citas existentes se completan cuando se puede
 * saber con certeza: el médico tiene una sola especialidad, o un pago con
 * tarjeta ya la registró.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cita_medica ADD COLUMN IF NOT EXISTS id_especialidad INT REFERENCES especialidad(id) ON DELETE SET NULL');

        // Pagos con Stripe: la especialidad cobrada quedó en detalles_pasarela.
        DB::statement(<<<'SQL'
            UPDATE cita_medica c
            SET id_especialidad = (p.detalles_pasarela->>'id_especialidad')::int
            FROM pago_cita p
            WHERE p.id_cita = c.id
              AND c.id_especialidad IS NULL
              AND p.detalles_pasarela->>'id_especialidad' IS NOT NULL
              AND EXISTS (SELECT 1 FROM especialidad e WHERE e.id = (p.detalles_pasarela->>'id_especialidad')::int)
        SQL);

        // Médicos con una sola especialidad: no hay duda.
        DB::statement(<<<'SQL'
            UPDATE cita_medica c
            SET id_especialidad = unica.id_especialidad
            FROM (
                SELECT id_medico, min(id_especialidad) AS id_especialidad
                FROM medico_especialidad
                GROUP BY id_medico
                HAVING count(*) = 1
            ) unica
            WHERE unica.id_medico = c.id_medico
              AND c.id_especialidad IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cita_medica DROP COLUMN IF EXISTS id_especialidad');
    }
};
