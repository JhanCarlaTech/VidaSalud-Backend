# CICLO #2 – Funcionalidades Especializadas, Historiales, Notificaciones e Inteligencia Operativa

**Criterio de dependencia:** agrupa los casos de uso que dependen de los datos generados en el Ciclo #1 (la receta y el historial necesitan consultas registradas; los reportes y el tablero necesitan citas, asistencia y pagos), además de la importación masiva y las herramientas de inteligencia artificial.

| ID | Caso de uso | Actor | Prioridad | Módulo |
|----|-------------|-------|-----------|--------|
| CU04 | Cargar Usuarios por Lotes (Excel/CSV) | A | Media | `ModuloSeguridad` |
| CU06 | Consultar Bitácora de Auditoría | A | Alta | `ModuloSeguridad` |
| CU10 | Gestionar Licencias y Bloqueos de Agenda | A, M | Media | `ModuloPersonalMedico` |
| CU12 | Gestionar Catálogo de Enfermedades | A, M | Media | `ModuloPacientes` |
| CU18 | Emitir Receta Médica | M | Alta | `ModuloAtencionClinica` |
| CU19 | Consultar Historial Clínico del Paciente | M, P | Alta | `ModuloAtencionClinica` |
| CU20 | Gestionar Notificaciones y Recordatorios | R, P | Media | `ModuloInteligenciaReportes` |
| CU21 | Generar Reportes Operativos Tradicionales | A, R | Alta | `ModuloInteligenciaReportes` |
| CU22 | Generar Reportes Generativos por Voz (IA) | A, R | Media | `ModuloInteligenciaReportes` |
| CU23 | Visualizar Dashboard Administrativo | A | Media | `ModuloInteligenciaReportes` |

---

## Plan de implementación

### Fase 0 – Preparación
| # | Tarea | Estado |
|---|-------|--------|
| 0.1 | Confirmar el trabajo en curso del Ciclo 1 (precio de consulta, sitio público y reserva en línea) | Pendiente (equipo) |
| 0.2 | Corregir CU17 y crear `ModuloAtencionClinica` | **Hecho** (ver abajo) |
| 0.3 | Migración con los cambios de esquema del Ciclo 2 | En curso: `2026_11_05_000000_agregar_activo_enfermedad` (CU12) y `2026_11_06_000000_agregar_lectura_y_estado_correo_notificacion` (CU20); el resto depende de D2 |
| 0.4 | Permisos nuevos en `PermisosSistemaSeeder` | En curso (`usuarios_importar` con CU04, `bitacora_consultar` con CU06 `enfermedades_gestionar` con CU12, `historial_consultar` con CU19, `notificaciones_gestionar` con CU20 y `dashboard_ver` con CU23) |
| 0.5 | Entorno: extensiones `fileinfo`, `intl`, `gd`, `zip` de PHP (por `fileinfo`, Composer se ejecuta con `--ignore-platform-req=ext-fileinfo`); proceso `schedule:work` para los recordatorios de CU20 (los correos no necesitan `queue:work`); para la IA de CU22, `curl.cainfo` y `openssl.cafile` en `php.ini` apuntando a un paquete de certificados (`cacert.pem`), porque sin él PHP no verifica el HTTPS de la API | Pendiente |

#### Datos de prueba (reportes, tablero e historial)
`php artisan db:seed --class=DatosPruebaSeeder` genera, sin tocar los datos existentes:
- 40 pacientes (12 niños) y 2 médicos de demostración: Odontología, y Medicina General con Pediatría, con su grilla y una semana de vacaciones;
- unas 1.200 citas desde seis meses atrás hasta tres semanas adelante, con los médicos existentes y los nuevos;
- consultas con signos vitales para las atendidas, recetas en el 60 % de ellas y pagos al precio de cada especialidad, con varios métodos, reembolsos y pendientes.

Cumple las reglas del sistema:
- turnos reales de la grilla, sin superposición por médico ni por paciente, y nada dentro de licencias;
- un expediente por paciente;
- Pediatría con niños, Ginecología con mujeres y Cardiología con mayores de 35;
- la ocupación crece hacia el presente.

Todo lo generado se identifica por el correo `@demo.vidasalud.com` (contraseña `Password123!`). Se borra con `php artisan datos-prueba:eliminar`, y volver a ejecutar el seeder lo regenera sin duplicar. Con estos datos se detectó y corrigió en CU21 el total del detalle de inasistencias, que contaba todas las citas cerradas en lugar de solo las inasistencias.

#### 0.2 – Corrección de CU17 (Registrar Consulta Médica)
Registrar una consulta (`POST /api/consultas`) respondía **HTTP 500** porque `ConsultaService` usaba el modelo `ConsultaMedica`, que no existía. Se creó el módulo **`ModuloAtencionClinica`** previsto por la arquitectura y se movieron allí el controlador, la validación y el servicio de la consulta (la ruta no cambia). Correcciones:

| Corrección | Motivo |
|------------|--------|
| Modelos `ConsultaMedica`, `RecetaMedica` y `DetalleReceta` | Faltaba el primero; los otros dos los usa CU18. El IMC es columna generada: nunca se escribe. |
| Comparación del médico como entero | Con `!==` sin conversión, la verificación "la cita es suya" dependía del tipo devuelto por PostgreSQL. |
| Bloqueo de la cita durante el registro (`lockForUpdate`) | Un doble envío simultáneo intentaba registrar dos consultas; el índice único respondía con error 500. |
| Solo citas **del día** | Se podía "atender" una cita futura. |
| **Un expediente por paciente** | Se reutiliza el número de su primera consulta (`EXP-2026-001` de María López se conserva); en la primera consulta se usa `EXP-<código del paciente>`, por ejemplo `EXP-PAC-0003`. Antes se generaba un expediente por cita. |
| La respuesta incluye el IMC y la fecha de atención | Se recarga la consulta tras guardarla, porque los calcula la base de datos. |

Probado por API: consulta nueva con su IMC, reutilización del expediente, cita ajena (422), cita de otro día (422), cita ya atendida (422), datos inválidos (422), recepcionista sin permiso (403) y doble envío simultáneo (solo una consulta registrada).

### Fase 1 – Casos de uso, en orden de dependencia
1. **CU06** Bitácora → 2. **CU12** Enfermedades → 3. **CU10** Licencias → 4. **CU18** Receta → 5. **CU19** Historial → 6. **CU04** Carga por lotes → 7. **CU20** Notificaciones → 8. **CU21** Reportes → 9. **CU23** Dashboard → 10. **CU22** Reportes por voz.

### Fase 2 – Proceso por caso de uso (igual que en el Ciclo 1)
Documentar la sección en este archivo → backend (validación, servicio, controlador, recurso) → pruebas por API, incluidas las excepciones → frontend → lint y build → limpieza de los datos de prueba.

### Decisiones pendientes
| # | Tema | Pregunta |
|---|------|----------|
| D1 | CU17 | ¿El médico solo atiende citas con el paciente **En Sala de Espera**? (Hoy se admiten también Programada y Confirmada, siempre que sean del día). |
| D2 | Esquema | **Resuelta en parte (CU12):** se agregó `activo` a `enfermedad` con una migración aditiva, y `nivel_riesgo` se limita a Bajo, Moderado, Alto y Crítico en la aplicación (sin `CHECK` en la base). En CU20 se agregaron a `notificacion` las columnas `leida`, `fecha_lectura` y `estado_correo`. Sigue pendiente: `consulta_diagnostico` (consulta ↔ enfermedad, CU18/CU19) y las alergias del paciente. |
| D3 | CU04 | **Resuelta:** se importan personal (roles administrativos) y pacientes; la contraseña es opcional y, si falta, el sistema genera una temporal (ver CU04). |
| D4 | CU19 | **Resuelta (CU19):** el médico ve el historial de los pacientes con los que tiene al menos una cita Atendida o pendiente (Programada, Confirmada o En Sala de Espera); el paciente, solo el suyo; Administración y Recepción no ven datos clínicos. La regla está en `AccesoClinicoService` y se cambia en un solo lugar. |
| D5 | CU20 | **Resuelta en el código, pendiente en la configuración:** las notificaciones van siempre a la bandeja de la aplicación y por correo con el mailer de Laravel. Hoy `MAIL_MAILER=log` (el correo queda en `storage/logs/laravel.log`); para enviar correos reales basta configurar `MAIL_MAILER=smtp` y los datos del servidor en `.env`, sin cambiar código. |
| D6 | CU22 | **Resuelta en el código, pendiente la clave:** el proveedor es Anthropic (Claude, con el SDK oficial `anthropic-ai/sdk`), activo solo si `ANTHROPIC_API_KEY` está en `.env`; sin clave, o si la IA falla, responde un intérprete por reglas, así que la funcionalidad no depende de la IA. |

---

## CU06 – Consultar Bitácora de Auditoría  
**Actor:** Administrador (A)  
**Prioridad:** Alta  
**Módulo:** Módulo 1 – Seguridad, Usuarios y Configuración del Sistema (`ModuloSeguridad`)  
**Permiso requerido:** `bitacora_consultar` (nuevo; Administrador)

### Descripción funcional
Permite al Administrador **revisar todo lo que ocurre en el sistema**: quién hizo cada operación, sobre qué módulo, cuándo, desde qué IP y qué datos cambiaron. Los eventos los registran los demás casos de uso (`INSERT`, `UPDATE`, `DELETE`, `LOGIN`, `LOGIN_FALLIDO`, `REPROGRAMACION`, `ASISTENCIA`, `IMPORTACION`, …) a través de `BitacoraService::registrar()`; este caso de uso **solo los consulta**. Incluye filtros, el detalle de cada evento con la comparación "antes / después" y la exportación a CSV.

**Reglas de negocio:**
- **RN01 – Solo lectura:** la bitácora no se modifica ni se elimina. No hay rutas para hacerlo y el modelo `Bitacora` rechaza cualquier `update` o `delete` (solo admite inserciones).
- **RN02 – Autor del evento:** cada evento muestra al usuario que realizó la acción. Un inicio de sesión fallido con un correo que no existe no tiene usuario: se muestra "Sin usuario" con el correo que se intentó usar.
- **RN03 – Filtros combinables:** usuario (nombre, CI o correo, incluido el correo de un intento fallido), acción, módulo (tabla afectada), IP (por su inicio: `192.168.` encuentra toda la red) y rango de fechas por días completos en la hora de Bolivia. Los resultados se ordenan del más reciente al más antiguo, de 20 en 20.
- **RN04 – Lectura comprensible:** las acciones y tablas se muestran con nombres legibles ("Inicio de sesión fallido", "Horarios de atención"); los filtros solo ofrecen las acciones y módulos que existen en la bitácora. El detalle compara los datos anteriores y nuevos **campo por campo** y marca los que cambiaron.
- **RN05 – Datos sensibles ocultos:** los valores de claves como `contrasena`, `password` o `token` se muestran como `[oculto]` en el detalle y en la exportación, aunque un caso de uso los hubiera registrado.
- **RN06 – Exportación:** se exportan a CSV (separador `;`, compatible con Excel) los eventos que cumplen los filtros, **hasta 5.000 por archivo**; si hay más, se pide acotar los filtros.
- **RN07 – Auditoría de la auditoría:** cada exportación se registra como evento `EXPORTACION` con los filtros usados y la cantidad de eventos. Consultar el listado no genera eventos, para no llenar la bitácora con sus propias lecturas.

