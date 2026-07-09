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

# Deploy the branch the server is currently on (override with DEPLOY_BRANCH).
# We intentionally do NOT force-checkout a fixed branch: an empty/placeholder
# branch would wipe the working tree. Whatever is deployed here gets updated.
DEPLOY_BRANCH="${DEPLOY_BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"

echo "→ Fetching latest code (branch: $DEPLOY_BRANCH)"
git fetch origin "$DEPLOY_BRANCH"
git checkout "$DEPLOY_BRANCH"
git pull --ff-only origin "$DEPLOY_BRANCH"

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
    # Clear caches only — do NOT config:cache here. Under Docker the real
    # DB_CONNECTION (pgsql) is injected via compose environment, while .env
    # still holds the sqlite default; config:cache would freeze the wrong value,
    # and a half-written bootstrap/cache/config.php breaks boot with
    # "require(bootstrap/cache/config.php): No such file or directory". This
    # matches docker/entrypoint.sh, which reads config live on purpose.
    docker compose exec -T app php artisan optimize:clear
    echo "✓ Updated. All data and uploads are intact."
fi
