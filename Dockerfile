# syntax=docker/dockerfile:1

# --- Frontend build ---
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js ./
RUN npm run build

# --- PHP deps ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader

# --- Runtime ---
FROM dunglas/frankenphp:1-php8.3 AS runtime

# s6-overlay
ARG S6_OVERLAY_VERSION=3.2.0.0
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
RUN tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/
RUN tar -C / -Jxpf /tmp/s6-overlay-x86_64.tar.xz

# PHP extensions needed by Laravel + image work (mbstring added: required by Laravel core and intervention/image v4)
RUN install-php-extensions pdo_mysql zip gd intl opcache pcntl mbstring

# Bounded memory_limit — backstop so an untrusted image/zip fails one request/job
# instead of OOM-killing the process. / メモリ上限のバックストップ。
COPY docker/php/wydoujin.ini /usr/local/etc/php/conf.d/zz-wydoujin.ini

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/s6/s6-rc.d /etc/s6-overlay/s6-rc.d

# App processes run as www-data (see the s6 run scripts); FrankenPHP/Caddy needs
# writable XDG dirs, and the init-perms oneshot re-chowns the /data volume at boot
# (the volume mount masks this build-time chown). / 非rootで実行するためのXDG設定。
ENV XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data
RUN mkdir -p /data /library /config \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

EXPOSE 8080
ENTRYPOINT ["/init"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD curl -fsS http://127.0.0.1:8080/health || exit 1
