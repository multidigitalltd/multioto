#!/usr/bin/env bash
#
# Turn on browser (Web Push) notifications, once, on the server.
#
# Push needs a VAPID key pair. `php artisan webpush:vapid` writes it into .env —
# but run inside the container that .env is a copy baked into the image: it
# disappears on the next rebuild, and the running process never sees it anyway
# because compose reads env_file from the HOST at start. So the keys are
# generated in the container, printed, and written HERE, next to docker-compose.
#
# Safe to re-run: existing keys are left alone unless --force is given. The
# private key is never printed — it is the one secret that lets anyone send
# notifications to your team's browsers.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

FORCE="${1:-}"

if [ ! -f .env ]; then
    echo "✗ אין קובץ .env בתיקייה $ROOT"
    exit 1
fi

if grep -qE '^VAPID_PUBLIC_KEY=.+' .env && [ "$FORCE" != "--force" ]; then
    echo "✓ מפתחות VAPID כבר מוגדרים — אין מה לעשות."
    echo "  (להחלפה: bash docker/setup-push.sh --force — כל המנויים הקיימים יפסיקו לקבל ויצטרכו להירשם מחדש)"
    exit 0
fi

echo "→ מייצר מפתחות VAPID"
KEYS="$(docker compose exec -T app php artisan webpush:vapid --show 2>/dev/null | tr -d '\r')"

PUBLIC="$(echo "$KEYS" | grep '^VAPID_PUBLIC_KEY=' | cut -d= -f2-)"
PRIVATE="$(echo "$KEYS" | grep '^VAPID_PRIVATE_KEY=' | cut -d= -f2-)"

if [ -z "$PUBLIC" ] || [ -z "$PRIVATE" ]; then
    echo "✗ ייצור המפתחות נכשל. ודאו שהקונטיינרים רצים: docker compose ps"
    exit 1
fi

# A dated copy before touching the file that holds every secret this install has.
cp .env ".env.backup-$(date '+%Y%m%d-%H%M%S')"

# Drop any existing (blank or old) lines, then append the new pair. sed -i on the
# real file rather than a temp copy, so file ownership and permissions survive.
sed -i '/^VAPID_PUBLIC_KEY=/d;/^VAPID_PRIVATE_KEY=/d' .env
printf 'VAPID_PUBLIC_KEY=%s\nVAPID_PRIVATE_KEY=%s\n' "$PUBLIC" "$PRIVATE" >> .env

# VAPID_SUBJECT identifies the sender to the push services. Blank is accepted by
# some and rejected by others, so it is filled from APP_URL when missing.
if ! grep -qE '^VAPID_SUBJECT=.+' .env; then
    APP_URL="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"' || true)"
    printf 'VAPID_SUBJECT=%s\n' "${APP_URL:-https://localhost}" >> .env
fi

echo "→ מפעיל מחדש את הקונטיינרים כדי שיקראו את המפתחות"
docker compose up -d

echo
echo "✓ פושים הופעלו."
echo "  עכשיו: היכנסו לפאנל ← הפרופיל שלכם ← הפעילו 'התראות דפדפן' ואשרו בדפדפן."
echo "  כל חבר צוות שרוצה לקבל התראות עושה זאת בפרופיל שלו, מכל מכשיר בנפרד."
