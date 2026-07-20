#!/usr/bin/env bash
#
# Host-side deploy agent. Watches the ops/ directory for a deploy request
# written by the admin panel ("עדכן עכשיו") and runs update.sh when it appears.
#
# This runs on the HOST (outside the containers), so it — and only it — is
# allowed to rebuild images and apply migrations. The web app never executes
# shell commands; it only drops a request flag. That privilege separation is
# the whole point.
#
# Install it as a once-a-minute cron via docker/install-deploy-watcher.sh.
#
set -euo pipefail

# Project root = parent of this script's directory.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OPS="$ROOT/ops"
REQUEST="$OPS/deploy.request"
LOCK="$OPS/deploy.lock"
STATUS="$OPS/deploy.status"
VERSION="$OPS/version.json"
AVAILABLE="$OPS/available.json"

mkdir -p "$OPS"

# Detect whether a newer version is waiting upstream, so the panel can show
# "עדכון זמין" with the count of pending commits. Best-effort — a fetch failure
# (offline) just leaves the previous state untouched.
check_available() {
    local branch behind short
    branch="$(git -C "$ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)"
    git -C "$ROOT" fetch --quiet origin "$branch" 2>/dev/null || return 0
    behind="$(git -C "$ROOT" rev-list --count "HEAD..origin/$branch" 2>/dev/null || echo 0)"

    if [ "${behind:-0}" -gt 0 ]; then
        short="$(git -C "$ROOT" rev-parse --short "origin/$branch" 2>/dev/null || echo unknown)"

        # Extract the highlights of the pending versions from the INCOMING build's
        # changelog, so the panel can show "why upgrade" before installing. We read
        # the JSON changelog (data, never executed) from git and diff via the app.
        # Best-effort: any failure just omits the highlights.
        local releases="[]"
        if git -C "$ROOT" show "origin/$branch:config/changelog.json" > "$OPS/incoming-changelog.json" 2>/dev/null; then
            releases="$(cd "$ROOT" && docker compose exec -T app php artisan app:changelog-diff ops/incoming-changelog.json 2>/dev/null || echo '[]')"
            [ -z "$releases" ] && releases="[]"
            rm -f "$OPS/incoming-changelog.json"
        fi

        printf '{"behind":%s,"short":"%s","at":"%s","releases":%s}\n' \
            "$behind" "$short" "$(date '+%Y-%m-%d %H:%M')" "$releases" > "$AVAILABLE"
    else
        rm -f "$AVAILABLE"
    fi
}

stamp_version() {
    local sha short date
    sha="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
    short="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
    date="$(date '+%Y-%m-%d %H:%M')"
    printf '{"sha":"%s","short":"%s","date":"%s"}\n' "$sha" "$short" "$date" > "$VERSION"
}

write_status() {
    # $1=state (success|failed)  $2=message
    printf '{"state":"%s","message":"%s","at":"%s"}\n' \
        "$1" "$(echo "$2" | tr -d '"' | tr '\n' ' ')" "$(date '+%Y-%m-%d %H:%M')" > "$STATUS"
}

# Stamp the version on every run so the panel shows the truth even before the
# first UI-triggered update, and refresh whether an upstream update is waiting.
stamp_version
check_available

# Nothing to do unless an update was requested and none is already running.
[ -f "$REQUEST" ] || exit 0
[ -f "$LOCK" ] && exit 0

mv "$REQUEST" "$LOCK"

if OUTPUT="$(cd "$ROOT" && bash update.sh 2>&1)"; then
    stamp_version
    check_available
    write_status success "העדכון הושלם בהצלחה"
else
    write_status failed "$(echo "$OUTPUT" | tail -n 3)"
fi

rm -f "$LOCK"
