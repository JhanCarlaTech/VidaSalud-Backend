<?php

namespace Database\Seeders;

use App\Modules\ModuloSeguridad\Models\Rol;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de prueba para los reportes (CU21), el tablero (CU23) y el historial (CU19).
 *
 *     php artisan db:seed --class=DatosPruebaSeeder      (crea o regenera)
 *     php artisan datos-prueba:eliminar                  (borra solo lo generado aquí)
 *
 * Genera pacientes y dos médicos de demostración (correo @demo.vidasalud.com) y, con ellos
 * y con los médicos existentes, unos seis meses de citas hasta hoy y tres semanas por delante,
 * con consultas, recetas y pagos. Respeta las reglas del sistema: turnos reales de la grilla,
 * sin superposición por médico ni por paciente, sin licencias, un expediente por paciente.
 * No modifica datos que no sean de demostración. Es determinista para una misma fecha.
 */
class DatosPruebaSeeder extends Seeder
{
    /** Marca de todo lo generado: los usuarios de demostración tienen este dominio de correo. */
    public const DOMINIO = '@demo.vidasalud.com';

    private const CONTRASENA = 'Password123!';

    private const DIAS_ATRAS = 180;

    private const DIAS_ADELANTE = 21;

    private const NOMBRES_F = ['María', 'Ana', 'Rosa', 'Carmen', 'Silvia', 'Patricia', 'Gabriela', 'Daniela', 'Lucía', 'Verónica', 'Paola', 'Claudia', 'Mónica', 'Valeria', 'Natalia', 'Sofía', 'Camila', 'Andrea'];

    private const NOMBRES_M = ['Juan', 'Carlos', 'Luis', 'Jorge', 'Pedro', 'Miguel', 'José', 'Fernando', 'Ricardo', 'Marco', 'Diego', 'Sergio', 'Alejandro', 'Raúl', 'Hugo', 'Mateo', 'Santiago', 'Daniel'];

    private const APELLIDOS = ['Quispe', 'Mamani', 'Condori', 'Choque', 'Flores', 'Vargas', 'Rojas', 'Gutiérrez', 'Torrez', 'Limachi', 'Apaza', 'Huanca', 'Ticona', 'Calle', 'Arce', 'Salazar', 'Paredes', 'Mendoza', 'Villca', 'Copa'];

    /** Médicos de demostración: [nombre, apellido, código, especialidades, bloques [día ISO, inicio, fin, minutos]]. */
    private const MEDICOS_DEMO = [
        ['Lucía', 'Rojas', 'MED-901', ['Odontología'], [[1, '14:00', '18:00', 30], [4, '14:00', '18:00', 30]]],
        ['Andrés', 'Villca', 'MED-902', ['Medicina General', 'Pediatría'], [[2, '14:00', '18:00', 20], [6, '08:00', '12:00', 30]]],
    ];

