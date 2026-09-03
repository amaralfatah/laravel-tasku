#!/usr/bin/env bash
set -euo pipefail

dnf install -y -q \
  php8.3-cli php8.3-mbstring php8.3-xml php8.3-pdo php8.3-pgsql \
  php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-opcache

curl -sS https://getcomposer.org/installer \
  | php -- --quiet --install-dir=/usr/local/bin --filename=composer

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

npm ci
