# CICLO #1 – Módulo Base, Entidades Maestras y Operación Principal de Citas y Pagos

---

## CU01 – Autenticar y Gestionar Sesión  
**Actor:** Administrador (A), Recepcionista (R), Médico (M), Paciente (P)  
**Prioridad:** Alta  
**Módulo:** Módulo 1 – Seguridad, Usuarios y Configuración del Sistema (`ModuloSeguridad`)

### Descripción funcional
Permite a cualquier actor acceder de forma segura al sistema mediante un **Portal de Acceso Único** (pantalla de login) usando su **correo** y **contraseña**. La contraseña se verifica contra el hash **BCRYPT** almacenado en `usuario.contrasena`. Tras un inicio de sesión exitoso, el sistema crea una **sesión segura** (cookie `HttpOnly` gestionada por Laravel Sanctum en modo SPA), carga los **roles y permisos activos** del usuario y lo redirige al panel que corresponde a su rol.

**Reglas de Identidad y Redirección (según `rol_usuario`):**
- **Administrador:** Acceso total. No requiere tabla de extensión. Redirige a `/admin/dashboard`.
- **Médico:** Requiere obligatoriamente un registro en **`medico`** con `activo = TRUE`. Redirige a `/medico/agenda` (agenda del día).
- **Recepcionista:** No requiere tabla de extensión. Redirige a `/recepcion/agenda` (citas del día y control de asistencia).
- **Paciente:** Requiere obligatoriamente un registro en **`paciente`** con `activo = TRUE`. Redirige a `/paciente/inicio` (mis citas, reservar, historial).
- **Usuario con varios roles:** Se toma el rol de mayor jerarquía (Administrador > Médico > Recepcionista > Paciente) como panel inicial. El menú muestra las opciones de todos sus roles activos.

**Reglas de negocio:**
- **RN01:** Solo pueden autenticarse usuarios con `usuario.activo = TRUE`.
- **RN02:** Solo se consideran los roles con `rol_usuario.activo = TRUE`, `rol.activo = TRUE` y `fecha_expiracion` nula o futura. Lo mismo aplica a `rol_permiso`.
- **RN03:** Un usuario sin ningún rol activo no puede iniciar sesión.
- **RN04:** Ante credenciales incorrectas, el mensaje es siempre genérico (*"Correo o contraseña incorrectos"*). Nunca se indica si el correo existe.
- **RN05:** Máximo **5 intentos fallidos** por combinación correo + IP. Después se bloquea el acceso durante **1 minuto** (limitación de intentos, sin columnas adicionales en BD).
- **RN06:** La sesión expira tras **120 minutos de inactividad** (`SESSION_LIFETIME`).
- **RN07:** La contraseña nunca viaja ni se devuelve en ninguna respuesta. El hash se excluye del modelo (`$hidden`).

### Flujo principal (mínimo viable)
1. **Login**  
   - El frontend solicita la cookie CSRF (`GET /sanctum/csrf-cookie`).  
   - El usuario envía `correo` y `contrasena` (`POST /api/auth/login`).  
   - El sistema valida el formato de los datos (`LoginRequest`: correo válido y obligatorio, contraseña obligatoria).  
   - Verifica que no se haya superado el límite de intentos (RN05).  
   - Busca en `usuario` por `correo` con consulta parametrizada y valida `activo = TRUE` (RN01).  
   - Verifica la contraseña con `Hash::check()` (BCRYPT).  
   - Si es incorrecta: incrementa el contador de intentos, registra el evento en `bitacora` y responde **401** con mensaje genérico (RN04).  
   - Si es correcta:
     - Limpia el contador de intentos.
     - **Carga de roles y permisos activos** (RN02). Si no tiene ninguno, se rechaza (RN03).
     - **Validación de extensión:** si es Médico, verifica `medico.activo`; si es Paciente, verifica `paciente.activo`.
     - **Regenera el ID de sesión** (evita la fijación de sesión).
     - Registra `LOGIN` en `bitacora` (usuario, IP de origen).
     - Retorna los datos básicos del usuario, sus roles, sus permisos y la ruta de redirección.

2. **Validar sesión**  
   - En cada petición protegida, el middleware `auth:sanctum` valida la cookie de sesión.  
   - El middleware `permiso:<accion>` verifica que el usuario tenga el permiso requerido (403 en caso contrario).  
   - Al recargar la página, el frontend consulta `GET /api/auth/me` para recuperar el usuario, sus roles y permisos. Si la sesión expiró, responde **401** y el frontend redirige al login.

3. **Logout**  
   - El usuario pulsa "Cerrar sesión" (`POST /api/auth/logout`).  
   - El sistema registra `LOGOUT` en `bitacora`, invalida la sesión y regenera el token CSRF.  
   - El frontend limpia el estado de autenticación y redirige al login.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Campos vacíos o correo con formato inválido | **422** con el error de cada campo |
| E2 | Correo inexistente o contraseña incorrecta | **401** "Correo o contraseña incorrectos" |
| E3 | Usuario con `activo = FALSE` | **403** "Cuenta inactiva. Contacte con la administración" |
| E4 | Usuario sin roles activos, o médico/paciente con su registro de extensión inactivo | **403** "Su cuenta no tiene acceso habilitado" |
| E5 | Más de 5 intentos fallidos | **429** "Demasiados intentos. Intente nuevamente en N segundos" |
| E6 | Sesión expirada o inexistente al acceder a una ruta protegida | **401** y redirección al login |
| E7 | Usuario autenticado sin el permiso requerido | **403** "No tiene permisos para esta acción" |
| E8 | Error interno (BD caída, etc.) | **500** con mensaje genérico. El detalle solo se escribe en el log del servidor |

### Endpoints (API REST)
| Método | Ruta | Protección | Descripción |
|--------|------|------------|-------------|
| GET | `/sanctum/csrf-cookie` | Pública | Inicializa la protección CSRF |
| POST | `/api/auth/login` | Pública + límite de intentos | Inicia la sesión |
| GET | `/api/auth/me` | `auth:sanctum` | Devuelve el usuario autenticado, sus roles y permisos |
| POST | `/api/auth/logout` | `auth:sanctum` | Cierra la sesión |

### Componentes de implementación
- **Backend (`app/Modules/ModuloSeguridad`):** `Models/Usuario.php` (autenticable, mapea `correo`/`contrasena`), `Models/Rol.php`, `Models/Permiso.php`, `Models/Bitacora.php`, `Requests/LoginRequest.php`, `Repositories/UsuarioRepository.php`, `Services/AuthService.php`, `Services/BitacoraService.php`, `Controllers/AuthController.php`, `Resources/UsuarioResource.php`, `Exceptions/AutenticacionException.php`, `Middleware/VerificarPermiso.php` (alias `permiso`), `routes.php`.
- **Backend compartido:** `app/Shared/Http/ApiResponse.php` (formato `{ success, message, data | errors }`), `app/Shared/Support/ScriptSqlClinico.php` (carga `Base de Datos/script.sql` en migración y seeder), manejo de excepciones de la API en `bootstrap/app.php`.
- **Frontend (`src/`):** `core/api/http-client.js` (Axios con cookie de sesión + CSRF), `core/api/error-handler.js`, `modules/ModuloSeguridad/services/auth.service.js`, `context/AuthContext.jsx` (incluye `canAccess(accion)`), `hooks/useAuth.js`, `components/ProtectedRoute.jsx` (RBAC por rol) y `PublicRoute.jsx`, `pages/LoginPage/` (`LoginPage.js` lógica + `LoginPage.view.jsx` vista + `index.js`), `pages/InicioPage/` (panel inicial por rol), `shared/components/layout/MainLayout.jsx` + `Sidebar.jsx`.

### Funcionalidades extras
- Recuperación de contraseña por correo (sub-caso "Recuperar acceso", con la tabla `password_reset_tokens` de Laravel).  
- Opción "Recordarme" (requiere agregar `remember_token` a `usuario`).  
- Historial de inicios de sesión visible en el perfil del usuario (CU05), leído desde `bitacora`.

### Tablas implicadas
| Tabla         | Lectura/Escritura | Motivo |
|---------------|-------------------|--------|
| `usuario`     | Lectura           | Validar credenciales (`correo`, `contrasena`) y estado `activo` |
| `rol_usuario` | Lectura           | Obtener los roles activos y vigentes del usuario |
| `rol`         | Lectura           | Nombre y estado del rol (define la redirección) |
| `rol_permiso` | Lectura           | Permisos activos y vigentes de cada rol |
| `permiso`     | Lectura           | Acciones permitidas (`accion`) |
| `medico`      | Lectura           | Extensión obligatoria para el rol Médico |
| `paciente`    | Lectura           | Extensión obligatoria para el rol Paciente |
| `bitacora`    | Escritura         | Auditoría de `LOGIN`, `LOGIN_FALLIDO` y `LOGOUT` |
| `sessions`    | CRUD              | Tabla de infraestructura de Laravel (`SESSION_DRIVER=database`) |

### Dependencias y conexiones
- **Ciclo 1:** Necesita que existan usuarios con roles asignados (CU02) y roles con permisos (CU03). Mientras se construyen, los datos iniciales los aportan los **seeders** (rol, permiso, rol_permiso, usuarios de prueba).  
- **Corrección previa obligatoria:** El hash de contraseña de los usuarios de prueba en `script.sql` es inválido (59 caracteres en lugar de 60) y ningún usuario podría iniciar sesión. Los usuarios de prueba deben generarse en el seeder con `Hash::make('Password123!')`.  
- **Todos los ciclos:** Es la puerta de entrada a cualquier operación protegida. El middleware `permiso:<accion>` será reutilizado por todos los módulos, por lo que los permisos de cada CU deben existir en la tabla `permiso`.  
- **Ciclo 2 (CU06):** Los eventos `LOGIN`, `LOGIN_FALLIDO` y `LOGOUT` registrados aquí serán consultables desde la Bitácora de Auditoría.

---

## CU02 – Gestionar Usuarios y Asignar Roles  
**Actor:** Administrador (A)  
**Prioridad:** Alta  
**Módulo:** Módulo 1 – Seguridad, Usuarios y Configuración del Sistema (`ModuloSeguridad`)  
**Permiso requerido:** `usuarios_gestionar`

### Descripción funcional
Permite al Administrador **registrar, consultar, modificar, activar y desactivar** las cuentas de usuario del sistema y **asignarles uno o varios roles** (Administrador, Recepcionista, Médico, Paciente). Los usuarios nunca se eliminan físicamente: se desactivan, para conservar la integridad de citas, consultas y la bitácora.