### Flujo principal (mínimo viable)
1. El Administrador abre **Bitácora** en el menú de Acceso y seguridad.
2. El sistema muestra los eventos más recientes (fecha, usuario, acción, módulo e IP) y el total.
3. Filtra por usuario, acción, módulo, IP o fechas (con atajos: hoy, últimos 7 y 30 días); el listado se actualiza al instante (RN03).
4. Pulsa **Ver** en un evento: el sistema muestra el detalle y la tabla de campos con sus valores antes y después (RN04–RN05).
5. Opcionalmente pulsa **Exportar CSV** y descarga los eventos filtrados (RN06–RN07).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Filtros inválidos: fecha mal escrita, fecha final anterior a la inicial, acción, módulo o IP con caracteres no permitidos | **422** con el error del campo (la pantalla valida el rango antes de consultar) |
| E2 | Exportar sin eventos o con más de 5.000 | **422** en `exportacion` con el motivo; la pantalla lo avisa sin llamar al servidor |
| E3 | Usuario sin el permiso `bitacora_consultar` | **403** "No tiene permisos para esta acción" |
| E4 | Evento inexistente | **404** |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`) y el permiso `bitacora_consultar`. Solo hay rutas `GET`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/bitacora?buscar=&accion=&tabla=&ip=&desde=&hasta=&page=&por_pagina=` | Listado paginado y filtrado |
| GET | `/api/bitacora/catalogo` | Acciones y módulos registrados (para los filtros) y el máximo de exportación |
| GET | `/api/bitacora/{id}` | Detalle: datos anteriores y nuevos (sin valores sensibles) y cambios campo por campo |
| GET | `/api/bitacora/exportar?<mismos filtros>` | Descarga CSV de los eventos filtrados |

### Componentes de implementación
- **Backend (`app/Modules/ModuloSeguridad`):** `Controllers/BitacoraController.php`, `Requests/BitacoraFiltroRequest.php` (validación y normalización de filtros; fechas en la zona horaria de la clínica), `Repositories/BitacoraRepository.php` (consultas parametrizadas, lectura por bloques para exportar), `Services/BitacoraConsultaService.php` (etiquetas, ocultamiento de datos sensibles, comparación y exportación), `Resources/BitacoraResource.php`; `Models/Bitacora.php` ahora rechaza modificaciones y eliminaciones. `BitacoraService` (registro de eventos) no cambia.
- **Frontend (`src/modules/ModuloSeguridad`):** `services/bitacora.service.js`, `pages/BitacoraPage/` (`BitacoraPage.js` + `BitacoraPage.view.jsx` + `components/DetalleEventoModal.jsx`). Ruta: `/admin/bitacora` (permiso `bitacora_consultar`), en el menú **Acceso y seguridad**.

**Estado: implementado.** Probado por API y a través del proxy de Vite: listado, cada filtro por separado (acción, módulo, usuario por nombre y CI, correo de un intento fallido sin usuario, IP por prefijo, día exacto comparado con la hora de Bolivia, comodines `%` tratados como texto), E1 (rango invertido, acción con caracteres no permitidos, fecha mal escrita), detalle de alta, modificación (roles y teléfono marcados como cambiados) y cambio de contraseña (`[oculto]`), exportación CSV (BOM, `;`, sin contraseñas) con su evento `EXPORTACION`, E2 sin resultados, E3 (recepcionista → 403), E4 (404) y rechazo de `update`/`delete` en el modelo.

### Funcionalidades extras
- **Inmutabilidad en la base de datos:** un *trigger* que impida `UPDATE` y `DELETE` sobre `bitacora` también para accesos fuera de la aplicación (requiere cambiar `script.sql`, ver D2).
- **Índices** en `fecha_registro`, `id_usuario` y `accion` cuando la bitácora crezca.
- Historial de un registro concreto (por ejemplo, todos los cambios de un paciente) y acceso directo "Ver actividad" desde la página de usuarios.
- Política de retención: archivar los eventos de más de N años.
- Alertas ante varios inicios de sesión fallidos seguidos desde la misma IP (se apoya en CU20).

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `bitacora` | Lectura, inserción | Consulta de eventos; evento `EXPORTACION` (RN07) |
| `usuario` | Lectura | Autor de cada evento y búsqueda por usuario |

### Dependencias y conexiones
- **Ciclo 1:** muestra los eventos que registran CU01, CU02, CU03, CU05, CU07–CU09, CU11, CU13–CU15, CU17 y CU24, y CU04 en este ciclo. Cuando un caso de uso registre una acción o tabla nueva, aparece sola en los filtros; para que tenga un nombre legible se agrega a `ETIQUETAS_ACCION` o `ETIQUETAS_TABLA` de `BitacoraConsultaService`.  
- **CU19:** los accesos al historial clínico se registrarán en la bitácora y se podrán revisar aquí.  
- **CU23:** el tablero puede reutilizar `BitacoraRepository` para mostrar los inicios de sesión fallidos recientes.

---

## CU10 – Gestionar Licencias y Bloqueos de Agenda  
**Actor:** Administrador (A), Médico (M)  
**Prioridad:** Media  
**Módulo:** Módulo 2 – Gestión de Personal Médico y Especialidades (`ModuloPersonalMedico`)  
**Permiso requerido:** `medicos_gestionar` para gestionar las licencias de cualquier médico; un Médico gestiona las suyas sin permiso adicional (igual que CU09, RN05)

### Descripción funcional
Permite registrar los periodos en que un médico **no atiende** (vacaciones, bajas por enfermedad, capacitaciones, congresos o trámites) sobre la tabla `indisponibilidad_medico`. Mientras dura una licencia, `DisponibilidadService` (CU09) marca sus turnos como **bloqueados** y CU13/CU14 no permiten reservarlos ni reprogramar citas hacia ellos. Si la licencia cubre citas ya agendadas, el sistema **las lista antes y después de guardar** para que se reprogramen con CU14.

**Reglas de negocio:**
- **RN01 – Periodo válido:** el fin es posterior al inicio y está en el futuro; una licencia nueva no empieza en un día pasado (sí puede empezar hoy aunque la hora ya pasó, por ejemplo una baja avisada a media mañana). Dura como máximo **180 días** y empieza dentro del **próximo año**. Las fechas se manejan en la hora de la clínica con el formato `AAAA-MM-DD HH:mm`; una licencia de **días completos** va de las 00:00 del primer día a las 23:59 del último.
- **RN02 – Acceso:** con `medicos_gestionar` (Administrador) se gestionan las licencias de cualquier médico; un Médico solo ve y gestiona las suyas, aunque pida las de otro (403). Otros roles reciben 403.
- **RN03 – Sin superposición:** las licencias de un mismo médico no se superponen (sí pueden ser contiguas). El registro bloquea la fila del médico para que dos altas simultáneas no se crucen.
- **RN04 – Historial:** una licencia **finalizada** no se modifica ni elimina. Una **en curso** conserva su inicio: solo se adelanta o extiende su fin (por ejemplo, si el médico vuelve antes) y no se elimina. Solo las **próximas** se eliminan.
- **RN05 – Citas afectadas:** se pueden registrar licencias que cubren citas pendientes (Programada, Confirmada o En Sala de Espera), porque una baja no puede esperar a que se reprogramen. El formulario muestra esas citas mientras se completa; al guardar, la respuesta las incluye y el listado indica cuántas siguen pendientes en cada licencia, con fecha, paciente y teléfono para avisarle.
- **RN06 – Listado:** por periodo (en curso y próximas por defecto, en curso, próximas, finalizadas o todas) y, para el Administrador, por médico.
- **RN07 – Auditoría:** el alta, la modificación y la eliminación se registran en `bitacora` (`indisponibilidad_medico`), con la cantidad de citas afectadas.
- **RN08 – Médico activo:** no se registran licencias de médicos inactivos.

### Flujo principal (mínimo viable)
1. El Administrador abre **Licencias** (o el botón **Licencias** de un médico en CU08); el Médico abre **Mis licencias**.
2. El sistema muestra las licencias en curso y próximas, con su estado y las citas pendientes que contienen.
3. Pulsa **Registrar licencia**, elige el médico (solo el Administrador), indica días completos o un rango con horas y el motivo. Mientras completa el periodo, el sistema muestra las citas pendientes que quedarán dentro (RN05).
4. Guarda: el sistema valida RN01, RN03 y RN08, registra la licencia, bloquea esos turnos y, si hay citas afectadas, las muestra en un diálogo con el acceso a **Citas** para reprogramarlas.
5. Opcionalmente edita o elimina una licencia según su estado (RN04).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Datos inválidos: fechas mal escritas, fin anterior al inicio, sin motivo, sin médico (Administrador), médico inexistente o inactivo | **422** con el error del campo |
| E2 | Periodo fuera de RN01 (día pasado, fin pasado, más de 180 días, a más de un año) o superpuesto con otra licencia (RN03) | **422** con el motivo, por ejemplo "Se superpone con la licencia del 27/11/2026 08:00 al 27/11/2026 10:00 (Control médico)." |
| E3 | Modificar o eliminar una licencia finalizada, cambiar el inicio de una en curso o eliminarla (RN04) | **422** en `licencia` o `fecha_inicio` |
| E4 | Médico que pide o modifica licencias de otro médico, o usuario sin rol de médico ni el permiso | **403** "No tiene permisos para esta acción" |
| E5 | Licencia inexistente | **404** |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`); la autorización por médico la hace `LicenciaController` (RN02).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/licencias?medico=&periodo=&page=` | Listado (el Médico, solo las suyas) con el número de citas afectadas |
| GET | `/api/licencias/medicos` | Médicos para los selectores (solo Administrador) |
| GET | `/api/licencias/citas-afectadas?id_medico=&fecha_inicio=&fecha_fin=` | Vista previa de citas pendientes en un periodo |
| POST | `/api/licencias` | Registra una licencia; responde con la licencia y sus citas afectadas |
| GET | `/api/licencias/{id}/citas` | Citas pendientes que siguen dentro de la licencia |
| PUT | `/api/licencias/{id}` | Modifica periodo y motivo (RN04) |
| DELETE | `/api/licencias/{id}` | Elimina una licencia próxima |

### Componentes de implementación
- **Backend (`app/Modules/ModuloPersonalMedico`):** `Controllers/LicenciaController.php` (autorización RN02), `Requests/LicenciaRequest.php`, `Services/LicenciaService.php` (RN01, RN03, RN04, RN07, RN08), `Repositories/LicenciaRepository.php` (listado con conteo de citas, superposición, citas afectadas y bloqueo del médico), `Resources/LicenciaResource.php`; `Models/IndisponibilidadMedico.php` con la relación al médico y las fechas guardadas con su desfase horario. Las citas (fecha y horas locales) se comparan con la licencia (`timestamptz`) en SQL con `AT TIME ZONE` y la zona de la clínica. La bitácora (CU06) muestra la tabla como "Licencias médicas".
- **Frontend (`src/modules/ModuloPersonalMedico`):** `services/licencias.service.js`, `utils/licencias.js` (periodo legible y estados), `pages/LicenciasPage/` (`LicenciasPage.js` + `LicenciasPage.view.jsx` + `components/LicenciaFormModal.jsx`, `CitasAfectadas.jsx`, `CitasLicenciaModal.jsx`). Rutas: `/admin/licencias` (permiso `medicos_gestionar`, admite `?medico=ID`) y `/medico/licencias` (rol Médico). `formatearDia()` en `shared/utils/formatters.js` formatea fechas sin hora sin el desfase de UTC.