    /** Por especialidad: motivos de consulta, síntomas, tratamientos y medicamentos [nombre, dosis, frecuencia, días]. */
    private const CLINICA = [
        'Medicina General' => [
            'motivos' => ['Control general', 'Fiebre y malestar general', 'Dolor de garganta', 'Chequeo anual', 'Dolor de cabeza persistente', 'Dolor abdominal'],
            'sintomas' => ['Temperatura de 38 °C y mialgias', 'Odinofagia y amígdalas eritematosas', 'Cefalea frontal sin signos de alarma', 'Sin síntomas, control de rutina', 'Dolor epigástrico posprandial'],
            'tratamientos' => ['Reposo, hidratación y antipirético', 'Antibiótico por 7 días y control', 'Analgésico y control en dos semanas', 'Estudios de laboratorio de rutina', 'Dieta blanda e inhibidor de bomba de protones'],
            'medicamentos' => [['Paracetamol', '500 mg', 'Cada 8 horas', 5], ['Ibuprofeno', '400 mg', 'Cada 8 horas', 3], ['Amoxicilina', '500 mg', 'Cada 8 horas', 7], ['Omeprazol', '20 mg', 'Cada 24 horas (ayunas)', 14], ['Loratadina', '10 mg', 'Cada 24 horas', 10]],
        ],
        'Pediatría' => [
            'motivos' => ['Control de niño sano', 'Vacunación', 'Fiebre en niño', 'Tos persistente', 'Diarrea aguda'],
            'sintomas' => ['Desarrollo acorde a la edad', 'Fiebre de 38,5 °C de 2 días', 'Tos seca nocturna', 'Deposiciones líquidas sin deshidratación'],
            'tratamientos' => ['Control de crecimiento y vacunas al día', 'Antipirético por peso y control', 'Nebulizaciones y control en 5 días', 'Sales de rehidratación oral'],
            'medicamentos' => [['Paracetamol jarabe', '5 ml', 'Cada 6 horas si hay fiebre', 3], ['Sales de rehidratación oral', '1 sobre', 'Después de cada deposición', 3], ['Salbutamol inhalador', '2 inhalaciones', 'Cada 6 horas', 5], ['Zinc jarabe', '5 ml', 'Cada 24 horas', 10]],
        ],
        'Ginecología' => [
            'motivos' => ['Control ginecológico', 'Control prenatal', 'Dolor pélvico', 'Consulta por anticoncepción'],
            'sintomas' => ['Sin molestias, control anual', 'Gestación de 20 semanas sin complicaciones', 'Dolor pélvico intermitente', 'Ciclos irregulares'],
            'tratamientos' => ['Papanicolaou y control anual', 'Ácido fólico, hierro y ecografía', 'Ecografía pélvica y analgésico', 'Orientación anticonceptiva'],
            'medicamentos' => [['Ácido fólico', '1 mg', 'Cada 24 horas', 30], ['Sulfato ferroso', '300 mg', 'Cada 24 horas', 30], ['Naproxeno', '550 mg', 'Cada 12 horas', 5]],
        ],
        'Odontología' => [
            'motivos' => ['Limpieza dental', 'Dolor de muela', 'Control de ortodoncia', 'Caries'],
            'sintomas' => ['Placa bacteriana moderada', 'Dolor pulsátil en molar inferior', 'Brackets en buen estado', 'Caries en dos piezas'],
            'tratamientos' => ['Profilaxis y fluorización', 'Endodoncia programada', 'Ajuste de arco', 'Restauración con resina'],
            'medicamentos' => [['Ibuprofeno', '400 mg', 'Cada 8 horas', 3], ['Amoxicilina', '500 mg', 'Cada 8 horas', 7], ['Clorhexidina enjuague', '15 ml', 'Cada 12 horas', 10]],
        ],
        'Cardiología' => [
            'motivos' => ['Control de presión arterial', 'Palpitaciones', 'Dolor en el pecho', 'Control post infarto'],
            'sintomas' => ['Presión arterial 150/95', 'Palpitaciones ocasionales en reposo', 'Dolor torácico atípico', 'Estable, sin dolor torácico'],
            'tratamientos' => ['Ajuste de antihipertensivo y dieta hiposódica', 'Holter de 24 horas', 'Electrocardiograma y prueba de esfuerzo', 'Continuar tratamiento y control en 3 meses'],
            'medicamentos' => [['Losartán', '50 mg', 'Cada 24 horas', 30], ['Amlodipino', '5 mg', 'Cada 24 horas', 30], ['Aspirina', '100 mg', 'Cada 24 horas', 30], ['Atorvastatina', '20 mg', 'Cada noche', 30]],
        ],
    ];

    private const MOTIVOS_CANCELACION = ['El paciente tuvo un viaje imprevisto', 'Se sintió mejor antes de la cita', 'Cruce con su horario de trabajo', 'Solicitó otro médico', 'Emergencia familiar'];

    private const INDICACIONES = ['Tomar con abundante agua.', 'Volver a control si los síntomas persisten más de 3 días.', 'Evitar comidas grasosas durante el tratamiento.', 'Reposo relativo por 48 horas.', null];

    /** Métodos de pago (nombre contiene) y su peso relativo. */
    private const METODOS = ['QR' => 45, 'Efectivo' => 30, 'Tarjeta' => 18, 'Transferencia' => 7];

    private CarbonImmutable $hoy;

    private CarbonImmutable $ahora;

