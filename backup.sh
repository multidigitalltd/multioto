#!/usr/bin/env bash
#
# Daily PostgreSQL backup for the Docker install. Dumps the database to a
# timestamped, gzipped file and keeps the most recent N days.
#
# Usage:  ./backup.sh            (writes to ./backups)
# Cron:   0 3 * * *  cd /path/to/multioto && ./backup.sh >> backups/backup.log 2>&1
#
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-./backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
DB_NAME="${DB_DATABASE:-multioto}"
DB_USER="${DB_USERNAME:-multioto}"

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$BACKUP_DIR/multioto-$STAMP.sql.gz"

echo "→ Dumping database to $OUT"
docker compose exec -T postgres pg_dump -U "$DB_USER" "$DB_NAME" | gzip > "$OUT"

echo "→ Pruning backups older than $KEEP_DAYS days"
find "$BACKUP_DIR" -name 'multioto-*.sql.gz' -type f -mtime "+$KEEP_DAYS" -delete

echo "✓ Backup complete: $OUT"

# Restore (manual):
#   gunzip -c backups/multioto-YYYYMMDD-HHMMSS.sql.gz | \
#     docker compose exec -T postgres psql -U multioto -d multioto
