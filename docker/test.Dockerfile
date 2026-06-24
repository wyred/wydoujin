# Throwaway image for running the Pest suite in Docker (see `make test`).
#
# NOT part of the production build (that's the root Dockerfile). It only carries
# PHP + the extensions the app needs + composer; the source is bind-mounted at
# run time and vendor lives in a named volume, so `composer install` is only slow
# on the first run. Tests use SQLite :memory: (see phpunit.xml), so no DB service
# is needed. Tag is unpinned on purpose — this is a dev tool, not a shipped image.
FROM dunglas/frankenphp:1-php8.3

# pdo_sqlite for the test DB; gd for intervention/image (cover generation tests);
# sockets is required by pest-plugin-browser (a dev dep, so composer install needs it).
RUN install-php-extensions pdo_sqlite pdo_mysql zip gd intl opcache pcntl mbstring sockets

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
