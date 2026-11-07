# Despliegue en Railway – Clínica Vida Salud

Se despliegan **tres servicios en un mismo proyecto de Railway**:

| Servicio (nombre en Railway) | Origen | Qué hace |
|---|---|---|
| `Postgres` | Plantilla de Railway (*Database → PostgreSQL*) | Base de datos |
| `Backend` | Repo `VidaSalud-Backend`, rama `main` | API Laravel (FrankenPHP), puerto 8080. **Sin dominio público** |
| `Frontend` | Repo `VidaSalud-Frontend`, rama `main` | SPA React servida con Caddy. **Único dominio público** |

```
Navegador ──HTTPS──▶ Frontend (Caddy)
                       ├─ /            → archivos de la SPA
                       └─ /api, /sanctum → Backend (red privada de Railway) ──▶ Postgres
```

**Por qué el frontend reenvía la API.** El login usa la cookie de sesión de Sanctum, que exige que la SPA y la API estén en el mismo sitio. Los dominios `*.up.railway.app` cuentan como sitios distintos entre sí, así que si el navegador llamara a la API en otro dominio, bloquearía la cookie. Con el reenvío, todo se sirve desde el dominio del frontend, igual que el proxy de Vite en desarrollo.

Cada repo trae su `Dockerfile` y su `railway.json` (healthcheck y reinicios): Railway los detecta solo.

---

## 1. Crear el proyecto

1. Railway → **New Project** → **Deploy PostgreSQL**. Deje el nombre del servicio como `Postgres`.
2. **+ Create → GitHub Repo → VidaSalud-Backend** y renombre el servicio a `Backend`. En *Settings → Source*, rama `main`.
3. **+ Create → GitHub Repo → VidaSalud-Frontend** y renombre el servicio a `Frontend`. En *Settings → Source*, rama `main`.
4. `Frontend` → *Settings → Networking* → **Generate Domain** (puerto **8080**).
   - `Backend` no necesita dominio público.

> Los nombres `Postgres`, `Backend` y `Frontend` importan: las variables de abajo los usan en las referencias `${{...}}`, que distinguen mayúsculas.

## 2. Variables del Backend

Antes, en su máquina, genere la clave de la aplicación:

```bash
php artisan key:generate --show   # copie el resultado: base64:...
```

`Backend` → *Variables* → *Raw Editor*, y pegue (complete los valores marcados con `<...>`):

```env
APP_NAME="Clinica Vida Salud"
APP_ENV=production
APP_DEBUG=false
APP_KEY=<base64:... generado arriba>
APP_URL=https://${{Frontend.RAILWAY_PUBLIC_DOMAIN}}
FRONTEND_URL=https://${{Frontend.RAILWAY_PUBLIC_DOMAIN}}
APP_LOCALE=es
APP_FALLBACK_LOCALE=en
APP_TIMEZONE=America/La_Paz
PORT=8080

# Primer despliegue: con la base vacía se cargan los datos del script.sql y este administrador
ADMIN_CORREO=admin@vidasalud.com
ADMIN_CONTRASENA=<una contraseña segura>

DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=${{Frontend.RAILWAY_PUBLIC_DOMAIN}}
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
LOG_LEVEL=info

# Stripe (CU24). Webhook: ver el paso 5
STRIPE_SECRET_KEY=<sk_test_... o sk_live_...>
STRIPE_PUBLISHABLE_KEY=<pk_test_... o pk_live_...>
STRIPE_WEBHOOK_SECRET=
STRIPE_MONEDA=bob

# Correo (CU20). Sin SMTP, use MAIL_MAILER=log (los correos solo quedan en el log)
MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-responder@vidasalud.com"
MAIL_FROM_NAME="Clinica Vida Salud"
# MAIL_MAILER=smtp
# MAIL_HOST=<smtp.servidor.com>
# MAIL_PORT=587
# MAIL_USERNAME=<usuario>
# MAIL_PASSWORD=<contraseña>

# Opcionales
CLINICA_NOMBRE="Clínica Vida Salud"
CLINICA_DIRECCION="Av. Principal #123, Santa Cruz de la Sierra, Bolivia"
CLINICA_TELEFONO="+591 3 3000000"
ANTHROPIC_API_KEY=
RUN_SCHEDULER=true
```