**Reglas de negocio:**
- **RN01:** `ci` y `correo` son únicos en todo el sistema. El correo se guarda en minúsculas.
- **RN02:** La contraseña debe tener **mínimo 8 caracteres e incluir letras y números**. Se guarda con hash BCRYPT y nunca se devuelve en las respuestas.
- **RN03:** Todo usuario debe tener **al menos un rol** asignado.
- **RN04:** Los roles **Médico** y **Paciente** requieren su registro de extensión (`medico` / `paciente`). Por eso solo se pueden asignar a usuarios que ya lo tengan; las altas de médicos y pacientes se hacen desde CU08 (Gestionar Personal Médico) y CU11 (Gestionar Pacientes), que crean el usuario y su extensión en un solo paso.
- **RN05:** Quitar un rol no borra la fila de `rol_usuario`: se marca `activo = FALSE` (historial de asignaciones). Volver a asignarlo la reactiva con una nueva `fecha_asignacion`.
- **RN06:** El Administrador **no puede desactivarse a sí mismo ni quitarse su propio rol de Administrador**.
- **RN07:** Siempre debe existir **al menos un Administrador activo**.
- **RN08:** Al desactivar un usuario se **cierran todas sus sesiones activas** (se eliminan sus filas de `sessions`). Al restablecer su contraseña también pierde sus sesiones abiertas, porque Sanctum invalida las sesiones cuyo hash de contraseña cambió.
- **RN09:** Cada alta, modificación y cambio de estado se registra en `bitacora` con los datos anteriores y nuevos (sin la contraseña).

### Flujo principal (mínimo viable)
1. **Listar usuarios**  
   - Listado paginado (10 por página), ordenado por apellido y nombre.  
   - Búsqueda por **CI, nombre completo o correo** y filtros por **rol** y **estado** (activo/inactivo).  
   - Cada fila muestra nombre, correo, CI, roles activos y estado.

2. **Crear usuario**  
   - El Administrador pulsa "Nuevo usuario" e ingresa CI, nombre, apellido, correo, teléfono, sexo, contraseña y su confirmación, y selecciona los roles.  
   - El sistema valida los datos (RN01–RN04), crea el usuario con `activo = TRUE`, inserta sus filas en `rol_usuario` y registra `INSERT` en la bitácora, todo en una transacción.

3. **Editar usuario y asignar/quitar roles**  
   - Permite modificar los datos personales y los roles.  
   - La contraseña es opcional: si se deja en blanco se conserva la actual; si se ingresa, se restablece (RN02).  
   - El sistema aplica RN04–RN07, sincroniza `rol_usuario` (RN05) y registra `UPDATE` en la bitácora.

4. **Activar / desactivar usuario**  
   - El Administrador confirma la acción en un diálogo.  
   - Al desactivar se aplican RN06–RN08. Un usuario inactivo no puede iniciar sesión (CU01, E3).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Datos obligatorios vacíos o con formato inválido | **422** con el error de cada campo |
