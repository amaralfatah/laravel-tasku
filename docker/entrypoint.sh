#!/usr/bin/env bash
set -euo pipefail

cd /app

php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

# Daily notification commands from routes/console.php. One container, so the
# scheduler runs in the background next to the web server.
if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
    php artisan schedule:work &
fi

exec frankenphp php-server --root public --listen ":${PORT}"