    public function run(): void
    {
        self::eliminar();
        mt_srand(2026);

        $this->ahora = CarbonImmutable::now();
        $this->hoy = $this->ahora->startOfDay();

        DB::transaction(function () {
            $hash = Hash::make(self::CONTRASENA);
            $pacientes = $this->crearPacientes($hash);
            $this->crearMedicosDemo($hash);
            $resumen = $this->crearCitas($pacientes);

            $this->command?->info(sprintf(
                'Datos de prueba: %d pacientes, %d médicos de demostración, %d citas, %d consultas, %d recetas y %d pagos.',
                count($pacientes), count(self::MEDICOS_DEMO), ...$resumen,
            ));
            $this->command?->line('Usuarios de demostración: correo …'.self::DOMINIO.', contraseña '.self::CONTRASENA);
        });
    }

    /**
     * Borra solo lo generado por este seeder (usuarios con correo de demostración y sus datos).
     *
     * @return int Usuarios eliminados.
     */
    public static function eliminar(): int
    {
        return DB::transaction(function () {
            $usuarios = DB::table('usuario')->where('correo', 'like', '%'.self::DOMINIO)->pluck('id');
            if ($usuarios->isEmpty()) {
                return 0;
            }

            $citas = DB::table('cita_medica')->whereIn('id_paciente', $usuarios)->orWhereIn('id_medico', $usuarios)->pluck('id');
            $contactos = DB::table('paciente')->whereIn('id_usuario', $usuarios)->whereNotNull('id_contacto_emergencia')->pluck('id_contacto_emergencia');

            // Pagos y consultas tienen ON DELETE RESTRICT; las recetas caen en cascada con la consulta.
            DB::table('pago_cita')->whereIn('id_cita', $citas)->delete();
            DB::table('consulta_medica')->whereIn('id_cita_medica', $citas)->delete();
            DB::table('cita_medica')->whereIn('id', $citas)->delete();
            DB::table('sessions')->whereIn('user_id', $usuarios)->delete();
            // Paciente, médico, horarios, licencias, roles y antecedentes caen en cascada con el usuario.
            DB::table('usuario')->whereIn('id', $usuarios)->delete();
            DB::table('contacto_de_emergencia')->whereIn('id', $contactos)->delete();

            return $usuarios->count();
        });
    }

    // --- Personas -------------------------------------------------------------------

    /** @return array<int, array{id: int, codigo: string, sexo: string, edad: int, peso: float, estatura: float}> */
    private function crearPacientes(string $hash): array
    {
        $idRol = DB::table('rol')->where('nombre', Rol::PACIENTE)->value('id');
        $siguiente = (int) DB::table('paciente')->where('codigo', '~', '^PAC-[0-9]+$')->max(DB::raw('CAST(SUBSTRING(codigo FROM 5) AS INTEGER)'));
        $enfermedades = DB::table('enfermedad')->where('activo', true)->pluck('id')->all();
        $pacientes = [];

        for ($i = 0; $i < 40; $i++) {
            $sexo = $i % 2 === 0 ? 'F' : 'M';
            $nombre = $this->elegir($sexo === 'F' ? self::NOMBRES_F : self::NOMBRES_M);
            $apellido = $this->elegir(self::APELLIDOS);
            // Los primeros 12 son niños (para Pediatría); el resto, adultos de 18 a 80 años.
            $edad = $i < 12 ? mt_rand(1, 13) : mt_rand(18, 80);
            $nacimiento = $this->hoy->subYears($edad)->subDays(mt_rand(0, 364));

            $contacto = DB::table('contacto_de_emergencia')->insertGetId([
                'nombre' => $this->elegir(self::NOMBRES_F), 'apellido' => $apellido, 'parentesco' => $edad < 18 ? 'Madre' : $this->elegir(['Esposo(a)', 'Hijo(a)', 'Hermano(a)']),
                'telefono' => '7'.mt_rand(1000000, 9999999),
            ]);

            $id = DB::table('usuario')->insertGetId([
                'ci' => (string) (9100000 + $i), 'nombre' => $nombre, 'apellido' => $apellido,
                'correo' => mb_strtolower($this->ascii($nombre).'.'.$this->ascii($apellido).'.'.($i + 1)).self::DOMINIO,
                'contrasena' => $hash, 'telefono' => '7'.mt_rand(1000000, 9999999), 'sexo' => $sexo, 'activo' => true,
            ]);

            $codigo = sprintf('PAC-%04d', ++$siguiente);
            DB::table('paciente')->insert([
                'id_usuario' => $id, 'codigo' => $codigo, 'fecha_nacimiento' => $nacimiento->toDateString(),
                'tipo_sangre' => $this->elegir(['O+', 'O+', 'O+', 'A+', 'A+', 'B+', 'AB+', 'O-']), 'id_contacto_emergencia' => $contacto, 'activo' => true,
            ]);
            DB::table('rol_usuario')->insert(['id_rol' => $idRol, 'id_usuario' => $id, 'activo' => true]);

            // CU12: antecedentes en un tercio de los adultos.
            if ($edad >= 30 && $enfermedades && mt_rand(1, 3) === 1) {
                DB::table('paciente_enfermedad')->insert([
                    'id_paciente' => $id, 'id_enfermedad' => $this->elegir($enfermedades),
                    'fecha_diagnostico' => $this->hoy->subYears(mt_rand(1, min(15, $edad - 18)))->toDateString(),
                    'observaciones' => 'En tratamiento y control periódico.',
                ]);
            }

            $pacientes[] = [
                'id' => $id, 'codigo' => $codigo, 'sexo' => $sexo, 'edad' => $edad,
                'peso' => $edad < 14 ? 8 + $edad * 3 : mt_rand(50, 95),
                'estatura' => $edad < 14 ? 70 + $edad * 6 : mt_rand(150, 185),
            ];
        }

        return $pacientes;
    }

