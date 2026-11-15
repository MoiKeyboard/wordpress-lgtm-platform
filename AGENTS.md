# AGENTS.md: Autonomous AI Agent Operating Guidelines

Welcome, autonomous AI agent or platform automation engineer. This repository houses an enterprise-grade, security-hardened **WordPress & Grafana LGTM OpenTelemetry Platform** engineered for constrained AWS EC2 compute (`t2.micro`: 1 vCPU, 965 MiB RAM, Ubuntu 22.04 LTS).

When operating in this codebase, you must adhere strictly to the architectural constraints, security policies, and standard operating procedures outlined below.

---

## 1. Core Operating Constraints & Guardrails

### 🛡️ Rule 1: Zero Secrets in Git (Strictly Enforced)
* **NEVER** commit plain-text credentials, passwords, private keys (`.pem`, `.key`), certificates, or `.env` files to Git.
* Secrets are injected dynamically at runtime via **GitHub Actions Repository Secrets** into an ephemeral in-RAM key (`/dev/shm`) and a host-local, root-owned (`0600`) `/opt/wordpress-stack/.env` file.
* Always run `gitleaks detect --source=. --verbose` before submitting changes.

### 🏛️ Rule 2: 100% Host OS Integrity Preservation
* **NEVER** install application runtimes (PHP, MySQL/MariaDB, Apache, Nginx) natively on the Ubuntu host OS via `apt` or source compilation.
* The host OS must run **ONLY**:
  1. Linux Kernel primitives & sysctl configurations (`vm.swappiness = 10`, `vm.vfs_cache_pressure = 50`).
  2. A dedicated `2,048 MiB` `/swapfile`.
  3. **UFW Firewall** allowing strictly ports `22`, `80`, and `443`.
  4. **Docker Engine & Compose v2 plugin**.
* All application and observability workloads must run exclusively inside governed Docker containers.

### 🔄 Rule 3: Strict GitOps & Infrastructure-as-Code
* **NEVER** perform untracked, ad-hoc manual hotfixes or configuration edits directly on the target EC2 instance.
* All configuration changes must be authored in IaC templates (`ansible/`, `docker/`), committed to a tracked feature branch, validated through CI status checks, and merged via Pull Request.

### ⚖️ Rule 4: Extreme Resource Governance on `t2.micro` (965 MiB RAM)
* Total nominal container `mem_limit` is bounded. All Go-based telemetry runtimes (`alloy`, `loki`, `tempo`, `prometheus`, `grafana`) **MUST** enforce `GOMEMLIMIT` soft ceilings and aggressive garbage collection (`GOGC=25`).
* MariaDB must maintain `performance_schema = OFF` and `innodb_buffer_pool_size = 128M`.
* WordPress PHP-FPM must use `pm = ondemand` so idle processes consume 0 MiB RAM.

---

## 2. Platform Agent Skills Inventory

This repository provides modular, standardized AI agent skills under `.agent/skills/`. When performing tasks, invoke the relevant skill for step-by-step operational instructions:

| Skill Identifier | Location | When to Invoke |
| :--- | :--- | :--- |
| **`platform-ec2-provisioning`** | [`.agent/skills/platform-ec2-provisioning/`](.agent/skills/platform-ec2-provisioning/SKILL.md) | Deploying, qualifying, and bootstrapping the stack onto a fresh EC2 instance. |
| **`platform-secret-lifecycle`** | [`.agent/skills/platform-secret-lifecycle/`](.agent/skills/platform-secret-lifecycle/SKILL.md) | Rotating `APP_MASTER_SECRET`, re-templating `.env`, or auditing secret leakage. |
| **`platform-observability-triage`** | [`.agent/skills/platform-observability-triage/`](.agent/skills/platform-observability-triage/SKILL.md) | Triaging missing traces, Tempo 504 timeouts, kernel OOM kills, or CPU steal. |
| **`platform-security-compliance`** | [`.agent/skills/platform-security-compliance/`](.agent/skills/platform-security-compliance/SKILL.md) | Auditing UFW rules, rate limiting, XML-RPC 403 blocks, or container permissions. |
| **`platform-disaster-recovery`** | [`.agent/skills/platform-disaster-recovery/`](.agent/skills/platform-disaster-recovery/SKILL.md) | Testing database snapshots, verifying 7-day retention, and running restore drills. |

---

## 3. Pre-Commit Quality Gate Checklist

Before pushing any branch or submitting a pull request, execute this local verification suite:

```bash
# 1. Run Secret Detection
gitleaks detect --source=. --verbose --redact

# 2. Lint Ansible Syntax
ansible-playbook -i ansible/inventory.ini.example ansible/playbook.yml --syntax-check

# 3. Validate Docker Compose Specification
docker compose -f docker/docker-compose.yml config -q

# 4. Scan Infrastructure-as-Code for Misconfigurations
trivy config . --severity HIGH,CRITICAL --exit-code 0
```

---

## 4. Post-Deployment Verification Commands

After CD convergence on the target EC2 node (`https://<EC2_HOST>`):

```bash
# Verify HTTP 301 Redirect to HTTPS
curl -s -o /dev/null -w "%{http_code}\n" "http://${EC2_HOST}/" | grep -q "301"

# Verify WordPress Landing Page
curl -k -s -o /dev/null -w "%{http_code}\n" "https://${EC2_HOST}/" | grep -q "200"

# Verify XML-RPC Hardening (Must be 403 Forbidden)
curl -k -s -o /dev/null -w "%{http_code}\n" "https://${EC2_HOST}/xmlrpc.php" | grep -q "403"

# Verify Anonymous Read-Only Grafana
curl -k -s -o /dev/null -w "%{http_code}\n" "https://${EC2_HOST}/grafana/" | grep -q "200"

# Verify Tempo Trace Generation
curl -s -k "https://${EC2_HOST}/grafana/api/datasources/proxy/uid/tempo/api/search?limit=1" | grep -q "traceID"
```
