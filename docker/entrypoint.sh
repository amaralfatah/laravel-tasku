#!/bin/sh
set -e

# Configuration is cached here rather than at build time so that the image
# never carries baked-in secrets and can be promoted between environments.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Render runs migrations through `preDeployCommand`, which fires once per
# deploy. This is the fallback for platforms without that hook; leave it off
# wherever more than one instance boots at the same time.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
