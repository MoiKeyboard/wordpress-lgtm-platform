#!/usr/bin/env bash
# =============================================================================
# Automated Daily Backup Script for MariaDB & WordPress wp-content
# Scheduled via Ansible Cron at 02:30 UTC daily with 7-day retention
# =============================================================================
set -euo pipefail

STACK_DIR="/opt/wordpress-stack"
BACKUP_ROOT="/var/backups/wordpress"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
RETENTION_DAYS=7

mkdir -p "${BACKUP_ROOT}"

# Load credentials from root-owned 0600 .env
if [[ -f "${STACK_DIR}/.env" ]]; then
    # shellcheck disable=SC1091
    source "${STACK_DIR}/.env"
else
    echo "[ERROR] ${STACK_DIR}/.env not found" >&2
    exit 1
fi

echo "[$(date -Is)] Starting MariaDB database dump..."
docker exec mariadb mariadb-dump \
    -u root \
    -p"${MYSQL_ROOT_PASSWORD}" \
    --single-transaction \
    --quick \
    "${MYSQL_DATABASE}" | gzip > "${BACKUP_ROOT}/db_${TIMESTAMP}.sql.gz"

echo "[$(date -Is)] Starting WordPress wp-content volume archive..."
docker run --rm \
    -v wordpress-stack_wp_data:/wp_data:ro \
    -v "${BACKUP_ROOT}:/backup" \
    alpine:3.20 \
    tar -czf "/backup/wp_content_${TIMESTAMP}.tar.gz" -C /wp_data wp-content

chmod 0600 "${BACKUP_ROOT}/db_${TIMESTAMP}.sql.gz" "${BACKUP_ROOT}/wp_content_${TIMESTAMP}.tar.gz"

echo "[$(date -Is)] Pruning backups older than ${RETENTION_DAYS} days..."
find "${BACKUP_ROOT}" -type f -name "*.gz" -mtime +"${RETENTION_DAYS}" -delete

echo "[$(date -Is)] Backup completed successfully."
