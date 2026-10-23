backend-vidasalud/
├── app/
│   ├── Modules/
│   │   ├── ModuloSeguridad/                → Módulo 1: CU01–CU06 (Autenticación, Usuarios, Roles, Bitácora)
│   │   │   ├── Models/                     → Modelos aislados por módulo
│   │   │   │   ├── Usuario.php
│   │   │   │   ├── Rol.php
│   │   │   │   ├── Permiso.php
│   │   │   │   └── Bitacora.php
│   │   │   ├── Controllers/
│   │   │   │   ├── AuthController.php      → CU01: Login, Logout, Session check
│   │   │   │   ├── UsuarioController.php   → CU02: CRUD Usuarios y asignación de roles
│   │   │   │   ├── RolController.php       → CU03: Roles y Permisos
│   │   │   │   ├── ImportUsuarioController.php → CU04: Carga masiva por lotes (Excel/CSV)
│   │   │   │   ├── PerfilController.php    → CU05: Configuración de perfil personal
│   │   │   │   └── BitacoraController.php  → CU06: Consulta de logs de auditoría
│   │   │   ├── Requests/                   → Validaciones de entrada (DTOs Input)
│   │   │   │   ├── LoginRequest.php
│   │   │   │   ├── UsuarioCreateRequest.php
│   │   │   │   └── ImportLoteRequest.php
│   │   │   ├── Resources/                  → Respuestas formateadas (DTOs Output)
│   │   │   │   ├── UsuarioResource.php
│   │   │   │   └── BitacoraResource.php
│   │   │   ├── Services/                   → Lógica de negocio
│   │   │   │   ├── AuthService.php
│   │   │   │   ├── UsuarioService.php
│   │   │   │   └── ImportLoteService.php
│   │   │   └── Repositories/               → Consultas complejas a BD
│   │   │       ├── UsuarioRepository.php
│   │   │       └── BitacoraRepository.php
│   │   │
│   │   ├── ModuloPersonalMedico/           → Módulo 2: CU07–CU10 (Especialidades, Médicos, Horarios, Licencias)[cite: 2, 3, 5]
│   │   │   ├── Models/
│   │   │   │   ├── Medico.php
│   │   │   │   ├── Especialidad.php
│   │   │   │   ├── HorarioAtencion.php
│   │   │   │   └── BloqueoAgenda.php       → Indisponibilidad médica / Licencias
│   │   │   ├── Controllers/
│   │   │   │   ├── EspecialidadController.php → CU07: CRUD Especialidades[cite: 2, 3, 5]
│   │   │   │   ├── MedicoController.php       → CU08: CRUD Personal Médico[cite: 2, 3, 5]
│   │   │   │   ├── HorarioController.php      → CU09: Disponibilidad y grilla de atención[cite: 2, 3, 5]
│   │   │   │   └── BloqueoAgendaController.php → CU10: Licencias y bloqueos de agenda[cite: 2, 3, 5]
│   │   │   ├── Requests/
│   │   │   │   ├── MedicoStoreRequest.php
│   │   │   │   └── HorarioUpdateRequest.php
│   │   │   ├── Resources/
│   │   │   │   ├── MedicoResource.php
│   │   │   │   └── HorarioResource.php
│   │   │   └── Services/
│   │   │       ├── MedicoService.php
│   │   │       └── DisponibilidadService.php → Valida horas disponibles/traslapes[cite: 2, 3, 5]
│   │   │
│   │   ├── ModuloPacientes/                → Módulo 3: CU11–CU12 (Pacientes y Catálogo de Enfermedades)[cite: 2, 3, 5]
│   │   │   ├── Models/
│   │   │   │   ├── Paciente.php
│   │   │   │   ├── ContactoEmergencia.php
│   │   │   │   └── Enfermedad.php
│   │   │   ├── Controllers/
│   │   │   │   ├── PacienteController.php   → CU11: CRUD Pacientes y contactos de emergencia[cite: 2, 3, 5]
│   │   │   │   └── EnfermedadController.php → CU12: Catálogo de enfermedades[cite: 2, 3, 5]
│   │   │   ├── Requests/
│   │   │   │   └── PacienteRequest.php
│   │   │   ├── Resources/
│   │   │   │   └── PacienteResource.php
│   │   │   └── Services/
│   │   │       └── PacienteService.php
│   │   │
│   │   ├── ModuloOperacionCitas/           → Módulo 4: CU13–CU16 (Citas, Modalidad, Reprogramación, Asistencia)[cite: 2, 3, 5]
│   │   │   ├── Models/
│   │   │   │   ├── CitaMedica.php
│   │   │   │   └── ModalidadCita.php
│   │   │   ├── Controllers/
│   │   │   │   ├── CitaController.php       → CU13, CU14: Agendar, Reprogramar y Cancelar citas[cite: 2, 3, 5]
│   │   │   │   ├── AsistenciaController.php → CU15: Control de asistencia (Check-in/Estados)[cite: 2, 3, 5]
│   │   │   │   └── AgendaController.php     → CU16: Consultar agenda médica[cite: 2, 3, 5]
│   │   │   ├── Requests/
│   │   │   │   ├── CitaAgendarRequest.php
│   │   │   │   └── CitaReprogramarRequest.php
│   │   │   ├── Resources/
│   │   │   │   └── CitaResource.php
│   │   │   └── Services/
│   │   │       └── CitaService.php          → Aplica índices de no-solapamiento e integridad[cite: 2, 3, 5]
│   │   │
│   │   ├── ModuloAtencionClinica/          → Módulo 5: CU17–CU19 (Consulta, Recetas e Historial)[cite: 2, 3, 5]
│   │   │   ├── Models/
│   │   │   │   ├── ConsultaMedica.php
│   │   │   │   ├── RecetaMedica.php
│   │   │   │   └── DetalleReceta.php
│   │   │   ├── Controllers/
│   │   │   │   ├── ConsultaController.php  → CU17: Registrar atención médica e IMC[cite: 2, 3, 5]
│   │   │   │   ├── RecetaController.php    → CU18: Emitir receta y detalle de medicamentos[cite: 2, 3, 5]
│   │   │   │   └── HistorialController.php → CU19: Expediente e historial clínico[cite: 2, 3, 5]
│   │   │   ├── Requests/
│   │   │   │   ├── ConsultaStoreRequest.php
│   │   │   │   └── RecetaStoreRequest.php
│   │   │   ├── Resources/
│   │   │   │   ├── ConsultaResource.php
│   │   │   │   └── HistorialResource.php
│   │   │   └── Services/
│   │   │       ├── ConsultaService.php
│   │   │       └── RecetaService.php       → Guarda Composición (Receta + Detalle)[cite: 2, 3, 5]
│   │   │
│   │   └── ModuloInteligenciaReportes/     → Módulo 6: CU20–CU23 (Notificaciones, Reportes PDF, Voz e IA, Dashboard)[cite: 2, 3, 5]
│   │       ├── Models/
│   │       │   └── Notificacion.php
│   │       ├── Controllers/
│   │       │   ├── NotificacionController.php → CU20: Enviar/Listar recordatorios[cite: 2, 3, 5]
│   │       │   ├── ReporteController.php      → CU21: Exportar PDF/CSV tradicionales[cite: 2, 3, 5]
│   │       │   ├── ReporteVozController.php   → CU22: Reportes generativos por voz (IA)[cite: 2, 3, 5]
│   │       │   └── DashboardController.php    → CU23: Indicadores y métricas ejecutivas[cite: 2, 3, 5]
│   │       ├── Services/
│   │       │   ├── ReportePdfService.php      → Generación mediante DomPDF/Snappy
│   │       │   ├── VoiceParserService.php     → Procesa orden de voz a consulta SQL[cite: 2, 3, 5]
│   │       │   └── DashboardService.php
│   │       └── Resources/
│   │           └── DashboardResource.php
│   │
│   ├── Observers/                          → Auditoría Transversal
│   │   └── AuditObserver.php              → Intercepta cambios y registra en `bitacora`[cite: 2, 3, 5]
│   │
│   └── Shared/                             → Base compartida
│       ├── Services/
│       │   └── StorageService.php         → Local Storage / Google Cloud Storage[cite: 1]
│       └── Traits/
│           └── ApiResponseTrait.php       → Respuestas API estandarizadas
│
├── database/
│   ├── migrations/                        → DDL PostgreSQL (Tablas, Enums, Índices)[cite: 2, 3, 5]
│   └── seeders/                           → Inicialización de Roles, Permisos y Admin
│
└── routes/
    ├── api.php                             → Carga y agrupa las rutas de cada módulo
    └── web.php