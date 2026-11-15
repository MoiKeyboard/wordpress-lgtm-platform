---
name: platform-ec2-provisioning
description: Automated pre-flight validation, bootstrapping, and idempotent Ansible deployment of the WordPress + LGTM stack to AWS EC2 target hosts.
---

# Platform Skill: EC2 Provisioning & Stack Bootstrapping

## Overview
This skill guides an autonomous AI agent or platform engineer in deploying, provisioning, and verifying the production WordPress and Grafana LGTM observability stack onto any target AWS EC2 virtual machine running Ubuntu 22.04 LTS.

---

## 1. Pre-Flight Discovery & Host Qualification

Before running Ansible convergence, inspect the target host to ensure it satisfies baseline compute and perimeter requirements:

```bash
# 1. Verify OS Distribution (Must be Ubuntu 22.04 LTS x86_64)
lsb_release -d
uname -m

# 2. Inspect Memory, Swap & Disk Headroom
free -m
df -h /

# 3. Verify Required Inbound Network Ports (22, 80, 443 must be reachable)
# Ensure AWS Security Group allows ingress from your controller IP.
```

### Minimum Resource Baseline
* **CPU:** 1 vCPU (`t2.micro` or greater).
* **RAM:** $\ge 965\text{ MiB}$.
* **Disk:** $\ge 20\text{ GB}$ root EBS volume ($\ge 12\text{ GB}$ available space).
* **Perimeter:** Inbound TCP `22`, `80`, `443`. All high telemetry ports (`3000`, `9090`, `3100`, `3200`) remain strictly internal.

---

## 2. Ephemeral SSH Key Handling (Enterprise Hygiene)

Never write persistent unencrypted SSH private keys to shared host filesystems or continuous integration runner disks. Always utilize memory-backed ephemeral storage:

```bash
# Provision key directly into volatile RAM
KEY_PATH="/dev/shm/ec2_ephemeral_key"
echo "${SSH_PRIVATE_KEY}" > "${KEY_PATH}"
chmod 0600 "${KEY_PATH}"

# Ensure cleanup on trap or exit
trap 'shred -u "${KEY_PATH}" 2>/dev/null || rm -f "${KEY_PATH}"' EXIT
```

---

## 3. Provisioning Execution Paths

### Method A: GitOps Automated Continuous Deployment (Recommended)
1. Ensure the following 5 GitHub Repository Secrets are configured:
   - `EC2_HOST`: Target instance public IP or DNS.
   - `EC2_USER`: SSH username (`devops` or `ubuntu`).
   - `SSH_PRIVATE_KEY`: Private OpenSSH key string.
   - `ANSIBLE_BECOME_PASS`: Sudo passphrase for the target user.
   - `APP_MASTER_SECRET`: High-entropy master secret ($\ge 16$ characters).
2. Trigger the deployment:
   ```bash
   gh workflow run "cd.yml" --ref main
   gh run watch $(gh run list --workflow="cd.yml" --limit 1 --json databaseId --jq '.[0].databaseId')
   ```

### Method B: Direct Controller CLI Invocation
```bash
ansible-playbook -i "${INVENTORY_FILE}" ansible/playbook.yml \
  -e "DOMAIN_NAME=${TARGET_HOST}" \
  -e "APP_MASTER_SECRET=${APP_MASTER_SECRET}" \
  --private-key="${KEY_PATH}"
```

---

## 4. Post-Deployment Smoke Verification Checklist

After the playbook completes with `failed=0`, execute the automated smoke suite against the target endpoint:

```bash
TARGET="https://${EC2_HOST}"

# 1. Verify HTTP to HTTPS Redirection (Expect HTTP 301)
curl -s -o /dev/null -w "%{http_code}\n" "http://${EC2_HOST}/" | grep -q "301"

# 2. Verify WordPress Landing Page (Expect HTTP 200)
curl -k -s -o /dev/null -w "%{http_code}\n" "${TARGET}/" | grep -q "200"

# 3. Verify XML-RPC Perimeter Hardening (Expect HTTP 403 Forbidden)
curl -k -s -o /dev/null -w "%{http_code}\n" "${TARGET}/xmlrpc.php" | grep -q "403"

# 4. Verify Grafana Anonymous Read-Only Endpoint (Expect HTTP 200)
curl -k -s -o /dev/null -w "%{http_code}\n" "${TARGET}/grafana/" | grep -q "200"

# 5. Verify Grafana Internal Health Status
curl -k -s "${TARGET}/grafana/api/health" | grep -q '"database":"ok"'
```

---

## 5. Rollback & Failure Recovery Procedures

If Ansible convergence fails:
1. Inspect the detailed Ansible task failure log.
2. Check for memory pressure on the target host: `dmesg -T | grep -i oom`.
3. If container pull was interrupted by network timeouts, re-execute the playbook:
   ```bash
   ansible-playbook -i "${INVENTORY_FILE}" ansible/playbook.yml --tags stack
   ```
4. Verify Docker daemon status on the host:
   ```bash
   sudo systemctl status docker --no-pager
   sudo docker compose -f /opt/wordpress-stack/docker-compose.yml ps
   ```
