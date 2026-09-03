# syntax=docker/dockerfile:1

# Single image for every process type. The web service serves it through
# FrankenPHP; the worker service runs the same image with another command.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.3-bookworm
ARG NODE_IMAGE=node:22-bookworm-slim

# Named so the assets stage can copy the Node toolchain out of it.
FROM ${NODE_IMAGE} AS node

# ---------------------------------------------------------------------------
# base — the PHP runtime shared by the build stages and the final image.
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base

WORKDIR /app

# pdo_pgsql for Neon, zip/gd/intl for PhpSpreadsheet, pcntl for queue workers.
RUN install-php-extensions \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-tasku.ini

# The writable tree is not part of the build context, and Artisan refuses to
# boot without it — the build stages need it as much as the runtime does.
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

# ---------------------------------------------------------------------------
# vendor — composer dependencies, production only.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Packages first so the download layer survives application edits.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# ---------------------------------------------------------------------------
# assets — Vite build. Needs PHP as well as Node, because the Wayfinder plugin
# shells out to `php artisan wayfinder:generate` during the build.
# ---------------------------------------------------------------------------
FROM base AS assets

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

ENV APP_ENV=production \
    NODE_ENV=production

COPY package.json package-lock.json .npmrc ./
RUN npm ci --include=dev --no-audit --no-fund

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Wayfinder boots Artisan mid-build, which wants a key. This one is scoped to
# the command, so it lands in no layer and no image.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= npm run build

# ---------------------------------------------------------------------------
# runtime — what actually ships.
# ---------------------------------------------------------------------------
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=vendor /app /app
COPY --from=assets /app/public/build ./public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh docker/worker.sh /usr/local/bin/

RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/worker.sh \
    && php artisan package:discover --ansi

# Render overrides this with its own PORT; the Caddyfile reads the same value.
ENV PORT=10000
EXPOSE 10000

# The base image probes the Caddy admin API, which is switched off here, and
# the worker role serves no HTTP at all. Liveness is the platform's job —
# Render uses `healthCheckPath: /up` from render.yaml.
HEALTHCHECK NONE

ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