| E2 | CI o correo ya registrados | **422** "El CI ya está registrado" / "El correo ya está registrado" |
| E3 | Rol Médico o Paciente sin registro de extensión | **422** indicando que debe registrarse desde CU08 / CU11 |
| E4 | El Administrador intenta desactivarse o quitarse su rol de Administrador | **422** con el motivo |
| E5 | La operación dejaría al sistema sin Administradores activos | **422** "Debe existir al menos un administrador activo" |
| E6 | Usuario inexistente | **404** "Recurso no encontrado" |
| E7 | Usuario sin el permiso `usuarios_gestionar` | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`) y el permiso `usuarios_gestionar`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/usuarios?buscar=&rol=&activo=&page=` | Lista paginada con filtros |
| GET | `/api/usuarios/{id}` | Detalle de un usuario con sus roles |
| POST | `/api/usuarios` | Crea un usuario y asigna sus roles |
| PUT | `/api/usuarios/{id}` | Modifica datos, contraseña (opcional) y roles |
| PATCH | `/api/usuarios/{id}/estado` | Activa o desactiva (`{ "activo": true \| false }`) |
| GET | `/api/roles?activos=1` | Roles activos para el formulario (definido en CU03) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloSeguridad`):** `Controllers/UsuarioController.php`, `Controllers/RolController.php`, `Requests/UsuarioRequest.php` (reglas comunes) + `UsuarioCreateRequest.php` + `UsuarioUpdateRequest.php` (más `app/Shared/Http/Requests/CambiarEstadoRequest.php`), `Services/UsuarioService.php`, `Repositories/UsuarioRepository.php` (búsqueda paginada, sincronización de roles, cierre de sesiones), `Resources/UsuarioResource.php`.
- **Frontend (`src/modules/ModuloSeguridad`):** `services/usuario.service.js`, `services/rol.service.js`, `hooks/useUsuarios.js`, `hooks/useRoles.js`, `pages/UsuariosPage/` (`UsuariosPage.js` + `UsuariosPage.view.jsx` + `components/UsuarioFormModal.jsx` + `components/CambiarEstadoModal.jsx`).
- **Frontend compartido (`src/shared`):** `components/ui/Modal.jsx`, `DataTable.jsx`, `Pagination.jsx`, `Badge.jsx`, `Select.jsx`, `hooks/useDebounce.js`.

### Funcionalidades extras
- Carga masiva de usuarios desde Excel/CSV (es el CU04, Ciclo 2).  
- Vista previa de los permisos efectivos que hereda un usuario.  
- Fecha de expiración por rol asignado (la columna `rol_usuario.fecha_expiracion` ya existe y CU01 la respeta).

### Tablas implicadas
| Tabla          | Operación | Motivo |
|----------------|-----------|--------|
| `usuario`      | Inserción, lectura, actualización | Entidad principal (sin borrado físico) |
| `rol`          | Lectura   | Roles disponibles para asignar |
| `rol_usuario`  | Inserción, actualización (activar/desactivar) | Asignación de roles con historial |
| `medico` / `paciente` | Lectura | Verificar la extensión exigida por RN04 |
| `sessions`     | Eliminación | Cierre forzado de sesiones al desactivar (RN08) |
| `bitacora`     | Inserción | Auditoría de cada operación (RN09) |

### Dependencias y conexiones
- **Ciclo 1:** Depende de CU01 (sesión y middleware `permiso`). CU03 (Roles y Permisos) ampliará el endpoint `/api/roles`. CU08 y CU11 reutilizarán `UsuarioService` para crear el usuario junto con su extensión `medico` / `paciente`.  
- **Ciclo 2:** CU04 (Carga por lotes) reutilizará las mismas validaciones de `UsuarioRequest`. CU06 (Bitácora) consultará los registros `INSERT` / `UPDATE` que genera este CU.

---

## CU03 – Gestionar Roles y Asignar Permisos  
**Actor:** Administrador (A)  
**Prioridad:** Alta  
**Módulo:** Módulo 1 – Seguridad, Usuarios y Configuración del Sistema (`ModuloSeguridad`)  
**Permiso requerido:** `roles_gestionar` (nuevo, ver Dependencias)

### Descripción funcional
Permite al Administrador **crear, modificar, activar y desactivar roles** y definir **qué permisos otorga cada uno** mediante una lista de permisos agrupados por módulo. Los permisos son el catálogo de acciones que el backend verifica en cada endpoint (middleware `permiso:<accion>`), por lo que **los define el equipo de desarrollo** y desde la interfaz solo se asignan a los roles.

**Reglas de negocio:**
- **RN01:** El nombre del rol es obligatorio, único sin distinguir mayúsculas y solo admite letras y espacios (máx. 50 caracteres).
- **RN02:** Los **roles del sistema** (Administrador, Medico, Recepcionista, Paciente) **no se pueden renombrar ni desactivar**, porque CU01 (redirección por rol) y CU02 (extensiones `medico` / `paciente`) dependen de sus nombres. Sí se pueden modificar su descripción y sus permisos.
- **RN03:** El rol **Administrador debe conservar siempre** los permisos `usuarios_gestionar` y `roles_gestionar`, para que nadie pueda quedar sin acceso a la administración del sistema.
- **RN04:** Los roles no se eliminan: se desactivan. Un rol inactivo deja de otorgar permisos de inmediato (CU01, RN02) y no se puede asignar en CU02.
- **RN05:** Quitar un permiso no borra la fila de `rol_permiso`: se marca `activo = FALSE` (historial). Volver a asignarlo la reactiva con una nueva `fecha_asignacion`.
- **RN06:** Los cambios de permisos se aplican **en la siguiente petición** de cada usuario afectado, porque el middleware recalcula los permisos en cada solicitud.
- **RN07:** Cada alta, modificación y cambio de estado se registra en `bitacora` con los datos anteriores y nuevos (incluida la lista de permisos).

### Flujo principal (mínimo viable)
1. **Listar roles**  
   - Muestra nombre, descripción, si es rol del sistema, cantidad de usuarios con el rol activo, cantidad de permisos y estado.

2. **Crear rol**  
   - El Administrador ingresa nombre y descripción y marca los permisos en la lista agrupada por módulo (con "Seleccionar todos" por grupo).  
   - El sistema valida (RN01), crea el rol activo, inserta sus filas en `rol_permiso` y registra `INSERT` en la bitácora, en una transacción.

3. **Editar rol y asignar/quitar permisos**  
   - Permite cambiar nombre (salvo roles del sistema), descripción y permisos.  
   - El sistema aplica RN02, RN03 y RN05 y registra `UPDATE` en la bitácora.

4. **Activar / desactivar rol**  
   - Confirmación previa que indica cuántos usuarios tienen el rol. Aplica RN02 y RN04.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Nombre vacío, con caracteres no permitidos o demasiado largo | **422** con el error del campo |
| E2 | Ya existe un rol con ese nombre | **422** "Ya existe un rol con ese nombre" |
| E3 | Renombrar o desactivar un rol del sistema | **422** con el motivo |
| E4 | Quitar al Administrador un permiso obligatorio | **422** indicando los permisos que debe conservar |
| E5 | Permiso inexistente o inactivo | **422** "Uno de los permisos seleccionados no existe o está inactivo" |
| E6 | Rol inexistente | **404** "Recurso no encontrado" |
| E7 | Usuario sin el permiso `roles_gestionar` | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| GET | `/api/roles?activos=1` | `usuarios_gestionar` o `roles_gestionar` | Lista de roles con sus permisos y conteo de usuarios (`activos=1` para los formularios de CU02) |
| POST | `/api/roles` | `roles_gestionar` | Crea un rol con sus permisos |
| PUT | `/api/roles/{id}` | `roles_gestionar` | Modifica nombre, descripción y permisos |
| PATCH | `/api/roles/{id}/estado` | `roles_gestionar` | Activa o desactiva (`{ "activo": true \| false }`) |
| GET | `/api/permisos` | `roles_gestionar` | Catálogo de permisos activos |

### Componentes de implementación
- **Backend (`app/Modules/ModuloSeguridad`):** `Controllers/RolController.php`, `Controllers/PermisoController.php`, `Requests/RolRequest.php` (con la regla compartida `app/Shared/Validation/UnicoSinMayusculas.php`), `app/Shared/Http/Requests/CambiarEstadoRequest.php` (compartido con CU02 y CU07), `Services/RolService.php`, `Repositories/RolRepository.php` (conteos y sincronización de permisos), `Resources/RolResource.php`, `Resources/PermisoResource.php`; `database/seeders/PermisosSistemaSeeder.php`.
- **Frontend (`src/modules/ModuloSeguridad`):** `services/rol.service.js`, `services/permiso.service.js`, `hooks/useRoles.js`, `hooks/usePermisos.js`, `pages/RolesPage/` (`RolesPage.js` + `RolesPage.view.jsx` + `components/RolFormModal.jsx` + `components/CambiarEstadoRolModal.jsx`).
- **Frontend compartido:** `shared/components/ui/ConfirmModal.jsx` (confirmaciones reutilizadas por CU02 y CU03).

### Funcionalidades extras
- Fecha de expiración por permiso asignado (la columna `rol_permiso.fecha_expiracion` ya existe y CU01 la respeta).  
- Duplicar un rol existente para crear uno similar.  
- Vista de "permisos efectivos" de un usuario que combina todos sus roles.

### Tablas implicadas
| Tabla          | Operación | Motivo |
|----------------|-----------|--------|
| `rol`          | Inserción, lectura, actualización | Gestión de roles (sin borrado físico) |
| `permiso`      | Lectura   | Catálogo de acciones disponibles |
| `rol_permiso`  | Inserción, actualización (activar/desactivar) | Asociación permisos ↔ rol con historial |
| `rol_usuario`  | Lectura   | Conteo de usuarios por rol |
| `bitacora`     | Inserción | Auditoría (RN07) |

### Dependencias y conexiones
- **Nuevo permiso `roles_gestionar`:** el script base no tenía un permiso para este CU. Lo crea `PermisosSistemaSeeder` (que puede ejecutarse varias veces sin duplicar) y lo asigna al Administrador. En una base existente: `php artisan db:seed --class=PermisosSistemaSeeder`. Los permisos que necesiten los siguientes CU se agregarán en ese mismo seeder.  
- **Ciclo 1:** CU02 usa `/api/roles?activos=1` para asignar roles. CU01 aplica los permisos asignados aquí en cada petición.  
- **Ciclo 2:** CU06 (Bitácora) consultará los registros que genera este CU.

---

## CU05 – Configurar Perfil Personal  
**Actor:** Administrador (A), Recepcionista (R), Médico (M), Paciente (P)  
**Prioridad:** Alta  
**Módulo:** Módulo 1 – Seguridad, Usuarios y Configuración del Sistema (`ModuloSeguridad`)  
**Permiso requerido:** ninguno (solo sesión activa). Cada usuario accede únicamente a **su propio** perfil.

### Descripción funcional
Permite a cualquier usuario autenticado **consultar y modificar sus datos de contacto**, **cambiar su contraseña**, **configurar cómo recibe las notificaciones** de sus citas y **revisar la seguridad de su cuenta** (sesiones abiertas y accesos recientes), pudiendo cerrar las sesiones de otros dispositivos.

**Reglas de negocio:**
- **RN01:** El usuario puede modificar nombre, apellido, correo, teléfono y sexo. El **CI no se puede modificar** desde el perfil (es su identificador: lo corrige el Administrador en CU02). Los **roles tampoco**.
- **RN02:** Cambiar el **correo** (que es el dato de inicio de sesión) exige confirmar la **contraseña actual**. El correo sigue siendo único en el sistema.
- **RN03:** Para cambiar la contraseña se exige la **contraseña actual**. La nueva debe cumplir la política de CU02 (mínimo 8 caracteres con letras y números) y ser **distinta de la actual**.
- **RN04:** Al cambiar la contraseña, la sesión actual se mantiene y **todas las demás sesiones del usuario se cierran**.
- **RN05:** Máximo **5 intentos por minuto** en las operaciones que verifican la contraseña actual (protección contra adivinación).
- **RN06:** Las preferencias de notificación son: recibir recordatorios por **correo**, por **notificación push** y la **anticipación** del recordatorio (2, 12, 24 o 48 horas). Si el usuario nunca las configuró se usan los valores por defecto: correo sí, push sí, 24 horas.
- **RN07:** El usuario ve sus sesiones abiertas (dispositivo, IP, última actividad) **sin exponer los identificadores de sesión**, y puede cerrar todas las demás.
- **RN08:** Los cambios de datos y de contraseña se registran en `bitacora` (la contraseña nunca se guarda en ella; se anota "cambiada").

### Flujo principal (mínimo viable)
1. **Ver perfil**: datos personales, roles (solo lectura) y fecha de registro.
2. **Editar datos personales**: el usuario modifica sus datos y guarda. Si cambió el correo, el sistema le pide su contraseña actual (RN02). Se registra `UPDATE` en la bitácora.
3. **Cambiar contraseña**: ingresa la actual, la nueva y su confirmación. El sistema valida (RN03, RN05), actualiza el hash BCRYPT, cierra las demás sesiones (RN04) y registra `UPDATE` en la bitácora.
4. **Configurar notificaciones**: activa o desactiva los canales y elige la anticipación (RN06).
5. **Seguridad de la cuenta**: consulta sus sesiones abiertas y sus últimos 10 accesos (inicios de sesión correctos, fallidos y rechazados), y puede "Cerrar las demás sesiones" (RN07).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Datos con formato inválido o vacíos | **422** con el error de cada campo |
| E2 | El correo ya pertenece a otro usuario | **422** "El correo ya está registrado" |
| E3 | Cambia el correo sin confirmar su contraseña, o la contraseña actual es incorrecta | **422** "La contraseña actual no es correcta" |
| E4 | La nueva contraseña es igual a la actual o no cumple la política | **422** con el motivo |
| E5 | Más de 5 intentos por minuto al verificar la contraseña | **429** "Demasiados intentos. Intente nuevamente en N segundos" |
| E6 | Sesión expirada | **401** y redirección al login (CU01, E6) |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`) y operan siempre sobre el usuario autenticado.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/perfil` | Datos personales, roles y preferencias de notificación |
| PUT | `/api/perfil` | Modifica los datos personales (`contrasena_actual` si cambia el correo) |
| PUT | `/api/perfil/contrasena` | Cambia la contraseña (límite de 5 intentos por minuto) |
| PUT | `/api/perfil/preferencias` | Guarda las preferencias de notificación |
| GET | `/api/perfil/sesiones` | Sesiones abiertas del usuario |
| DELETE | `/api/perfil/sesiones` | Cierra todas las sesiones excepto la actual |
| GET | `/api/perfil/accesos` | Últimos 10 accesos registrados en la bitácora |

### Componentes de implementación
- **Backend (`app/Modules/ModuloSeguridad`):** `Controllers/PerfilController.php`, `Requests/PerfilUpdateRequest.php`, `Requests/CambiarContrasenaRequest.php`, `Requests/PreferenciasRequest.php`, `Services/PerfilService.php`, `Models/PreferenciaNotificacion.php`, `Resources/PerfilResource.php`, `Resources/SesionResource.php`; migración `create_preferencia_notificacion_table`.
- **Frontend (`src/modules/ModuloSeguridad`):** `services/perfil.service.js`, `pages/PerfilPage/` (`PerfilPage.js` + `PerfilPage.view.jsx` + `components/DatosPersonalesForm.jsx`, `CambiarContrasenaForm.jsx`, `PreferenciasForm.jsx`, `SeguridadCuenta.jsx`).
- **Frontend compartido:** `shared/components/ui/Toggle.jsx` (interruptor tipo cápsula del documento de diseño).

### Funcionalidades extras
- Foto de perfil (requiere una columna nueva en `usuario` y almacenamiento de archivos).  
- Verificar el nuevo correo con un enlace antes de aplicar el cambio.  
- Autenticación en dos pasos.

### Tablas implicadas
| Tabla       | Operación | Motivo |
|-------------|-----------|--------|
| `usuario`   | Lectura y actualización | Datos propios y contraseña |
| `rol_usuario` / `rol` | Lectura | Mostrar los roles del usuario |
| `preferencia_notificacion` | Inserción y actualización | Preferencias de notificación (tabla nueva) |
| `sessions`  | Lectura y eliminación | Sesiones abiertas y cierre de las demás |
| `bitacora`  | Lectura e inserción | Accesos recientes y auditoría (RN08) |

### Dependencias y conexiones
- **Tabla nueva `preferencia_notificacion`:** el script base no tiene dónde guardar preferencias. Se crea con una migración de Laravel (`id_usuario` como clave primaria y foránea a `usuario`). **Conviene agregarla también a `Base de Datos/script.sql`** para que el script siga siendo la referencia completa del esquema.  
- **Ciclo 1:** Depende de CU01 (sesión). Reutiliza la política de contraseñas y la bitácora de CU02.  
- **Ciclo 2 (CU20):** El envío de recordatorios deberá respetar `preferencia_notificacion` (canales y anticipación).  
- **Efecto en CU02:** como Sanctum invalida las sesiones cuyo hash de contraseña cambió, cuando el Administrador restablece la contraseña de un usuario, ese usuario también pierde sus sesiones abiertas.

---

## CU07 – Gestionar Especialidades Médicas  
**Actor:** Administrador (A)  
**Prioridad:** Alta  
**Módulo:** Módulo 2 – Gestión de Personal Médico y Especialidades (`ModuloPersonalMedico`)  
**Permiso requerido:** `medicos_gestionar` para registrar, modificar y cambiar el estado. La consulta está disponible para cualquier usuario autenticado.

### Descripción funcional
Permite al Administrador mantener el **catálogo de especialidades** de la clínica (Medicina General, Pediatría, Cardiología, etc.): registrarlas, modificarlas, activarlas y desactivarlas, y ver qué médicos ejercen cada una. Es la base del Módulo 2: los médicos (CU08) se asocian a especialidades y los pacientes eligen una especialidad al reservar una cita (CU13).

**Reglas de negocio:**
- **RN01:** El nombre es obligatorio, **único sin distinguir mayúsculas**, solo admite letras (con tildes) y espacios, máximo 100 caracteres. La descripción es opcional (máx. 500 caracteres).
- **RN02:** Las especialidades **no se eliminan**: se desactivan, porque están vinculadas a médicos (`medico_especialidad`) y a su historial de atención.
- **RN03:** Una especialidad **inactiva** no se ofrece al asignar especialidades a un médico (CU08) ni al reservar citas (CU13). Los médicos que ya la tenían asignada **conservan la relación**.
- **RN04:** Antes de desactivar se informa cuántos **médicos activos** ejercen la especialidad.
- **RN05:** El listado es de consulta pública para usuarios autenticados (lo necesitan CU08 y CU13), pero solo quien tiene `medicos_gestionar` puede modificarlo.
- **RN06:** Cada alta, modificación y cambio de estado se registra en `bitacora` con los datos anteriores y nuevos.

### Flujo principal (mínimo viable)
1. **Listar especialidades**: nombre, descripción, cantidad de médicos activos y estado, con búsqueda por nombre o descripción y filtro por estado.
2. **Registrar especialidad**: el Administrador ingresa nombre y descripción. El sistema valida (RN01), la crea activa y registra `INSERT` en la bitácora.
3. **Modificar especialidad**: cambia nombre y descripción. El formulario muestra los médicos que la ejercen (solo lectura; se asignan en CU08). Se registra `UPDATE` en la bitácora.
4. **Activar / desactivar**: confirmación que indica cuántos médicos activos la tienen (RN03, RN04).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Nombre vacío, con números o símbolos, o demasiado largo | **422** con el error del campo |
| E2 | Ya existe una especialidad con ese nombre (sin importar mayúsculas) | **422** "Ya existe una especialidad con ese nombre" |
| E3 | Especialidad inexistente | **404** "Recurso no encontrado" |
| E4 | Usuario sin el permiso `medicos_gestionar` intenta modificar | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| GET | `/api/especialidades?buscar=&activo=` | Sesión activa | Lista con médicos y conteo de médicos activos |
| POST | `/api/especialidades` | `medicos_gestionar` | Registra una especialidad |
| PUT | `/api/especialidades/{id}` | `medicos_gestionar` | Modifica nombre y descripción |
| PATCH | `/api/especialidades/{id}/estado` | `medicos_gestionar` | Activa o desactiva (`{ "activo": true \| false }`) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloPersonalMedico`):** `Models/Especialidad.php`, `Models/Medico.php` (relación con especialidades), `Controllers/EspecialidadController.php`, `Requests/EspecialidadRequest.php`, `Services/EspecialidadService.php`, `Resources/EspecialidadResource.php`, `routes.php`.
- **Backend compartido (`app/Shared`):** `Http/Requests/CambiarEstadoRequest.php` (usado por CU02, CU03 y CU07) y `Validation/UnicoSinMayusculas.php` (regla de unicidad sin distinguir mayúsculas, usada por CU03 y CU07).
- **Frontend (`src/modules/ModuloPersonalMedico`):** `services/especialidades.service.js`, `hooks/useEspecialidades.js`, `pages/EspecialidadesPage/` (`EspecialidadesPage.js` + `EspecialidadesPage.view.jsx` + `components/EspecialidadFormModal.jsx` + `components/CambiarEstadoEspecialidadModal.jsx`).