    private function crearMedicosDemo(string $hash): void
    {
        $idRol = DB::table('rol')->where('nombre', Rol::MEDICO)->value('id');

        foreach (self::MEDICOS_DEMO as $indice => [$nombre, $apellido, $codigo, $especialidades, $bloques]) {
            $id = DB::table('usuario')->insertGetId([
                'ci' => (string) (9200000 + $indice), 'nombre' => $nombre, 'apellido' => $apellido,
                'correo' => 'dr.'.mb_strtolower($this->ascii($apellido)).self::DOMINIO, 'contrasena' => $hash,
                'telefono' => '7'.mt_rand(1000000, 9999999), 'sexo' => $nombre === 'Lucía' ? 'F' : 'M', 'activo' => true,
            ]);
            DB::table('rol_usuario')->insert(['id_rol' => $idRol, 'id_usuario' => $id, 'activo' => true]);
            DB::table('medico')->insert(['id_usuario' => $id, 'codigo' => $codigo, 'salario' => 9500, 'activo' => true]);

            foreach (DB::table('especialidad')->whereIn('nombre', $especialidades)->pluck('id') as $idEspecialidad) {
                DB::table('medico_especialidad')->insert(['id_medico' => $id, 'id_especialidad' => $idEspecialidad]);
            }
            foreach ($bloques as [$dia, $inicio, $fin, $minutos]) {
                DB::table('horario_atencion')->insert(['id_medico' => $id, 'dia_semana' => $dia, 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'duracion_minutos' => $minutos, 'activo' => true]);
            }
        }

        // CU10: una semana de vacaciones de la odontóloga, para ver la ocupación descontada en el tablero.
        $inicio = $this->hoy->subDays(70)->startOfWeek();
        DB::table('indisponibilidad_medico')->insert([
            'id_medico' => DB::table('medico')->where('codigo', 'MED-901')->value('id_usuario'),
            'fecha_inicio' => $inicio->format('Y-m-d 00:00:00P'), 'fecha_fin' => $inicio->addDays(4)->format('Y-m-d 23:59:00P'),
            'motivo' => 'Vacaciones',
        ]);
    }

    // --- Citas, consultas, recetas y pagos -------------------------------------------------

