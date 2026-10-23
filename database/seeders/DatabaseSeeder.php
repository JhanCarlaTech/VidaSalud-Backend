<?php

namespace Database\Seeders;

use App\Modules\ModuloSeguridad\Models\Rol;
use App\Shared\Support\ScriptSqlClinico;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Contraseña común de los usuarios de prueba del script.
     */
    private const CONTRASENA_PRUEBA = 'Password123!';

    /**
     * Carga los datos iniciales de 'Base de Datos/script.sql' (roles, permisos,
     * usuarios, médicos, pacientes, citas, etc.), configura el administrador
     * con las credenciales del .env (ADMIN_CORREO, ADMIN_CONTRASENA) y agrega
     * los permisos que no figuran en el script.
     */
    public function run(): void
    {
        $correoAdmin = Str::lower(trim((string) config('clinica.admin.correo')));
        $contrasenaAdmin = (string) config('clinica.admin.contrasena');

        if ($correoAdmin === '' || $contrasenaAdmin === '') {
            throw new RuntimeException('Defina ADMIN_CORREO y ADMIN_CONTRASENA en el archivo .env antes de ejecutar el seeder.');
        }

        DB::transaction(function () use ($correoAdmin, $contrasenaAdmin) {
            DB::unprepared(ScriptSqlClinico::datosIniciales());

            // El hash escrito en el script no es un BCRYPT válido (59 caracteres),
            // por lo que se regenera con el hasher de Laravel.
            DB::table('usuario')->update([
                'contrasena' => Hash::make(self::CONTRASENA_PRUEBA),
            ]);

            DB::table('usuario')
                ->where('id', $this->idAdministrador())
                ->update([
                    'correo' => $correoAdmin,
                    'contrasena' => Hash::make($contrasenaAdmin),
                ]);
        });

        $this->call(PermisosSistemaSeeder::class);
    }

    /**
     * Primer usuario con el rol Administrador cargado por el script.
     */
    private function idAdministrador(): int
    {
        $id = DB::table('usuario')
            ->join('rol_usuario', 'rol_usuario.id_usuario', '=', 'usuario.id')
            ->join('rol', 'rol.id', '=', 'rol_usuario.id_rol')
            ->where('rol.nombre', Rol::ADMINISTRADOR)
            ->orderBy('usuario.id')
            ->value('usuario.id');

        if ($id === null) {
            throw new RuntimeException('El script.sql no contiene ningún usuario con el rol Administrador.');
        }

        return $id;
    }
}
