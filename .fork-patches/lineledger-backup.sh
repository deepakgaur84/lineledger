#!/bin/bash
set -euo pipefail

# LineLedger nightly backup — merged task.
#
# 1. Exports every company's data synchronously (blocks until all are done,
#    so it's safe to immediately move the resulting files — no queue-timing
#    guesswork).
# 2. Moves every finished backup ZIP off the app's own storage volume to a
#    separate NAS share (different physical protection than the docker
#    volume it started on), renamed to <timestamp>-<company-slug>.zip.
#    LineLedger organizes backups per-company as
#    backups/<company_id>/<id>-<timestamp>.zip — the numeric id is dropped
#    entirely from the offsite name, replaced by the slug, and reordered
#    so files sort chronologically by filename.
# 3. Cleans up the now-empty per-company subfolders left behind in the
#    source directory (cosmetic — nothing depends on them existing).
# 4. Deletes anything older than 365 days from the offsite folder.
#
# Run this via DSM's Task Scheduler (Control Panel > Task Scheduler >
# Create > Scheduled Task > User-defined script), not cron directly — gives
# you a GUI to set timing, and DSM emails you its own stdout/stderr if the
# script fails, independent of LineLedger's own SchedulerFailureAlert.

COMPOSE_DIR="/volume1/docker/lineledger"
CONTAINER="lineledger-app-1"
MYSQL_CONTAINER="lineledger-mysql-1"
SOURCE_DIR="$COMPOSE_DIR/storage/app/private/backups"
DEST_DIR="/volume1/Backup/docker/lineledger"

# Backup retention used to be 365 days - changed to 90 days
RETENTION_DAYS=90
#RETENTION_DAYS=365

cd "$COMPOSE_DIR"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting LineLedger backup run..."

# --- Step 1: export every company (blocking) ---
if ! docker exec "$CONTAINER" php artisan backups:export-all; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: backups:export-all reported a failure — check its output above."
    echo "Continuing to move/prune whatever backups DID complete, rather than aborting entirely."
fi

# --- Build a company_id -> slug lookup, for naming the offsite files ---
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2-)
DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2-)
DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2-)

declare -A SLUG_MAP
while IFS=$'\t' read -r id slug; do
    SLUG_MAP["$id"]="$slug"
done < <(docker exec "$MYSQL_CONTAINER" mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B -e "SELECT id, slug FROM companies;" 2>/dev/null)

# --- Step 2: move finished ZIPs to the offsite backup share, renamed ---
mkdir -p "$DEST_DIR"

moved=0
if [ -d "$SOURCE_DIR" ]; then
    # -mindepth 2: backups live one level down, in a per-company subfolder
    # (backups/<company_id>/<file>.zip).
    while IFS= read -r -d '' zip_path; do
        company_id=$(basename "$(dirname "$zip_path")")
        original_name=$(basename "$zip_path")
        slug="${SLUG_MAP[$company_id]:-company-$company_id}"

        # Original filename is "<backup_id>-<timestamp>.zip" — note the
        # leading number is the backup record's own id, NOT the company id
        # (the folder name), so it can't be stripped by matching company_id.
        # Pull the timestamp out by its fixed shape instead (8-digit date,
        # dash, 6-digit time) regardless of what precedes it.
        if [[ "$original_name" =~ ([0-9]{8}-[0-9]{6})\.zip$ ]]; then
            timestamp="${BASH_REMATCH[1]}"
        else
            # Fallback if the naming pattern ever changes upstream: keep the
            # original name rather than silently mangling it.
            timestamp="${original_name%.zip}"
        fi
        new_name="${timestamp}-${slug}.zip"

        mv "$zip_path" "$DEST_DIR/$new_name"
        echo "Moved: $original_name -> $new_name"
        moved=$((moved + 1))
    done < <(find "$SOURCE_DIR" -mindepth 2 -type f -name "*.zip" -print0)

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Moved $moved backup file(s) to $DEST_DIR."

    # --- Step 3: clean up now-empty per-company subfolders ---
    # Only the numbered company-id folders — never touch tmp/, in case
    # LineLedger expects it to persist regardless of emptiness.
    removed_dirs=$(find "$SOURCE_DIR" -mindepth 1 -maxdepth 1 -type d -empty ! -name tmp -print -delete | wc -l)
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Removed $removed_dirs empty folder(s) from $SOURCE_DIR."
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WARNING: source folder $SOURCE_DIR does not exist — nothing to move."
fi

# --- Step 4: prune anything older than RETENTION_DAYS from the offsite folder ---
deleted=$(find "$DEST_DIR" -type f -name "*.zip" -mtime +"$RETENTION_DAYS" -print -delete | wc -l)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Pruned $deleted backup file(s) older than $RETENTION_DAYS days from $DEST_DIR."

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup run complete."