**Estado: implementado.** Probado por API y a través del proxy de Vite: vista previa con la cita CM-0002, alta con 1 cita afectada, turnos bloqueados en la disponibilidad de CU09 (la cita sigue "ocupada"), licencia contigua, superpuesta (422), E1/E2 (día pasado, fin anterior, más de 180 días, a más de un año, sin motivo, formato, sin médico, médico inexistente o que no es médico, periodo inválido), Médico (solo ve las suyas aunque pida otro médico, 403 al crear o modificar las de otro, alta, edición y baja de las propias), recepcionista (403), licencia en curso (no cambia el inicio ni se elimina, sí se adelanta su fin; no admite fin en el pasado), finalizada (no se modifica ni elimina), día completo (bloquea los 8 turnos), filtros por periodo, 404 y bitácora.

### Funcionalidades extras
- **Aviso automático a los pacientes** de las citas afectadas por correo o en la bandeja de la aplicación (CU20).
- Reprogramación asistida: proponer el siguiente turno libre del mismo médico o de otro de la misma especialidad para cada cita afectada.
- Licencias recurrentes (por ejemplo, todos los viernes por la tarde durante un mes).
- Aprobación: el Médico solicita la licencia y el Administrador la aprueba.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `indisponibilidad_medico` | Lectura, inserción, modificación, eliminación | Licencias y bloqueos |
| `medico` / `usuario` | Lectura (bloqueo de fila) | Médico activo, nombres y RN03 |
| `cita_medica` / `paciente` | Lectura | Citas afectadas y datos de contacto del paciente (RN05) |
| `bitacora` | Inserción | Auditoría (RN07) |

### Dependencias y conexiones
- **Ciclo 1:** CU09 (`DisponibilidadService`) ya marcaba como bloqueados los turnos dentro de una licencia, y CU13/CU14 rechazan esos turnos ("El médico no atiende en ese horario por una licencia"); CU10 es quien crea esas licencias. Las citas afectadas se reprograman o cancelan con CU14.  
- **CU06:** los cambios de licencias aparecen en la bitácora como "Licencias médicas".  
- **CU20:** podrá avisar a los pacientes de las citas afectadas.

---

## CU12 – Gestionar Catálogo de Enfermedades  
**Actor:** Administrador (A), Médico (M)  
**Prioridad:** Media  
**Módulo:** Módulo 3 – Gestión de Pacientes e Información Médica Base (`ModuloPacientes`)  
**Permiso requerido:** `enfermedades_gestionar` (nuevo; Administrador y Médico)

### Descripción funcional
Mantiene el **catálogo clínico de enfermedades** con su nivel de riesgo y permite registrar los **antecedentes patológicos** de cada paciente (`paciente_enfermedad`): qué enfermedad tiene, desde cuándo y con qué observaciones. Es la base del diagnóstico de la consulta (CU17/CU18) y del historial clínico (CU19).

**Reglas de negocio:**
- **RN01 – Nombre único:** el nombre de la enfermedad es obligatorio (3 a 150 caracteres: letras, números, espacios y `- ( ) , . ' /`, para nombres como "Diabetes Mellitus Tipo 2" o "COVID-19") y no se repite sin distinguir mayúsculas ni espacios.
- **RN02 – Nivel de riesgo:** obligatorio, uno de **Bajo, Moderado, Alto o Crítico**.
- **RN03 – Sin borrado físico:** las enfermedades se activan o desactivan. Una enfermedad inactiva no se ofrece ni se acepta en antecedentes nuevos, pero los pacientes que ya la tienen la conservan (se muestra "Fuera del catálogo") y su antecedente sigue siendo editable.
- **RN04 – Antecedentes:** cada enfermedad figura **una sola vez** por paciente (clave primaria de `paciente_enfermedad`; el registro bloquea la fila del paciente). La fecha de diagnóstico es opcional, no puede ser futura ni anterior al nacimiento del paciente. Al editar no se cambia la enfermedad (se elimina y se registra otra). Un antecedente cargado por error se puede eliminar.
- **RN05 – Catálogo:** se lista completo, ordenado por nombre, con búsqueda por nombre o descripción y filtros por nivel de riesgo y estado; indica cuántos pacientes tienen cada enfermedad.
- **RN06 – Búsqueda de pacientes:** para registrar antecedentes se busca al paciente por CI, código o nombre (mínimo 2 caracteres, hasta 10 resultados). Solo se devuelven datos de identificación (código, CI, nombre, fecha de nacimiento, edad y estado), no la ficha completa ni el contacto. Cualquier médico puede consultar a cualquier paciente; si D4 restringe el acceso al historial (CU19), esta búsqueda se ajustará igual.
- **RN07 – Auditoría:** las altas, modificaciones y cambios de estado del catálogo (`enfermedad`) y de los antecedentes (`paciente_enfermedad`) se registran en `bitacora`.

### Flujo principal (mínimo viable)
1. **Catálogo:** el Administrador o el Médico abre **Enfermedades**, busca o filtra, y registra o edita una enfermedad con su nivel de riesgo (RN01–RN02). Para retirarla, la desactiva (RN03).
2. **Antecedentes:** abre **Antecedentes** (o el botón **Antecedentes** de un paciente en CU11, si tiene el permiso), busca al paciente (RN06) y ve sus antecedentes del más reciente al más antiguo.
3. Pulsa **Registrar antecedente**, elige una enfermedad activa que el paciente aún no tenga, y opcionalmente la fecha de diagnóstico y observaciones (RN04).
4. Puede editar la fecha y las observaciones, o eliminar un antecedente registrado por error.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Datos inválidos: nombre vacío, corto o con caracteres no permitidos, nivel de riesgo ausente o fuera de la lista, fecha mal escrita o futura, búsqueda de menos de 2 caracteres | **422** con el error del campo |
| E2 | Nombre de enfermedad repetido (RN01) | **422** "Ya existe una enfermedad con ese nombre." |
| E3 | Antecedente con una enfermedad inexistente o inactiva, ya registrada en el paciente, con fecha anterior al nacimiento o intentando cambiar la enfermedad al editar | **422** con el motivo |
| E4 | Usuario sin el permiso `enfermedades_gestionar` (Recepcionista, Paciente) | **403** "No tiene permisos para esta acción" |
| E5 | Enfermedad, paciente o antecedente inexistente | **404** |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`) y el permiso `enfermedades_gestionar`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/enfermedades?buscar=&nivel_riesgo=&activo=` | Catálogo con el número de pacientes de cada enfermedad |
| POST | `/api/enfermedades` | Registra una enfermedad |
| PUT | `/api/enfermedades/{id}` | Modifica nombre, descripción y nivel de riesgo |
| PATCH | `/api/enfermedades/{id}/estado` | Activa o desactiva (RN03) |
| GET | `/api/antecedentes/pacientes?buscar=` | Búsqueda de pacientes (RN06) |
| GET | `/api/pacientes/{id}/antecedentes` | Identificación del paciente y sus antecedentes |
| POST | `/api/pacientes/{id}/antecedentes` | Registra un antecedente |
| PUT | `/api/pacientes/{id}/antecedentes/{idEnfermedad}` | Modifica fecha de diagnóstico y observaciones |
| DELETE | `/api/pacientes/{id}/antecedentes/{idEnfermedad}` | Elimina un antecedente registrado por error |

### Componentes de implementación
- **Backend (`app/Modules/ModuloPacientes`):** `Models/Enfermedad.php` (niveles de riesgo) y la relación `Paciente::enfermedades()` (pivote con fecha y observaciones, porque `paciente_enfermedad` tiene clave compuesta); `Controllers/EnfermedadController.php` y `AntecedenteController.php`; `Requests/EnfermedadRequest.php` (reutiliza `UnicoSinMayusculas` de CU03/CU07) y `AntecedenteRequest.php`; `Services/EnfermedadService.php` y `AntecedenteService.php`; `Resources/EnfermedadResource.php` y `AntecedenteResource.php`. Migración `2026_11_05_000000_agregar_activo_enfermedad.php` (D2). La bitácora (CU06) muestra las tablas como "Catálogo de enfermedades" y "Antecedentes de pacientes".
- **Frontend (`src/modules/ModuloPacientes`):** `services/enfermedades.service.js`, `utils/enfermedades.js` (niveles y su estilo), `pages/EnfermedadesPage/` (`EnfermedadesPage.js` + `.view.jsx` + `components/EnfermedadFormModal.jsx` y `CambiarEstadoEnfermedadModal.jsx`) y `pages/AntecedentesPage/` (`AntecedentesPage.js` + `.view.jsx` + `components/BuscadorPaciente.jsx` y `AntecedenteFormModal.jsx`). Rutas `/enfermedades` y `/antecedentes?paciente=ID` (permiso `enfermedades_gestionar`), en el menú **Pacientes** del Administrador y del Médico, y botón **Antecedentes** en la página de pacientes (solo con el permiso).

**Estado: implementado.** Probado por API y a través del proxy de Vite: catálogo (listado con conteo de pacientes, filtro por nivel, búsqueda con tilde, alta con espacios normalizados y paréntesis, "COVID-19", nombre repetido con otra mayúscula, nivel inválido o ausente, caracteres no permitidos, nombre vacío, edición conservando el propio nombre, desactivación, filtro de inactivas, 404); antecedentes como Médico (búsqueda por nombre y por código, búsqueda de 1 carácter, enfermedad inactiva, inexistente o ya registrada, fecha futura o anterior al nacimiento, alta con observaciones recortadas, intento de cambiar la enfermedad, edición, antecedente que el paciente no tiene, orden por fecha, enfermedad desactivada que sigue en el antecedente y se puede editar, eliminación y segunda eliminación, paciente inexistente); Recepcionista y Paciente (403); bitácora.

### Funcionalidades extras
- **Código CIE-10** de cada enfermedad, para estandarizar diagnósticos y reportes (requiere una columna nueva).
- **Alergias** del paciente como sección propia (pendiente en D2).
- Mostrar al paciente sus antecedentes (solo lectura) en **Mi ficha médica** (CU11).
- Alertar al médico, al abrir la consulta, de los antecedentes de riesgo Alto o Crítico del paciente.
- Restricción `CHECK` de los niveles de riesgo en la base de datos, cuando se apruebe el cambio de esquema.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `enfermedad` | Lectura, inserción, modificación | Catálogo; columna nueva `activo` |
| `paciente_enfermedad` | Lectura, inserción, modificación, eliminación | Antecedentes del paciente |
| `paciente` / `usuario` | Lectura (bloqueo de fila del paciente) | Búsqueda, identificación y RN04 |
| `bitacora` | Inserción | Auditoría (RN07) |

### Dependencias y conexiones
- **Ciclo 1:** amplía CU11 (ficha del paciente) con sus antecedentes; reutiliza el patrón de catálogo de CU07 y la validación `UnicoSinMayusculas`.  
- **CU18/CU19:** el diagnóstico de la consulta y el historial clínico usarán este catálogo; la tabla `consulta_diagnostico` sigue pendiente en D2.  
- **CU06:** los cambios aparecen en la bitácora.

