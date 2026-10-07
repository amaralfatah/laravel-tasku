# syntax=docker/dockerfile:1

# FrankenPHP serves Laravel straight from public/ with its built-in Caddy, so a
# single process replaces the usual nginx + php-fpm pair.
FROM dunglas/frankenphp:1-php8.3 AS base

# Cap parallel compile jobs: one per core OOMs on many-core builders.
RUN IPE_PROCESSOR_COUNT=4 install-php-extensions pdo_pgsql gd zip intl bcmath opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Front-end assets. Bun installs, Node runs Vite (see .ai/rules/general.md),
# and the Wayfinder Vite plugin calls `php artisan wayfinder:generate`, so this
# stage needs PHP, the Composer dependencies and both JavaScript toolchains.
FROM base AS assets

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=oven/bun:1.4.0 /usr/local/bin/bun /usr/local/bin/bun

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY package.json bun.lock ./
RUN bun install --frozen-lockfile

COPY . .
# Booting the framework at build time must not reach for drivers that need a
# database, so pin the few values the boot reads.
RUN composer dump-autoload --optimize \
    && CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync bun run build

FROM base AS app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan package:discover --ansi \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000

EXPOSE 10000

ENTRYPOINT ["docker/entrypoint.sh"]
