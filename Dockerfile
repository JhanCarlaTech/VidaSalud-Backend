# syntax=docker/dockerfile:1
#
# API de Clínica Vida Salud (Laravel 13) para producción.
# FrankenPHP = PHP 8.5 + servidor web (Caddy) en un solo binario: no hace falta nginx ni php-fpm.
# Escucha en $PORT (Railway lo define; por defecto 8080).

FROM dunglas/frankenphp:1-php8.5

# Extensiones: PostgreSQL, y las que exige el código (fileinfo y curl ya vienen con PHP).
RUN install-php-extensions pdo_pgsql pgsql intl zip bcmath gmp opcache pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PORT=8080

WORKDIR /app

# 1) Dependencias primero: esta capa se reutiliza mientras composer.lock no cambie.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress \
    && rm -rf /root/.composer/cache

# 2) Código de la aplicación (ver .dockerignore: sin .env, vendor ni archivos locales).
COPY . .

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-vidasalud.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080

# Migraciones, datos iniciales, cachés y planificador; luego el servidor web.
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