## 3. Variables del Frontend

`Frontend` → *Variables*:

```env
PORT=8080
BACKEND_URL=http://${{Backend.RAILWAY_PRIVATE_DOMAIN}}:8080
```

El frontend llega a la API por la red privada de Railway, y el puerto debe coincidir con `PORT` del Backend.

## 4. Desplegar

Al guardar las variables, Railway construye y despliega. Al arrancar, el contenedor del Backend ([docker/entrypoint.sh](docker/entrypoint.sh)) hace, en orden:

1. **Verifica `APP_KEY`.** Si falta, el despliegue falla con un mensaje claro.
2. **`php artisan optimize`:** genera las cachés de configuración, rutas, eventos y vistas.
3. **`php artisan migrate --force`:** crea o actualiza las tablas.
4. **`php artisan app:preparar-datos`:**
   - con la base **vacía**, carga el `script.sql`, los precios, el método Stripe, el administrador y los permisos;
   - con datos, **solo** actualiza los permisos, sin tocar contraseñas ni registros.
5. **`php artisan schedule:work`** en segundo plano: recordatorios de citas cada 15 minutos (CU20).
6. **Inicia FrankenPHP** en el puerto 8080. El healthcheck de Railway espera a que `/up` responda.

Abra `https://<dominio-del-frontend>` e inicie sesión con `ADMIN_CORREO` / `ADMIN_CONTRASENA`.

## 5. Stripe

- **Webhook:** Stripe → *Desarrolladores → Webhooks → Agregar endpoint*:
  - **URL:** `https://<dominio-del-frontend>/api/stripe/webhook`
  - **Eventos:** `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled` y `payment_intent.processing`.

  Copie el *signing secret* (`whsec_...`) en `STRIPE_WEBHOOK_SECRET` del Backend. Sin webhook los pagos funcionan igual; el webhook solo cubre el caso en que el paciente cierra la pestaña justo después de pagar.
- **Producción real:** cambie a las claves `sk_live_` y `pk_live_`.

## 6. Verificación rápida

| Prueba | Resultado esperado |
|---|---|
| `https://<frontend>/` | Página principal con imágenes |
| `https://<frontend>/api/publico/modalidades` | JSON con las modalidades |
| Iniciar sesión como administrador | Entra al panel. Si vuelve al login, revise `SANCTUM_STATEFUL_DOMAINS` y `SESSION_SECURE_COOKIE` |
| Reservar y pagar con `4242 4242 4242 4242` (modo prueba) | "Pagado · Ver recibo" |
| Logs del Backend | `Base vacía: datos iniciales...` (primera vez) o `permisos del sistema actualizados` |

## Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| El Backend se reinicia con "falta APP_KEY" | Agregue `APP_KEY` (paso 2) |
| Error "Defina ADMIN_CORREO y ADMIN_CONTRASENA" | Primer despliegue sin administrador: agregue ambas variables |
| `502` en `/api/...` | El Backend no está listo o `BACKEND_URL` no coincide con el nombre del servicio o con el puerto 8080 |
| Login devuelve 419 o vuelve al inicio | `SANCTUM_STATEFUL_DOMAINS` debe ser el dominio del frontend, sin `https://` |
| No aparece el pago con tarjeta | Faltan `STRIPE_SECRET_KEY` o `STRIPE_PUBLISHABLE_KEY` |

## Probar las imágenes localmente (opcional, con Docker)

```bash
# Backend (necesita un PostgreSQL accesible)
docker build -t vidasalud-api ./Backend
docker run --env-file Backend/.env -e PORT=8080 -p 8080:8080 vidasalud-api

# Frontend
docker build -t vidasalud-web ./Frontend
docker run -e BACKEND_URL=http://host.docker.internal:8080 -p 8081:8080 vidasalud-web
```