### Funcionalidades extras
- Ícono o color por especialidad para identificarla en la agenda.  
- Precio base de la consulta por especialidad (útil para CU24, Pasarela de Pago).

### Tablas implicadas
| Tabla                 | Operación | Motivo |
|-----------------------|-----------|--------|
| `especialidad`        | Inserción, lectura, actualización | Catálogo (sin borrado físico) |
| `medico_especialidad` | Lectura   | Médicos que ejercen cada especialidad |
| `medico` / `usuario`  | Lectura   | Nombre y estado de esos médicos |
| `bitacora`            | Inserción | Auditoría (RN06) |

### Dependencias y conexiones
- **Ciclo 1:** CU08 (Gestionar Personal Médico) asigna especialidades **activas** a los médicos. CU09 (Horarios) y CU13 (Citas) usan la especialidad para buscar médicos disponibles.  
- **Datos iniciales:** el script carga 5 especialidades (Medicina General, Pediatría, Ginecología, Odontología, Cardiología); Odontología no tiene médicos asignados.

---

## CU08 – Gestionar Personal Médico  
**Actor:** Administrador (A)  
**Prioridad:** Alta  
**Módulo:** Módulo 2 – Gestión de Personal Médico y Especialidades (`ModuloPersonalMedico`)  
**Permiso requerido:** `medicos_gestionar`

### Descripción funcional
Permite al Administrador **registrar el legajo profesional de los médicos**: sus datos personales y de acceso, código, salario y especialidades; además de **modificarlos, activarlos y desactivarlos**. El registro crea en **un solo paso** el `usuario`, su extensión `medico`, sus especialidades y le asigna el rol **Medico**, evitando el doble trámite "crear usuario y luego crear médico" (por eso CU02 no permite asignar el rol Medico directamente).

**Reglas de negocio:**
- **RN01:** Los datos personales siguen las reglas de CU02: CI y correo únicos, correo en minúsculas, contraseña de mínimo 8 caracteres con letras y números (hash BCRYPT).
- **RN02:** El **código** del médico lo genera el sistema de forma correlativa (`MED-001`, `MED-002`, …) y no se modifica.
- **RN03:** El **salario** es obligatorio, mayor o igual a 0 y con hasta 2 decimales (moneda: Bs).
- **RN04:** Todo médico debe tener **al menos una especialidad**. Solo se pueden **agregar especialidades activas**; las inactivas que ya tenía asignadas se pueden conservar (CU07, RN03).
- **RN05:** El registro crea `usuario` + `medico` + `medico_especialidad` + `rol_usuario` (rol Medico) en **una sola transacción**: o se guarda todo o nada.
- **RN06:** Los médicos no se eliminan: se desactivan (`medico.activo = FALSE`). **No se puede desactivar a un médico con citas pendientes** (Programada o Confirmada, de hoy en adelante); primero deben reprogramarse o cancelarse (CU14).
- **RN07:** Un médico inactivo no puede ingresar con el rol Medico (CU01, RN02). Si no tiene otro rol habilitado, se **cierran sus sesiones** al desactivarlo. La cuenta `usuario` no se desactiva, porque puede tener otros roles.
- **RN08:** Cada alta, modificación y cambio de estado se registra en `bitacora` (sin la contraseña).

### Flujo principal (mínimo viable)
1. **Listar médicos**: código, nombre, correo, especialidades, salario y estado; paginado (10 por página), con búsqueda por nombre, CI, código o correo y filtros por especialidad y estado.
2. **Registrar médico**: el Administrador ingresa datos personales, contraseña inicial, salario y especialidades. El sistema valida (RN01–RN04), genera el código y guarda todo en una transacción (RN05). Se registra `INSERT` en la bitácora.
3. **Modificar médico**: datos personales, contraseña (opcional, para restablecerla), salario y especialidades. Se registra `UPDATE` en la bitácora.
4. **Activar / desactivar**: confirmación previa. Al desactivar se aplican RN06 y RN07.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Datos vacíos o con formato inválido | **422** con el error de cada campo |
| E2 | CI o correo ya registrados | **422** "El CI ya está registrado" / "El correo ya está registrado" |
| E3 | Sin especialidades, o se agrega una especialidad inactiva | **422** con el motivo |
| E4 | Desactivar un médico con citas pendientes | **422** "Tiene N citas pendientes. Reprográmelas o cancélelas antes de desactivarlo" |
| E5 | Médico inexistente | **404** "Recurso no encontrado" |
| E6 | Usuario sin el permiso `medicos_gestionar` | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
Todos requieren sesión (`auth:sanctum`) y el permiso `medicos_gestionar`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/medicos?buscar=&especialidad=&activo=&page=` | Lista paginada con filtros |
| GET | `/api/medicos/{id}` | Detalle del médico |
| POST | `/api/medicos` | Registra usuario + médico + especialidades + rol |
| PUT | `/api/medicos/{id}` | Modifica datos, contraseña (opcional), salario y especialidades |
| PATCH | `/api/medicos/{id}/estado` | Activa o desactiva (`{ "activo": true \| false }`) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloPersonalMedico`):** `Controllers/MedicoController.php`, `Requests/MedicoRequest.php` (extiende las reglas de `UsuarioRequest` de CU02) + `MedicoCreateRequest.php` + `MedicoUpdateRequest.php`, `Services/MedicoService.php`, `Resources/MedicoResource.php`; `app/Modules/ModuloOperacionCitas/Models/CitaMedica.php` (para contar citas pendientes).
- **Frontend (`src/modules/ModuloPersonalMedico`):** `services/medicos.service.js`, `hooks/useMedicos.js`, `pages/MedicosPage/` (`MedicosPage.js` + `MedicosPage.view.jsx` + `components/MedicoFormModal.jsx` + `components/CambiarEstadoMedicoModal.jsx`).
- **Frontend compartido:** `shared/utils/formatters.js` → `formatearMoneda()` (Bs); `ModuloSeguridad/components/CamposCuenta.jsx` (campos de datos personales y contraseña) y `ModuloSeguridad/utils/validacionCuenta.js`, compartidos con el formulario de usuarios de CU02.

### Funcionalidades extras
- Convertir en médico a un usuario que ya existe (por ejemplo, un recepcionista que se titula).  
- Matrícula profesional, fecha de contratación y foto del legajo.  
- Historial de cambios de salario.

### Tablas implicadas
| Tabla                 | Operación | Motivo |
|-----------------------|-----------|--------|
| `usuario`             | Inserción, lectura, actualización | Datos personales y de acceso |
| `medico`              | Inserción, lectura, actualización | Legajo: código, salario y estado |
| `medico_especialidad` | Inserción, eliminación | Especialidades del médico |
| `especialidad`        | Lectura   | Especialidades disponibles (CU07) |
| `rol` / `rol_usuario` | Lectura, inserción | Asignación del rol Medico |
| `cita_medica`         | Lectura   | Citas pendientes (RN06) |
| `sessions`            | Eliminación | Cierre de sesiones al desactivar (RN07) |
| `bitacora`            | Inserción | Auditoría (RN08) |

### Dependencias y conexiones
- **Ciclo 1:** Depende de CU07 (especialidades) y reutiliza la validación y la sincronización de roles de CU02. CU09 (Horarios) define la grilla de atención de cada médico registrado aquí; CU13 busca médicos activos por especialidad.  
- **Ciclo 2:** CU10 (Licencias y bloqueos de agenda) trabaja sobre los médicos registrados aquí.

---

## CU09 – Gestionar Horarios y Disponibilidad Médica  
**Actor:** Administrador (A), Médico (M)  
**Prioridad:** Alta  
**Módulo:** Módulo 2 – Gestión de Personal Médico y Especialidades (`ModuloPersonalMedico`)  
**Permiso requerido:** `medicos_gestionar` para gestionar los horarios de cualquier médico; el **Médico** gestiona **solo los suyos** (sin permiso adicional). La consulta de disponibilidad está abierta a cualquier usuario autenticado.

### Descripción funcional
Permite definir la **grilla semanal de atención** de cada médico: los bloques de horario por día de la semana (hora de inicio, hora de fin y duración de cada turno). A partir de esa grilla el sistema calcula la **disponibilidad** de un médico en una fecha concreta: los turnos libres, los ocupados por citas y los bloqueados por licencias. Ese cálculo es el que usará CU13 para ofrecer horarios al reservar una cita.

