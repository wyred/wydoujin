# syntax=docker/dockerfile:1

# --- Frontend build ---
# Base images pinned by digest (reproducible; a re-tag can't change the build). Keep the
# tag comment for readability; bump digests via Dependabot. / 再現性のためダイジェスト固定。
FROM node:22-alpine@sha256:16e22a550f3863206a3f701448c45f7912c6896a62de43add43bb9c86130c3e2 AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js ./
RUN npm run build

# --- PHP deps ---
FROM composer:2@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader

# --- Runtime ---
FROM dunglas/frankenphp:1-php8.3@sha256:46e2afafc47ab66e2aa8da8a1c91070d7fe1cca057153df451de68ef1c84d864 AS runtime

# s6-overlay
ARG S6_OVERLAY_VERSION=3.2.0.0
# Verify the SHA-256 of each tarball before extracting it into / as PID-1 init code. / 展開前にSHA-256検証。
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
RUN echo "4b0c0907e6762814c31850e0e6c6762c385571d4656eb8725852b0b1586713b6  /tmp/s6-overlay-noarch.tar.xz" | sha256sum -c - \
    && tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/
RUN echo "ad982a801bd72757c7b1b53539a146cf715e640b4d8f0a6a671a3d1b560fe1e2  /tmp/s6-overlay-x86_64.tar.xz" | sha256sum -c - \
    && tar -C / -Jxpf /tmp/s6-overlay-x86_64.tar.xz

# PHP extensions needed by Laravel + image work (mbstring: Laravel core + intervention/image v4).
# imagick: CoverGenerator prefers it over gd — its pixel cache is C-side (bounded by
# resource limits, spills to disk, so it can't trip PHP's memory_limit) and it does
# libjpeg shrink-on-load, decoding large JPEG covers at a fraction of full size.
RUN install-php-extensions pdo_mysql zip gd imagick intl opcache pcntl mbstring

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