---

## CU18 – Emitir Receta Médica  
**Actor:** Médico (M)  
**Prioridad:** Alta  
**Módulo:** Módulo 5 – Atención Clínica, Expediente e Historial (`ModuloAtencionClinica`)  
**Permiso requerido:** `citas_atender` para emitir (además, la consulta debe ser del médico); ver o imprimir una receta solo exige sesión y ser su médico o su paciente

### Descripción funcional
Permite al Médico **emitir recetas** para las consultas que atendió (CU17): una o varias líneas de medicamentos, con su dosis, frecuencia y duración, más indicaciones generales. La receta queda registrada en `receta_medica` y `detalle_receta`, y se puede **ver e imprimir** con formato de documento (encabezado de la clínica, paciente, médico, "Rp." y firma), o guardar como PDF desde el navegador.

**Reglas de negocio:**
- **RN01 – Médico de la consulta:** solo el médico que atendió la consulta emite sus recetas y ve sus consultas. Las de otro médico responden 403.
- **RN02 – Medicamentos:** de 1 a **15** por receta. Cada línea lleva medicamento (máx. 150), dosis (máx. 100), frecuencia (máx. 100) y duración de 1 a **365 días**. Un medicamento no se repite en la misma receta (sin distinguir mayúsculas). Las líneas totalmente vacías del formulario se descartan; las indicaciones generales son opcionales (máx. 2000).
- **RN03 – Plazo:** las recetas se emiten hasta **7 días** después de la atención; pasado ese plazo la consulta aparece como "Plazo vencido".
- **RN04 – Todo o nada:** la receta y sus líneas se guardan en una transacción, con la consulta bloqueada durante la emisión.
- **RN05 – Acceso a la receta:** la ven el médico que la emitió, el propio paciente y, desde CU19, los médicos que atienden al paciente (regla D4); el resto de médicos, recepción y administración reciben 403.
- **RN06 – Documento inalterable:** una receta emitida no se modifica ni se elimina. Si hay un error, se emite otra (una consulta admite varias recetas).
- **RN07 – Auditoría:** cada emisión se registra en `bitacora` (`receta_medica`) con el expediente y los medicamentos.

### Flujo principal (mínimo viable)
1. El Médico abre **Atención clínica → Recetas**: el sistema lista sus consultas atendidas (de la más reciente a la más antigua) con el número de recetas de cada una. Puede buscar por paciente, CI, código o expediente.
2. Pulsa **Emitir receta** en una consulta dentro del plazo (RN03).
3. Agrega los medicamentos (con sugerencias de frecuencia) y las indicaciones generales, y confirma.
4. El sistema valida (RN01–RN02), guarda la receta (RN04, RN07) y ofrece **Imprimir receta**.
5. La vista imprimible muestra el documento y el botón **Imprimir / Guardar como PDF**. Las recetas anteriores de la consulta se ven con **Ver recetas**.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Sin medicamentos, línea incompleta, duración fuera de 1–365, medicamento repetido, más de 15 líneas o textos demasiado largos | **422** con el error de cada línea (`medicamentos.N.campo`) |
| E2 | Consulta atendida hace más de 7 días (RN03) | **422** en `consulta` |
| E3 | Consulta de otro médico, receta ajena, o usuario sin `citas_atender` al emitir | **403** "No tiene permisos para esta acción" |
| E4 | Consulta o receta inexistente | **404** |

### Endpoints (API REST)
| Método | Ruta | Acceso | Descripción |
|--------|------|--------|-------------|
| GET | `/api/recetas/consultas?buscar=&page=` | `citas_atender` | Consultas del médico autenticado con su número de recetas y si aún se puede emitir |
| GET | `/api/consultas/{id}/recetas` | `citas_atender` + médico de la consulta | Recetas emitidas en la consulta |
| POST | `/api/consultas/{id}/recetas` | `citas_atender` + médico de la consulta | Emite una receta |
| GET | `/api/recetas/{id}` | Sesión + médico emisor o paciente | Receta completa para ver o imprimir |

### Componentes de implementación
- **Backend (`app/Modules/ModuloAtencionClinica`):** `Controllers/RecetaController.php`, `Requests/RecetaRequest.php` (normaliza las líneas y detecta medicamentos repetidos), `Services/RecetaService.php` (RN01, RN03–RN07), `Resources/RecetaResource.php` (número `REC-000001`, paciente, médico con especialidades y datos de la clínica); los modelos `RecetaMedica` y `DetalleReceta` creados en la Fase 0. Datos de la clínica en `config/clinica.php` (`CLINICA_NOMBRE`, `CLINICA_DIRECCION`, `CLINICA_TELEFONO` en `.env`, con valores por defecto). La bitácora (CU06) muestra la tabla como "Recetas médicas".
- **Frontend (nuevo `src/modules/ModuloAtencionClinica`):** `services/recetas.service.js`, `utils/recetas.js`, `components/RecetaDocumento.jsx` (documento imprimible, reutilizable por CU19), `pages/RecetasPage/` (`RecetasPage.js` + `.view.jsx` + `components/EmitirRecetaModal.jsx` y `RecetasConsultaModal.jsx`) y `pages/RecetaImprimiblePage/`. Rutas: `/medico/recetas` (rol Médico, menú **Atención clínica**) y `/recetas/:id/imprimir` (fuera del diseño principal para imprimir solo el documento).
- **PDF:** se genera con el diálogo de impresión del navegador ("Guardar como PDF") y estilos de impresión, sin dependencias nuevas. Generarlo en el servidor requeriría instalar una librería como `dompdf` (ver extras).

**Estado: implementado.** Probado por API y a través del proxy de Vite: listado de consultas del médico (plazo vigente y vencido, búsqueda por nombre y código), emisión con textos normalizados y una línea vacía descartada, número `REC-000002`, datos de paciente, médico y clínica; E1 (sin medicamentos, solo líneas vacías, línea incompleta, duración 0 y 366, medicamento repetido con otra mayúscula, 16 líneas), E2 (consulta del 23/10), E3 (consulta de otra médica al emitir y al listar recetas, otra médica al ver la receta, recepcionista, paciente al emitir), acceso del paciente dueño a su receta, E4 y bitácora. Las consultas de prueba se crearon directamente en la base porque ningún médico tenía turnos libres ese día.

### Funcionalidades extras
- **PDF generado en el servidor** (por ejemplo, con `barryvdh/laravel-dompdf`), para adjuntarlo a un correo (CU20) o descargarlo sin pasar por el diálogo de impresión.
- **Vademécum:** catálogo de medicamentos con presentaciones y dosis habituales para autocompletar.
- Código QR en la receta para que la farmacia verifique su autenticidad.
- Anulación de una receta con motivo (requiere una columna de estado).
- Emitir la receta desde el mismo formulario de la consulta (CU17).

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `consulta_medica` / `cita_medica` | Lectura (bloqueo de la consulta) | Médico de la consulta, plazo y datos del expediente |
| `receta_medica` | Lectura, inserción | Receta emitida |
| `detalle_receta` | Lectura, inserción | Medicamentos de la receta |
| `paciente` / `medico` / `usuario` / `especialidad` | Lectura | Datos del documento |
| `bitacora` | Inserción | Auditoría (RN07) |

### Dependencias y conexiones
- **CU17:** las recetas se emiten sobre las consultas registradas allí (la ruta `POST /api/consultas` no cambia).  
- **CU19:** el historial del paciente mostrará sus recetas con `RecetaDocumento` y `GET /api/recetas/{id}`, que ya permite el acceso del paciente.  
- **CU06:** las emisiones aparecen en la bitácora.

---

## CU19 – Consultar Historial Clínico del Paciente  
**Actor:** Médico (M), Paciente (P)  
**Prioridad:** Alta  
**Módulo:** Módulo 5 – Atención Clínica, Expediente e Historial (`ModuloAtencionClinica`)  
**Permiso requerido:** `historial_consultar` (nuevo; Médico) para el historial de sus pacientes; el Paciente consulta el suyo solo con sesión

### Descripción funcional
Reúne en una sola pantalla el **expediente del paciente**: identificación y ficha (edad, sexo, tipo de sangre), un resumen (expediente, número de consultas y recetas, última atención), sus **antecedentes** (CU12) y la **línea de tiempo de consultas** (CU17) con signos vitales, IMC, motivo, evolución, síntomas, tratamiento y las **recetas** emitidas (CU18), que se pueden abrir e imprimir. El Médico lo consulta para los pacientes que atiende; el Paciente, para sí mismo.

**Reglas de negocio:**
- **RN01 – Acceso (decisión D4):** un Médico accede al historial de un paciente solo si tiene con él al menos una cita **Atendida o pendiente** (Programada, Confirmada o En Sala de Espera); una cita cancelada no da acceso. El Paciente ve solo el suyo. Administración y Recepción no acceden a datos clínicos. La regla vive en `AccesoClinicoService` y se aplica también a las recetas (CU18, RN05).
- **RN02 – Contenido:** la ficha muestra solo datos clínicos y de identificación (sin contacto de emergencia ni datos de la cuenta). Cada consulta indica qué médico la atendió y si fue el mismo que consulta ("Atendida por usted").
- **RN03 – Orden y paginación:** las consultas se muestran de la más reciente a la más antigua, de 5 en 5; los antecedentes, del diagnóstico más reciente al más antiguo.
- **RN04 – Registro de accesos:** al tratarse de datos de salud, cada apertura del historial (primera página) se registra en `bitacora` como `ACCESO_HISTORIAL`, con el paciente y si accedió el médico o el propio paciente. Pasar de página no genera eventos nuevos.
- **RN05 – Solo lectura:** el historial no modifica datos; las consultas se registran en CU17, las recetas en CU18 y los antecedentes en CU12.

### Flujo principal (mínimo viable)
1. El Médico abre **Atención clínica → Mis pacientes**: el sistema lista los pacientes que atiende (RN01), primero los de próxima cita más cercana, con su última atención. Puede buscar por nombre, CI o código.
2. Pulsa **Ver historial**: el sistema registra el acceso (RN04) y muestra ficha, resumen, antecedentes y consultas (RN02–RN03).
3. En una consulta, abre **Ver e imprimir** en una receta (CU18).
4. El Paciente abre **Mi historial** y ve la misma información de sí mismo.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Médico sin relación de atención con el paciente (nunca lo atendió, o solo tuvo citas canceladas) | **403** "No tiene permisos para esta acción" |
| E2 | Usuario sin `historial_consultar` (Recepcionista, Administrador, Paciente con el historial de otro) o que no es paciente en "Mi historial" | **403** |
| E3 | Paciente inexistente | **404** |
| E4 | Paciente sin consultas | **200** con el historial vacío ("Aún no hay consultas registradas") |

### Endpoints (API REST)
| Método | Ruta | Acceso | Descripción |
|--------|------|--------|-------------|
| GET | `/api/historial/pacientes?buscar=&page=` | `historial_consultar` | Pacientes que atiende el médico, con próxima cita y última atención |
| GET | `/api/pacientes/{id}/historial?page=` | `historial_consultar` + RN01 | Historial del paciente |
| GET | `/api/mi-historial?page=` | Sesión de paciente | Historial propio |

