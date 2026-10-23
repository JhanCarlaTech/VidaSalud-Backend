4. FLUJO DE TRABAJO: CAPTURA DE REQUISITOS

4.1. Encontrar Actores y Casos de Uso
Para el Sistema Web de Gestión de Citas Médicas para la Clínica Vida Salud, los actores representan a los distintos usuarios que interactúan con la aplicación web y móvil, ya sea de manera directa o indirecta dentro del proceso administrativo, médico y de atención al paciente.
Los casos de uso permiten identificar las principales funcionalidades que el sistema debe ofrecer para optimizar la gestión de pacientes, programación de citas, control de asistencia, cobros en línea, historial clínico y generación de reportes operativos en la clínica.

4.1.1. Actores

Administrador
Responsable de configurar y supervisar la totalidad del sistema. Gestiona la creación y administración de usuarios, asignación de roles, registro de médicos y sus respectivas especialidades, así como el control global de parámetros del sistema, configuración de pasarelas de pago, auditoría de bitácora y consulta de reportes consolidados. Tiene acceso total a las funcionalidades administrativas y de configuración.

Recepcionista
Responsable de la gestión operativa diaria de las citas médicas y la atención al cliente en la recepción. Encargada de registrar nuevos pacientes, buscar pacientes existentes, verificar la disponibilidad de horarios médicos, programar, reprogramar y cancelar citas, verificar transacciones o estados de pago, además de registrar la asistencia o inasistencia de los pacientes el día de su atención.

Médico
Personal profesional de salud encargado de la consulta y atención clínica. Es responsable de consultar su agenda diaria de atenciones, visualizar los datos básicos y antecedentes del paciente, verificar la fecha y hora de sus citas asignadas, identificar el estado de las consultas (pendientes, atendidas, canceladas) y marcar la cita como atendida registrando la ficha clínica y la receta médica.

Paciente
Usuario final del sistema que gestiona sus servicios de salud a través del perfil en la aplicación web o móvil. Realiza su registro personal, gestiona sus datos de contacto y de emergencia, administra su información médica básica (alergias, tipo de sangre, enfermedades crónicas), busca disponibilidad médica por especialidad, reserva y realiza el pago de sus citas médicas mediante pasarela digital, realiza el seguimiento de sus citas y consulta su historial de atenciones.

4.1.2. Clasificación y Reestructuración de Casos de Uso
Organizado rigurosamente por Módulos Funcionales, reordenando la numeración correlativa sin duplicados e integrando el módulo de pasarela de pago:

┌─────────────────────────────────────────────────────────────────────────┐
│                     ESTRUCTURA GENERAL DE MÓDULOS                       │
├─────────────────────────────────────────────────────────────────────────┤
│  Módulo 1: Seguridad, Usuarios y Configuración del Sistema (CU01–CU06)  │
│  Módulo 2: Personal Médico y Especialidades (CU07–CU10)                 │
│  Módulo 3: Pacientes e Información Base (CU11–CU12)                     │
│  Módulo 4: Operación de Citas Médicas, Agenda y Pagos (CU13–CU16, CU24) │
│  Módulo 5: Atención Clínica, Expediente e Historial (CU17–CU19)         │
│  Módulo 6: Notificaciones, Inteligencia Operativa y Reportes (CU20–CU23)│
└─────────────────────────────────────────────────────────────────────────┘

Módulo 1: Seguridad, Usuarios y Configuración del Sistema
CU01 - Autenticar y Gestionar Sesión: Permite a los usuarios acceder de forma segura con sus credenciales (verificación de hash BCRYPT), gestionar el cierre de sesión y validar el token y permisos de rol activo. (Todos los actores)
CU02 - Gestionar Usuarios y Asignar Roles: Registrar, modificar, activar/desactivar usuarios y asociarles sus roles en el sistema. (Administrador)
CU03 - Gestionar Roles y Asignar Permisos: Definir los permisos y accesos granulares de cada rol. (Administrador)
CU04 - Cargar Usuarios por Lotes (Excel/CSV): Importación masiva de usuarios o pacientes mediante archivos estructurados. (Administrador)
CU05 - Configurar Perfil Personal: Modificar datos de contacto, contraseña, notificaciones y preferencias personales de privacidad. (Todos los actores)
CU06 - Consultar Bitácora de Auditoría: Registrar y consultar logs de operaciones, transacciones, datos modificados e IP de origen. (Administrador)