**Reglas de negocio:**
- **RN01:** Cada bloque tiene día de la semana (1 = lunes … 7 = domingo), hora de inicio y de fin (formato 24 h, inicio < fin) y duración del turno de **15, 20, 30, 45 o 60 minutos**. El bloque debe alcanzar para **al menos un turno**; si el rango no es múltiplo de la duración, el último tramo incompleto no se ofrece.
- **RN02:** Los bloques de un mismo médico **no se pueden superponer** en el mismo día (sí pueden ser contiguos: 08:00–12:00 y 12:00–14:00).
- **RN03:** Un bloque **no se puede eliminar ni modificar** si dejaría **fuera de horario citas pendientes** (Programada o Confirmada, de hoy en adelante) que caen en él; primero deben reprogramarse (CU14).
- **RN04:** Los bloques se eliminan físicamente (no tienen registros que dependan de ellos: las citas guardan su propia fecha y hora). El historial queda en la bitácora.
- **RN05:** El Médico solo puede ver y modificar **su propia** grilla; el Administrador, la de cualquier médico.
- **RN06 – Cálculo de disponibilidad** para una fecha: se generan los turnos de los bloques del día de la semana y cada turno queda como:
  - **ocupado:** se superpone con una cita no cancelada (Programada, Confirmada, Atendida o Inasistencia);
  - **bloqueado:** se superpone con una indisponibilidad del médico (`indisponibilidad_medico`, gestionada en CU10);
  - **pasado:** la fecha es hoy y el turno ya comenzó;
  - **disponible:** en cualquier otro caso.  
  Un médico inactivo no tiene turnos disponibles. Solo se consultan fechas desde hoy hasta 90 días adelante.
- **RN07:** Cada alta, modificación y eliminación de un bloque se registra en `bitacora`.

### Flujo principal (mínimo viable)
1. **Ver grilla semanal**: el Administrador entra a "Horarios" desde el listado de médicos (CU08); el Médico, desde "Mis horarios". Se muestran los 7 días con sus bloques y la cantidad de turnos de cada uno.
2. **Agregar bloque**: elige el día, las horas y la duración. El sistema valida (RN01, RN02) y registra `INSERT` en la bitácora.
3. **Modificar bloque**: cambia día, horas o duración. Se valida RN01–RN03 y se registra `UPDATE`.
4. **Eliminar bloque**: confirmación previa; se valida RN03 y se registra `DELETE`.
5. **Consultar disponibilidad**: elige una fecha y ve los turnos con su estado (RN06).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Día, horas o duración inválidos; fin anterior al inicio; bloque menor que un turno | **422** con el error del campo |
| E2 | El bloque se superpone con otro del mismo día | **422** "Se superpone con el bloque HH:MM–HH:MM del <día>" |
| E3 | Modificar o eliminar un bloque con citas pendientes que quedarían fuera de horario | **422** "Hay N citas pendientes en este bloque (próxima: fecha y hora)…" |
| E4 | Un Médico intenta gestionar la grilla de otro médico | **403** "No tiene permisos para esta acción" |
| E5 | Médico o bloque inexistente, o el bloque no pertenece a ese médico | **404** "Recurso no encontrado" |
| E6 | Fecha de disponibilidad pasada o a más de 90 días | **422** con el motivo |

