#!/usr/bin/env bash
#
# Entrypoint for the Multioto containers. The first argument selects the role:
#   web        — generate the app key (once), migrate, then serve
#   queue      — run the Horizon queue worker
#   scheduler  — run the Laravel scheduler loop
#
set -euo pipefail

# Guard the classic bind-mount gotcha: if the host ./.env did not exist when
# the stack came up, Docker creates it as a *directory*. Fail loudly with the
# fix instead of silently booting without an APP_KEY.
if [ -d .env ]; then
    echo "ERROR: /app/.env is a directory, not a file." >&2
    echo "On the host run:  rm -rf .env && cp .env.example .env  then 'docker compose up -d'." >&2
    exit 1
fi
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Block until PostgreSQL accepts a connection (db:show connects and exits
# non-zero when the server isn't reachable yet).
wait_for_db() {
    echo "→ Waiting for database..."
    until php artisan db:show >/dev/null 2>&1; do
        sleep 2
    done
}

# The web container owns key generation; the others wait for it so all three
# share one APP_KEY (no race writing to the mounted .env).
wait_for_key() {
    echo "→ Waiting for app key..."
    until grep -q '^APP_KEY=base64:' .env 2>/dev/null; do
        sleep 2
    done
}

case "${1:-web}" in
    web)
        if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
            php artisan key:generate --force
        fi
        wait_for_db
        echo "→ Running migrations"
        php artisan migrate --force
        # Ensure the panel is usable out of the box: starter plans so the
        # onboarding wizard has something to pick. Idempotent — never overwrites
        # plans the team has edited.
        php artisan app:seed-plans || true
        # Clear any stale caches but do NOT config:cache — caching would freeze
        # whatever DB_CONNECTION is in .env (sqlite by default) instead of the
        # pgsql value the compose environment injects. Reading config live keeps
        # the container's env authoritative. (Also avoids route:cache choking on
        # the closure route.)
        php artisan optimize:clear
        echo "→ Serving on :8000"
        exec php artisan serve --host=0.0.0.0 --port=8000
        ;;
    queue)
        wait_for_key
        wait_for_db
        exec php artisan horizon
        ;;
    scheduler)
        wait_for_key
        wait_for_db
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
