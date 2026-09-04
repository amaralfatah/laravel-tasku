#!/usr/bin/env bash
set -euo pipefail

dnf install -y -q \
  php8.3-cli php8.3-mbstring php8.3-xml php8.3-pdo php8.3-pgsql \
  php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-opcache \
  unzip

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

# The build image ships its own Bun, and it is older than the one that wrote
# bun.lock — a lockfile from a newer Bun is unreadable to it ("Unknown lockfile
# version"). So pin the version here rather than trusting whatever is present,
# and keep it at an absolute path: the shell that runs `buildCommand` is a
# separate one, and its PATH may still resolve `bun` to the preinstalled copy.
BUN_VERSION=1.4.0
curl -fsSL https://bun.sh/install | bash -s "bun-v${BUN_VERSION}"
install -m 0755 "$HOME/.bun/bin/bun" /usr/local/bin/bun

/usr/local/bin/bun install --frozen-lockfile
