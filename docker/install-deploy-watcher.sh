#!/usr/bin/env bash
#
# One-time setup: install the deploy watcher as a once-a-minute cron job for the
# current user, so the "עדכן עכשיו" button in the admin panel actually deploys.
#
# Safe to re-run — it replaces its own cron line and never touches others.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WATCHER="$ROOT/docker/deploy-watcher.sh"
MARKER="# multioto-deploy-watcher"

chmod +x "$WATCHER"
mkdir -p "$ROOT/ops"

# Keep every existing cron line except our previous marker, then append ours.
( crontab -l 2>/dev/null | grep -v "$MARKER" || true
  echo "* * * * * bash $WATCHER >> $ROOT/ops/watcher.log 2>&1 $MARKER"
) | crontab -

echo "✓ סוכן העדכון הותקן. מעתה כפתור 'עדכן עכשיו' בממשק יבצע עדכון תוך כדקה."
echo "  לוג: $ROOT/ops/watcher.log"
