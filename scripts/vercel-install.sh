#!/usr/bin/env bash
set -euo pipefail

dnf install -y -q \
  php8.3-cli php8.3-mbstring php8.3-xml php8.3-pdo php8.3-pgsql \
  php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-opcache

curl -sS https://getcomposer.org/installer \
  | php -- --quiet --install-dir=/usr/local/bin --filename=composer

# `composer install` runs `artisan package:discover`, which boots the framework.
# Blank build-time environment variables would otherwise resolve to an empty
# string instead of falling through to the defaults in config/, so pin the few
# values the boot actually reads. Runtime keeps whatever Vercel injects.
export CACHE_STORE="${CACHE_STORE:-array}"
export SESSION_DRIVER="${SESSION_DRIVER:-array}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

npm ci
