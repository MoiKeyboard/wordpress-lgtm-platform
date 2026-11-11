# Production WordPress & Grafana LGTM OpenTelemetry Platform

[![CI - Security Scan, Lint & Build Validation](https://img.shields.io/badge/CI-Gitleaks%20%7C%20Ansible%20%7C%20Trivy-success?style=flat-square)](#)
[![Observability - Grafana LGTM + OTel](https://img.shields.io/badge/Observability-Grafana%20LGTM%20%2B%20OpenTelemetry-orange?style=flat-square)](#)
[![IaC - Ansible + Docker Compose](https://img.shields.io/badge/IaC-Ansible%20%2B%20Docker%20Compose-blue?style=flat-square)](#)

An enterprise-grade, security-hardened **WordPress** and **Grafana LGTM (Loki, Grafana, Tempo, Prometheus + Alloy)** observability stack engineered for constrained AWS EC2 compute (`1 vCPU`, `965 MiB RAM`).

---

## 🏛️ System Architecture Diagram

```mermaid
flowchart TB
    subgraph Public["Public Internet"]
        Client["Browser / Evaluator\n(HTTPS :443)"]
        GH["GitHub Actions Runner\n(SSH :22 / Ephemeral RAM Key)"]
    end

    subgraph Host["AWS EC2 Host (Ubuntu 22.04 LTS | 1 vCPU | 965 MiB RAM + 2 GiB Swap)"]
        UFW["UFW Firewall\n(Allow 22, 80, 443 Only)"]

        subgraph Docker["Docker Engine (Resource Governed Containers)"]

            subgraph DMZ["frontend_net (Bridge)"]
                Nginx["nginx:1.27-alpine-otel\n(TLS 1.2/1.3 | Security Headers | Rate Limiting\nngx_otel_module W3C Trace Injection)"]
            end

            subgraph Backend["backend_net (internal: true | No External Route)"]
                WP["wordpress:6.7-php8.2-fpm-alpine\n(pm = ondemand | OPcache 64MB\next-opentelemetry W3C Tracer)"]
                DB[("mariadb:10.11\n(innodb_buffer_pool=128M\nperf_schema=OFF)")]
            end

            subgraph Observability["observability_net (LGTM Telemetry Plane)"]
                Alloy["Grafana Alloy (Unified Collector)\n(Host Unix Exporter | Docker Logs | OTLP Router)"]
                Loki["Grafana Loki\n(Monolithic Log Engine)"]
                Tempo["Grafana Tempo\n(Monolithic Distributed Tracing)"]
                Prom["Prometheus\n(TSDB Metrics & Alert Rules)"]
                Grafana["Grafana OSS\n(Pre-Provisioned Unified Dashboard)"]
            end
        end
    end

    Client -->|"HTTPS :443 (/)"| UFW --> Nginx
    Client -->|"HTTPS :443 (/grafana/)"| UFW --> Nginx
    GH -->|"SSH :22 (Ansible Playbook)"| UFW

    Nginx -->|"FastCGI :9000 + traceparent"| WP
    Nginx -->|"HTTP Reverse Proxy"| Grafana
    WP -->|"SQL :3306 (SAVEQUERIES Traced)"| DB

    Nginx -.->|"OTLP gRPC :4317 (Edge Spans)"| Alloy
    Nginx -.->|"JSON Access Logs (trace_id)"| Alloy
    WP -.->|"OTLP HTTP :4318 (PHP & SQL Spans)"| Alloy

    Alloy -->|"Push Logs"| Loki
    Alloy -->|"Push Traces"| Tempo
    Prom -->|"Scrape Metrics"| Alloy
    Grafana -->|"LogQL (Derived trace_id)"| Loki
    Grafana -->|"TraceQL / Waterfall"| Tempo
    Grafana -->|"PromQL"| Prom
```

---

## ✨ Key Architectural Highlights

1. **100% Host OS Integrity Preservation:**
   * Zero application dependencies (PHP, MySQL, Apache, Nginx) are installed on the Ubuntu host OS.
   * Only **UFW**, a **2 GB `/swapfile`** (`vm.swappiness=10`), and **Docker Engine** run at the host level.
2. **End-to-End W3C OpenTelemetry Tracing (`Nginx` $\rightarrow$ `WordPress PHP-FPM` $\rightarrow$ `MariaDB SQL`):**
   * Uses official `nginx:1.27-alpine-otel` (`ngx_otel_module`) at the edge and lightweight PHP/SQL `$wpdb` auto-instrumentation (`docker/wordpress/otel-wordpress-tracer.php`).
   * Every HTTP request generates a distributed trace in **Grafana Tempo** breaking down Nginx proxy latency, WordPress PHP execution, and individual MariaDB SQL statements.
   * **Logs-to-Traces Correlation:** Nginx JSON logs in **Grafana Loki** include `"trace_id"`, featuring a one-click **"View Trace in Tempo"** button inside Grafana.
3. **Engineered for `t2.micro` (`965 MiB` RAM) Stability:**
   * **MariaDB 10.11:** Tuned with `performance_schema = OFF` (saves ~100 MiB RAM) and `innodb_buffer_pool_size = 128M`.
   * **WordPress PHP-FPM:** Uses `pm = ondemand` and bytecode `OPcache` so idle workers consume `0 MiB` RAM.
   * **LGTM Stack:** Configured with Go runtime soft memory limits (`GOMEMLIMIT`) and aggressive garbage collection (`GOGC=25`) alongside Docker `mem_limit` caps, keeping total system RAM usage around **~550–680 MiB**.
4. **Defense-in-Depth Security & Zero-Secret Public Repository:**
   * **Secrets Management:** Zero credentials, IP addresses, or SSH keys exist in Git. Secrets are injected at runtime via **GitHub Actions Secrets** into a root-owned (`0600`) `/opt/wordpress-stack/.env` file.
   * **Edge Hardening:** TLS 1.2/1.3 termination (`80` $\rightarrow$ `443` redirect), HSTS/CSP security headers, brute-force rate-limiting (`2 req/s`) on `/wp-login.php`, and `403 Forbidden` blocks on `/xmlrpc.php`, dotfiles, and PHP execution inside `/wp-content/uploads/`.
   * **Network Isolation:** MariaDB runs on an `internal: true` Docker bridge network (`backend_net`) with zero host port bindings.

---

## 📂 Repository Structure

* [docs/PROJECT_DELIVERY_PLAN.md](docs/PROJECT_DELIVERY_PLAN.md) — Enterprise Project Charter, Discovery Assessment, WBS, and RAID Log.
* [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — Architecture Decision Records (ADRs), OpenTelemetry pipeline, and memory engineering.
* [docs/RUNBOOK.md](docs/RUNBOOK.md) — GitHub Secrets setup, Day-2 operations, and Backup/Restore procedures.
* [ansible/playbook.yml](ansible/playbook.yml) — Idempotent Ansible playbook & roles (`host_hardening`, `docker_engine`, `wordpress_lgtm_stack`).
* [docker/docker-compose.yml](docker/docker-compose.yml) — Multi-network container stack with healthchecks and `mem_limit` governance.
* [.github/workflows/ci.yml](.github/workflows/ci.yml) — Gitleaks secret scanning, Ansible/Compose linting, and Trivy security scanning.
* [.github/workflows/cd.yml](.github/workflows/cd.yml) — Ephemeral SSH deployment via Ansible + automated HTTPS smoke verification.
* [scripts/package-submission.ps1](scripts/package-submission.ps1) — Automates appending `.txt` to all code/config files for email submission.

---

## 🚀 Quick Verification Endpoints

Once deployed to the target EC2 host (`https://<EC2_HOST>`):

| Service | Endpoint | Notes |
| :--- | :--- | :--- |
| **WordPress Application** | `https://<EC2_HOST>/` | HTTP `:80` automatically redirects (`301`) to HTTPS `:443`. |
| **WordPress Admin** | `https://<EC2_HOST>/wp-login.php` | Protected by Nginx rate-limiting (`2r/s`, burst `5`). |
| **XML-RPC Protection Check** | `https://<EC2_HOST>/xmlrpc.php` | Returns `403 Forbidden` by design. |
| **Grafana LGTM Dashboards** | `https://<EC2_HOST>/grafana/` | Pre-provisioned with **WordPress Production & LGTM OpenTelemetry Overview** dashboard (`Production Observability` folder). |