Módulo 2: Gestión de Personal Médico y Especialidades
CU07 - Gestionar Especialidades Médicas: Administrar la lista de especialidades de la clínica (Medicina General, Pediatría, Cardiología, etc.). (Administrador)
CU08 - Gestionar Personal Médico: Registrar legajo profesional, salario, asignación de especialidades y estado de los médicos. (Administrador)
CU09 - Gestionar Horarios y Disponibilidad Médica: Definir la grilla semanal de atención por médico (días, horas de inicio/fin y duración en bloques). (Administrador, Médico)
CU10 - Gestionar Licencias y Bloqueos de Agenda: Registrar ausencias, feriados, licencias médicas o indisponibilidad imprevista para bloquear agendas. (Administrador, Médico)

Módulo 3: Gestión de Pacientes e Información Médica Base
CU11 - Gestionar Pacientes: Registrar nuevos pacientes, buscar por CI/nombre, modificar perfil y administrar contactos de emergencia. (Administrador, Recepcionista, Paciente)
CU12 - Gestionar Catálogo de Enfermedades: Administrar el catálogo global de enfermedades, descripciones y niveles de riesgo. (Administrador, Médico)

Módulo 4: Operación de Citas Médicas, Agenda y Pagos
CU13 - Gestionar Citas Médicas y Modalidad: Programar citas seleccionando especialidad, médico, fecha, hora disponible y modalidad (Presencial, Virtual, Domiciliaria). (Recepcionista, Paciente)
CU14 - Reprogramar y Cancelar Citas Médicas: Cambiar horarios de citas programadas o cancelarlas registrando el motivo correspondiente sin eliminar el registro. (Recepcionista, Paciente)
CU15 - Registrar Control de Asistencia: Transicionar los estados de la cita el día de la atención ('Confirmada', 'En Sala de Espera', 'Atendida', 'Inasistencia'). (Recepcionista)
CU16 - Consultar Agenda Médica: Visualizar y filtrar el listado diario de citas asignadas por médico, fecha y estado. (Recepcionista, Médico)
CU24 - Gestionar Pasarela de Pago: Configurar y procesar transacciones electrónicas (tarjetas de crédito/débito, código QR, Stripe/PayPal) para el pago de la reserva de citas médicas, validación de comprobantes, confirmación de transacciones y emisión de comprobante de pago digital. (Administrador, Paciente, Recepcionista)

Módulo 5: Atención Clínica, Expediente e Historial
CU17 - Registrar Consulta Médica: Formulario de atención médica presencial o virtual. Permite cambiar el estado de la cita a 'Atendida', registrar antecedentes, motivo de consulta, examen físico (peso, estatura, cálculo de IMC automático) y diagnóstico. (Médico)
CU18 - Emitir Receta Médica: Formulario compuesto derivado de la consulta para prescribir medicamentos, dosis, frecuencia y duración del tratamiento. (Médico)
CU19 - Consultar Historial Clínico del Paciente: Módulo de consulta pasiva que permite desplegar el expediente cronológico con las atenciones pasadas, diagnósticos y recetas prescritas. (Médico, Paciente)

Módulo 6: Notificaciones, Inteligencia Operativa y Reportes
CU20 - Gestionar Notificaciones y Recordatorios: Envío de alertas automáticas (Push/Email) para confirmación y recordatorio de próximas citas. (Sistema, Recepcionista, Paciente)
CU21 - Generar Reportes Operativos Tradicionales: Filtrar y exportar reportes tabulares de citas por fecha, médico, especialidad y estado en formatos PDF o CSV. (Administrador, Recepcionista)
CU22 - Generar Reportes Generativos por Comando de Voz: Interfaz conversacional (Voice-to-Text) que permite dictar solicitudes de reportes en lenguaje natural (ej. "Muéstrame un reporte de citas canceladas del Dr. Pérez este mes"). El sistema procesa la consulta en PostgreSQL, estructura el reporte gráfico/tabular y genera un resumen ejecutivo por voz sintetizada.
CU23 - Visualizar Dashboard Administrativo: Panel gerencial interactivo con indicadores gráficos (KPIs), volumen de atenciones, métricas de inasistencias y rendimiento de la clínica. (Administrador)

4.2. Priorizar Casos de Uso

Leyenda de Actores:
Administrador = A
Recepcionista = R
Médico = M
Paciente = P

