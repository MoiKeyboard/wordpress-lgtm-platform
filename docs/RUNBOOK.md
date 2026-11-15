# Production Operations & Incident Response Runbook

## 1. GitHub Actions Secrets Configuration

To run the automated CD pipeline (`.github/workflows/cd.yml`) from a public GitHub repository without exposing credentials, configure the following **Repository Secrets** (`Settings` $\rightarrow$ `Secrets and variables` $\rightarrow$ `Actions`):

| GitHub Secret Name | Description | Required? |
| :--- | :--- | :---: |
| `EC2_HOST` | Public DNS or IP of the target AWS EC2 instance | Yes |
| `EC2_USER` | SSH username on the Ubuntu host (e.g., `devops`) | Yes |
| `SSH_PRIVATE_KEY` | Full PEM/OpenSSH private key file content for the target host | Yes |
| `ANSIBLE_BECOME_PASS` | Sudo password for the SSH user (`spoved`) | Yes |
| `APP_MASTER_SECRET` | **Single unified secret** for all internal applications (MariaDB, WordPress, Grafana) | **Yes (Recommended)** |
| `MYSQL_ROOT_PASSWORD` | Optional override for MariaDB root password (defaults to `APP_MASTER_SECRET`) | No |
| `MYSQL_PASSWORD` | Optional override for WordPress DB user password (defaults to `APP_MASTER_SECRET`) | No |
| `GF_SECURITY_ADMIN_PASSWORD` | Optional override for Grafana admin password (defaults to `APP_MASTER_SECRET`) | No |

---

## 2. Automated Secret Rotation Procedure

To rotate application passwords without manual intervention or configuration drift:
1. Navigate to **GitHub Repository** $\rightarrow$ `Settings` $\rightarrow$ `Secrets and variables` $\rightarrow$ `Actions`.
2. Update `APP_MASTER_SECRET` with a new strong secret value.
3. Trigger the **CD - Deploy WordPress & LGTM Stack to AWS EC2** workflow (or push a commit to `main`).
4. Ansible securely re-renders `/opt/wordpress-stack/.env` (`chmod 0600`) and restarts the container stack with the rotated credentials.

---

## 3. Grafana Access Control & Anonymous Read-Only Mode

To minimize attack surface on public endpoints while providing zero-friction visibility for assessment and monitoring:
- **Anonymous Viewer Mode**: Enabled by default (`GF_AUTH_ANONYMOUS_ENABLED=true`, `GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer`). Anyone navigating to `https://<EC2_HOST>/grafana/` is automatically granted **read-only Viewer** privileges to inspect dashboards, Loki logs, and Tempo traces without entering credentials.
- **Attack Surface Elimination**: User registration is disabled (`GF_USERS_ALLOW_SIGN_UP=false`), organization creation is disabled (`GF_USERS_ALLOW_ORG_CREATE=false`), and password bruteforcing on public forms is mitigated.
- **Admin Access**: Administrative access (for datasource editing or system settings) is reserved for authenticated users via `admin` and `APP_MASTER_SECRET` via the "Sign In" button.

---

## 4. Day-2 Operations Commands

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

## 5. Backup & Disaster Recovery Procedures

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

## 6. Packaging Files for Email Attachment (`.txt` Rule)

Per the assignment submission instructions, all non-`.txt` text files can be automatically cloned with a `.txt` extension appended (e.g., `playbook.yml.txt`, `backup.sh.txt`) into `submission_txt/` by running:

```powershell
pwsh ./scripts/package-submission.ps1
```