### Componentes de implementación
- **Backend (`app/Modules/ModuloAtencionClinica`):** `Services/AccesoClinicoService.php` (regla D4, reutilizada por `RecetaService`), `Services/HistorialService.php` (pacientes del médico, historial y registro de accesos; reutiliza `AntecedenteService` de CU12), `Controllers/HistorialController.php`, `Resources/HistorialConsultaResource.php` (y `AntecedenteResource` de CU12). `RecetaResource::numero()` comparte el formato `REC-000001`. La bitácora (CU06) muestra la acción como "Consulta de historial clínico".
- **Frontend (`src/modules/ModuloAtencionClinica`):** `services/historial.service.js`, `components/HistorialClinico.jsx` y `components/ConsultaHistorial.jsx` (compartidos por médico y paciente), `pages/MisPacientesPage/` y `pages/HistorialPage/` (`HistorialPage.js` + `.view.jsx`, con la opción `propio`). Rutas: `/medico/historial` y `/medico/historial/:id` (permiso `historial_consultar`, menú **Atención clínica → Mis pacientes**) y `/paciente/historial` (rol Paciente, menú **Mi historial**).

**Estado: implementado.** Probado por API y a través del proxy de Vite con los datos del script: lista de pacientes del Dr. Pérez (María, atendida, y Jhan, con cita pendiente), búsqueda por nombre y código; historial de María con ficha, resumen, consulta con signos vitales e IMC y la receta REC-000001; E1 (Dr. Pérez → Jorge, Dr. Fernández → María, Dra. Gómez → Sonia con cita cancelada, lista vacía de la Dra. Gómez); Dr. Fernández → Jorge (cita pendiente, con 2 antecedentes y sin consultas, E4); receta #1 vista por su médico y su paciente, y 403 para otro médico y otro paciente; "Mi historial" de María y Jorge; E2 (Jorge con el historial de María, médico en "Mi historial", Recepcionista y Administrador); E3; segunda página sin evento nuevo en la bitácora. Nota: las búsquedas distinguen tildes ("lopez" no encuentra "López"), igual que el resto del sistema.

### Funcionalidades extras
- **Diagnósticos** de cada consulta vinculados al catálogo de enfermedades (tabla `consulta_diagnostico`, pendiente en D2).
- **Gráfico de evolución** del peso y el IMC entre consultas.
- **Búsqueda sin tildes** con la extensión `unaccent` de PostgreSQL (está disponible pero no instalada).
- Exportar el historial completo a PDF para entregarlo al paciente o a otra institución.
- Acceso de emergencia ("romper el vidrio") con motivo obligatorio y alerta en la bitácora.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `cita_medica` | Lectura | Relación médico–paciente (RN01), próxima cita |
| `consulta_medica` | Lectura | Consultas, signos vitales e IMC |
| `receta_medica` / `detalle_receta` | Lectura | Recetas de cada consulta |
| `paciente_enfermedad` / `enfermedad` | Lectura | Antecedentes |
| `paciente` / `usuario` / `medico` / `especialidad` | Lectura | Identificación y médico de cada consulta |
| `bitacora` | Inserción | Registro de accesos (RN04) |

### Dependencias y conexiones
- **CU12, CU17 y CU18:** el historial muestra lo que registran (antecedentes, consultas y recetas); no los modifica.  
- **CU06:** los accesos al historial se auditan en la bitácora.  
- **CU13/CU14:** agendar una cita crea la relación médico–paciente que da acceso al historial; cancelarla no la quita si existe otra cita atendida o pendiente.

---

## CU04 – Cargar Usuarios por Lotes (Excel/CSV)  
**Actor:** Administrador (A)  
**Prioridad:** Media  
**Módulo:** Módulo 1 – Seguridad, Usuarios y Configuración del Sistema (`ModuloSeguridad`)  
**Permiso requerido:** `usuarios_importar` (nuevo; Administrador)

### Descripción funcional
Permite al Administrador **registrar muchas cuentas de una sola vez** a partir de un archivo **CSV** (el que genera Excel con "Guardar como → CSV UTF-8"). Hay dos tipos de carga, con su propia **plantilla descargable**: **personal** (usuarios con roles administrativos, igual que CU02) y **pacientes** (cuenta + ficha + contacto de emergencia, igual que CU11). La carga se hace en **dos pasos**: primero se **valida** el archivo y se muestra una vista previa con los errores de cada fila, sin escribir nada; luego se **importa**, solo si no hay errores.

**Reglas de negocio:**
- **RN01 – Formato:** archivo `.csv` o `.txt` de hasta **2 MB** y **200 filas** de datos. Se detecta el separador (`;`, `,` o tabulación) y la codificación (UTF-8 o Windows-1252, la que usa Excel por defecto); los encabezados no distinguen mayúsculas, tildes ni espacios. Las filas vacías se ignoran.
- **RN02 – Columnas:** personal → `ci`, `nombre`, `apellido`, `correo`, `telefono`, `sexo`, `roles`, `contrasena`; pacientes → `ci`, `nombre`, `apellido`, `correo`, `telefono`, `sexo`, `fecha_nacimiento`, `tipo_sangre`, `contacto_nombre`, `contacto_apellido`, `contacto_parentesco`, `contacto_telefono`, `contrasena`. Son obligatorias `ci`, `nombre`, `apellido`, `correo` y, según el tipo, `roles` o `fecha_nacimiento`.
- **RN03 – Mismas validaciones que el alta individual:** cada fila se valida con las reglas de CU02 (personal) o CU11 (pacientes): CI y correo únicos, formato de correo, teléfono, sexo, fecha de nacimiento, tipo de sangre y contacto de emergencia completo. Además, el **CI y el correo no pueden repetirse dentro del archivo**.
- **RN04 – Roles (personal):** la columna `roles` admite uno o varios nombres separados por `|`, sin distinguir mayúsculas. Solo se aceptan **roles activos**, y nunca Médico ni Paciente: esos se registran desde CU08 y CU11 (CU02, RN04).
- **RN05 – Valores aceptados:** `sexo` admite M/F/O o Masculino/Femenino/Otro; `fecha_nacimiento` admite AAAA-MM-DD o DD/MM/AAAA; `tipo_sangre` no distingue mayúsculas.
- **RN06 – Contraseñas:** la columna `contrasena` es opcional. Si se completa, debe cumplir la política de CU02 (mínimo 8 caracteres con letras y números). Si está vacía, el sistema **genera una contraseña temporal** que se muestra **una sola vez** al terminar (descargable como CSV) para entregarla al usuario, quien debería cambiarla en CU05. Nunca se guarda en texto plano.
- **RN07 – Todo o nada:** la importación vuelve a validar el archivo completo (no se confía en la vista previa) y crea todas las cuentas en **una sola transacción**: si una fila falla, no se crea ninguna.
- **RN08 – Auditoría:** cada cuenta creada se registra en `bitacora` igual que en el alta individual, y además se registra un evento `IMPORTACION` con el tipo, el nombre del archivo y la cantidad de cuentas.

### Flujo principal (mínimo viable)
1. El Administrador abre **"Carga masiva"**, elige el tipo (personal o pacientes) y descarga la **plantilla** con un ejemplo.
2. Completa la plantilla en Excel, la guarda como CSV y la selecciona.
3. Pulsa **Validar**: el sistema lee el archivo (RN01–RN02), valida cada fila (RN03–RN06) y muestra el resumen (total, válidas, con errores) y la tabla de filas con sus errores. No se escribe nada.
4. Corrige el archivo y vuelve a validarlo hasta que no queden errores.
5. Pulsa **Importar N registros**: el sistema revalida y crea las cuentas (RN07–RN08). Se muestra cuántas se crearon y, si se generaron contraseñas, la tabla de credenciales temporales con la opción de descargarla.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Sin archivo, extensión no admitida, más de 2 MB o tipo de carga inválido | **422** con el error del campo |
| E2 | Archivo vacío, sin filas de datos, con más de 200 filas o sin alguna columna obligatoria | **422** en `archivo` con el motivo (p. ej., "Faltan las columnas: correo, roles") |
| E3 | Filas con errores en la validación | **200** con el detalle por fila; el botón de importar queda deshabilitado |
| E4 | Se intenta importar un archivo que aún tiene errores | **422** "El archivo tiene N filas con errores…" y no se crea ninguna cuenta |
| E5 | Usuario sin el permiso `usuarios_importar` | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`) y el permiso `usuarios_importar`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/importaciones/plantilla?tipo=personal\|pacientes` | Descarga la plantilla CSV (UTF-8 con BOM, separador `;`) |
| POST | `/api/importaciones/validar` | `multipart/form-data` (`tipo`, `archivo`): vista previa con errores por fila |
| POST | `/api/importaciones` | `multipart/form-data` (`tipo`, `archivo`): revalida e importa todo o nada |

### Componentes de implementación
- **Backend (`app/Modules/ModuloSeguridad`):** `Controllers/ImportUsuarioController.php`, `Requests/ImportLoteRequest.php`, `Services/ImportLoteService.php` (lectura, normalización, validación por fila e importación); reglas compartidas en `Requests/UsuarioRequest.php` (`reglasCuenta()`, `mensajesCuenta()`) y `ModuloPacientes/Requests/PacienteRequest.php` (`reglasDatosPaciente()`, `mensajesPaciente()`); `app/Shared/Support/LectorCsv.php` (separador, codificación y encabezados). Reutiliza `UsuarioService::crear()` (CU02) y `PacienteService::crear()` (CU11).
- **Frontend (`src/modules/ModuloSeguridad`):** `services/importacion.service.js`, `pages/CargaLotesPage/` (`CargaLotesPage.js` + `CargaLotesPage.view.jsx` + `components/VistaPreviaImportacion.jsx` + `components/ResultadoImportacion.jsx`); `src/shared/utils/archivos.js` (descarga de archivos y CSV compatible con Excel, para la plantilla y las credenciales). Ruta: `/admin/usuarios/importar` (permiso `usuarios_importar`), con acceso desde el botón **Carga por lotes** de la página de usuarios.

**Estado: implementado.** Probado por API y a través del proxy de Vite: plantillas, archivo de Excel en Windows-1252 con `;`, fechas DD/MM/AAAA, errores por fila (correo inválido, CI repetido en el archivo y ya registrado, rol Médico o inexistente, sexo, contraseña corta, fecha inválida o futura, tipo de sangre, contacto incompleto), E1/E2 (sin columnas, vacío, 201 filas, `.xlsx`, tipo inválido), E4 (no se crea ninguna cuenta), E5 (recepcionista → 403), importación con credenciales temporales, inicio de sesión con la contraseña generada y bitácora `IMPORTACION`. Sin regresiones en CU02, CU05 ni CU11 tras compartir sus reglas.

### Funcionalidades extras
- **Archivos Excel (.xlsx):** requiere habilitar las extensiones `zip`, `gd` y `fileinfo` de PHP e instalar `phpoffice/phpspreadsheet`; el lector se puede agregar junto al de CSV sin cambiar el resto del flujo.  
- Importación de **médicos** (requiere columnas de salario y especialidades de CU08).  
- Procesar archivos grandes en segundo plano con una cola, avisando al terminar.  
- Obligar a cambiar la contraseña temporal en el primer inicio de sesión.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `usuario` | Lectura, inserción | Unicidad de CI y correo; cuentas nuevas |
| `rol` / `rol_usuario` | Lectura, inserción | Roles del personal (RN04); rol Paciente |
| `paciente` / `contacto_de_emergencia` | Inserción | Ficha de los pacientes importados |
| `bitacora` | Inserción | Auditoría (RN08) |

