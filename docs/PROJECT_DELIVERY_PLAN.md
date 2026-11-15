# Enterprise Project Delivery Plan: Production WordPress & LGTM Observability Platform

| Document Control | Details |
| :--- | :--- |
| **Project Title** | Production-Grade WordPress Provisioning, Hardening & Full-Stack OpenTelemetry (LGTM) Observability |
| **Target Environment** | AWS EC2 (`ap-southeast-1` / Ubuntu 22.04.2 LTS) |
| **Delivery Methodology** | GitOps / Infrastructure-as-Code (Ansible + Docker Compose + GitHub Actions CI/CD) |
| **Security Classification** | Public GitHub Repository (Strict Zero-Secret-In-Repo Policy) |

---

## 1. Executive Summary & Project Charter

### 1.1 Problem Statement
Provision, harden, and operate a production-ready WordPress instance on a pre-allocated AWS Ubuntu EC2 virtual machine with strict perimeter firewall rules (only TCP ports `22`, `80`, and `443` open) and constrained compute resources (`t2.micro`: 1 vCPU, 965 MiB RAM, 0 B Swap). The solution must preserve host OS integrity, enforce enterprise secrets management across a public GitHub repository, automate delivery via CI/CD pipelines, and provide deep full-stack observability.

### 1.2 Project Objectives & Success Criteria (KPIs)
| Objective | Target Metric / Acceptance Criteria |
| :--- | :--- |
| **OS Integrity Preservation** | `0` application runtimes (PHP, MySQL, Apache) installed natively on Ubuntu host; only Docker Engine, UFW, and Swap configured at the OS layer. |
| **Resource Stability (SLO)** | Steady-state memory utilization `< 750 MiB` (`< 78%` physical RAM) with `2,048 MiB` Swap buffer; `0` Linux OOM-killer events under load. |
| **Security Posture** | `0` secrets committed to Git (`gitleaks` verified); strict HTTP security headers; TLS 1.2/1.3 termination; brute-force rate limiting on `/wp-login.php`; `/xmlrpc.php` blocked (`403`). |
| **Full-Stack Observability** | **Grafana LGTM Stack** (Loki, Grafana, Tempo, Prometheus + Alloy) deployed with **end-to-end W3C OpenTelemetry trace propagation** (`Nginx` -> `WordPress PHP-FPM` -> `MariaDB SQL`) and correlated Logs-to-Traces-to-Metrics. |
| **CI/CD Automation** | 100% automated linting, secret scanning, vulnerability scanning, idempotent Ansible deployment, and automated post-deployment smoke tests via GitHub Actions. |

---

## 2. Discovery & Baseline Infrastructure Assessment

Prior to architectural design, a non-destructive discovery inspection was executed against the target EC2 node:

| Layer | Discovered Specification | Engineering Implication |
| :--- | :--- | :--- |
| **Operating System** | Ubuntu 22.04.2 LTS (`Jammy Jellyfish`, Kernel `x86_64`) | Compatible with official Docker Engine apt repositories and modern `cgroup v2` resource limits. |
| **Compute (CPU)** | `1 vCPU` — Intel(R) Xeon(R) CPU E5-2686 v4 @ 2.30GHz | Avoid heavy compilation on host; use lightweight Alpine PHP-FPM images and pre-built binaries. |
| **Memory & Swap** | `965 MiB` Total RAM (`~595 MiB` available), **`0 B` Swap** | **Critical Risk:** Untuned MySQL + Apache + LGTM stack requires `>1.5 GiB` RAM and will immediately trigger kernel OOM kills. Requires mandatory Swap provisioning and Go/MariaDB/PHP memory capping. |
| **Storage** | `20 GB` Root EBS Volume (`2.4 GB` used, `17 GB` free) | Sufficient capacity to allocate a `2 GB` `/swapfile`, Docker images, TSDB retention (`256 MB`), and automated local database backups. |
| **Network & Firewall** | AWS Security Group allows TCP `22`, `80`, `443` only | Grafana (`:3000`), Prometheus (`:9090`), Loki (`:3100`), and Tempo (`:3200`) cannot be accessed on high ports. Route Grafana via Nginx reverse proxy subpath (`/grafana/`) over port `443`. |

---