    /** @return array{0: int, 1: int, 2: int, 3: int} citas, consultas, recetas, pagos */
    private function crearCitas(array $pacientes): array
    {
        $medicos = $this->medicosConGrilla();
        $metodos = $this->metodosPago();
        // Presencial la mayoría, luego virtual; el resto, poco frecuente.
        $modalidades = [];
        foreach (DB::table('modalidad_cita')->orderBy('id')->pluck('id') as $posicion => $idModalidad) {
            $modalidades[$idModalidad] = [75, 20][$posicion] ?? 5;
        }
        $siguienteCita = (int) DB::table('cita_medica')->where('codigo_cita', '~', '^CM-[0-9]+$')->max(DB::raw('CAST(SUBSTRING(codigo_cita FROM 4) AS INTEGER)'));
        $ocupadosPaciente = [];
        // Un expediente por paciente: se reutiliza el de sus consultas anteriores (como CU17).
        $expedientes = DB::table('consulta_medica')->join('cita_medica', 'cita_medica.id', '=', 'consulta_medica.id_cita_medica')
            ->groupBy('cita_medica.id_paciente')->selectRaw('cita_medica.id_paciente, min(nro_expediente) as expediente')
            ->pluck('expediente', 'id_paciente')->all();
        $contadores = [0, 0, 0, 0];
        $transaccion = 0;

        for ($dia = $this->hoy->subDays(self::DIAS_ATRAS); $dia <= $this->hoy->addDays(self::DIAS_ADELANTE); $dia = $dia->addDay()) {
            // La ocupación crece hacia el presente (45 % hace seis meses, 75 % hoy) y baja en el futuro.
            $antiguedad = $this->hoy->diffInDays($dia, false);
            $ocupacion = $antiguedad <= 0 ? 0.75 - 0.30 * (-$antiguedad / self::DIAS_ATRAS) : 0.45 - 0.015 * $antiguedad;

            foreach ($medicos as $medico) {
                foreach ($medico['bloques'][$dia->dayOfWeekIso] ?? [] as [$inicio, $fin, $minutos]) {
                    for ($turno = $dia->setTimeFromTimeString($inicio); $turno->addMinutes($minutos) <= $dia->setTimeFromTimeString($fin); $turno = $turno->addMinutes($minutos)) {
                        $clave = $medico['id'].'|'.$turno->format('Y-m-d H:i');
                        if (isset($medico['ocupados'][$clave]) || $this->enLicencia($turno, $minutos, $medico['licencias']) || mt_rand(1, 100) > $ocupacion * 100) {
                            continue;
                        }

                        $especialidad = $this->elegir($medico['especialidades']);
                        $paciente = $this->pacientePara($especialidad, $pacientes, $ocupadosPaciente, $turno);
                        if ($paciente === null) {
                            continue;
                        }
                        $ocupadosPaciente[$paciente['id'].'|'.$turno->format('Y-m-d H:i')] = true;

                        $pasada = $turno < $this->ahora;
                        $estado = $pasada
                            ? $this->ponderado(['Atendida' => 72, 'Inasistencia' => 10, 'Cancelada' => 18])
                            : $this->ponderado(['Programada' => 75, 'Confirmada' => 15, 'Cancelada' => 10]);
                        $creada = $pasada
                            ? $turno->subDays(mt_rand(1, 15))->setTime(mt_rand(8, 18), mt_rand(0, 59))
                            : $this->ahora->subDays(mt_rand(0, 10))->setTime(mt_rand(8, 18), mt_rand(0, 59));
                        if ($creada > $this->ahora) {
                            $creada = $this->ahora->subHour();
                        }

                        $idCita = DB::table('cita_medica')->insertGetId([
                            'codigo_cita' => sprintf('CM-%04d', ++$siguienteCita), 'id_paciente' => $paciente['id'], 'id_medico' => $medico['id'],
                            'id_modalidad' => $this->ponderado($modalidades),
                            'fecha' => $dia->toDateString(), 'hra_inicio' => $turno->format('H:i'), 'hra_fin' => $turno->addMinutes($minutos)->format('H:i'),
                            'motivo' => $this->elegir(self::CLINICA[$especialidad]['motivos']), 'estado' => $estado,
                            'motivo_cancelacion' => $estado === 'Cancelada' ? $this->elegir(self::MOTIVOS_CANCELACION) : null,
                            'fecha_creacion' => $creada->format('Y-m-d H:i:sP'),
                        ]);
                        $contadores[0]++;

                        if ($estado === 'Atendida') {
                            $expedientes[$paciente['id']] ??= 'EXP-'.$paciente['codigo'];
                            [$recetas] = $this->crearConsulta($idCita, $turno, $paciente, $especialidad, $expedientes[$paciente['id']]);
                            $contadores[1]++;
                            $contadores[2] += $recetas;
                        }

                        $pago = $this->datosPago($estado, $turno, $creada, $medico['precios'][$especialidad]);
                        if ($pago) {
                            DB::table('pago_cita')->insert([
                                // Se sortea el nombre del método (QR, Efectivo…) con su peso y se traduce a su id.
                                'id_cita' => $idCita, 'id_metodo_pago' => $metodos[$this->ponderado(array_intersect_key(self::METODOS, $metodos))],
                                'codigo_transaccion' => sprintf('TXN-%s-%05d', $pago['fecha']->format('Ymd'), ++$transaccion),
                                'monto' => $pago['monto'], 'moneda' => 'BOB', 'estado' => $pago['estado'], 'fecha_pago' => $pago['fecha']->format('Y-m-d H:i:sP'),
                            ]);
                            $contadores[3]++;
                        }
                    }
                }
            }
        }

        return $contadores;
    }

