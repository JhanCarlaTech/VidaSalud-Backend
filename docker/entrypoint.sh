#!/bin/sh
# Arranque del contenedor de la API (Railway). Se detiene ante cualquier error
# para que Railway marque el despliegue como fallido en lugar de servir algo roto.
set -e
cd /app

if [ -z "$APP_KEY" ]; then
  echo "ERROR: falta APP_KEY. Genérela con 'php artisan key:generate --show' y agréguela en Railway." >&2
  exit 1
fi

# Cachés de configuración, rutas, eventos y vistas (leen las variables de entorno de Railway).
php artisan optimize

# Estructura de la base de datos.
php artisan migrate --force

# Datos iniciales solo con la base vacía; en cada despliegue, solo los permisos (idempotente).
php artisan app:preparar-datos

# CU20: recordatorios de citas cada 15 minutos (desactivar con RUN_SCHEDULER=false).
if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
  php artisan schedule:work &
fi

exec "$@"
