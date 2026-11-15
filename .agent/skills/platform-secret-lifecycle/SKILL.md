---
name: platform-secret-lifecycle
description: Manage the zero-downtime rotation and verification of the unified APP_MASTER_SECRET across MariaDB, WordPress, and Grafana without repository leakage.
---

# Platform Skill: Secret Lifecycle & Zero-Downtime Rotation

## Overview
This skill governs the generation, distribution, rotation, and auditing of application secrets within the platform. It enforces the **Zero-Secrets-In-Git** principle and coordinates the rotation of `APP_MASTER_SECRET` across MariaDB, WordPress, and Grafana without introducing downtime or configuration drift.

---

## 1. Cryptographic Entropy Generation

Never use predictable or dictionary-based strings for infrastructure passphrases. Generate a high-entropy secret using OpenSSL or Python's `secrets` module:

```bash
# Generate 32-byte (256-bit) base64-encoded secret without special character shell hazards
NEW_SECRET=$(python3 -c "import secrets; print(secrets.token_urlsafe(32))")
echo "Generated High-Entropy Master Secret."
```

---

## 2. Automated GitOps Rotation Workflow (Recommended)

1. **Update GitHub Repository Secret:**
   ```bash
   gh secret set APP_MASTER_SECRET --body "${NEW_SECRET}"
   ```
2. **Trigger Automated Ansible Deployment:**
   ```bash
   gh workflow run "cd.yml" --ref main
   ```
3. **What Ansible Automates on the Host:**
   - Securely re-templates `/opt/wordpress-stack/.env` with `owner=root`, `group=root`, `mode=0600`.
   - Derives updated credentials for MariaDB root, WordPress DB user, and Grafana admin.
   - Restarts containers with zero credential drift.

---

## 3. Manual / In-Place Zero-Downtime Rotation Runbook

If performing an emergency secret rotation directly on the EC2 host:

```bash
cd /opt/wordpress-stack

# 1. Update the .env file with strict permissions
sudo sed -i "s/^APP_MASTER_SECRET=.*/APP_MASTER_SECRET=${NEW_SECRET}/" .env
sudo sed -i "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=${NEW_SECRET}/" .env
sudo sed -i "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=${NEW_SECRET}/" .env
sudo sed -i "s/^GF_SECURITY_ADMIN_PASSWORD=.*/GF_SECURITY_ADMIN_PASSWORD=${NEW_SECRET}/" .env
sudo chmod 0600 .env

# 2. Update MariaDB Running Credentials Dynamically
sudo docker exec mariadb mariadb -u root -p"${OLD_SECRET}" -e "
  ALTER USER 'root'@'localhost' IDENTIFIED BY '${NEW_SECRET}';
  ALTER USER 'wp_user'@'%' IDENTIFIED BY '${NEW_SECRET}';
  FLUSH PRIVILEGES;
"

# 3. Gracefully Reload WordPress PHP-FPM and Grafana
sudo docker compose up -d --no-deps wordpress grafana
```

---

## 4. Secret Leakage Verification & Compliance Audit

Verify that no unencrypted credentials exist in Git history, task logs, or open file paths:

```bash
# 1. Run Gitleaks Secret Scanner across Git commit history
gitleaks detect --source=. --verbose --redact

# 2. Verify Host .env File Permissions (Must be strictly 0600, root:root)
stat -c "%a %U:%G" /opt/wordpress-stack/.env | grep -q "600 root:root"

# 3. Ensure no secrets are echoed in Ansible task outputs
grep -E "no_log: true" ansible/roles/wordpress_lgtm_stack/tasks/main.yml
```

---

## 5. Security Guardrails & Anti-Patterns
* **NEVER** commit `.env`, `.pem`, `.key`, or credentials to Git (enforced by `.gitignore` and CI `gitleaks`).
* **NEVER** pass raw passwords in process argument lists visible to `ps aux` (`-p"${PASSWORD}"` inside containers must use environment or configuration file feeds where possible).
* **ALWAYS** verify application database connectivity immediately after rotation:
  ```bash
  sudo docker exec wordpress wp core is-installed --allow-root
  ```