### Endpoints (API REST)
| Método | Ruta | Acceso | Descripción |
|--------|------|--------|-------------|
| GET | `/api/medicos/{id}/horarios` | `medicos_gestionar` o el propio médico | Datos básicos del médico y su grilla |
| POST | `/api/medicos/{id}/horarios` | `medicos_gestionar` o el propio médico | Agrega un bloque |
| PUT | `/api/medicos/{id}/horarios/{horario}` | `medicos_gestionar` o el propio médico | Modifica un bloque |
| DELETE | `/api/medicos/{id}/horarios/{horario}` | `medicos_gestionar` o el propio médico | Elimina un bloque |
| GET | `/api/medicos/{id}/disponibilidad?fecha=AAAA-MM-DD` | Sesión activa | Turnos del día con su estado (RN06) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloPersonalMedico`):** `Models/HorarioAtencion.php`, `Models/IndisponibilidadMedico.php` (lectura; su gestión es CU10), `Controllers/HorarioController.php`, `Requests/HorarioRequest.php`, `Services/HorarioService.php`, `Services/DisponibilidadService.php` (cálculo de turnos), `Resources/HorarioResource.php`.
- **Frontend (`src/modules/ModuloPersonalMedico`):** `services/horarios.service.js`, `hooks/useHorarios.js`, `components/HorarioGrid.jsx` (grilla semanal reutilizable), `pages/HorariosPage/` (`HorariosPage.js` + `HorariosPage.view.jsx` + `components/HorarioFormModal.jsx` + `components/DisponibilidadDia.jsx`). Rutas: `/admin/medicos/:id/horarios` (Administrador) y `/medico/horarios` (Médico).

### Funcionalidades extras
- Copiar la grilla de un día a otros días.  
- Duración de turno distinta por especialidad.  
- Vigencia de la grilla (desde / hasta) para cambios de horario planificados.

### Tablas implicadas
| Tabla                     | Operación | Motivo |
|---------------------------|-----------|--------|
| `horario_atencion`        | Inserción, lectura, actualización, eliminación | Grilla semanal |
| `medico` / `usuario`      | Lectura   | Datos del médico y estado |
| `cita_medica`             | Lectura   | Turnos ocupados (RN06) y citas afectadas (RN03) |
| `indisponibilidad_medico` | Lectura   | Turnos bloqueados por licencias (RN06) |
| `bitacora`                | Inserción | Auditoría (RN07) |

### Dependencias y conexiones
- **Ciclo 1:** Depende de CU08 (médicos). CU13 (Gestionar Citas) usará el endpoint de disponibilidad para ofrecer solo turnos **disponibles**; CU14 (Reprogramar) lo usará para elegir el nuevo turno.  
- **Ciclo 2:** CU10 (Licencias y bloqueos) registra las indisponibilidades que este CU ya considera en el cálculo.  
- **Zona horaria:** la disponibilidad se calcula en `America/La_Paz` (`APP_TIMEZONE`). La indisponibilidad de ejemplo del script se cargó con `+00`, por lo que en hora local abarca de 04:00 a 14:00.

---

## CU11 – Gestionar Pacientes  
**Actor:** Administrador (A), Recepcionista (R), Paciente (P)  
**Prioridad:** Alta  
**Módulo:** Módulo 3 – Gestión de Pacientes e Información Médica Base (`ModuloPacientes`)  
**Permiso requerido:** `pacientes_gestionar` (Administrador y Recepcionista) para gestionar a cualquier paciente. El Paciente **se registra solo** (sin sesión) y gestiona **su propia ficha**.

### Descripción funcional
Permite **registrar, buscar, modificar, activar y desactivar pacientes**, junto con su **contacto de emergencia** y su información médica base (fecha de nacimiento y tipo de sangre). Igual que CU08, el registro crea en un solo paso el `usuario`, su extensión `paciente` y le asigna el rol **Paciente**. Además, cualquier persona puede **crear su propia cuenta de paciente** desde la pantalla de inicio de sesión, y cada paciente puede actualizar su tipo de sangre y su contacto de emergencia desde "Mi ficha médica".

**Reglas de negocio:**
- **RN01:** Los datos personales y la contraseña siguen las reglas de CU02 (CI y correo únicos, correo en minúsculas, contraseña de mínimo 8 caracteres con letras y números).
- **RN02:** El **código** del paciente lo genera el sistema de forma correlativa (`PAC-0001`, `PAC-0002`, …) y no se modifica.
- **RN03:** La **fecha de nacimiento** es obligatoria, anterior a hoy y posterior a 1900. La **edad** se calcula, no se guarda.
- **RN04:** El **tipo de sangre** es opcional y debe ser uno de: A+, A−, B+, B−, AB+, AB−, O+, O−.
- **RN05:** El **contacto de emergencia** es opcional; si se registra, requiere nombre, apellido, parentesco y teléfono. Quitarlo elimina el registro del contacto (cada contacto pertenece a un solo paciente).
- **RN06:** El registro crea `usuario` + `contacto_de_emergencia` + `paciente` + `rol_usuario` (rol Paciente) en **una sola transacción**.
- **RN07 – Autorregistro:** es público, pero tiene un límite de **5 intentos por minuto por IP**. Al terminar, el paciente queda con la sesión iniciada y entra a su panel.
- **RN08 – Mi ficha médica:** el Paciente solo puede modificar **su tipo de sangre y su contacto de emergencia**. Sus datos personales se editan en CU05 (Perfil), y la fecha de nacimiento solo la corrige el personal (es un dato de identificación).
- **RN09:** Los pacientes no se eliminan: se desactivan. **No se puede desactivar a un paciente con citas pendientes** (Programada o Confirmada, de hoy en adelante). Si no tiene otro rol habilitado, se cierran sus sesiones.
- **RN10:** Cada alta, autorregistro, modificación y cambio de estado se registra en `bitacora` (sin la contraseña).

### Flujo principal (mínimo viable)
1. **Listar y buscar pacientes** (Administrador / Recepcionista): código, nombre, CI, edad, tipo de sangre, contacto de emergencia y estado; paginado, con búsqueda por **CI, nombre, código o correo** y filtro por estado.
2. **Registrar paciente** (personal): datos personales, contraseña inicial, fecha de nacimiento, tipo de sangre y contacto de emergencia opcional. Se aplican RN01–RN06 y se registra `INSERT` en la bitácora.
3. **Modificar paciente** (personal): todos los datos anteriores; la contraseña es opcional (restablecer). Se registra `UPDATE`.
4. **Activar / desactivar** (personal): confirmación previa; se aplica RN09.
5. **Autorregistro** (público): desde "Crear cuenta" en el inicio de sesión. Se aplican RN01–RN07; se registra `REGISTRO` en la bitácora.
6. **Mi ficha médica** (Paciente): ve su código, edad y datos médicos base, y actualiza su tipo de sangre y su contacto de emergencia (RN08).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Datos vacíos o inválidos (fecha futura, tipo de sangre desconocido, contacto incompleto) | **422** con el error de cada campo |
| E2 | CI o correo ya registrados | **422** "El CI ya está registrado" / "El correo ya está registrado" |
| E3 | Desactivar un paciente con citas pendientes | **422** "Tiene N citas pendientes…" |
| E4 | Más de 5 autorregistros por minuto desde la misma IP | **429** "Demasiados intentos…" |
| E5 | Un usuario sin registro de paciente consulta "Mi ficha médica" | **404** "No tiene una ficha de paciente" |
| E6 | Paciente inexistente | **404** "Recurso no encontrado" |
| E7 | Usuario sin `pacientes_gestionar` intenta gestionar pacientes | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
| Método | Ruta | Acceso | Descripción |
|--------|------|--------|-------------|
| GET | `/api/pacientes?buscar=&activo=&page=` | `pacientes_gestionar` | Lista paginada con búsqueda |
| GET | `/api/pacientes/{id}` | `pacientes_gestionar` | Detalle del paciente |
| POST | `/api/pacientes` | `pacientes_gestionar` | Registra usuario + paciente + contacto + rol |
| PUT | `/api/pacientes/{id}` | `pacientes_gestionar` | Modifica el paciente |
| PATCH | `/api/pacientes/{id}/estado` | `pacientes_gestionar` | Activa o desactiva |
| POST | `/api/auth/registro` | Público (5 por minuto) | Autorregistro e inicio de sesión |
| GET | `/api/mi-ficha` | Sesión del propio paciente | Ficha médica propia |
| PUT | `/api/mi-ficha` | Sesión del propio paciente | Actualiza tipo de sangre y contacto de emergencia |

### Componentes de implementación
- **Backend (`app/Modules/ModuloPacientes`):** `Models/Paciente.php`, `Models/ContactoEmergencia.php`, `Controllers/PacienteController.php` (personal), `Controllers/AutoservicioPacienteController.php` (autorregistro y mi ficha), `Requests/PacienteRequest.php` + `PacienteCreateRequest.php` + `PacienteUpdateRequest.php` + `FichaPacienteRequest.php`, `Services/PacienteService.php`, `Resources/PacienteResource.php`, `routes.php`. En `ModuloSeguridad`, `AuthService::abrirSesion()` (inicio de sesión tras el autorregistro).
- **Frontend (`src/modules/ModuloPacientes`):** `services/pacientes.service.js`, `hooks/usePacientes.js`, `components/CamposPaciente.jsx` (datos médicos y contacto de emergencia), `pages/PacientesPage/` (listado + `PacienteFormModal.jsx` + `CambiarEstadoPacienteModal.jsx`), `pages/RegistroPage/` (autorregistro), `pages/MiFichaPage/` (ficha propia). Rutas: `/pacientes` (personal), `/registro` (público) y `/paciente/ficha` (Paciente).

### Funcionalidades extras
- **Alergias:** el esquema no tiene dónde guardarlas; requiere agregar una columna o tabla (por ejemplo, `paciente.alergias TEXT`).  
- **Enfermedades crónicas:** se registrarán en `paciente_enfermedad` con el catálogo de CU12 (Ciclo 2).  
- Verificación del correo en el autorregistro y recuperación de contraseña.  
- Paciente menor de edad vinculado a la cuenta de su tutor.

### Tablas implicadas
| Tabla                    | Operación | Motivo |
|--------------------------|-----------|--------|
| `usuario`                | Inserción, lectura, actualización | Datos personales y de acceso |
| `paciente`               | Inserción, lectura, actualización | Código, fecha de nacimiento, tipo de sangre y estado |
| `contacto_de_emergencia` | Inserción, actualización, eliminación | Contacto de emergencia (RN05) |
| `rol` / `rol_usuario`    | Lectura, inserción | Asignación del rol Paciente |
| `cita_medica`            | Lectura   | Citas pendientes (RN09) |
| `sessions`               | Eliminación | Cierre de sesiones al desactivar |
| `bitacora`               | Inserción | Auditoría (RN10) |

### Dependencias y conexiones
- **Ciclo 1:** Reutiliza la validación de cuentas de CU02 y el patrón de alta integrada de CU08. CU13 (Gestionar Citas) busca al paciente registrado aquí para agendarle una cita. Habilita el rol Paciente que CU02 dejaba bloqueado.  
- **Ciclo 2:** CU12 (Catálogo de enfermedades) completará la información médica base con las enfermedades del paciente; CU19 (Historial clínico) mostrará la ficha del paciente.

---

## CU13 – Gestionar Citas Médicas y Modalidad  
**Actor:** Recepcionista (R), Paciente (P). El Administrador también puede agendar.  
**Prioridad:** Alta  
**Módulo:** Módulo 4 – Operación de Citas Médicas, Agenda y Pagos (`ModuloOperacionCitas`)  
**Permiso requerido:** `citas_programar` para agendar a **cualquier** paciente (Recepcionista y Administrador). El **Paciente** agenda **solo para sí mismo** y consulta sus propias citas.

### Descripción funcional
Permite **programar una cita médica** eligiendo, paso a paso, la **especialidad**, el **médico**, la **fecha**, un **turno disponible** y la **modalidad** de atención (Presencial, Virtual o Domiciliaria), junto con el motivo de la consulta. Los turnos ofrecidos salen del cálculo de disponibilidad de CU09, por lo que nunca se ofrece un horario fuera de la grilla, ocupado, bloqueado por licencia o ya pasado. El paciente puede además consultar **"Mis citas"** (próximas e historial).

**Reglas de negocio:**
- **RN01:** El paciente y el médico deben estar **activos**, y el médico debe ejercer la **especialidad elegida**, que también debe estar activa.
- **RN02:** El turno debe estar **disponible** según CU09 (RN06): pertenecer a la grilla del médico, no estar ocupado ni bloqueado y no haber comenzado. La hora de fin la calcula el sistema a partir de la duración del turno; no la envía el cliente.
- **RN03:** La fecha va **desde hoy hasta 90 días** adelante.
- **RN04:** Un paciente **no puede tener dos citas pendientes que se superpongan** en horario, aunque sean con médicos distintos.
- **RN05 – Concurrencia:** el registro se hace en una transacción con un **bloqueo de PostgreSQL** (`pg_advisory_xact_lock`) y vuelve a verificar la disponibilidad dentro de ella. Si dos personas eligen el mismo turno a la vez, solo la primera lo obtiene; el índice único parcial `idx_cita_medico_no_solapada` es la última barrera.
- **RN06:** La **modalidad** es obligatoria y proviene del catálogo `modalidad_cita`. El **motivo** es obligatorio (5 a 500 caracteres) para que el médico se prepare.
- **RN07:** El **código** de la cita lo genera el sistema de forma correlativa (`CM-0004`, `CM-0005`, …). La cita nace en estado **Programada**.
- **RN08:** El Paciente solo agenda para sí mismo (el sistema ignora cualquier otro paciente que envíe). Quien tiene `citas_programar` debe indicar el paciente.
- **RN09:** Cada cita agendada se registra en `bitacora`.

### Flujo principal (mínimo viable)
1. **(Solo personal) Elegir paciente:** búsqueda por CI, nombre o código entre los pacientes activos (CU11).
2. **Elegir especialidad:** se muestran las especialidades activas (CU07).
3. **Elegir médico:** médicos activos que ejercen esa especialidad, con los días de la semana en que atienden (CU08, CU09).
4. **Elegir fecha y turno:** se sugieren los próximos días de atención del médico; al elegir una fecha se muestran sus turnos y solo los **disponibles** se pueden seleccionar.
5. **Elegir modalidad y escribir el motivo.**
6. **Confirmar:** el sistema valida RN01–RN08 dentro de la transacción (RN05), registra la cita como **Programada** con su código y la bitácora, y muestra el comprobante.
7. **Mis citas (Paciente):** lista de sus citas próximas e historial con fecha, hora, médico, modalidad, estado y código.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Datos faltantes o inválidos (fecha fuera de rango, modalidad inexistente, motivo vacío) | **422** con el error de cada campo |
| E2 | Paciente o médico inactivo, o el médico no ejerce la especialidad | **422** con el motivo |
| E3 | La hora no corresponde a un turno de la grilla del médico | **422** "El horario elegido no corresponde a un turno del médico" |
| E4 | El turno ya fue reservado (incluida una reserva simultánea), está bloqueado por licencia o ya pasó | **422** con el motivo; se debe elegir otro turno |
| E5 | El paciente ya tiene otra cita pendiente en ese horario | **422** "Ya tiene la cita CM-XXXX a esa hora" |
| E6 | Un usuario sin `citas_programar` y sin registro de paciente intenta agendar | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
| Método | Ruta | Acceso | Descripción |
|--------|------|--------|-------------|
| GET | `/api/modalidades` | Sesión activa | Catálogo de modalidades de cita |
| GET | `/api/medicos/directorio?especialidad=` | Sesión activa | Médicos activos de una especialidad, con sus días de atención (sin salario) |
| GET | `/api/medicos/{id}/disponibilidad?fecha=` | Sesión activa | Turnos del día (CU09) |
| POST | `/api/citas` | `citas_programar` o Paciente | Agenda una cita |
| GET | `/api/mis-citas?periodo=proximas\|historial` | Paciente | Citas propias |

### Componentes de implementación
- **Backend (`app/Modules/ModuloOperacionCitas`):** `Models/CitaMedica.php` (relaciones), `Models/ModalidadCita.php`, `Controllers/CitaController.php`, `Controllers/ModalidadController.php`, `Requests/CitaAgendarRequest.php`, `Services/CitaService.php` (reglas y transacción con bloqueo), `Resources/CitaResource.php`, `routes.php`. En `ModuloPersonalMedico`: `Controllers/DirectorioMedicoController.php`. Reutiliza `DisponibilidadService` de CU09.
- **Frontend (`src/modules/ModuloOperacionCitas`):** `services/citas.service.js`, `hooks/useCitas.js`, `components/SlotPicker.jsx` (selección de turnos), `pages/AgendarCitaPage/` (asistente paso a paso + comprobante), `pages/MisCitasPage/`. Rutas: `/citas/nueva` (personal), `/paciente/citas/nueva` y `/paciente/citas` (Paciente).

### Funcionalidades extras
- Guardar la **especialidad** en la cita (`cita_medica.id_especialidad`): hoy solo se guarda el médico, y un médico puede tener varias especialidades; sería útil para los reportes de CU21.  
- Sugerir automáticamente la próxima fecha con turnos disponibles.  
- Enviar la confirmación por correo (CU20) y cobrar la reserva en línea (CU24).

### Tablas implicadas
| Tabla                     | Operación | Motivo |
|---------------------------|-----------|--------|
| `cita_medica`             | Inserción, lectura | Registro y consulta de citas |
| `modalidad_cita`          | Lectura   | Catálogo de modalidades |
| `paciente` / `usuario`    | Lectura   | Paciente de la cita (activo) |
| `medico` / `medico_especialidad` / `especialidad` | Lectura | Médico y especialidad (RN01) |
| `horario_atencion` / `indisponibilidad_medico` | Lectura | Disponibilidad (RN02, vía CU09) |
| `bitacora`                | Inserción | Auditoría (RN09) |

### Dependencias y conexiones
- **Ciclo 1:** Depende de CU07 (especialidades), CU08 (médicos), CU09 (disponibilidad) y CU11 (pacientes). CU14 reprogramará y cancelará las citas creadas aquí; CU15 cambiará su estado el día de la atención; CU16 las mostrará en la agenda; CU24 cobrará la reserva.  
- **Ciclo 2:** CU20 enviará la confirmación y los recordatorios según las preferencias de CU05.

---

## CU14 – Reprogramar y Cancelar Citas Médicas  
**Actor:** Recepcionista (R), Paciente (P). El Administrador también puede hacerlo.  
**Prioridad:** Alta  
**Módulo:** Módulo 4 – Operación de Citas Médicas, Agenda y Pagos (`ModuloOperacionCitas`)  
**Permiso requerido:** `citas_programar` para modificar **cualquier** cita (Recepcionista y Administrador). El **Paciente** modifica **solo sus propias** citas.

### Descripción funcional
Permite **cambiar la fecha y la hora** de una cita pendiente a otro turno disponible del **mismo médico** (y, opcionalmente, su modalidad), o **cancelarla registrando el motivo**. La cita **nunca se elimina**: conserva su código y queda en el historial con su estado. El personal cuenta con una pantalla **"Citas"** para buscar cualquier cita (por código, paciente o médico) y actuar sobre ella; el paciente lo hace desde **"Mis citas"**.

**Reglas de negocio:**
- **RN01:** Solo se pueden reprogramar o cancelar citas **pendientes** (Programada o Confirmada) que **todavía no comenzaron**.
- **RN02 – Anticipación:** el Paciente puede reprogramar o cancelar **hasta 2 horas antes** del inicio de la cita; después debe comunicarse con recepción. El personal puede hacerlo hasta que la cita comience.
- **RN03:** La reprogramación se hace **con el mismo médico**, que debe seguir activo. Para cambiar de médico se cancela la cita y se agenda una nueva (CU13).
- **RN04:** El nuevo turno debe estar **disponible** (CU09) y ser **distinto** del actual, dentro del rango de hoy a 90 días. El paciente no puede quedar con dos citas superpuestas (sin contar la que se está moviendo).
- **RN05:** Se aplica la misma protección de **concurrencia** de CU13 (transacción con bloqueo e índice único parcial). La cita conserva su **código** y su **estado**; si se reprograma una cita Confirmada, sigue Confirmada.
- **RN06:** Al cancelar, el **motivo es obligatorio** (5 a 500 caracteres) y se guarda en `motivo_cancelacion`. El turno queda libre de inmediato para otros pacientes.
- **RN07:** Cada reprogramación y cancelación se registra en `bitacora` (acciones `REPROGRAMACION` y `CANCELACION`) con los datos anteriores y nuevos.

### Flujo principal (mínimo viable)
1. **Buscar la cita:** el personal usa "Citas" (búsqueda por código, paciente o médico y filtros por estado, fecha y periodo); el paciente usa "Mis citas → Próximas".
2. **Reprogramar:** elige una nueva fecha (se sugieren los próximos días de atención del médico) y un turno disponible; opcionalmente cambia la modalidad. El sistema valida RN01–RN05, actualiza la cita y registra `REPROGRAMACION`.
3. **Cancelar:** escribe el motivo y confirma. El sistema valida RN01, RN02 y RN06, cambia el estado a **Cancelada** y registra `CANCELACION`.

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Fecha, turno o motivo inválidos o vacíos | **422** con el error del campo |
| E2 | La cita no está pendiente o ya comenzó | **422** "Solo se pueden modificar citas pendientes que aún no comenzaron" |
| E3 | El Paciente intenta modificarla con menos de 2 horas de anticipación | **422** "…hasta 2 horas antes. Comuníquese con recepción" |
| E4 | Turno no disponible, igual al actual o superpuesto con otra cita del paciente | **422** con el motivo |
| E5 | El médico de la cita está inactivo | **422** "El médico ya no atiende; cancele la cita y agende una nueva" |
| E6 | El Paciente intenta modificar una cita ajena, o un usuario sin permiso | **403** "No tiene permisos para esta acción" |
| E7 | Cita inexistente | **404** "Recurso no encontrado" |

### Endpoints (API REST)
| Método | Ruta | Acceso | Descripción |
|--------|------|--------|-------------|
| GET | `/api/citas?buscar=&estado=&fecha=&periodo=proximas\|historial\|todas&page=` | `citas_programar` | Búsqueda paginada de citas |
| PATCH | `/api/citas/{id}/reprogramar` | `citas_programar` o el paciente de la cita | Nueva fecha, turno y (opcional) modalidad |
| PATCH | `/api/citas/{id}/cancelar` | `citas_programar` o el paciente de la cita | Cancela con motivo |

### Componentes de implementación
- **Backend (`app/Modules/ModuloOperacionCitas`):** `Controllers/CitaController.php` (`index`, `reprogramar`, `cancelar`), `Requests/CitaReprogramarRequest.php`, `Requests/CitaCancelarRequest.php`, `Services/CitaService.php` (reglas compartidas con CU13: turno disponible, superposición y transacción con bloqueo), `Resources/CitaResource.php` (incluye el inicio de la cita y los días de atención del médico).
- **Frontend (`src/modules/ModuloOperacionCitas`):** `components/ReprogramarCitaModal.jsx` (reutiliza `SlotPicker` de CU13), `components/CancelarCitaModal.jsx`, `utils/citas.js` (`puedeModificar()`), `pages/CitasPage/` (gestión para el personal) y acciones en `pages/MisCitasPage/`. Ruta nueva: `/citas` (personal).

### Funcionalidades extras
- Límite de reprogramaciones por cita.  
- Lista de espera: avisar a otros pacientes cuando se libera un turno.  
- Notificar al paciente y al médico del cambio (CU20) y reembolsar el pago si corresponde (CU24).

### Tablas implicadas
| Tabla            | Operación | Motivo |
|------------------|-----------|--------|
| `cita_medica`    | Lectura, actualización | Fecha, horas, modalidad, estado y motivo de cancelación |
| `horario_atencion` / `indisponibilidad_medico` | Lectura | Disponibilidad del nuevo turno (vía CU09) |
| `medico` / `paciente` / `usuario` | Lectura | Estado del médico y datos para la búsqueda |
| `bitacora`       | Inserción | Auditoría (RN07) |

### Dependencias y conexiones
- **Ciclo 1:** Depende de CU13 (citas) y CU09 (disponibilidad). Destraba las reglas de CU08 y CU11 que impiden desactivar médicos o pacientes con citas pendientes, y la de CU09 que impide quitar bloques de horario con citas.  
- **Ciclo 2:** CU20 notificará los cambios; CU06 permitirá consultar las acciones `REPROGRAMACION` y `CANCELACION`.

---

## CU15 – Registrar Control de Asistencia  
**Actor:** Recepcionista (R). El Administrador también puede hacerlo.  
**Prioridad:** Alta  
**Módulo:** Módulo 4 – Operación de Citas Médicas, Agenda y Pagos (`ModuloOperacionCitas`)  
**Permiso requerido:** `asistencia_registrar` (nuevo; Recepcionista y Administrador)

### Descripción funcional
Permite a la recepción llevar el **control de asistencia de las citas del día**: **confirmar** la asistencia, registrar la **llegada** del paciente (pasa a **En Sala de Espera**) o marcar la **inasistencia** cuando no se presentó. La pantalla muestra las citas de una fecha (por defecto, hoy) con un resumen por estado y, en cada cita, **solo las acciones válidas** en ese momento.

**Estados y transiciones permitidas:**

| Estado actual | Puede pasar a | Condición |
|---------------|---------------|-----------|
| Programada | **Confirmada** | La cita aún no comenzó |
| Programada / Confirmada | **En Sala de Espera** (llegada) | Solo el **día de la cita** |
| Programada / Confirmada | **Inasistencia** | Pasaron **15 minutos** desde la hora de inicio (tolerancia) |
| En Sala de Espera | Confirmada (*deshacer llegada*) | Corrección, solo el día de la cita |
| Inasistencia | En Sala de Espera (*llegó tarde*) | Corrección, solo el día de la cita |

**Reglas de negocio:**
- **RN01:** Solo se aplican las transiciones de la tabla. **Atendida** la registra el **médico** en CU17 al guardar la consulta, de modo que nunca haya una cita atendida sin ficha clínica. Las citas **Canceladas** no tienen acciones de asistencia.
- **RN02 – Tolerancia:** la inasistencia solo se marca cuando pasaron 15 minutos desde la hora de inicio.
- **RN03:** El estado **En Sala de Espera** se agrega al tipo `estado_cita_enum` y al índice único parcial `idx_cita_medico_no_solapada`, para que el turno de un paciente que ya llegó **siga ocupado** y no pueda reservarse de nuevo.
- **RN04:** Una cita En Sala de Espera **sigue pendiente**: impide desactivar al médico o al paciente (CU08, CU11) y quitar el bloque de horario (CU09). Pero **ya no se puede reprogramar ni cancelar** (CU14), porque el paciente está presente.
- **RN05:** Cada cambio de asistencia se registra en `bitacora` (acción `ASISTENCIA`) con el estado anterior y el nuevo.

### Flujo principal (mínimo viable)
1. La recepción abre **"Asistencia"**: se muestran las citas de hoy ordenadas por hora, con un resumen por estado. Puede cambiar de día y filtrar por paciente, médico o código.
2. Cuando el paciente llama o se presenta antes, pulsa **Confirmar** → Confirmada.
3. Cuando el paciente llega a la clínica, pulsa **Registrar llegada** → En Sala de Espera.
4. Si el paciente no se presenta, pasados 15 minutos pulsa **No asistió** → Inasistencia.
5. Ante un error, usa **Deshacer llegada** o **Llegó tarde** (correcciones del mismo día).

### Flujos alternativos y excepciones
| Código | Situación | Respuesta del sistema |
|--------|-----------|-----------------------|
| E1 | Estado destino inválido | **422** con el error del campo |
| E2 | Transición no permitida desde el estado actual (p. ej., cita Cancelada o Atendida) | **422** "No se puede pasar de X a Y" |
| E3 | Registrar llegada o corregir en un día distinto al de la cita | **422** "Solo se puede registrar el día de la cita" |
| E4 | Marcar inasistencia antes de los 15 minutos de tolerancia | **422** "Podrá marcar la inasistencia desde las HH:MM" |
| E5 | Confirmar una cita que ya comenzó | **422** con el motivo |
| E6 | Usuario sin el permiso `asistencia_registrar` | **403** "No tiene permisos para esta acción" |

### Endpoints (API REST)
| Método | Ruta | Permiso | Descripción |
|--------|------|---------|-------------|
| GET | `/api/asistencia?fecha=&buscar=` | `asistencia_registrar` | Citas del día con resumen por estado y las acciones permitidas en cada una |
| PATCH | `/api/citas/{id}/asistencia` | `asistencia_registrar` | Cambia el estado (`{ "estado": "Confirmada" \| "En Sala de Espera" \| "Inasistencia" }`) |

### Componentes de implementación
- **Backend (`app/Modules/ModuloOperacionCitas`):** `Controllers/AsistenciaController.php`, `Requests/AsistenciaRequest.php`, `Services/AsistenciaService.php` (máquina de estados y condiciones), `Models/CitaMedica.php` (estado nuevo, `ESTADOS_PENDIENTES` y `ESTADOS_MODIFICABLES`); migración `agregar_estado_en_sala_de_espera` (valor del ENUM e índice único parcial); permiso `asistencia_registrar` en `PermisosSistemaSeeder`.
- **Frontend (`src/modules/ModuloOperacionCitas`):** `services/asistencia.service.js`, `pages/AsistenciaPage/` (`AsistenciaPage.js` + `AsistenciaPage.view.jsx`). Ruta: `/asistencia`.

### Funcionalidades extras
- Registrar la **hora de llegada** para medir los tiempos de espera (CU23).  
- Marcar automáticamente como inasistencia, al final del día, las citas que no tuvieron llegada.  
- Cola de la sala de espera visible para el médico (CU16).

### Tablas implicadas
| Tabla         | Operación | Motivo |
|---------------|-----------|--------|
| `cita_medica` | Lectura, actualización | Estado de asistencia |
| `paciente` / `medico` / `usuario` / `modalidad_cita` | Lectura | Datos mostrados en la lista |
| `bitacora`    | Inserción | Auditoría (RN05) |

### Dependencias y conexiones
- **Cambio de esquema:** la migración agrega `'En Sala de Espera'` a `estado_cita_enum` y recrea `idx_cita_medico_no_solapada` incluyéndolo. **Conviene reflejar ambos cambios en `Base de Datos/script.sql`**.  
- **Ciclo 1:** Depende de CU13 (citas). CU16 (Agenda médica) mostrará estos estados; CU17 (Consulta médica) atenderá las citas En Sala de Espera y las marcará como **Atendida**.  
- **Ciclo 2:** CU21 y CU23 usarán las inasistencias para sus reportes e indicadores.

---

## CU05 – Auditoría de Usuarios  
**Actor:** Superusuario (Global), Administrador (Sucursal)  
**Prioridad:** Alta  

### Descripción funcional
Permite consultar el registro histórico de todas las operaciones realizadas en la base de datos. La visibilidad de los datos depende del tipo de usuario.

### Flujo principal
1. **Consultar logs (Visibilidad Jerárquica)**  
   - **Superusuario (S):** Tiene acceso total. Puede filtrar logs por cualquier sucursal o ver eventos globales del sistema.
   - **Administrador (E):** Acceso restringido. Solo puede ver logs de su propia sucursal (eventos generados por él mismo o sus empleados subordinados).
   - Filtros comunes: Usuario, Tabla, Operación (INSERT, UPDATE, DELETE), Rango de fechas.
   - Muestra datos anteriores y nuevos en formato JSON.

2. **Ver detalle de un registro**  
   - Expande la fila o abre modal mostrando completamente los JSONB de `datos_anteriores` y `datos_nuevos`.

### Funcionalidades extras
- Exportar resultados a CSV/Excel.  
- Gráfico de actividad por usuario/día.  

### Tablas implicadas
| Tabla            | Operación | Motivo |
|------------------|-----------|--------|
| `log_auditoria`  | Lectura   | Consulta de auditoría |
| `usuario`        | Lectura   | Para mostrar nombre del usuario responsable |

### Dependencias y conexiones
- **Fuerte dependencia transversal:** Todos los demás casos de uso deben insertar filas en `log_auditoria` cuando realicen operaciones de escritura. Por tanto, hay que implementar un mecanismo centralizado (triggers o un servicio de auditoría) **antes** de dar por terminado cualquier otro CU.  
- Si no se hace desde el principio, en el ciclo 2 el caos será aún mayor.

---

## CU06 – Gestionar Sucursales  
**Actor:** Superusuario (SU), Administrador (A)  
**Prioridad:** Alta  

### Descripción funcional
Mantener el catálogo de sucursales. El Superusuario (SU) tiene control total. El Administrador (A) solo puede ver y editar la información de su propia sucursal.

### Flujo mínimo
1. **Listar sucursales:**
   - **Superusuario (SU):** Lista todas las sucursales.
   - **Administrador (A):** Solo ve el registro de su propia sucursal.
2. **Crear sucursal:** Reservado para el Superusuario (SU). Solo podra asignar sucursal si el usuario a asignar tiene el rol de Administrador(A) y es tipo Empleado(E).
3. **Editar sucursal:** 
   - El Superusuario edita cualquier sucursal.
   - El Administrador edita datos de contacto/horarios de la suya.

### Funcionalidades extras
- Visualizar en mapa (si se guardan coordenadas en `ubicacion`).  
- Ver estadísticas asociadas más adelante (ventas por sucursal en dashboard).

### Tablas implicadas
| Tabla      | Operación | Motivo |
|------------|-----------|--------|
| `sucursal` | CRUD      | Gestión de sucursales |

### Dependencias y conexiones
- **Ciclo 1:** Sirve de base para CU27 (Sectores) y CU28 (Mesas).  
- **Ciclo 2 y 3:** Prácticamente todos los demás módulos (inventario por sucursal, comandas, caja, reservas) dependen de que al menos una sucursal esté creada y activa.  
- Es vital que el CU06 quede **completo y estable** porque será la referencia de muchas FK.

---

## CU26 – Gestionar Personal  
**Actor:** Superusuario (SU), Administrador (A)  
**Prioridad:** Media  

### Descripción funcional
Administrar el registro de empleados. El Administrador (A) solo puede gestionar empleados de su propia sucursal. El Superusuario (SU) gestiona todo el personal y sus asignaciones.

### Flujo mínimo
1. **Listar empleados**
   - **Administrador (A):** Solo ve empleados de su sucursal.
   - **Superusuario (SU):** Ve todos los empleados, con filtro por sucursal.

2. **Crear empleado**  
   - El Administrador crea empleados asignándolos automáticamente a su sucursal.
   - El Superusuario elige la sucursal de destino.
   - Se debe crear un `usuario` (si no existe) a través de un form integrado.
   - Internamente: inserta en `usuario`, luego en `empleado` con el `id_usuario` recién creado.  
   - Asigna sucursal(opcional) mediante `empleado_sucursal` (puede hacerse en el mismo paso o en uno posterior).  
3. **Editar empleado**  
   - Permite modificar salario, turno, fechas, y también los datos de usuario (nombre, etc.).  
4. **Desvincular/remover**  
   - Establece `fecha_finalizacion` en `empleado` o desactiva el usuario.  
   - Opcionalmente, finaliza la asignación a sucursal con `fecha_fin`.

### Funcionalidades extras
- Reasignar empleado entre sucursales conservando histórico de fechas.  
- Ver historial de sucursales por donde pasó.  
- Generar carnet con código QR.

### Tablas implicadas
| Tabla                | Operación                        | Motivo |
|----------------------|----------------------------------|--------|
| `usuario`            | Inserción, actualización         | Datos personales y acceso |
| `empleado`           | Inserción, actualización         | Datos laborales |
| `empleado_sucursal`  | Inserción, actualización (fin)   | Asignación a sucursales |
| `sucursal`           | Lectura                          | Para elegir sucursal al asignar |

### Dependencias y conexiones
- **Ciclo 1:**  
  - Necesita que exista CU02 (Usuarios) para la creación de usuarios, pero lo recomendable es que **el propio CU26 gestione la creación del usuario** para no duplicar flujos. El administrador no debería tener que ir primero a “Crear Usuario” y luego a “Crear Empleado”. Puedes simplificarlo haciendo que CU26 invoque internamente la lógica de registro de `usuario` (la misma que usa CU02).  
  - También depende de CU06 (Sucursales).  
- **Futuro:**  
  - Los empleados van a ser los Cajeros, Meseros, Cocineros… que aparecen como actores. Cuando se abra una Caja (CU22) o se asigne una Comanda (CU14), se seleccionará un empleado. Por tanto, el `id_empleado` debe estar correctamente vinculado a un usuario con el rol apropiado (Cajero, Mesero…). Conviene comprobar esa coherencia desde YA.

---

## CU27 – Gestionar Sectores  
**Actor:** Superusuario (SU), Administrador (A)  
**Prioridad:** Alta  

### Descripción funcional
Configurar las áreas en las que se divide una sucursal. Estos datos son específicos de cada sucursal.

### Flujo mínimo
1. **Listar sectores:**
   - El sistema filtra automáticamente por la sucursal activa del Administrador o la seleccionada por el Superusuario.
2. **Crear sector:** El Administrador lo crea para su sucursal; el Superusuario para la activa.
3. **Editar sector** y desactivarlo.

### Funcionalidades extras
- Configurar colores o íconos para ayudar a la vista de planta (aunque no se guarde en BD).  

### Tablas implicadas
| Tabla      | Operación | Motivo |
|------------|-----------|--------|
| `sucursal` | Lectura   | Para mostrar/validar la sucursal |
| `sector`   | CRUD      | Gestión de sectores |

### Dependencias
- Depende de CU06 (Sucursales).  
- **Ciclo siguiente cercano:** CU28 (Mesas) se ancla directamente en `sector`.

---

## CU28 – Gestionar Mesas  
**Actor:** Superusuario (SU), Administrador (A)  
**Prioridad:** Alta  

### Descripción funcional
Manejar el inventario de mesas dentro de cada sector. Proceso restringido a la sucursal activa.

### Flujo mínimo
1. **Listar mesas:** Filtrado por sector de la sucursal activa.
2. **Crear mesa:** El Administrador lo crea para su sucursal.
3. **Editar mesa** (capacidad, número) y cambiar manualmente el estado (por ejemplo, a `FUERA_SERVICIO`).  
4. **Desactivar** (`activo = FALSE`).

### Funcionalidades extras
- Plano gráfico de mesas (drag & drop). No es requisito mínimo pero es un plus visual.  
- Cambiar estado de varias mesas a la vez (por ejemplo, al iniciar jornada).

### Tablas implicadas
| Tabla    | Operación | Motivo |
|----------|-----------|--------|
| `sector` | Lectura   | Para asociar la mesa a un sector |
| `mesa`   | CRUD      | Gestión de mesas |

### Dependencias
- Depende de CU27 (Sectores).  
- **Futuro ciclo 4:** El estado `disponibilidad` será manipulado por CU18 (Reservas) y CU14 (Comandas). Por eso, el CU28 debe **permitir al administrador forzar el estado** pero también asegurarse de que otros procesos puedan leer y modificar ese campo mediante API.

---

## CU29 – Registrarse en el Sistema  
**Actor:** Cliente (Público General)  
**Prioridad:** Media  

### Descripción funcional
Permite a un usuario externo crear su propia cuenta desde la opción de "Registrarse" del **Portal Único**. Este flujo está restringido a la creación de perfiles de tipo **Cliente**.

### Flujo principal
1. **Acceso:** El usuario selecciona "Crear Cuenta" en la pantalla principal.
2. **Formulario de registro:** CI, nombre, apellido, username, contraseña, correo, teléfono.
3. **Creación de cuenta**  
   - Inserta en `usuario` con `activo = TRUE` y `estado_acceso = 'HABILITADO'`.  
   - Inserta en `cliente` vinculando al mismo `id_usuario`, con valores por defecto: `puntos_fidelidad = 0`, `nivel_cliente = 'REGULAR'`.  
4. **Confirmación** (opcional) – puede enviarse un correo de bienvenida o un enlace de activación.  
5. **Inicio de sesión automático** (o redirigir a login).

### Funcionalidades extras
- Registro con redes sociales (requiere columnas adicionales o tabla aparte).  
- Verificación de correo electrónico para activar la cuenta.  
- Política de aceptación de términos y condiciones.

### Tablas implicadas
| Tabla      | Operación                  | Motivo |
|------------|----------------------------|--------|
| `usuario`  | Inserción                  | Cuenta de acceso del cliente |
| `cliente`  | Inserción                  | Perfil específico de cliente |

### Dependencias y conexiones
- **Ciclo 1:** Se apoya en el mismo mecanismo de encriptación de contraseña y validaciones que CU02.  
- **Futuro:**
  - El cliente registrado puede iniciar sesión con CU01.  
  - La tabla `cliente` será esencial en el e‑commerce (carrito de compras, transacciones online) y en las reservas de mesas.  
  - Asegurarse de que el `cliente` tenga un `nit` opcional para facturación (campo ya existe).  

---
