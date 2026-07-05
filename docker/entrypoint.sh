#!/usr/bin/env bash
#
# Entrypoint for the Multioto containers. The first argument selects the role:
#   web        — generate the app key (once), migrate, then serve
#   queue      — run the Horizon queue worker
#   scheduler  — run the Laravel scheduler loop
#
set -euo pipefail

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
        php artisan optimize
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
