---
name: platform-security-compliance
description: Audit, enforce, and verify defense-in-depth security baselines across the host perimeter, reverse proxy, container privileges, and CI pipelines.
---

# Platform Skill: Security Compliance & Perimeter Audit

## Overview
This skill guides an autonomous AI agent or platform engineer through continuous audit and verification of the platform's defense-in-depth security posture, spanning perimeter firewall rules, edge reverse proxy hardening, container privilege isolation, and automated CI scans.

---

## 1. Perimeter Firewall & Port Isolation Audit

The host must never expose telemetry ingestion or administrative container ports directly to the public internet:

```bash
# 1. Verify UFW Firewall Status (Must be active with only 22, 80, 443 allowed)
sudo ufw status verbose

# Expected:
# 22/tcp                     ALLOW IN    Anywhere
# 80/tcp                     ALLOW IN    Anywhere
# 443/tcp                    ALLOW IN    Anywhere

# 2. Verify Listening Ports on the Host (Must ONLY bind 22, 80, 443)
sudo ss -tulpn | grep LISTEN
```
*Rule:* Ports `3000` (Grafana), `9090` (Prometheus), `3100` (Loki), `3200`/`4317` (Tempo), and `3306` (MariaDB) must **NEVER** appear in public host port bindings.

---

## 2. Edge Reverse Proxy Hardening Checks

Verify that Nginx blocks common attack vectors and enforces strict transport security:

```bash
TARGET="https://127.0.0.1"

# 1. Verify XML-RPC Access is Blocked (Must return 403 Forbidden)
curl -k -s -o /dev/null -w "%{http_code}\n" "${TARGET}/xmlrpc.php" | grep -q "403"

# 2. Verify PHP Execution in Uploads Directory is Blocked (Must return 403)
curl -k -s -o /dev/null -w "%{http_code}\n" "${TARGET}/wp-content/uploads/malicious.php" | grep -q "403"

# 3. Verify Rate Limiting on Login Endpoint (2 requests/sec, burst 5)
for i in {1..8}; do
  CODE=$(curl -k -s -o /dev/null -w "%{http_code}" "${TARGET}/wp-login.php")
  echo "Request $i: HTTP $CODE"
done | grep -q "503"

# 4. Verify Security Response Headers
curl -k -I "${TARGET}/" | grep -iE "(strict-transport-security|x-frame-options|x-content-type-options|referrer-policy|permissions-policy)"
```

---

## 3. Container Isolation & Runtime Privilege Audit

Verify that container processes are strictly bounded and cannot gain escalated privileges:

```bash
cd /opt/wordpress-stack

# 1. Verify 'no-new-privileges' is active across all containers
for container in $(sudo docker compose ps -q); do
  NAME=$(docker inspect --format '{{.Name}}' "$container")
  NO_NEW_PRIV=$(docker inspect --format '{{.HostConfig.SecurityOpt}}' "$container")
  echo "$NAME: $NO_NEW_PRIV" | grep -q "no-new-privileges:true"
done

# 2. Verify MariaDB Network Isolation (backend_net must be internal: true)
docker network inspect wordpress-stack_backend_net | grep -q '"Internal": true'

# 3. Verify Anonymous Read-Only Grafana Mode
curl -k -s "${TARGET}/grafana/api/users" | grep -q "Unauthorized"
```

---

## 4. Static IaC & Container Vulnerability Scanning

Run static vulnerability and misconfiguration scans before merging changes into `main`:

```bash
# 1. Run Gitleaks across Git history
gitleaks detect --source=. --verbose --redact

# 2. Run Aqua Security Trivy across Docker Compose and Ansible definitions
trivy config . --severity HIGH,CRITICAL --exit-code 0
```
