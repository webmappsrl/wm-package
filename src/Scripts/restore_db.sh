#!/bin/bash

set -e

log() { echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1"; }

# Load .env if present
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
[ -f "${PROJECT_ROOT}/.env" ] && set -o allexport && source "${PROJECT_ROOT}/.env" && set +o allexport

# Defaults
SQL_DUMP_GZ_FILE="${SQL_DUMP_GZ_FILE:-last_dump.sql.gz}"
SQL_DUMP_FILE_NAME="${SQL_DUMP_FILE_NAME:-last_dump.sql}"
WIPE_DATABASE="${WIPE_DATABASE:-true}"
PHP_ARTISAN_COMMAND="${PHP_ARTISAN_COMMAND:-php artisan}"
CLEANUP_DUMP_FILES="${CLEANUP_DUMP_FILES:-true}"
DB_USER="${DB_USERNAME}"
DB_NAME="${DB_DATABASE}"
DB_CONTAINER_NAME="${DB_CONTAINER_NAME:-postgres_${APP_NAME}}"
PHP_CONTAINER_NAME="${PHP_CONTAINER_NAME}"

SQL_DUMP_GZ_PATH="${PROJECT_ROOT}/storage/backups/${SQL_DUMP_GZ_FILE}"
SQL_DUMP_PATH="${PROJECT_ROOT}/storage/backups/${SQL_DUMP_FILE_NAME}"

# Auto-detect PHP container if not set
if [ -z "$PHP_CONTAINER_NAME" ] && [ -n "$APP_NAME" ]; then
  for cn in \
    "php_${APP_NAME}" "php81_${APP_NAME}" "php82_${APP_NAME}" \
    "php83_${APP_NAME}" "${APP_NAME}_php_1" "${APP_NAME}-php-1" \
    "${APP_NAME}_php-fpm_1" "${APP_NAME}-php-fpm-1" "php"
  do
    if docker ps --filter "name=^/${cn}$" --format "{{.Names}}" | grep -q "^${cn}$"; then
      PHP_CONTAINER_NAME=$cn; break
    fi
  done
fi

# Prerequisite checks
[ -f "$SQL_DUMP_GZ_PATH" ] || { log "ERROR: $SQL_DUMP_GZ_PATH not found."; exit 1; }
docker ps --filter "name=^/${DB_CONTAINER_NAME}$" --format "{{.Names}}" | grep -q "^${DB_CONTAINER_NAME}$" \
  || { log "ERROR: DB container '${DB_CONTAINER_NAME}' not running."; exit 1; }
if [ "$WIPE_DATABASE" = "true" ]; then
  [ -n "$PHP_CONTAINER_NAME" ] || { log "ERROR: PHP_CONTAINER_NAME not set."; exit 1; }
  docker ps --filter "name=^/${PHP_CONTAINER_NAME}$" --format "{{.Names}}" | grep -q "^${PHP_CONTAINER_NAME}$" \
    || { log "ERROR: PHP container '${PHP_CONTAINER_NAME}' not running."; exit 1; }
fi

# Decompress
log "Decompressing $SQL_DUMP_GZ_PATH..."
gunzip -c "$SQL_DUMP_GZ_PATH" > "$SQL_DUMP_PATH"

# Wipe DB
if [ "$WIPE_DATABASE" = "true" ]; then
  log "Wiping DB $DB_NAME via $PHP_CONTAINER_NAME..."
  docker exec -i "$PHP_CONTAINER_NAME" $PHP_ARTISAN_COMMAND db:wipe --force
fi

# Import
log "Importing $SQL_DUMP_PATH into $DB_NAME..."
cat "$SQL_DUMP_PATH" | docker exec -i "$DB_CONTAINER_NAME" psql -U "$DB_USER" -d "$DB_NAME"

cleanup_dump_files() {
    if [ "$CLEANUP_DUMP_FILES" = "true" ]; then
        log "Cleaning up temporary decompressed SQL file..."
        if [ -f "${SQL_DUMP_PATH}" ]; then
            rm -f "${SQL_DUMP_PATH}"
            log "Removed decompressed file: ${SQL_DUMP_PATH}"
        else
            log "No decompressed file to remove at ${SQL_DUMP_PATH}."
        fi
        # The original .sql.gz file (${SQL_DUMP_GZ_PATH}) will no longer be deleted here.
        log "Original gzipped dump file (${SQL_DUMP_GZ_PATH}) is kept."
        log "Temporary files partially cleaned up."
    else
        log "Skipping cleanup of temporary decompressed dump file. Original gzipped file is always kept."
    fi
}

cleanup_dump_files

log "Restore complete."