    /** @return array{0: int} recetas creadas */
    private function crearConsulta(int $idCita, CarbonImmutable $turno, array $paciente, string $especialidad, string $expediente): array
    {
        $datos = self::CLINICA[$especialidad];
        $atencion = $turno->addMinutes(mt_rand(0, 12));

        $idConsulta = DB::table('consulta_medica')->insertGetId([
            'id_cita_medica' => $idCita, 'nro_expediente' => $expediente,
            'peso' => round($paciente['peso'] + mt_rand(-20, 20) / 10, 1), 'estatura' => $paciente['estatura'],
            'motivo_de_consultas' => $this->elegir($datos['motivos']), 'orden_cronologico' => 'Cuadro de '.mt_rand(1, 10).' días de evolución',
            'sintomas' => $this->elegir($datos['sintomas']), 'tratamiento' => $this->elegir($datos['tratamientos']),
            'escala_del_dolor' => mt_rand(0, 7), 'fecha_atencion' => $atencion->format('Y-m-d H:i:sP'),
        ]);

        // CU18: receta en el 60 % de las consultas, con 1 a 3 medicamentos distintos.
        if (mt_rand(1, 100) > 60) {
            return [0];
        }

        $idReceta = DB::table('receta_medica')->insertGetId([
            'id_consulta' => $idConsulta, 'fecha_emision' => $atencion->addMinutes(15)->format('Y-m-d H:i:sP'),
            'indicaciones_generales' => $this->elegir(self::INDICACIONES),
        ]);
        $medicamentos = $datos['medicamentos'];
        shuffle($medicamentos);
        foreach (array_slice($medicamentos, 0, mt_rand(1, min(3, count($medicamentos)))) as [$nombre, $dosis, $frecuencia, $dias]) {
            DB::table('detalle_receta')->insert(['id_receta' => $idReceta, 'medicamento' => $nombre, 'dosis' => $dosis, 'frecuencia' => $frecuencia, 'duracion_dias' => $dias]);
        }

        return [1];
    }

    /**
     * CU24: casi todas las atendidas pagadas (algunas pendientes), parte de las canceladas
     * reembolsadas y parte de las confirmadas prepagadas.
     *
     * @return array{estado: string, monto: float, fecha: CarbonImmutable}|null
     */
    private function datosPago(string $estado, CarbonImmutable $turno, CarbonImmutable $creada, float $precio): ?array
    {
        $prepago = $creada->addHours(mt_rand(1, 20));
        if ($prepago > $this->ahora) {
            $prepago = $this->ahora->subMinutes(30);
        }

        return match (true) {
            $estado === 'Atendida' && mt_rand(1, 100) <= 92 => ['estado' => 'Completado', 'monto' => $precio, 'fecha' => mt_rand(1, 3) === 1 ? $prepago : $turno->subMinutes(mt_rand(5, 40))],
            $estado === 'Atendida' => ['estado' => 'Pendiente', 'monto' => $precio, 'fecha' => $turno],
            $estado === 'Cancelada' && mt_rand(1, 100) <= 25 => ['estado' => 'Reembolsado', 'monto' => $precio, 'fecha' => $prepago],
            $estado === 'Confirmada' && mt_rand(1, 100) <= 60 => ['estado' => 'Completado', 'monto' => $precio, 'fecha' => $prepago],
            default => null,
        };
    }