### Dependencias y conexiones
- **Ciclo 1:** Reutiliza las validaciones y los servicios de alta de CU02 (usuarios) y CU11 (pacientes), por lo que cualquier regla nueva en esos casos de uso se aplica también a la carga masiva.  
- **Rendimiento:** cada contraseña se cifra con BCRYPT (unos 220 ms por cuenta con `BCRYPT_ROUNDS=12`), por lo que una carga de 200 filas tarda alrededor de 45 segundos. Ese es el motivo del límite de RN01.

---

## CU20 – Gestionar Notificaciones y Recordatorios  
**Actor:** Sistema, Recepcionista (R), Paciente (P)  
**Prioridad:** Media  
**Módulo:** Módulo 6 – Inteligencia Operativa, Notificaciones y Reportes (`ModuloInteligenciaReportes`, nuevo)  
**Permiso requerido:** `notificaciones_gestionar` (nuevo; Administrador y Recepcionista); el Paciente usa su bandeja solo con sesión

### Descripción funcional
Mantiene informado al paciente sobre sus citas. El **sistema** genera avisos automáticos cuando una cita se agenda, reprograma o cancela (CU13/CU14) y **recordatorios** antes de cada cita, con la anticipación que el paciente eligió en su perfil (CU05). Toda notificación queda en la **bandeja del paciente** dentro de la aplicación y, si sus preferencias lo permiten, también se envía por **correo**. La **Recepción** ve todo lo enviado, puede escribir **avisos manuales**, reenviar correos fallidos y disparar los recordatorios al momento.

**Reglas de negocio:**
- **RN01 – Tipos:** Cita agendada, Cita reprogramada, Cita cancelada (con el motivo), Recordatorio de cita y Aviso de la clínica (manual). Los mensajes incluyen código de cita, médico, fecha en español y hora.
- **RN02 – Avisos automáticos:** se generan después de confirmar la operación de la cita (CU13/CU14), sea quien sea que la hizo. Un fallo al crear el aviso nunca deshace ni hace fallar la cita (solo se registra en el log).
- **RN03 – Canales:** la bandeja siempre recibe la notificación. El correo respeta `notificar_email` de CU05 (por defecto, sí): si el paciente lo desactivó o no tiene correo, queda **"Solo en la app"** (`omitido`); si el servidor de correo falla, queda **"fallido"** y se puede reenviar. El correo sale después de responder al usuario, así que agendar no espera al servidor de correo, y no necesita `queue:work`.
- **RN04 – Recordatorios:** para citas Programadas o Confirmadas de pacientes activos que empiezan dentro de la anticipación del paciente (CU05: 2, 12, 24 o 48 horas; 24 por defecto). Cada cita recibe **un solo** recordatorio; si se reprograma, recibe uno nuevo para la nueva hora. El planificador los envía cada 15 minutos (`php artisan schedule:work`, o `php artisan notificaciones:recordatorios` a mano), con un bloqueo que impide duplicados si coinciden dos ejecuciones.
- **RN05 – Avisos manuales:** Recepción escribe un mensaje (5 a 1000 caracteres) para un paciente activo, opcionalmente vinculado a una de sus citas (la cita debe ser del paciente).
- **RN06 – Reenvío:** solo se reenvían correos pendientes o fallidos; uno ya enviado o "solo en la app" responde 422.
- **RN07 – Bandeja:** el paciente ve solo sus notificaciones, de la más reciente a la más antigua, con el filtro "solo no leídas", y las marca como leídas una a una o todas juntas. El menú muestra cuántas tiene sin leer (se actualiza cada minuto y al leerlas). Una notificación ajena responde 404.
- **RN08 – Auditoría:** los avisos manuales y los reenvíos se registran en `bitacora`; los automáticos no, porque ya se audita la operación de la cita que los origina.

### Flujo principal (mínimo viable)
1. Recepción agenda, reprograma o cancela una cita (CU13/CU14): el sistema crea el aviso y lo envía por correo (RN02–RN03).
2. Cada 15 minutos el planificador envía los recordatorios que correspondan (RN04).
3. El Paciente ve la cantidad sin leer en **Notificaciones**, abre su bandeja y las marca como leídas (RN07).
4. Recepción abre **Notificaciones y reportes → Notificaciones**, filtra por paciente, tipo, estado del correo o fechas, reenvía un correo fallido (RN06) o envía un **Nuevo aviso** (RN05).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Aviso sin paciente, con paciente inexistente o inactivo, mensaje corto o largo, o cita de otro paciente | **422** con el error del campo |
| E2 | Reenviar un correo ya enviado o de un paciente que no recibe correos | **422** en `notificacion` |
| E3 | Filtros inválidos (tipo, estado del correo, fechas) | **422** |
| E4 | Usuario sin `notificaciones_gestionar` en la gestión, o que no es paciente en la bandeja | **403** |
| E5 | Notificación inexistente o ajena en la bandeja | **404** |
| E6 | El servidor de correo no responde | La notificación queda en la bandeja con estado **fallido**; la cita no se ve afectada |

### Endpoints (API REST)
| Método | Ruta | Acceso | Descripción |
|--------|------|--------|-------------|
| GET | `/api/mis-notificaciones?no_leidas=&page=` | Paciente | Bandeja con el total sin leer |
| GET | `/api/mis-notificaciones/resumen` | Paciente | Número sin leer (insignia del menú) |
| PATCH | `/api/mis-notificaciones/{id}/leida` | Paciente | Marca una como leída |
| PATCH | `/api/mis-notificaciones/leer-todas` | Paciente | Marca todas como leídas |
| GET | `/api/notificaciones?buscar=&tipo=&estado_correo=&desde=&hasta=&page=` | `notificaciones_gestionar` | Notificaciones enviadas |
| POST | `/api/notificaciones` | `notificaciones_gestionar` | Aviso manual |
| POST | `/api/notificaciones/{id}/reenviar` | `notificaciones_gestionar` | Reenvía el correo |
| POST | `/api/notificaciones/recordatorios` | `notificaciones_gestionar` | Envía ahora los recordatorios pendientes |

### Componentes de implementación
- **Backend (nuevo `app/Modules/ModuloInteligenciaReportes`):** `Models/Notificacion.php` (tipos, títulos y estados del correo), `Services/NotificacionService.php` (mensajes, recordatorios, avisos, reenvío y bandeja), `Jobs/EnviarCorreoNotificacion.php` (envío y estado; respeta las preferencias de CU05), `Mail/NotificacionPacienteMail.php` + `resources/views/mail/notificacion.blade.php` (correo monocromático), `Controllers/NotificacionController.php` y `MisNotificacionesController.php`, `Requests/AvisoRequest.php`, `Resources/NotificacionResource.php`, `routes.php`. Comando `notificaciones:recordatorios` y su programación cada 15 minutos en `routes/console.php`. `CitaService` (CU13/CU14) avisa al paciente tras agendar, reprogramar y cancelar. Migración `2026_11_06_000000_agregar_lectura_y_estado_correo_notificacion.php` (D2). `config/clinica.php` agrega `FRONTEND_URL` para el enlace del correo.
- **Frontend (nuevo `src/modules/ModuloInteligenciaReportes`):** `services/notificaciones.service.js`, `hooks/useNoLeidas.js` (insignia del menú), `utils/notificaciones.js`, `pages/MisNotificacionesPage/` (bandeja del paciente) y `pages/NotificacionesPage/` (+ `components/AvisoModal.jsx`, con búsqueda de pacientes de CU11 y sus próximas citas de CU14). El `Sidebar` muestra contadores en las opciones de menú que declaran `contador`, y un punto en el grupo cerrado. Rutas: `/paciente/notificaciones` (rol Paciente) y `/notificaciones` (permiso `notificaciones_gestionar`, menú **Notificaciones y reportes**).

**Estado: implementado.** Probado por API, por consola y a través del proxy de Vite: agendar, reprogramar y cancelar una cita real con Recepción (avisos en la bandeja y correos en el log, con fecha en español y motivo); recordatorios (1 para la cita de hoy con anticipación de 24 h, 0 en la segunda ejecución, uno nuevo tras reprogramarse, paciente con 12 h y correos desactivados → "Solo en la app", botón de Recepción sin pendientes); avisos manuales (con cita, a paciente sin correos, cita ajena, mensaje corto, paciente inexistente); correo fallido simulado con un SMTP inalcanzable y reenvío correcto, reenvío de enviada y de omitida (422); filtros de Recepción (tipo, estado, paciente, fechas, tipo inválido); bandeja de la paciente (5 sin leer, marcar una, solo no leídas, ajena → 404, todas, de nuevo sin pendientes); permisos (paciente y médico → 403, administrador → 200) y bitácora. Las citas de prueba de hoy se crearon directamente en la base.

### Funcionalidades extras
- **Correo real** (SMTP o un servicio como Mailgun) configurando `.env` (D5).
- Notificaciones **push** del navegador o **WhatsApp/SMS** (la preferencia `notificar_push` de CU05 queda reservada para ese canal).
- Avisar automáticamente a los pacientes de las **citas afectadas por una licencia** (CU10).
- Confirmación de asistencia desde el correo (botón "Confirmo mi cita", que pasaría la cita a Confirmada).
- Envío por cola (`ShouldQueue` + `queue:work`) cuando el volumen de correos lo justifique.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `notificacion` | Lectura, inserción, modificación | Bandeja, estado del correo y lectura; columnas nuevas `leida`, `fecha_lectura`, `estado_correo` |
| `cita_medica` | Lectura | Datos de los avisos y citas que necesitan recordatorio |
| `preferencia_notificacion` | Lectura | Correo sí/no y anticipación de los recordatorios (CU05) |
| `paciente` / `usuario` / `medico` | Lectura | Destinatario, correo y nombre del médico |
| `bitacora` | Inserción | Avisos manuales y reenvíos (RN08) |

### Dependencias y conexiones
- **CU05:** las preferencias del perfil (correo y anticipación) deciden el canal y el momento de los recordatorios.  
- **CU13/CU14:** cada cita agendada, reprogramada o cancelada genera su aviso; `CitaService` depende de `NotificacionService`.  
- **CU11:** el formulario de avisos busca pacientes con el listado de pacientes.  
- **CU06:** los avisos manuales y reenvíos aparecen en la bitácora como "Notificaciones".

---

## CU21 – Generar Reportes Operativos Tradicionales  
**Actor:** Administrador (A), Recepcionista (R)  
**Prioridad:** Alta  
**Módulo:** Módulo 6 – Inteligencia Operativa, Notificaciones y Reportes (`ModuloInteligenciaReportes`)  
**Permiso requerido:** `reportes_ver` (del script: Administrador, Recepcionista y Médico, este último con alcance personal)

### Descripción funcional
Genera los **reportes operativos** de la clínica para un periodo: **Citas por estado**, **Atenciones médicas**, **Inasistencias** e **Ingresos**. Cada uno se filtra (médico, especialidad, estado, modalidad, método o estado del pago, según el reporte), se **agrupa** (por estado, médico, especialidad, modalidad, método, día o mes) y muestra indicadores, la tabla agrupada y el detalle. Se exporta a **CSV** (resumen o detalle) y a **PDF** desde su versión imprimible. Los reportes son una **lista blanca** (`CatalogoReportes`) que CU22 reutilizará.

