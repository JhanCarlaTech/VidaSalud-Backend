<?php

use App\Modules\ModuloPersonalMedico\Models\Especialidad;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU07 y CU24 – Precio de la consulta por especialidad.
 *
 * La reserva en línea cobra la cita según la especialidad elegida. Las
 * especialidades existentes reciben los precios iniciales; el resto, el valor por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE especialidad ADD COLUMN IF NOT EXISTS precio_consulta NUMERIC(10,2) NOT NULL DEFAULT '
            .Especialidad::PRECIO_POR_DEFECTO.' CHECK (precio_consulta >= 0)');

        foreach (Especialidad::PRECIOS_INICIALES as $nombre => $precio) {
            DB::table('especialidad')->where('nombre', $nombre)->update(['precio_consulta' => $precio]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE especialidad DROP COLUMN IF EXISTS precio_consulta');
    }
};
