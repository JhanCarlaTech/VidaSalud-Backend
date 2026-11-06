<?php

namespace Database\Seeders;

use App\Modules\ModuloSeguridad\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Permisos que no están en 'Base de Datos/script.sql' y que necesitan los
 * casos de uso implementados. Puede ejecutarse varias veces sin duplicar:
 *
 *     php artisan db:seed --class=PermisosSistemaSeeder
 */
class PermisosSistemaSeeder extends Seeder
{
    /**
     * accion => [descripción, roles que lo reciben al crearse].
     */
    private const PERMISOS = [
        // CU23 – Visualizar Dashboard Administrativo
        'dashboard_ver' => ['Ver el tablero de indicadores de la clínica', [Rol::ADMINISTRADOR]],
        // CU20 – Gestionar Notificaciones y Recordatorios
        'notificaciones_gestionar' => ['Enviar avisos a pacientes, reenviar correos y ver las notificaciones enviadas', [Rol::ADMINISTRADOR, Rol::RECEPCIONISTA]],
        // CU19 – Consultar Historial Clínico (de los pacientes que atiende el médico)
        'historial_consultar' => ['Consultar el historial clínico de los pacientes que atiende', [Rol::MEDICO]],
        // CU12 – Gestionar Catálogo de Enfermedades
        'enfermedades_gestionar' => ['Gestionar el catálogo de enfermedades y los antecedentes de los pacientes', [Rol::ADMINISTRADOR, Rol::MEDICO]],
        // CU06 – Consultar Bitácora de Auditoría
        'bitacora_consultar' => ['Consultar y exportar la bitácora de auditoría', [Rol::ADMINISTRADOR]],
        // CU04 – Cargar Usuarios por Lotes
        'usuarios_importar' => ['Registrar usuarios y pacientes por lotes desde un archivo', [Rol::ADMINISTRADOR]],
        // CU03 – Gestionar Roles y Asignar Permisos
        'roles_gestionar' => ['Crear y modificar roles y asignar sus permisos', [Rol::ADMINISTRADOR]],
        // CU15 – Registrar Control de Asistencia
        'asistencia_registrar' => ['Registrar la asistencia de los pacientes a sus citas', [Rol::ADMINISTRADOR, Rol::RECEPCIONISTA]],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::PERMISOS as $accion => [$descripcion, $roles]) {
                DB::table('permiso')->insertOrIgnore(['accion' => $accion, 'descripcion' => $descripcion]);

                $idPermiso = DB::table('permiso')->where('accion', $accion)->value('id');
                $idsRoles = DB::table('rol')->whereIn('nombre', $roles)->pluck('id');

                foreach ($idsRoles as $idRol) {
                    DB::table('rol_permiso')->insertOrIgnore(['id_rol' => $idRol, 'id_permiso' => $idPermiso]);
                }
            }
        });
    }
}
