# Production Operations & Incident Response Runbook

## 1. Zero-to-Hero: Provisioning a New EC2 Instance from Scratch

Follow this procedure to bootstrap this platform onto a brand new AWS EC2 virtual machine.

### Target Node Requirements
* **OS:** Ubuntu 22.04 LTS (Jammy Jellyfish, 64-bit x86).
* **Instance Type:** `t2.micro` (1 vCPU, 965 MiB RAM) or larger.
* **Storage:** $\ge 20\text{ GB}$ gp2/gp3 EBS volume.
* **AWS Security Group:** Inbound TCP `22` (SSH), `80` (HTTP), `443` (HTTPS) from allowed evaluation IP ranges.
* **User & Privileges:** An SSH user (e.g. `ubuntu` or `devops`) with passwordless or standard `sudo` rights.

### Deployment Workflow (GitOps CD)
1. Fork or clone this repository to your GitHub account:
   ```bash
   git clone https://github.com/<YOUR_USER>/wordpress-lgtm-platform.git
   ```
2. In your GitHub repository, configure the 5 secrets under **Settings** $\rightarrow$ **Secrets and variables** $\rightarrow$ **Actions**:
   - `EC2_HOST`: Public IP or DNS of the new EC2 instance.
   - `EC2_USER`: SSH username (`ubuntu` or `devops`).
   - `SSH_PRIVATE_KEY`: Content of the private key (`.pem` file).
   - `ANSIBLE_BECOME_PASS`: Sudo password on the target instance.
   - `APP_MASTER_SECRET`: Cryptographically strong passphrase for DB and admin.
3. Trigger deployment by pushing to `main` or clicking **Run workflow** under **Actions** $\rightarrow$ **CD - Deploy WordPress & LGTM Stack to AWS EC2**.
4. The CD pipeline will execute Ansible, allocate swap, install Docker Engine, deploy the containers, and run automated HTTPS smoke tests.

### Alternative: Direct CLI Deployment via Ansible
```bash
# 1. Copy sample inventory
cp ansible/inventory.ini.example ansible/inventory.ini

# 2. Populate host IP and SSH credentials in ansible/inventory.ini
# 3. Execute Ansible Playbook
ansible-playbook -i ansible/inventory.ini ansible/playbook.yml \
  -e "DOMAIN_NAME=YOUR_EC2_PUBLIC_IP" \
  -e "APP_MASTER_SECRET=YourStrongPasswordHere_32Chars!"
```

---

## 2. GitHub Actions Secrets Configuration

| GitHub Secret Name | Description | Required? |
| :--- | :--- | :---: |
| `EC2_HOST` | Public DNS or IP of the target AWS EC2 instance | Yes |
| `EC2_USER` | SSH username on the Ubuntu host (e.g., `devops` or `ubuntu`) | Yes |
| `SSH_PRIVATE_KEY` | Full PEM/OpenSSH private key file content for the target host | Yes |
| `ANSIBLE_BECOME_PASS` | Sudo password for the SSH user | Yes |
| `APP_MASTER_SECRET` | **Single unified secret** for all internal applications (MariaDB, WordPress, Grafana) | **Yes (Recommended)** |
| `MYSQL_ROOT_PASSWORD` | Optional override for MariaDB root password (defaults to `APP_MASTER_SECRET`) | No |
| `MYSQL_PASSWORD` | Optional override for WordPress DB user password (defaults to `APP_MASTER_SECRET`) | No |
| `GF_SECURITY_ADMIN_PASSWORD` | Optional override for Grafana admin password (defaults to `APP_MASTER_SECRET`) | No |

---

## 3. Automated Secret Rotation Procedure

To rotate application passwords without manual intervention or configuration drift:
1. Navigate to **GitHub Repository** $\rightarrow$ `Settings` $\rightarrow$ `Secrets and variables` $\rightarrow$ `Actions`.
2. Update `APP_MASTER_SECRET` with a new strong secret value.
3. Trigger the **CD - Deploy WordPress & LGTM Stack to AWS EC2** workflow (or push a commit to `main`).
4. Ansible securely re-renders `/opt/wordpress-stack/.env` (`chmod 0600`) and restarts the container stack with the rotated credentials.

---

## 4. Grafana Access Control & Anonymous Read-Only Mode

To minimize attack surface on public endpoints while providing zero-friction visibility for assessment and monitoring:
- **Anonymous Viewer Mode**: Enabled by default (`GF_AUTH_ANONYMOUS_ENABLED=true`, `GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer`). Anyone navigating to `https://<EC2_HOST>/grafana/` is automatically granted **read-only Viewer** privileges to inspect dashboards, Loki logs, and Tempo traces without entering credentials.
- **Attack Surface Elimination**: User registration is disabled (`GF_USERS_ALLOW_SIGN_UP=false`), organization creation is disabled (`GF_USERS_ALLOW_ORG_CREATE=false`), and password bruteforcing on public forms is mitigated.
- **Admin Access**: Administrative access (for datasource editing or system settings) is reserved for authenticated users via `admin` and `APP_MASTER_SECRET` via the "Sign In" button.

---

## 5. Day-2 Operations & Service Triage

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
sudo docker compose logs -f --tail=100 nginx wordpress mariadb tempo
```

### Observability Troubleshooting Runbook

#### 1. Diagnosing Missing Traces or Tempo Timeouts (504)
- **Check Kernel OOM Kills:**
  ```bash
  dmesg -T | grep -i oom
  ```
  If `tempo` was killed by cgroup OOM, ensure `mem_limit` is at least `140m` and `GOMEMLIMIT=95MiB`.
- **Verify Tempo gRPC Listener:**
  ```bash
  docker logs --tail 50 tempo | grep -i "9095"
  ```
  Tempo must log `adding connection addr=127.0.0.1:9095`.
- **Direct Query Test:**
  ```bash
  curl -s -k "https://127.0.0.1/grafana/api/datasources/proxy/uid/tempo/api/search?limit=5"
  ```

#### 2. Diagnosing Slow Response or High CPU Steal
On `t2.micro`, burst credit depletion leads to CPU throttling (`%st` in `top` / `vmstat 1` up to 80%+).
- Check CPU steal: `vmstat 1 5`
- Check swap activity: `swapon --show` and `vmstat 1 5` (si/so columns)

---

## 6. Backup & Disaster Recovery Procedures

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

## 7. Packaging Files for Email Attachment (`.txt` Rule)

Per the assignment submission instructions, all non-`.txt` text files can be automatically cloned with a `.txt` extension appended into `submission_txt/` by running:

```powershell
pwsh ./scripts/package-submission.ps1
```
