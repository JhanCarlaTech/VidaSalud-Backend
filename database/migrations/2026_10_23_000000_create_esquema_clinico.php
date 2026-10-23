<?php

use App\Shared\Support\ScriptSqlClinico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esquema clínico de PostgreSQL (tipos ENUM, tablas, índices parciales y
 * columnas generadas) tomado directamente de 'Base de Datos/script.sql'.
 */
return new class extends Migration
{
    /**
     * Tablas del esquema, en orden inverso de dependencia.
     */
    private const TABLAS = [
        'paciente_enfermedad', 'enfermedad', 'detalle_receta', 'receta_medica',
        'consulta_medica', 'pago_cita', 'metodo_pago', 'notificacion', 'cita_medica',
        'modalidad_cita', 'indisponibilidad_medico', 'horario_atencion',
        'medico_especialidad', 'especialidad', 'medico', 'paciente',
        'contacto_de_emergencia', 'bitacora', 'rol_usuario', 'rol_permiso',
        'permiso', 'rol', 'usuario',
    ];

    private const TIPOS = ['estado_cita_enum', 'estado_pago_enum', 'sexo_enum'];

    public function up(): void
    {
        // 'migrate:fresh' elimina las tablas pero no los tipos ENUM. Se quitan los
        // que hayan quedado huérfanos (sin CASCADE: falla si alguna tabla los usa).
        $this->eliminarTipos();

        DB::unprepared(ScriptSqlClinico::estructura());
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            DB::statement("DROP TABLE IF EXISTS {$tabla} CASCADE");
        }

        $this->eliminarTipos();
    }

    private function eliminarTipos(): void
    {
        foreach (self::TIPOS as $tipo) {
            DB::statement("DROP TYPE IF EXISTS {$tipo}");
        }
    }
};