**Reglas de negocio:**
- **RN01 – Reportes:** Citas (total, atendidas, pendientes, canceladas, inasistencias); Atenciones (consultas registradas, pacientes distintos, recetas emitidas); Inasistencias (citas cerradas, atendidas, inasistencias y **tasa** = inasistencias / citas cerradas; el detalle lista solo las inasistencias con el teléfono del paciente); Ingresos (pagos, monto total y pago promedio).
- **RN02 – Filtros y agrupación:** cada reporte admite solo sus filtros y agrupaciones del catálogo; los demás se ignoran o se rechazan (422). Las citas se filtran por su fecha; las atenciones y los pagos, por el día local (hora de Bolivia) de su registro.
- **RN03 – Límites:** el periodo es obligatorio y de hasta **366 días**; en pantalla se muestran hasta **500** filas de detalle (el resto se exporta) y el CSV del detalle admite hasta **10.000** filas.
- **RN04 – Alcance:** Administración y Recepción ven toda la clínica. El Médico (que tiene `reportes_ver` en el script) ve **solo sus propios** reportes de citas, atenciones e inasistencias, aunque pida los de otro médico, y no ve los **ingresos** (403). Se considera alcance personal a quien tiene ficha de médico y no gestiona la agenda (`citas_programar`).
- **RN05 – Ingresos:** por defecto cuentan solo los pagos **Completados**; se puede elegir otro estado o "Todos".
- **RN06 – Seguridad de las consultas:** las columnas, agrupaciones y expresiones SQL son constantes del servidor; del usuario solo se reciben claves de la lista blanca y valores que viajan como parámetros.
- **RN07 – Especialidad:** la cita no guarda la especialidad, así que se usa la del médico; un médico con varias se agrupa como "Ginecología, Pediatría" (sin contar dos veces la cita). Filtrar por especialidad incluye a los médicos que la ejercen.
- **RN08 – Auditoría:** cada exportación a CSV se registra en `bitacora` (`EXPORTACION` sobre "Reportes") con el reporte, la sección, los filtros y las filas.

### Flujo principal (mínimo viable)
1. El usuario abre **Notificaciones y reportes → Reportes** (el Médico, **Mis reportes**).
2. Elige el reporte; el sistema propone el mes actual (con atajos: mes anterior, últimos 30 días, este año).
3. Ajusta los filtros y la agrupación: el reporte se regenera al instante con indicadores, tabla agrupada y detalle.
4. Exporta el **resumen** o el **detalle** a CSV, o abre **Imprimir / PDF**.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Sin fechas, fecha final anterior a la inicial, más de 366 días, estado o agrupación no admitidos (incluido un intento de inyección en `agrupar`) | **422** con el error del campo |
| E2 | Reporte inexistente | **404** |
| E3 | Médico que pide los ingresos, o usuario sin `reportes_ver` (Paciente) | **403** |
| E4 | Detalle de más de 10.000 filas al exportar | **422** en `exportacion` |
| E5 | Periodo sin datos | **200** con indicadores en cero y tablas vacías |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`) y `reportes_ver`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/reportes/catalogo` | Reportes disponibles para el usuario, sus filtros, agrupaciones, opciones y alcance |
| GET | `/api/reportes/{citas\|atenciones\|inasistencias\|ingresos}?desde=&hasta=&<filtros>&agrupar=` | Reporte con resumen, agrupado y detalle |
| GET | `/api/reportes/{tipo}/exportar?<mismos filtros>&seccion=agrupado\|detalle` | CSV (BOM, `;`, decimales con coma, fechas DD/MM/AAAA) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloInteligenciaReportes`):** `Services/CatalogoReportes.php` (lista blanca), `Services/ReporteService.php` (consultas base con Query Builder, métricas con `count(*) filter`, agrupación sobre una subconsulta, detalle, CSV y opciones de filtros), `Requests/ReporteRequest.php`, `Controllers/ReporteController.php` (alcance RN04). La bitácora (CU06) muestra la tabla como "Reportes".
- **Frontend (`src/modules/ModuloInteligenciaReportes`):** `services/reportes.service.js`, `utils/reportes.js` (formatos y periodos rápidos), `components/ReporteDocumento.jsx` (pinta cualquier reporte a partir de su estructura: indicadores, tabla agrupada y detalle; lo reutilizará CU22), `pages/ReportesPage/` (selector de reporte y filtros armados desde el catálogo) y `pages/ReporteImprimiblePage/` (filtros en la URL, fuera del diseño principal). Rutas: `/reportes` y `/reportes/imprimir` (permiso `reportes_ver`).

**Estado: implementado.** Probado por API y a través del proxy de Vite con los datos del script y una inasistencia de prueba: los cuatro reportes coinciden con consultas SQL de control (5 citas por estado, 1 atención con su receta, tasa de inasistencia 50 % y 100 % por médico, Bs 270 en 2 pagos QR); agrupaciones por especialidad (médico con dos especialidades en una sola fila), día, mes, médico y método; filtros por estado, médico, especialidad y método; E1 (sin fechas, rango invertido, más de 366 días, agrupación y estado no admitidos, inyección en `agrupar`), E2; CSV del resumen y del detalle con su evento en la bitácora; alcance del Médico (catálogo sin ingresos ni filtro de médico, solo sus citas aunque pida otro médico, ingresos y su exportación → 403), Recepción con toda la clínica y Paciente → 403. Formatos del frontend probados con Node.

### Funcionalidades extras
- **Gráficos** de las tablas agrupadas (barras por médico, línea por día) en escala de grises; se verán también en CU23.
- **Reportes programados** que lleguen por correo cada semana o mes (con CU20).
- PDF generado en el servidor (ver CU18) y exportación a Excel nativo (`.xlsx`, requiere las extensiones `zip` y `gd`).
- Guardar combinaciones de filtros como "reportes favoritos".
- Guardar la especialidad en la cita (pendiente de CU13) para no deducirla del médico.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `cita_medica` / `modalidad_cita` | Lectura | Reportes de citas e inasistencias |
| `consulta_medica` / `receta_medica` | Lectura | Reporte de atenciones |
| `pago_cita` / `metodo_pago` | Lectura | Reporte de ingresos |
| `paciente` / `medico` / `usuario` / `medico_especialidad` / `especialidad` | Lectura | Nombres, especialidades y filtros |
| `bitacora` | Inserción | Exportaciones (RN08) |

### Dependencias y conexiones
- **Ciclo 1:** se alimenta de las citas (CU13–CU15), las consultas (CU17) y los pagos (CU24).  
- **CU18:** el reporte de atenciones cuenta las recetas emitidas.  
- **CU22:** traducirá una orden por voz a un tipo de `CatalogoReportes` con sus filtros y llamará a `ReporteService::generar()`; la pantalla reutilizará `ReporteDocumento`.  
- **CU23:** el tablero puede reutilizar las mismas métricas.

---

## CU23 – Visualizar Dashboard Administrativo  
**Actor:** Administrador (A)  
**Prioridad:** Media  
**Módulo:** Módulo 6 – Inteligencia Operativa, Notificaciones y Reportes (`ModuloInteligenciaReportes`)  
**Permiso requerido:** `dashboard_ver` (nuevo; solo Administrador, porque incluye los ingresos de toda la clínica)

### Descripción funcional
Es la **página de inicio del Administrador** (`/admin/dashboard`): un tablero con los indicadores clave de un periodo y su evolución frente al periodo anterior, y gráficos en **escala de grises** de atenciones por día, ocupación de agenda por médico, ingresos por método de pago y citas por estado. Reutiliza las métricas de CU21, así que el tablero y los reportes siempre dan los mismos números.

**Reglas de negocio:**
- **RN01 – Indicadores:** Atenciones, Pacientes atendidos, Tasa de inasistencia, Ocupación de agenda e Ingresos (pagos completados). Cada uno indica si es mejor que **suba o que baje**.
- **RN02 – Comparación:** cada indicador se compara con el **periodo anterior de igual duración** (para 1–30 de noviembre, del 2 al 31 de octubre): en % para cantidades y en **puntos** para tasas. Si el periodo anterior está en cero o sin dato, se muestra "Sin datos para comparar". Un cambio que empeora se marca en rojo con flecha y texto (nunca solo con color).
- **RN03 – Gráficos:** una sola serie por gráfico en el negro del sistema, como pide la escala de grises (sin paleta categórica). Atenciones por día en línea, incluidos los días en cero; ocupación, ingresos y estados en **barras horizontales ordenadas**. Cada gráfico tiene su **vista de tabla** y se puede leer con el teclado; el cursor y la ayuda emergente nunca son la única forma de ver un valor.
- **RN04 – Ocupación de agenda:** citas no canceladas del médico frente a los **turnos que ofrece su grilla semanal** (CU09) en el periodo, sin contar los que caen en una **licencia** (CU10). La capacidad se calcula turno a turno en una sola consulta SQL; los médicos activos sin horario aparecen como "Sin horario". La ocupación general es el total de ocupados sobre el total de turnos.
- **RN05 – Periodo:** un solo filtro arriba gobierna todo el tablero (atajos de 7, 30 y 90 días, este mes y este año, o fechas a mano); por defecto, los últimos 30 días; hasta 366 días como en CU21. Al cambiarlo, el tablero anterior queda atenuado hasta que llega el nuevo, sin saltos.

### Flujo principal (mínimo viable)
1. El Administrador inicia sesión y llega al **Dashboard** con los últimos 30 días.
2. Lee los indicadores con su variación y los gráficos; con **Ver tabla** consulta los valores exactos.
3. Cambia el periodo con un atajo o con las fechas: todo el tablero se recalcula.
4. Para el detalle o la exportación, sigue el enlace a **Reportes** (CU21).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Solo una de las fechas, fecha final anterior a la inicial o más de 366 días | **422** con el error del campo |
| E2 | Usuario sin `dashboard_ver` (Recepcionista, Médico, Paciente) | **403** |
| E3 | Periodo sin datos | **200** con indicadores en cero, "Sin datos para comparar" y gráficos vacíos |

### Endpoints (API REST)
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/dashboard?desde=&hasta=` | Indicadores con variación, atenciones por día, ocupación por médico, ingresos por método y citas por estado (sin fechas, los últimos 30 días) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloInteligenciaReportes`):** `Services/DashboardService.php` (indicadores, comparación, serie diaria y ocupación con `generate_series` sobre la grilla y exclusión de licencias), `Controllers/DashboardController.php`; `ReporteService` expone `indicadores()` y `agrupar()` para reutilizar las métricas de CU21.
- **Frontend (`src/modules/ModuloInteligenciaReportes`):** `services/dashboard.service.js`, `pages/DashboardPage/` (`DashboardPage.js` + `.view.jsx`), y componentes de gráficos reutilizables en `components/graficos/`: `Indicador.jsx`, `GraficoLinea.jsx` (SVG medido a su ancho real, trazo de 2 px, lavado al 10 %, cuadrícula fina, máximo etiquetado, cursor con lectura y navegación con flechas), `BarrasHorizontales.jsx` (barras de 16 px con extremo redondeado y valor en la punta; en celular el nombre va arriba), `PanelGrafico.jsx` (tarjeta con **Ver tabla**) y `useAncho.js` (ancho medido y escala de eje "redonda" y entera para conteos). La ruta `/admin/dashboard` reemplaza la página genérica que tenía.

**Estado: implementado.** Probado por API y a través del proxy de Vite: indicadores y gráficos de octubre–noviembre que coinciden con el cálculo manual (Dr. Pérez: 2 citas en 136 turnos = 1,5 %, de 9 lunes y 8 miércoles con 8 turnos; Dra. Gómez 0 % con su única cita cancelada; Dr. Fernández 72 turnos, 64 con una licencia de día completo de prueba), comparación con el periodo anterior, un solo día, periodo por defecto, E1, E2 (Recepcionista y Médico). Los componentes se revisaron con capturas de Chrome headless en escritorio (1280 px) y en un marco de 390 px, con datos de ejemplo: se corrigieron un desborde horizontal en celular (el SVG ensanchaba su tarjeta) y barras demasiado angostas; quedó sin desborde (ancho del contenido = 390 px). La escala del eje se probó con Node (37 → 40, 136 → 150, sin marcas fraccionarias en conteos). El validador de paletas confirma el contraste del negro (21:1); sus pruebas de croma y franja de luminosidad aplican a paletas categóricas, que el tablero no usa.

### Funcionalidades extras
- **Mini-tendencias** (sparklines) en cada indicador y comparación con el mismo periodo del año anterior.
- Hacer clic en un médico o método para abrir el reporte de CU21 ya filtrado.
- Actualización automática cada pocos minutos y vista para pantalla de recepción.
- Indicadores de citas de hoy y próximas en tiempo real.
- Modo oscuro y textura para impresión en blanco y negro.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| `consulta_medica` / `cita_medica` / `pago_cita` / `metodo_pago` | Lectura | Métricas de CU21 |
| `horario_atencion` / `indisponibilidad_medico` | Lectura | Capacidad de turnos y licencias (RN04) |
| `medico` / `usuario` | Lectura | Médicos activos y nombres |

### Dependencias y conexiones
- **CU21:** comparte las métricas (`ReporteService`) para que tablero y reportes coincidan.  
- **CU09 y CU10:** la ocupación usa la grilla semanal y descuenta las licencias.  
- **CU01:** es la página de inicio del Administrador.

---

## CU22 – Generar Reportes Generativos por Comando de Voz (IA)  
**Actor:** Administrador (A), Recepcionista (R)  
**Prioridad:** Media  
**Módulo:** Módulo 6 – Inteligencia Operativa, Notificaciones y Reportes (`ModuloInteligenciaReportes`)  
**Permiso requerido:** `reportes_ver` (el mismo de CU21 y con el mismo alcance por rol: el Médico, solo sus datos)

### Descripción funcional
El usuario **pide un reporte con sus palabras**, dictándolo con el micrófono o escribiéndolo ("citas canceladas del mes pasado por médico", "ingresos de octubre por método de pago"). El sistema **interpreta** la consulta, genera el reporte de CU21 que corresponde, muestra **qué entendió** y **lee en voz alta** un resumen. El dictado y la lectura usan la Web Speech API del navegador; la interpretación ocurre en el servidor.

**Reglas de negocio:**
- **RN01 – La IA no toca la base de datos:** el intérprete solo devuelve una **intención** (tipo de reporte, periodo, filtros y agrupación) elegida entre las opciones de la lista blanca de CU21; nunca SQL, columnas ni expresiones. La consulta del usuario se trata como dato, no como instrucción.
- **RN02 – Dos intérpretes (D6):** con `ANTHROPIC_API_KEY` configurada responde **Claude** (`claude-opus-5-5`, salida estructurada con un esquema JSON cuyos valores son `enum` de las opciones válidas, esfuerzo bajo, 20 s de tiempo máximo y un reintento). Sin clave, o si la IA falla, se niega o no responde a tiempo, responde el **intérprete por reglas**, y la pantalla indica cuál respondió ("Interpretado con IA" / "por reglas").
- **RN03 – Validación de la intención:** se usa solo lo que pasa la validación del servidor: el tipo de reporte debe estar en el alcance del usuario; las fechas deben ser válidas, en orden y de hasta 366 días; médicos, especialidades, modalidades y métodos deben existir; estados y agrupaciones deben estar en la lista del reporte. Lo inválido se reemplaza por el valor por defecto y se explica en una **nota** (por ejemplo, "Ese reporte no se puede agrupar así").
- **RN04 – Lo que entiende el intérprete por reglas:**
  - **Reporte:** ingresos, pagos, cobros, recaudación o reembolsos; inasistencias o faltas; citas; atenciones o consultas.
  - **Periodo:** hoy, ayer, esta semana o la pasada, este mes o el pasado, este año o el pasado, "últimos N días, semanas o meses" (también con número en palabras), un mes con o sin año (un mes sin año es el más reciente que ya empezó), "del 1 al 15 de octubre", o un año. Si no se indica, se usan los últimos 30 días.
  - **Filtros:** médico por apellido o código, especialidad, estado de la cita, modalidad, método de pago y estado del pago.
  - **Agrupación:** "por médico", "por especialidad", "por día", "mensual", entre otras.
  - No distingue tildes ni mayúsculas.
- **RN05 – Resumen hablado:** una o dos frases con el periodo, los indicadores y el grupo principal ("Por médico, el primero es Juan Pérez (citas: 2)"), con montos en bolivianos y porcentajes "por ciento". Si no hay datos, dice "no hay registros con esos filtros". Se puede volver a escuchar o desactivar la lectura.
- **RN06 – Auditoría y límites:** cada consulta se registra en `bitacora` (`CONSULTA_VOZ`) con el texto (hasta 300 caracteres), el intérprete usado y los parámetros aplicados. La consulta tiene entre 3 y 300 caracteres, y se admiten hasta 20 consultas por minuto, porque con IA cada consulta tiene costo.
- **RN07 – Alcance:** el mismo de CU21 (clase compartida `AlcanceReportes`). Si un médico pide ingresos u otro médico, se le muestran sus propios datos y una nota lo explica.

### Flujo principal (mínimo viable)
1. El usuario abre **Notificaciones y reportes → Reportes por voz**.
2. Pulsa el micrófono y dice qué necesita; el texto aparece mientras habla (también puede escribirlo o elegir un ejemplo).
3. Al terminar de hablar, la consulta se envía sola: el servidor la interpreta (RN02), la valida (RN03) y genera el reporte de CU21.
4. El sistema muestra lo que entendió, las notas y el reporte completo (indicadores, tabla agrupada y detalle), y lee el resumen en voz alta (RN05).
5. Opcionalmente exporta el detalle a CSV o abre la versión imprimible (PDF) con los mismos filtros.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Consulta vacía, de menos de 3 o de más de 300 caracteres | **422** en `consulta` |
| E2 | Consulta que no pide un reporte ("hola, ¿qué tal el clima?") | **200** con `entendido: false`, un mensaje y ejemplos |
| E3 | La IA falla, se niega, no responde a tiempo o devuelve algo inválido | Responde el intérprete por reglas con la nota "No se pudo usar la IA en este momento" |
| E4 | Usuario sin `reportes_ver` (Paciente) | **403** |
| E5 | Navegador sin reconocimiento de voz (por ejemplo, Firefox) o sin permiso de micrófono | Se avisa y se puede escribir la consulta |
| E6 | Más de 20 consultas por minuto | **429** |

### Endpoints (API REST)
| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/reportes/voz` | `{ consulta }` → `{ entendido, interprete, notas, parametros, reporte, resumen_hablado }` (el reporte tiene la misma estructura que en CU21) |
| GET | `/api/reportes/catalogo` | Ahora informa también `interprete` (`ia` o `reglas`) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloInteligenciaReportes`):** `Services/Voz/InterpreteConsulta.php` (contrato), `InterpreteReglas.php`, `InterpreteClaude.php` (SDK oficial de Anthropic para PHP, `anthropic-ai/sdk` ^1.6, instalado con `--ignore-platform-req=ext-fileinfo`), `ConsultaVozService.php` (contexto, elección del intérprete con respaldo, validación, resumen hablado y auditoría), `Controllers/ConsultaVozController.php`; `Services/AlcanceReportes.php` (alcance de CU21, ahora compartido). Configuración en `config/services.php` (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODELO`, `ANTHROPIC_TIMEOUT`). La bitácora (CU06) muestra la acción como "Consulta de reporte por voz".
- **Frontend (`src/modules/ModuloInteligenciaReportes`):** `services/voz.service.js`, `hooks/useVoz.js` (`useDictado` con `SpeechRecognition` en `es-BO` y `useLectura` con `speechSynthesis`), `pages/ReportesVozPage/` (`ReportesVozPage.js` + `.view.jsx`); reutiliza `ReporteDocumento` de CU21. Ruta `/reportes/voz` (permiso `reportes_ver`), en el menú de Administración, Recepción y Médico.