| ID CASO DE USO   | CASO DE USO   | ACTOR   | PRIORIDAD   |
| CU01   | Autenticar y Gestionar Sesión   | A, R, M, P   | Alta   |
| CU02   | Gestionar Usuarios y Asignar Roles   | A   | Alta   |
| CU03   | Gestionar Roles y Asignar Permisos   | A   | Alta   |
| CU04   | Cargar Usuarios por Lotes (Excel/CSV)   | A   | Media   |
| CU05   | Configurar Perfil Personal   | A, R, M, P   | Alta   |
| CU06   | Consultar Bitácora de Auditoría   | A   | Alta   |
| CU07   | Gestionar Especialidades Médicas   | A   | Alta   |
| CU08   | Gestionar Personal Médico   | A   | Alta   |
| CU09   | Gestionar Horarios y Disponibilidad Médica   | A, M   | Alta   |
| CU10   | Gestionar Licencias y Bloqueos de Agenda   | A, M   | Media   |
| CU11   | Gestionar Pacientes   | A, R, P   | Alta   |
| CU12   | Gestionar Catálogo de Enfermedades   | A, M   | Media   |
| CU13   | Gestionar Citas Médicas y Modalidad   | R, P   | Alta   |
| CU14   | Reprogramar y Cancelar Citas Médicas   | R, P   | Alta   |
| CU15   | Registrar Control de Asistencia   | R   | Alta   |
| CU16   | Consultar Agenda Médica   | R, M   | Alta   |
| CU17   | Registrar Consulta Médica   | M   | Alta   |
| CU18   | Emitir Receta Médica   | M   | Alta   |
| CU19   | Consultar Historial Clínico del Paciente   | M, P   | Alta   |
| CU20   | Gestionar Notificaciones y Recordatorios   | R, P   | Media   |
| CU21   | Generar Reportes Operativos Tradicionales   | A, R   | Alta   |
| CU22   | Generar Reportes Generativos por Voz (IA)   | A, R   | Media   |
| CU23   | Visualizar Dashboard Administrativo   | A   | Media   |
| CU24   | Gestionar Pasarela de Pago   | A, R, P   | Alta   |

Ciclo #1: Módulo Base, Entidades Maestras y Operación Principal de Citas y Pagos
Criterio de Dependencia: Incluye los casos de uso fundamentales que permiten la seguridad/autenticación, la gestión de datos maestros (usuarios, personal médico, pacientes, especialidades, horarios) y el flujo transaccional principal (agendamiento, cobro/pago por pasarela, asistencia y atención médica). Sin estos, no pueden existir los historiales ni los reportes.

| ID CASO DE USO   | CASO DE USO   | ACTOR   | PRIORIDAD   |
| CU01   | Autenticar y Gestionar Sesión   | A, R, M, P   | Alta   |
| CU02   | Gestionar Usuarios y Asignar Roles   | A   | Alta   |
| CU03   | Gestionar Roles y Asignar Permisos   | A   | Alta   |
| CU05   | Configurar Perfil Personal   | A, R, M, P   | Alta   |
| CU07   | Gestionar Especialidades Médicas   | A   | Alta   |
| CU08   | Gestionar Personal Médico   | A   | Alta   |
| CU09   | Gestionar Horarios y Disponibilidad Médica   | A, M   | Alta   |
| CU11   | Gestionar Pacientes   | A, R, P   | Alta   |
| CU13   | Gestionar Citas Médicas y Modalidad   | R, P   | Alta   |
| CU14   | Reprogramar y Cancelar Citas Médicas   | R, P   | Alta   |
| CU15   | Registrar Control de Asistencia   | R   | Alta   |
| CU16   | Consultar Agenda Médica   | R, M   | Alta   |
| CU17   | Registrar Consulta Médica   | M   | Alta   |
| CU24   | Gestionar Pasarela de Pago   | A, R, P   | Alta   |

Ciclo #2: Funcionalidades Especializadas, Historiales, Notificaciones e Inteligencia Operativa
Criterio de Dependencia: Agrupa casos de uso que dependen funcionalmente de los datos generados en el Ciclo #1 (por ejemplo: la prescripción de recetas y consulta de historial dependen de la existencia de atenciones previas; los reportes y tableros dependen de los registros transaccionales), así como herramientas complementarias de importación masiva y utilitarios de IA.

| ID CASO DE USO   | CASO DE USO   | ACTOR   | PRIORIDAD   |
| CU04   | Cargar Usuarios por Lotes (Excel/CSV)   | A   | Media   |
| CU06   | Consultar Bitácora de Auditoría   | A   | Alta   |
| CU10   | Gestionar Licencias y Bloqueos de Agenda   | A, M   | Media   |
| CU12   | Gestionar Catálogo de Enfermedades   | A, M   | Media   |
| CU18   | Emitir Receta Médica   | M   | Alta   |
| CU19   | Consultar Historial Clínico del Paciente   | M, P   | Alta   |
| CU20   | Gestionar Notificaciones y Recordatorios   | R, P   | Media   |
| CU21   | Generar Reportes Operativos Tradicionales   | A, R   | Alta   |
| CU22   | Generar Reportes Generativos por Voz (IA)   | A, R   | Media   |
| CU23   | Visualizar Dashboard Administrativo   | A   | Media   |