    // --- Apoyo -----------------------------------------------------------------------

    /** Médicos activos con su grilla, especialidades con precio, turnos ya ocupados y licencias. */
    private function medicosConGrilla(): array
    {
        $medicos = [];
        foreach (DB::table('medico')->where('activo', true)->pluck('id_usuario') as $id) {
            $bloques = [];
            foreach (DB::table('horario_atencion')->where('id_medico', $id)->where('activo', true)->get() as $bloque) {
                $bloques[$bloque->dia_semana][] = [substr($bloque->hora_inicio, 0, 5), substr($bloque->hora_fin, 0, 5), (int) $bloque->duracion_minutos];
            }
            $especialidades = DB::table('medico_especialidad')->join('especialidad', 'especialidad.id', '=', 'medico_especialidad.id_especialidad')
                ->where('id_medico', $id)->pluck('especialidad.precio_consulta', 'especialidad.nombre')->all();
            $especialidades = array_intersect_key($especialidades, self::CLINICA);
            if (! $bloques || ! $especialidades) {
                continue;
            }

            $medicos[] = [
                'id' => $id,
                'bloques' => $bloques,
                'especialidades' => array_keys($especialidades),
                'precios' => array_map('floatval', $especialidades),
                // Turnos con citas reales que no se deben pisar.
                'ocupados' => DB::table('cita_medica')->where('id_medico', $id)->where('estado', '!=', 'Cancelada')->get(['fecha', 'hra_inicio'])
                    ->mapWithKeys(fn ($c) => ["{$id}|".substr((string) $c->fecha, 0, 10).' '.substr($c->hra_inicio, 0, 5) => true])->all(),
                'licencias' => DB::table('indisponibilidad_medico')->where('id_medico', $id)->get(['fecha_inicio', 'fecha_fin'])
                    ->map(fn ($l) => [CarbonImmutable::parse($l->fecha_inicio), CarbonImmutable::parse($l->fecha_fin)])->all(),
            ];
        }

        return $medicos;
    }

    private function enLicencia(CarbonImmutable $turno, int $minutos, array $licencias): bool
    {
        foreach ($licencias as [$inicio, $fin]) {
            if ($inicio < $turno->addMinutes($minutos) && $fin > $turno) {
                return true;
            }
        }

        return false;
    }

    /** Paciente adecuado y libre: niños en Pediatría, mujeres en Ginecología, mayores de 35 en Cardiología. */
    private function pacientePara(string $especialidad, array $pacientes, array $ocupados, CarbonImmutable $turno): ?array
    {
        $aptos = array_values(array_filter($pacientes, fn (array $p) => match ($especialidad) {
            'Pediatría' => $p['edad'] < 14,
            'Ginecología' => $p['sexo'] === 'F' && $p['edad'] >= 16,
            'Cardiología' => $p['edad'] >= 35,
            default => $p['edad'] >= 14 || $especialidad === 'Odontología',
        }));

        for ($intento = 0; $intento < 5 && $aptos; $intento++) {
            $paciente = $this->elegir($aptos);
            if (! isset($ocupados[$paciente['id'].'|'.$turno->format('Y-m-d H:i')])) {
                return $paciente;
            }
        }

        return null;
    }

    /** Id de cada método de pago por la palabra que lo identifica (QR, Efectivo…). */
    private function metodosPago(): array
    {
        $metodos = [];
        foreach (DB::table('metodo_pago')->where('activo', true)->get(['id', 'nombre']) as $metodo) {
            foreach (array_keys(self::METODOS) as $clave) {
                if (stripos($metodo->nombre, $clave) !== false) {
                    $metodos[$clave] = $metodo->id;
                }
            }
        }

        return $metodos;
    }

    private function elegir(array $opciones): mixed
    {
        return $opciones[mt_rand(0, count($opciones) - 1)];
    }

    /** Clave elegida según pesos relativos: ['Atendida' => 72, …]. */
    private function ponderado(array $pesos): int|string
    {
        $tirada = mt_rand(1, array_sum($pesos));
        foreach ($pesos as $clave => $peso) {
            if (($tirada -= $peso) <= 0) {
                return $clave;
            }
        }

        return array_key_first($pesos);
    }

    private function ascii(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }
}