**Estado: implementado con el intérprete por reglas; la IA está preparada pero sin probar con una clave real.**
- **Probado por API y a través del proxy de Vite:**
  - 16 consultas en lenguaje natural: reporte, meses (diciembre del año anterior), rangos de días, "últimas dos semanas", médicos por apellido y código, especialidad, modalidad, QR, reembolsos y "por médico/método/día/mes";
  - agrupación no admitida (nota), consulta sin sentido (E2) y E1;
  - Paciente (403);
  - alcance del Médico, que pide ingresos y otra médica: recibe sus datos con notas;
  - Recepción con toda la clínica;
  - bitácora y regresión de CU21 (catálogo, alcance, exportación).
- **La ruta de IA se probó sin costo con una clave inválida:**
  - el SDK arma la petición y la API responde 401 (`AuthenticationException`) una vez configurados los certificados;
  - el servicio respondió por reglas con su nota.
- **Falta probar con una clave real:** que Claude acepte el esquema y la calidad de la interpretación.

### Funcionalidades extras
- **Conversación de seguimiento** ("¿y el mes anterior?", "ahora solo cardiología") manteniendo la intención anterior.
- Respuestas generadas con IA que expliquen tendencias o anomalías a partir de los números del reporte, sin acceso directo a los datos.
- Comparar dos periodos en una sola orden ("ingresos de octubre contra septiembre").
- Reconocimiento de voz en el servidor (por ejemplo, Whisper) para navegadores sin Web Speech API.
- Fallbacks del lado del servidor de la API de Claude ante negativas (`fallbacks: "default"`); hoy el respaldo es el intérprete por reglas.

### Tablas implicadas
| Tabla | Operación | Motivo |
|-------|-----------|--------|
| Las de CU21 | Lectura | El reporte generado |
| `medico` / `usuario` / `especialidad` / `modalidad_cita` / `metodo_pago` | Lectura | Opciones válidas para el intérprete |
| `bitacora` | Inserción | Auditoría de consultas (RN06) |

### Dependencias y conexiones
- **CU21:** reutiliza `CatalogoReportes` (lista blanca), `ReporteService::generar()`, `AlcanceReportes` y, en pantalla, `ReporteDocumento`, la exportación CSV y la versión imprimible.  
- **CU06:** las consultas por voz aparecen en la bitácora.  
- **Anthropic (opcional):** `ANTHROPIC_API_KEY` en `.env` activa la IA; cada consulta tiene costo según el uso de la API.
