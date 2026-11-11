# Production Operations & Incident Response Runbook

## 1. GitHub Actions Secrets Configuration

To run the automated CD pipeline (`.github/workflows/cd.yml`) from a public GitHub repository without exposing credentials, configure the following **Repository Secrets** (`Settings` $\rightarrow$ `Secrets and variables` $\rightarrow$ `Actions`):

| GitHub Secret Name | Description |
| :--- | :--- |
| `EC2_HOST` | Public DNS or IP of the target AWS EC2 instance |
| `EC2_USER` | SSH username on the Ubuntu host (e.g., `devops`) |
| `SSH_PRIVATE_KEY` | Full PEM/OpenSSH private key file content for the target host |
| `ANSIBLE_BECOME_PASS` | Sudo password for the SSH user |
| `MYSQL_ROOT_PASSWORD` | Strong password for MariaDB `root` user (min 8 chars) |
| `MYSQL_PASSWORD` | Strong password for WordPress database user (`wp_user`) |
| `GF_SECURITY_ADMIN_PASSWORD` | Strong password for Grafana admin login (`/grafana/`) |

---

## 2. Day-2 Operations Commands

All containers and configurations reside in `/opt/wordpress-stack` on the EC2 host.

### Check Service Health & Resource Usage
```bash
cd /opt/wordpress-stack
sudo docker compose ps
sudo docker stats --no-stream
free -h
```

### View Live Service Logs
```bash
cd /opt/wordpress-stack
sudo docker compose logs -f --tail=100 nginx wordpress mariadb
```

### Restart or Upgrade Stack
```bash
cd /opt/wordpress-stack
sudo docker compose up -d --build --remove-orphans
```

---

## 3. Backup & Disaster Recovery Procedures

### Trigger an Immediate Manual Backup
Automated backups run daily at `02:30 UTC` via root cron and write compressed archives to `/var/backups/wordpress` (with 7-day retention). To trigger an immediate backup:
```bash
sudo /opt/wordpress-stack/backup.sh
ls -lh /var/backups/wordpress/
```

### Restore MariaDB Database from Backup
```bash
source /opt/wordpress-stack/.env
zcat /var/backups/wordpress/db_<TIMESTAMP>.sql.gz | \
  sudo docker exec -i mariadb mariadb -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}"
```

---

## 4. Packaging Files for Email Attachment (`.txt` Rule)

Per the assignment submission instructions, all non-`.txt` text files can be automatically cloned with a `.txt` extension appended (e.g., `playbook.yml.txt`, `backup.sh.txt`) into `submission_txt/` by running:

```powershell
pwsh ./scripts/package-submission.ps1
```