## 3. Work Breakdown Structure (WBS) & Delivery Milestones

| Phase | Milestone / Deliverable | Key Activities |
| :--- | :--- | :--- |
| **Phase 1** | **M1: Repository & IaC Scaffolding** | Initialize Git repo, `.gitignore`, Ansible roles (`host_hardening`, `docker_engine`, `wordpress_lgtm_stack`), and `.env.example`. |
| **Phase 2** | **M2: Application & OTel Stack** | Author `Dockerfile` (WordPress PHP-FPM + W3C OTel SQL tracer), `nginx.conf` (`nginx:1.27-alpine-otel` with JSON `trace_id` logs & security rules), and `mariadb/my.cnf`. |
| **Phase 3** | **M3: LGTM Observability As Code** | Author `config.alloy`, `loki-config.yml`, `tempo-config.yml`, `prometheus.yml`, and Grafana auto-provisioned datasources + unified dashboard JSON. |
| **Phase 4** | **M4: CI/CD Pipelines & Security** | Create `.github/workflows/ci.yml` and `cd.yml`, configure secrets handling, and add automated backup script + email `.txt` packaging script. |
| **Phase 5** | **M5: EC2 Provisioning & Verification** | Execute Ansible playbook against the EC2 instance, verify memory footprint (`free -h`, `docker stats`), verify HTTPS WordPress, Grafana dashboards, Loki logs, and Tempo traces. |
| **Phase 6** | **M6: GitHub Push & Submission Pack** | Push clean repository to public GitHub, configure GitHub Secrets, trigger live CI/CD pipeline run, and run `package-submission.ps1`. |

---

## 4. RAID Log (Risks, Assumptions, Issues, Dependencies)

| ID | Category | Item Description | Impact & Severity | Mitigation Strategy | Status |
| :---: | :---: | :--- | :---: | :--- | :---: |
| **R-01** | **Risk** | Constrained RAM on `t2.micro` (965 MiB) causing Linux kernel OOM kills when running 8 containers. | **High** | Provisioned 2,048 MiB `/swapfile` with `vm.swappiness=10`; capped MariaDB pool to 128M; disabled performance schema; set Go runtime `GOMEMLIMIT` + `GOGC=25` across all LGTM components. | **Mitigated** |
| **R-02** | **Risk** | ACME / Let's Encrypt CA blocks certificate issuance for default AWS domain `*.compute.amazonaws.com`. | **Medium** | Ansible automatically generates a 2048-bit RSA X.509 certificate with Subject Alternative Names matching the EC2 public DNS/IP; provides documented upgrade path to Certbot for custom domains. | **Mitigated** |
| **A-01** | **Assumption** | AWS Security Group allows inbound TCP traffic on ports 22, 80, and 443; high observability ports (3000, 9090, 3100, 3200) are blocked by cloud perimeter. | **High** | Designed Nginx edge reverse proxy to multiplex `/` (WordPress) and `/grafana/` (Observability) over port 443; telemetry ports remain strictly internal. | **Validated** |
| **A-02** | **Assumption** | Public GitHub repository requires strict zero-credential policy to comply with enterprise DevSecOps standards. | **High** | Single `APP_MASTER_SECRET` injected at runtime via GitHub Actions Secrets; zero passwords or IP addresses committed to Git; verified by Gitleaks in CI. | **Validated** |
| **I-01** | **Issue** | Tempo container OOM kill and gRPC frontend timeout during TraceQL block compaction. | **High** | Sized Tempo cgroup memory to `140m` (`GOMEMLIMIT=95MiB`); declared `grpc_listen_port: 9095`; extended Nginx Grafana `proxy_read_timeout` to 120s (PR #4). | **Resolved** |
| **I-02** | **Issue** | CPU credit exhaustion on `t2.micro` causing high CPU steal (`%st` up to 86%) and slow Ansible convergence. | **Medium** | Kept Ansible tasks lean and idempotent; tuned container CPU quotas; separated heavy build steps into base images. | **Managed** |
| **D-01** | **Dependency** | Target Ubuntu 22.04 LTS host provides functional systemd and internet egress to pull official Docker Hub images. | **Critical** | Tested base connectivity during pre-flight discovery; pinned official image tags for reproducibility. | **Validated** |

