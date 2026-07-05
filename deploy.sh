#!/usr/bin/env bash
#
# Deployment script for Multioto (Laravel).
# Use as the Deploy Script in Laravel Forge / Ploi, or run manually on a VPS.
# See docs/deployment.md for the full guide.

set -euo pipefail

echo "→ Pulling latest code"
git pull origin main

echo "→ Installing PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "→ Building front-end assets"
if [ -f package-lock.json ]; then
    npm ci
    npm run build
fi

echo "→ Publishing Filament assets"
php artisan filament:assets

echo "→ Running database migrations"
php artisan migrate --force

echo "→ Caching config, routes, views"
php artisan optimize

echo "→ Restarting queue workers (Horizon)"
php artisan horizon:terminate || true

echo "✓ Deploy complete"
