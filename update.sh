#!/usr/bin/env bash
#
# Safe in-place update from GitHub. Pulls the latest code and applies only NEW
# database migrations — it never drops tables or wipes data. Your PostgreSQL
# data and uploaded files live in Docker named volumes (pgdata, storage) and
# survive every update and rebuild.
#
# Usage on the server:
#   ./update.sh            # Docker install (default)
#   ./update.sh --native   # non-Docker (Forge/Ploi/VPS) install
#
set -euo pipefail

MODE="${1:-}"

echo "→ Fetching latest code"
git fetch origin
git checkout main
git pull --ff-only origin main

# Stamp the deployed version so the admin panel's "מערכת ועדכונים" screen shows it.
mkdir -p ops
printf '{"sha":"%s","short":"%s","date":"%s"}\n' \
    "$(git rev-parse HEAD 2>/dev/null || echo unknown)" \
    "$(git rev-parse --short HEAD 2>/dev/null || echo unknown)" \
    "$(date '+%Y-%m-%d %H:%M')" > ops/version.json

if [ "$MODE" = "--native" ]; then
    echo "→ Installing dependencies"
    composer install --no-dev --optimize-autoloader --no-interaction
    [ -f package-lock.json ] && npm ci && npm run build || true
    php artisan filament:assets
    echo "→ Applying new migrations (data preserved)"
    php artisan migrate --force
    php artisan optimize
    php artisan horizon:terminate || true
    echo "✓ Updated. Queue workers will restart automatically."
else
    echo "→ Rebuilding containers (named volumes keep your data)"
    docker compose up -d --build
    echo "→ Applying new migrations (data preserved)"
    docker compose exec -T app php artisan migrate --force
    docker compose exec -T app php artisan optimize
    echo "✓ Updated. All data and uploads are intact."
fi
