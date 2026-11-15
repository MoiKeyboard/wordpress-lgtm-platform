# Production WordPress & Grafana LGTM OpenTelemetry Platform

[![CI - Security Scan, Lint & Build Validation](https://img.shields.io/badge/CI-Gitleaks%20%7C%20Ansible%20%7C%20Trivy-success?style=flat-square)](#)
[![Observability - Grafana LGTM + OTel](https://img.shields.io/badge/Observability-Grafana%20LGTM%20%2B%20OpenTelemetry-orange?style=flat-square)](#)
[![IaC - Ansible + Docker Compose](https://img.shields.io/badge/IaC-Ansible%20%2B%20Docker%20Compose-blue?style=flat-square)](#)
[![Security - Zero Secrets In Repo](https://img.shields.io/badge/Security-Zero%20Secrets%20In%20Git-brightgreen?style=flat-square)](#)

An enterprise-grade, security-hardened **WordPress** and **Grafana LGTM (Loki, Grafana, Tempo, Prometheus + Alloy)** observability platform engineered for constrained AWS EC2 compute (`1 vCPU`, `965 MiB RAM`, Ubuntu 22.04 LTS).

---

## 🏛️ High-Level System Architecture

```mermaid
flowchart LR
    subgraph Ingress["Traffic & CI/CD Ingress"]
        User["Evaluator / Browser\n(HTTPS :443)"]
        GH["GitHub Actions Runner\n(SSH :22 CD Deploy)"]
    end

    subgraph Host["AWS EC2 Host (Ubuntu 22.04 LTS | 1 vCPU | 965 MiB RAM + 2 GB Swap)"]
        FW["Perimeter Firewall\n(Security Group + UFW: 22, 80, 443)"]
        
        subgraph Edge["Edge Gateway"]
            Nginx["Nginx Reverse Proxy\nTLS 1.2/1.3 | Rate Limiting | W3C Tracing"]
        end

        subgraph Core["Application Tier (Isolated)"]
            WP["WordPress Core\n(PHP-FPM 8.2 Alpine)"]
            DB[("MariaDB 10.11\n(Internal Network Only)")]
        end

        subgraph Observability["Observability Plane (LGTM)"]
            Grafana["Grafana OSS\n(Anonymous Read-Only)"]
            Alloy["Grafana Alloy\n(Unified Telemetry Collector)"]
            Loki["Grafana Loki\n(Correlated Logs)"]
            Tempo["Grafana Tempo\n(Distributed Traces)"]
            Prom["Prometheus\n(TSDB Metrics & Alerts)"]
        end
    end

    User --> FW --> Nginx
    GH --> FW

    Nginx -->|"FastCGI /"| WP
    WP -->|"SQL (Internal)"| DB
    Nginx -->|"Proxy /grafana/"| Grafana

    Nginx -.->|"Logs & Traces"| Alloy
    WP -.->|"Traces & SQL Spans"| Alloy
    Alloy --> Loki
    Alloy --> Tempo
    Prom --> Alloy
    Grafana --> Prom
    Grafana --> Loki
    Grafana --> Tempo
```

> [!NOTE]
> For the low-level component topology, internal Docker bridge subnets, OTLP gRPC/HTTP buffer topologies, and memory allocations, refer to the detailed [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

---

## ✨ Architectural Breakdown by System Layers

This platform is structured into modular layers—from network perimeter down to runtime governance and AI automation—delivering defense-in-depth, strict isolation, and stability on constrained cloud compute.

### 1. Network & Edge Security Layer
* **Minimal Attack Surface:** Inbound traffic is strictly restricted to TCP ports `22` (SSH), `80` (HTTP), and `443` (HTTPS) via AWS Security Groups and host-level **UFW**.
* **Edge Reverse Proxy Gateway:** Official `nginx:1.27-alpine-otel` terminates TLS 1.2 and 1.3, automatically redirecting HTTP `80` $\rightarrow$ `443` (`301 Moved Permanently`).
* **HSTS & Hardened Security Headers:** Injects `Strict-Transport-Security`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, and `Permissions-Policy: geolocation=(), microphone=(), camera=()`.
* **Internal Docker Virtual Networks:** Three isolated bridge networks prevent lateral movement:
  - `frontend_net`: Nginx edge proxy, WordPress FastCGI, and Grafana HTTP.
  - `backend_net` (`internal: true`): Isolated database bridge with **zero external routing**—MariaDB has no exposed host ports.
  - `observability_net`: Dedicated telemetry plane connecting Alloy, Loki, Tempo, Prometheus, and Grafana.

### 2. Identity, Authentication & Access Control Layer
* **Anonymous Read-Only Observability:** Grafana is configured in Anonymous Viewer mode (`GF_AUTH_ANONYMOUS_ENABLED=true`, `GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer`). Evaluators and monitoring agents inspect metrics, logs, and traces without credentials, eliminating public login brute-force attack vectors.
* **WordPress Perimeter Defenses:** 
  - Brute-force rate limiting on `/wp-login.php` capped at `2 req/s` (burst `5`).
  - `/xmlrpc.php` access returns `403 Forbidden` to prevent amplification and pingback attacks.
  - PHP execution is strictly blocked within `/wp-content/uploads/` to mitigate arbitrary file execution.
* **Host Access:** Password authentication is disabled on the host; SSH access requires ed25519 or 2048-bit RSA keypairs (`PermitRootLogin no`).

### 3. Host System, Kernel & Resource Governance Layer
* **100% Host OS Integrity Preservation:** Zero application dependencies (PHP, Apache, MySQL, Nginx) are installed on the Ubuntu host. Only Docker Engine, UFW, and swap exist at the OS layer.
* **Swap & Memory Pressure Tuning:** Ansible provisions a dedicated `2,048 MiB` `/swapfile` with `vm.swappiness = 10` and `vm.vfs_cache_pressure = 50`, preventing kernel OOM killer events under traffic spikes.
* **Container Cgroup Limits & Go Runtime Governance:**
  - MariaDB: Capped at `256m` memory limit with `performance_schema = OFF` (saving ~100 MiB) and `innodb_buffer_pool_size = 128M`.
  - WordPress PHP-FPM: Capped at `192m` with `pm = ondemand` (idle workers consume `0 MiB`).
  - LGTM Stack: All Go components (`alloy`, `loki`, `tempo`, `prometheus`, `grafana`) enforce `GOMEMLIMIT` soft ceilings and aggressive garbage collection (`GOGC=25`), backed by Docker `mem_limit` cgroups.
  - Hardened execution: All containers run with `security_opt: ["no-new-privileges:true"]`.

### 4. Configuration Management & Infrastructure-as-Code Layer
* **Idempotent Ansible Automation:** Complete provisioning is automated via `ansible/playbook.yml` structured into three reusable roles:
  - `host_hardening`: Configures sysctl memory parameters, UFW firewall, and swapfile.
  - `docker_engine`: Installs official Docker Engine and Compose plugin via upstream APT repositories.
  - `wordpress_lgtm_stack`: Deploys templates, self-signed TLS certificates with SAN, and starts the container stack.
* **Unified Secret Architecture (`APP_MASTER_SECRET`):** A single master secret securely derives MariaDB root, WordPress database user, and Grafana administrator credentials, eliminating secret fragmentation.

### 5. Application & Runtime Layer
* **WordPress 6.7 on PHP 8.2 FPM (Alpine):** High-efficiency PHP runtime with bytecode `OPcache` (64 MB) and `ext-opentelemetry` support.
* **Hardened Dynamic `wp-config.php`:** Disables file editing from the admin UI (`DISALLOW_FILE_EDIT`), forces administrative SSL (`FORCE_SSL_ADMIN`), caps WordPress internal memory to `96M`, and detects `HTTP_X_FORWARDED_PROTO` headers.
* **MariaDB 10.11 LTS:** Robust relational storage with automated daily backups (`mysqldump`) stored in `/var/backups/wordpress/` with 7-day retention.

### 6. Full-Stack Observability & Telemetry Layer (LGTM + OTel)
* **End-to-End W3C Distributed Tracing:**
  - `Nginx Edge Proxy` (`ngx_otel_module`) generates a W3C `traceparent` and injects it via FastCGI.
  - `WordPress PHP-FPM` auto-prepends an OpenTelemetry tracer (`otel-wordpress-tracer.php`), tracing PHP execution.
  - `MariaDB Database` queries are captured via WordPress `$wpdb->queries` (`SAVEQUERIES`), creating child spans for every SQL query with zero user-facing latency (`fastcgi_finish_request()`).
* **Logs-to-Traces Correlation:** Nginx JSON access logs contain `$otel_trace_id`. Grafana Loki automatically links log entries directly to the corresponding trace waterfall in **Grafana Tempo**.
* **Unified Telemetry Collection:** **Grafana Alloy** collects container stdout logs, host system metrics (Unix node exporter), and routes OTLP traces to Tempo.

### 7. CI/CD Pipeline & Continuous Delivery Layer
* **Automated CI Validation (`.github/workflows/ci.yml`):**
  - **Secret Detection:** Gitleaks scans git history on every pull request for exposed credentials.
  - **IaC Linting:** Validates Ansible playbook syntax and Docker Compose specification.
  - **Vulnerability Scanning:** Aqua Security Trivy scans repository configurations for misconfigurations and CVEs.
* **GitOps Continuous Deployment (`.github/workflows/cd.yml`):**
  - Triggers automatically upon merging pull requests to `main`.
  - Injects an ephemeral SSH private key directly into in-memory `/dev/shm` on the GitHub runner, securely shredded upon job completion.
  - Executes Ansible convergence against the target EC2 instance.
  - Executes post-deployment HTTPS smoke checks against WordPress (`/`), rate limits, XML-RPC (`403`), and Grafana health.

### 8. AI Agent & Platform Engineering Automation Layer
* **Autonomous Platform Operations:** Designed for operation by AI agents (e.g. Antigravity) and platform engineers using declarative instructions.
* **Automated Runbook Execution:** Standard operating procedures—including zero-downtime secret rotation, host diagnostics, and disaster recovery—are documented as executable workflows in [docs/RUNBOOK.md](docs/RUNBOOK.md).

---

## 🚀 Quickstart: Deploy to a Fresh EC2 Instance (Zero-to-Hero)

Follow these steps to deploy this exact production stack to any fresh Ubuntu 22.04 LTS instance.

### Prerequisites
1. **Target AWS EC2 Instance:**
   - Architecture: `x86_64` (Ubuntu 22.04 LTS, e.g. `t2.micro` or larger).
   - Inbound Security Group: Allow TCP `22`, `80`, and `443`.
   - SSH Access: A user with `sudo` privileges (e.g., `devops` or `ubuntu`) and an SSH keypair.

---

### Method A: Automated GitOps via GitHub Actions (Recommended)

1. **Fork or Clone the Repository:**
   ```bash
   git clone https://github.com/<YOUR_USER>/wordpress-lgtm-platform.git
   cd wordpress-lgtm-platform
   ```

2. **Configure GitHub Repository Secrets:**
   Navigate to your GitHub repository $\rightarrow$ **Settings** $\rightarrow$ **Secrets and variables** $\rightarrow$ **Actions** $\rightarrow$ **New repository secret**:

   | Secret Name | Description | Example / Value |
   | :--- | :--- | :--- |
   | `EC2_HOST` | Target EC2 public IPv4 or AWS public DNS | `ec2-xx-xx-xx-xx.compute.amazonaws.com` |
   | `EC2_USER` | SSH username on the instance | `ubuntu` or `devops` |
   | `SSH_PRIVATE_KEY` | Full PEM/OpenSSH private key | `-----BEGIN OPENSSH PRIVATE KEY-----...` |
   | `ANSIBLE_BECOME_PASS` | Sudo password for the SSH user | `your-sudo-password` (or any string if NOPASSWD) |
   | `APP_MASTER_SECRET` | Unified secret for internal DB and admin | `A_Strong_Random_Password_32_Chars!` |

3. **Trigger Deployment:**
   - Push any commit to `main`, or navigate to **Actions** $\rightarrow$ **CD - Deploy WordPress & LGTM Stack to AWS EC2** $\rightarrow$ click **Run workflow**.
   - The CD pipeline will provision swap, install Docker, template configurations, deploy containers, and run smoke tests automatically.

---

### Method B: Direct Local CLI Deployment via Ansible

If you prefer deploying directly from your local terminal or management bastion:

1. **Install Prerequisites on your Local Machine:**
   ```bash
   pip install ansible
   ```

2. **Configure Inventory:**
   ```bash
   cp ansible/inventory.ini.example ansible/inventory.ini
   ```
   Edit `ansible/inventory.ini`:
   ```ini
   [wordpress_nodes]
   ec2-target ansible_host=YOUR_EC2_PUBLIC_IP ansible_user=ubuntu ansible_ssh_private_key_file=~/.ssh/id_rsa ansible_become_password=your_sudo_password
   ```

3. **Run Ansible Playbook:**
   ```bash
   ansible-playbook -i ansible/inventory.ini ansible/playbook.yml \
     -e "DOMAIN_NAME=YOUR_EC2_PUBLIC_IP" \
     -e "APP_MASTER_SECRET=A_Strong_Random_Password_32_Chars!"
   ```

---

## 🔍 Verification & Health Endpoints

Once deployment completes, verify the live platform endpoints:

| Service | Endpoint | Expected Behavior |
| :--- | :--- | :--- |
| **WordPress HTTPS** | `https://<EC2_HOST>/` | HTTP 200 (Clean WordPress homepage) |
| **HTTP Redirect** | `http://<EC2_HOST>/` | HTTP 301 Moved Permanently $\rightarrow$ HTTPS |
| **WordPress Admin** | `https://<EC2_HOST>/wp-login.php` | HTTP 200 (Rate limited to 2 req/s) |
| **XML-RPC Perimeter Block** | `https://<EC2_HOST>/xmlrpc.php` | **HTTP 403 Forbidden** (Hardened) |
| **Grafana LGTM Dashboards** | `https://<EC2_HOST>/grafana/` | HTTP 200 (**Anonymous Viewer Mode**, no login required) |
| **Grafana Health API** | `https://<EC2_HOST>/grafana/api/health` | `{"commit":"...","database":"ok","version":"11.1.0"}` |

---

## 📂 Repository Layout

```
.
├── .github/
│   └── workflows/
│       ├── ci.yml               # PR checks: Gitleaks, Ansible lint, Docker lint, Trivy
│       └── cd.yml               # GitOps CD: Ephemeral SSH in RAM, Ansible convergence & smoke tests
├── ansible/
│   ├── ansible.cfg              # Ansible configuration (pipelining, SSH settings)
│   ├── inventory.ini.example    # Sample Ansible inventory template
│   ├── playbook.yml             # Primary idempotent deployment playbook
│   └── roles/
│       ├── host_hardening/      # UFW firewall, sysctl memory settings, 2GB swapfile
│       ├── docker_engine/       # Official upstream Docker Engine & Compose installation
│       └── wordpress_lgtm_stack/# TLS cert generation, env templating, docker compose lifecycle
├── docker/
│   ├── docker-compose.yml       # 8-container stack with 3 networks & cgroup mem_limits
│   ├── nginx/                   # Nginx 1.27 OTel configuration, security headers, rate limits
│   ├── wordpress/               # Dockerfile (PHP 8.2 FPM Alpine) + W3C OTel SQL auto-tracer
│   ├── mariadb/                 # my.cnf tuning (performance_schema=OFF, innodb buffer=128M)
│   └── lgtm/                    # Alloy, Loki, Tempo, Prometheus, and Grafana configurations
├── docs/
│   ├── ARCHITECTURE.md          # In-depth topology, OTel trace flow, memory engineering ADRs
│   ├── PROJECT_DELIVERY_PLAN.md # Project charter, discovery baseline, WBS, RAID log
│   └── RUNBOOK.md               # Day-2 operations, secret rotation, backup/restore procedures
└── scripts/
    ├── backup.sh                # Automated daily database backup with 7-day rotation
    └── package-submission.ps1   # Assignment packaging script (.txt extension generator)
```
