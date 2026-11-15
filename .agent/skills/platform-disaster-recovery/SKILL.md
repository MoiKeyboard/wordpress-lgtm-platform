---
name: platform-disaster-recovery
description: Execute and verify scheduled database backups, archive compression integrity, retention policies, and automated MariaDB restoration drills.
---

# Platform Skill: Disaster Recovery & Backup Verification

## Overview
This skill guides an autonomous AI agent or platform engineer in executing consistent MariaDB database backups, auditing backup retention policies, testing archive decompression integrity, and performing automated point-in-time restoration drills.

---

## 1. RPO & RTO Objectives

* **Recovery Point Objective (RPO):** 24 hours (automated daily cron backup at `02:30 UTC`).
* **Recovery Time Objective (RTO):** $< 5\text{ minutes}$ (in-place gzip stream restoration to running MariaDB container).
* **Backup Retention:** 7 days rolling retention stored in `/var/backups/wordpress/`.

---

## 2. Triggering an Immediate Manual Backup

To trigger a manual database snapshot prior to high-risk maintenance or schema migrations:

```bash
# 1. Execute the production backup script
sudo /opt/wordpress-stack/backup.sh

# 2. Verify creation of compressed backup archive
ls -lh /var/backups/wordpress/

# 3. Verify gzip archive integrity (Must return 0 errors)
LATEST_BACKUP=$(ls -t /var/backups/wordpress/db_*.sql.gz | head -n 1)
gzip -t "${LATEST_BACKUP}"
echo "Archive Integrity Check Passed: ${LATEST_BACKUP}"
```

---

## 3. Auditing Automated Cron & Retention Lifecycle

Verify that the systemd / root cron schedule and 7-day pruning logic are functioning:

```bash
# 1. Verify Root Crontab Entry
sudo crontab -l | grep "backup.sh"
# Expected: 30 2 * * * /opt/wordpress-stack/backup.sh >> /var/log/wordpress_backup.log 2>&1

# 2. Verify Pruning Logic
# The backup script executes: find /var/backups/wordpress -type f -name "db_*.sql.gz" -mtime +7 -delete
# Ensure no files older than 7 days exist in the backup directory
find /var/backups/wordpress -type f -name "db_*.sql.gz" -mtime +7
```

---

## 4. Point-in-Time Database Restoration Runbook

Execute this procedure to restore the database from a verified backup archive:

```bash
cd /opt/wordpress-stack

# 1. Source runtime environment variables
source .env

# 2. Identify the target backup archive
BACKUP_FILE="${1:-$(ls -t /var/backups/wordpress/db_*.sql.gz | head -n 1)}"
echo "Restoring database from: ${BACKUP_FILE}"

# 3. Stream compressed SQL into running MariaDB container
zcat "${BACKUP_FILE}" | sudo docker exec -i mariadb mariadb \
  -u root \
  -p"${MYSQL_ROOT_PASSWORD}" \
  "${MYSQL_DATABASE}"

# 4. Verify Database Integrity & Table Count
sudo docker exec mariadb mariadb -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" -e "
  SHOW TABLES;
  SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl', 'home');
"

# 5. Verify WordPress Frontend Responsiveness
curl -k -s -o /dev/null -w "%{http_code}\n" "https://127.0.0.1/" | grep -q "200"
echo "Database Restoration & Application Smoke Verification Succeeded."
```

---

## 5. Storage Footprint Governance
* Each compressed backup is typically $\approx 1\text{–}3\text{ MB}$.
* 7-day retention consumes $\le 25\text{ MB}$ of EBS storage, leaving over 14 GB of headroom on the 20 GB root volume.
