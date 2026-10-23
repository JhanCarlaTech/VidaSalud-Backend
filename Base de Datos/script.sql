--------------------------------------------------------------------------------
-- 1. TIPOS ENUMERADOS PERSONALIZADOS
--------------------------------------------------------------------------------
CREATE TYPE estado_cita_enum AS ENUM ('Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia');
CREATE TYPE estado_pago_enum AS ENUM ('Pendiente', 'Completado', 'Rechazado', 'Reembolsado');
CREATE TYPE sexo_enum AS ENUM ('M', 'F', 'O');

--------------------------------------------------------------------------------
-- 2. SEGURIDAD, USUARIOS Y ROLES (RBAC)
--------------------------------------------------------------------------------
CREATE TABLE usuario (
    id BIGSERIAL PRIMARY KEY,
    ci VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    contrasena VARCHAR(255) NOT NULL,
    telefono VARCHAR(20),
    sexo sexo_enum,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    fecha_creacion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rol (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    descripcion TEXT,
    fecha_creacion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE permiso (
    id SERIAL PRIMARY KEY,
    accion VARCHAR(50) NOT NULL UNIQUE,
    descripcion TEXT,
    fecha_creacion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE rol_permiso (
    id_rol INT REFERENCES rol(id) ON DELETE CASCADE,
    id_permiso INT REFERENCES permiso(id) ON DELETE CASCADE,
    fecha_asignacion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion TIMESTAMPTZ,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (id_rol, id_permiso)
);

CREATE TABLE rol_usuario (
    id_rol INT REFERENCES rol(id) ON DELETE CASCADE,
    id_usuario BIGINT REFERENCES usuario(id) ON DELETE CASCADE,
    fecha_asignacion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion TIMESTAMPTZ,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (id_rol, id_usuario)
);

CREATE TABLE bitacora (
    id BIGSERIAL PRIMARY KEY,
    id_usuario BIGINT REFERENCES usuario(id) ON DELETE SET NULL,
    accion VARCHAR(100) NOT NULL,
    tabla_afectada VARCHAR(50) NOT NULL,
    ip_origen VARCHAR(45) NOT NULL,
    datos_anteriores JSONB,
    datos_nuevos JSONB,
    fecha_registro TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

--------------------------------------------------------------------------------
-- 3. PACIENTES Y CONTACTOS DE EMERGENCIA
--------------------------------------------------------------------------------
CREATE TABLE contacto_de_emergencia (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    parentesco VARCHAR(50) NOT NULL,
    telefono VARCHAR(20) NOT NULL
);

CREATE TABLE paciente (
    id_usuario BIGINT PRIMARY KEY REFERENCES usuario(id) ON DELETE CASCADE,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    fecha_nacimiento DATE NOT NULL,
    tipo_sangre VARCHAR(5),
    id_contacto_emergencia BIGINT REFERENCES contacto_de_emergencia(id) ON DELETE SET NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

--------------------------------------------------------------------------------
-- 4. MÉDICOS, ESPECIALIDADES Y HORARIOS DE ATENCIÓN
--------------------------------------------------------------------------------
CREATE TABLE medico (
    id_usuario BIGINT PRIMARY KEY REFERENCES usuario(id) ON DELETE CASCADE,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    salario NUMERIC(10,2) CHECK (salario >= 0),
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE especialidad (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE medico_especialidad (
    id_medico BIGINT REFERENCES medico(id_usuario) ON DELETE CASCADE,
    id_especialidad INT REFERENCES especialidad(id) ON DELETE CASCADE,
    PRIMARY KEY (id_medico, id_especialidad)
);

CREATE TABLE horario_atencion (
    id BIGSERIAL PRIMARY KEY,
    id_medico BIGINT NOT NULL REFERENCES medico(id_usuario) ON DELETE CASCADE,
    dia_semana INT NOT NULL CHECK (dia_semana BETWEEN 1 AND 7), -- 1: Lunes, 7: Domingo
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    duracion_minutos INT NOT NULL DEFAULT 30,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT chk_horario_valido CHECK (hora_fin > hora_inicio)
);

CREATE TABLE indisponibilidad_medico (
    id BIGSERIAL PRIMARY KEY,
    id_medico BIGINT NOT NULL REFERENCES medico(id_usuario) ON DELETE CASCADE,
    fecha_inicio TIMESTAMPTZ NOT NULL,
    fecha_fin TIMESTAMPTZ NOT NULL,
    motivo TEXT,
    CONSTRAINT chk_fechas_indisponibilidad CHECK (fecha_fin > fecha_inicio)
);

--------------------------------------------------------------------------------
-- 5. OPERACIÓN DE CITAS, NOTIFICACIONES Y PASARELA DE PAGO
--------------------------------------------------------------------------------
CREATE TABLE modalidad_cita (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE -- Presencial, Virtual (Telemedicina), Domiciliaria
);

CREATE TABLE cita_medica (
    id BIGSERIAL PRIMARY KEY,
    codigo_cita VARCHAR(20) NOT NULL UNIQUE,
    id_paciente BIGINT NOT NULL REFERENCES paciente(id_usuario),
    id_medico BIGINT NOT NULL REFERENCES medico(id_usuario),
    id_modalidad INT NOT NULL REFERENCES modalidad_cita(id),
    fecha DATE NOT NULL,
    hra_inicio TIME NOT NULL,
    hra_fin TIME NOT NULL,
    motivo TEXT,
    estado estado_cita_enum NOT NULL DEFAULT 'Programada',
    motivo_cancelacion TEXT,
    fecha_creacion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_horas_cita CHECK (hra_fin > hra_inicio)
);

-- Prevención de citas dobles para el mismo médico y horario
CREATE UNIQUE INDEX idx_cita_medico_no_solapada ON cita_medica (id_medico, fecha, hra_inicio) 
WHERE estado IN ('Programada', 'Confirmada');

CREATE TABLE notificacion (
    id BIGSERIAL PRIMARY KEY,
    id_paciente BIGINT NOT NULL REFERENCES paciente(id_usuario) ON DELETE CASCADE,
    id_cita BIGINT REFERENCES cita_medica(id) ON DELETE SET NULL,
    tipo VARCHAR(50) NOT NULL,
    mensaje TEXT NOT NULL,
    fecha_envio TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado BOOLEAN NOT NULL DEFAULT FALSE
);

-- TABLAS DE LA PASARELA DE PAGO
CREATE TABLE metodo_pago (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE, -- Pago QR, Tarjeta de Crédito/Débito, Transferencia, Efectivo
    descripcion TEXT,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE pago_cita (
    id BIGSERIAL PRIMARY KEY,
    id_cita BIGINT NOT NULL UNIQUE REFERENCES cita_medica(id) ON DELETE RESTRICT,
    id_metodo_pago INT NOT NULL REFERENCES metodo_pago(id) ON DELETE RESTRICT,
    codigo_transaccion VARCHAR(100) UNIQUE,
    monto NUMERIC(10,2) NOT NULL CHECK (monto >= 0),
    moneda VARCHAR(3) NOT NULL DEFAULT 'BOB',
    estado estado_pago_enum NOT NULL DEFAULT 'Pendiente',
    fecha_pago TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    detalles_pasarela JSONB
);

--------------------------------------------------------------------------------
-- 6. CONSULTA MÉDICA, RECETAS E HISTORIAL CLÍNICO
--------------------------------------------------------------------------------
CREATE TABLE consulta_medica (
    id BIGSERIAL PRIMARY KEY,
    id_cita_medica BIGINT NOT NULL UNIQUE REFERENCES cita_medica(id) ON DELETE RESTRICT,
    nro_expediente VARCHAR(50) NOT NULL,
    peso NUMERIC(5,2) NOT NULL,
    estatura NUMERIC(5,2) NOT NULL,
    imc NUMERIC(4,2) GENERATED ALWAYS AS (peso / ((estatura/100) * (estatura/100))) STORED,
    motivo_de_consultas TEXT NOT NULL,
    orden_cronologico TEXT,
    sintomas TEXT,
    tratamiento TEXT,
    escala_del_dolor INT CHECK (escala_del_dolor BETWEEN 0 AND 10),
    fecha_atencion TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE receta_medica (
    id BIGSERIAL PRIMARY KEY,
    id_consulta BIGINT NOT NULL REFERENCES consulta_medica(id) ON DELETE CASCADE,
    fecha_emision TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    indicaciones_generales TEXT
);

CREATE TABLE detalle_receta (
    id BIGSERIAL PRIMARY KEY,
    id_receta BIGINT NOT NULL REFERENCES receta_medica(id) ON DELETE CASCADE,
    medicamento VARCHAR(150) NOT NULL,
    dosis VARCHAR(100) NOT NULL,
    frecuencia VARCHAR(100) NOT NULL,
    duracion_dias INT NOT NULL CHECK (duracion_dias > 0)
);

CREATE TABLE enfermedad (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    descripcion TEXT,
    nivel_riesgo VARCHAR(20)
);

CREATE TABLE paciente_enfermedad (
    id_paciente BIGINT REFERENCES paciente(id_usuario) ON DELETE CASCADE,
    id_enfermedad INT REFERENCES enfermedad(id) ON DELETE CASCADE,
    fecha_diagnostico DATE,
    observaciones TEXT,
    PRIMARY KEY (id_paciente, id_enfermedad)
);

--------------------------------------------------------------------------------
-- 7. POBLACIÓN DE DATOS E INSERCIONES INICIALES (SEEDERS)
--------------------------------------------------------------------------------

-- Roles y Permisos
INSERT INTO rol (nombre, descripcion) VALUES
('Administrador', 'Acceso total a la configuración del sistema y gestión general'),
('Medico', 'Acceso a la agenda médica, expedientes clínicos y recetas'),
('Recepcionista', 'Gestión de pacientes, agendamiento de citas y control de asistencia'),
('Paciente', 'Acceso al perfil personal, historial y reservas de citas');

INSERT INTO permiso (accion, descripcion) VALUES
('usuarios_gestionar', 'Crear, modificar y desactivar usuarios'),
('medicos_gestionar', 'Administrar personal médico, horarios y especialidades'),
('pacientes_gestionar', 'Registrar y actualizar datos de pacientes'),
('citas_programar', 'Reservar, reprogramar y cancelar citas médicas'),
('citas_atender', 'Marcar cita como atendida y registrar expediente médico'),
('reportes_ver', 'Consultar reportes estadísticos y gerenciales');

INSERT INTO rol_permiso (id_rol, id_permiso) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6),
(3, 3), (3, 4), (3, 6),
(2, 5), (2, 6);

-- Modalidades de Cita y Métodos de Pago
INSERT INTO modalidad_cita (nombre) VALUES 
('Presencial'), 
('Virtual (Telemedicina)'), 
('Domiciliaria') 
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO metodo_pago (nombre, descripcion) VALUES
('Pago QR (Simple/Bizum)', 'Pago rápido mediante escaneo de código QR bancario'),
('Tarjeta de Crédito / Débito', 'Pago en línea con Visa, Mastercard o Red Enlace'),
('Transferencia Bancaria', 'Transferencia directa a cuenta institucional de la clínica'),
('Efectivo en Recepción', 'Pago presencial al momento de la llegada')
ON CONFLICT (nombre) DO NOTHING;

-- Usuarios (Contraseñas Hash BCRYPT de 'Password123!')
INSERT INTO usuario (ci, nombre, apellido, correo, contrasena, telefono, sexo) VALUES
('10293847', 'Carlos', 'Mendoza', 'admin@vidasalud.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '70011223', 'M'),
('48392019', 'Juan', 'Pérez', 'dr.perez@vidasalud.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '71122334', 'M'),
('59382012', 'María', 'Gómez', 'dra.gomez@vidasalud.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '72233445', 'F'),
('69201928', 'Roberto', 'Fernández', 'dr.fernandez@vidasalud.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '73344556', 'M'),
('78201923', 'Ana', 'Roca', 'ana.recepcion@vidasalud.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '74455667', 'F'),
('89201924', 'Lucía', 'Suárez', 'lucia.recepcion@vidasalud.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '75566778', 'F'),
('91203921', 'María', 'López', 'maria.lopez@gmail.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '76677889', 'F'),
('92304912', 'Jorge', 'Martínez', 'jorge.martinez@hotmail.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '77788990', 'M'),
('93405923', 'Sonia', 'Vargas', 'sonia.vargas@outlook.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '78899001', 'F'),
('94506934', 'Luis', 'Morales', 'luis.morales@gmail.com', '$2a$12$KIXpZ7.p9.K.R2/c2s.Yxe4WbXvC1wV9T/6A/1yXG.1Gv8R8mR2.', '79900112', 'M');

-- Asignación de Roles
INSERT INTO rol_usuario (id_rol, id_usuario) VALUES
(1, 1), (2, 2), (2, 3), (2, 4), (3, 5), (3, 6), (4, 7), (4, 8), (4, 9), (4, 10);

-- Especialidades y Médicos
INSERT INTO especialidad (nombre, descripcion) VALUES
('Medicina General', 'Atención médica primaria y preventiva'),
('Pediatría', 'Atención integral de salud infantil y adolescentes'),
('Ginecología', 'Salud del sistema reproductor femenino'),
('Odontología', 'Diagnóstico y tratamiento bucodental'),
('Cardiología', 'Prevención y tratamiento de enfermedades cardiovasculares');

INSERT INTO medico (id_usuario, codigo, salario) VALUES
(2, 'MED-001', 8500.00),
(3, 'MED-002', 9200.00),
(4, 'MED-003', 9000.00);

INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES
(2, 1), (3, 2), (3, 3), (4, 5);

INSERT INTO horario_atencion (id_medico, dia_semana, hora_inicio, hora_fin, duracion_minutos) VALUES
(2, 1, '08:00', '12:00', 30),
(2, 3, '14:00', '18:00', 30),
(3, 2, '09:00', '13:00', 30),
(3, 4, '09:00', '13:00', 30),
(4, 5, '08:00', '12:00', 30);

INSERT INTO indisponibilidad_medico (id_medico, fecha_inicio, fecha_fin, motivo) VALUES
(2, '2026-11-20 08:00:00+00', '2026-11-20 18:00:00+00', 'Asistencia a Congreso Nacional de Medicina');

-- Pacientes y Contactos de Emergencia
INSERT INTO contacto_de_emergencia (nombre, apellido, parentesco, telefono) VALUES
('Pedro', 'López', 'Esposo', '70099887'),
('Carmen', 'Rios', 'Madre', '71188996'),
('Hugo', 'Morales', 'Hermano', '72277005');

INSERT INTO paciente (id_usuario, codigo, fecha_nacimiento, tipo_sangre, id_contacto_emergencia) VALUES
(7, 'PAC-0001', '1990-05-14', 'O+', 1),
(8, 'PAC-0002', '1985-08-22', 'A+', 2),
(9, 'PAC-0003', '1998-12-01', 'O-', NULL),
(10, 'PAC-0004', '2015-03-10', 'B+', 3);

-- Enfermedades
INSERT INTO enfermedad (nombre, descripcion, nivel_riesgo) VALUES
('Hipertensión Arterial', 'Presión sanguínea continuamente alta', 'Moderado'),
('Diabetes Mellitus Tipo 2', 'Nivel elevado de glucosa en sangre', 'Alto'),
('Asma Bronquial', 'Inflamación de las vías respiratorias', 'Moderado');

INSERT INTO paciente_enfermedad (id_paciente, id_enfermedad, fecha_diagnostico, observaciones) VALUES
(8, 1, '2022-01-15', 'Paciente bajo tratamiento controlado con Enalapril'),
(8, 2, '2023-06-10', 'Requiere control trimestral de glucemia');

-- Citas Médicas, Pagos y Consultas (Historial)
INSERT INTO cita_medica (codigo_cita, id_paciente, id_medico, id_modalidad, fecha, hra_inicio, hra_fin, motivo, estado) VALUES
('CM-0001', 7, 2, 1, '2026-11-02', '09:00', '09:30', 'Consulta por cefalea leve y fatiga', 'Atendida'),
('CM-0002', 8, 4, 1, '2026-11-27', '08:30', '09:00', 'Control cardiológico de rutina', 'Programada'),
('CM-0003', 9, 3, 2, '2026-11-03', '10:00', '10:30', 'Consulta ginecológica virtual', 'Cancelada');

-- Registro de Pago asociado a la Cita CM-0001
INSERT INTO pago_cita (id_cita, id_metodo_pago, codigo_transaccion, monto, estado, detalles_pasarela) VALUES
(1, 1, 'TXN-20261102-88219', 150.00, 'Completado', '{"gateway": "PagoQR", "authorization_code": "982134"}');

INSERT INTO consulta_medica (id_cita_medica, nro_expediente, peso, estatura, motivo_de_consultas, orden_cronologico, sintomas, tratamiento, escala_del_dolor) VALUES
(1, 'EXP-2026-001', 62.50, 165.00, 'Dolor de cabeza recurrente de 3 días de evolución', 'Aparición progresiva tras jornada laboral', 'Cefalea holocraneana leve, visión borrosa ocasional', 'Reposo, hidratación y paracetamol 500mg', 4);

INSERT INTO receta_medica (id_consulta, indicaciones_generales) VALUES
(1, 'Tomar con abundante agua. Evitar consumo de café durante el tratamiento.');

INSERT INTO detalle_receta (id_receta, medicamento, dosis, frecuencia, duracion_dias) VALUES
(1, 'Paracetamol', '500 mg', 'Cada 8 horas', 3),
(1, 'Complejo B', '1 Comprimido', 'Cada 24 horas (Mañanas)', 15);

INSERT INTO bitacora (id_usuario, accion, tabla_afectada, ip_origen, datos_nuevos) VALUES
(5, 'INSERT', 'cita_medica', '192.168.1.45', '{"codigo_cita": "CM-0001", "paciente": "María López"}'),
(2, 'INSERT', 'consulta_medica', '192.168.1.10', '{"expediente": "EXP-2026-001", "diagnostico": "Cefalea tensional"}');

-- =============================================================================
-- CONSULTAS DE VERIFICACIÓN (SELECT DE TODAS LAS TABLAS)
-- =============================================================================

-- 1. Módulo de Seguridad, Usuarios y Permisos
SELECT * FROM usuario;
SELECT * FROM rol;
SELECT * FROM permiso;
SELECT * FROM rol_permiso;
SELECT * FROM rol_usuario;
SELECT * FROM bitacora;

-- 2. Módulo de Pacientes
SELECT * FROM contacto_de_emergencia;
SELECT * FROM paciente;

-- 3. Módulo de Personal Médico y Disponibilidad
SELECT * FROM medico;
SELECT * FROM especialidad;
SELECT * FROM medico_especialidad;
SELECT * FROM horario_atencion;
SELECT * FROM indisponibilidad_medico;

-- 4. Módulo de Citas Médicas y Pasarela de Pago
SELECT * FROM modalidad_cita;
SELECT * FROM cita_medica;
SELECT * FROM metodo_pago;
SELECT * FROM pago_cita;
SELECT * FROM notificacion;

-- 5. Módulo de Atención Clínica, Expediente y Recetas
SELECT * FROM consulta_medica;
SELECT * FROM receta_medica;
SELECT * FROM detalle_receta;
SELECT * FROM enfermedad;
SELECT * FROM paciente_enfermedad;