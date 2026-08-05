# Docker Deployment, Backup, and CI/CD

> Part of the **KnowledgeBot AI** specification — §24–26, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 24. Docker and Deployment Design

## 24.1 Docker Principles

- Every application service runs in a container.
- Development and deployment use Docker Compose.
- Only Traefik exposes host ports by default.
- Databases and internal APIs remain on private networks.
- Containers run as non-root where practical.
- Images use multi-stage builds.
- Runtime images contain only required dependencies.
- Health checks are defined.
- Persistent data uses named volumes or mounted storage paths.
- Secrets are not baked into images.

## 24.2 Suggested Services

### Public Edge

- `traefik`

### Application

- `web` — Next.js admin and hosted web.
- `sdk` — static widget and iframe assets.
- `laravel-api` — PHP application runtime.
- `laravel-worker` — Laravel queue workers.
- `laravel-scheduler` — Laravel scheduler process.
- `ai-api` — FastAPI internal service.
- `ai-worker-ingestion` — document and chunking jobs.
- `ai-worker-crawl` — web crawl jobs.
- `ai-worker-embedding` — embedding and indexing jobs.
- `ai-worker-evaluation` — evaluation jobs.

A minimal deployment may combine some AI worker roles in one worker container with separate queues.

### Data

- `postgres`
- `qdrant`
- `valkey`
- `seaweedfs-master` or simplified SeaweedFS deployment.
- `seaweedfs-volume`
- `seaweedfs-filer`
- `seaweedfs-s3`

The exact SeaweedFS topology may be simplified for a portfolio environment.

### Observability

- `otel-collector`
- `prometheus`
- `grafana`
- `loki`
- `tempo` or `jaeger`

## 24.3 Networks

Suggested logical networks:

- `edge`: Traefik and intentionally routed services.
- `application`: web, Laravel, AI service, workers.
- `data`: application services and data services.
- `observability`: services and telemetry stack.

Network membership should be minimal.

## 24.4 Exposed Ports

Public host ports:

- 80.
- 443.

Development-only optional ports may be bound to localhost for database or dashboard inspection, but should not be enabled in production configuration.

## 24.5 Environment Profiles

Compose profiles may include:

- `core`: required application and data services.
- `observability`: metrics, logs, and traces.
- `gpu`: local embedding or OCR acceleration.
- `dev-tools`: local mail viewer, database UI, and diagnostics.
- `test`: isolated test dependencies.

## 24.6 Development Environment

Development should support:

- Bind-mounted source code.
- Hot reload for Next.js and SDK.
- Laravel development server or containerized web server.
- FastAPI reload mode.
- Separate test databases.
- Seed data.
- Fake provider adapter for local testing.
- Local mail capture.
- Sample documents and evaluation data.

## 24.7 Production Environment

Production should use:

- Immutable versioned images.
- Production dependency installs.
- No source bind mounts.
- Restricted container users.
- Resource limits.
- Restart policies.
- TLS.
- Backup jobs.
- Central logging.
- Monitoring alerts.

## 24.8 Initial Server Sizing

A practical external-LLM showcase deployment may begin around:

- 4 to 8 virtual CPU cores.
- 8 to 16 GB memory.
- Fast SSD storage.
- Additional storage based on document volume.

Local embedding, reranking, OCR, and image understanding may require more memory or a supported GPU. Worker concurrency should be configured according to actual resources.

These are planning estimates and must be validated through load tests.

## 24.9 Scaling Path

Scale independently:

- Next.js instances.
- Laravel API instances.
- Laravel workers.
- FastAPI instances.
- Ingestion workers.
- Crawl workers.
- Embedding workers.
- Qdrant deployment.
- PostgreSQL.
- Object storage.

Kubernetes should be considered only when operational needs justify it.

---

## 25. Backup and Disaster Recovery

## 25.1 Backup Scope

Back up:

- PostgreSQL.
- SeaweedFS objects.
- Qdrant snapshots or rebuild metadata.
- Critical configuration.
- Encryption-key recovery material through a separate secure process.
- Grafana dashboards and infrastructure configuration.

Valkey should not be treated as the only durable store for critical business data.

## 25.2 PostgreSQL Backups

- Daily logical or physical backup.
- Transaction-log or point-in-time recovery for serious deployments.
- Encrypted backup storage.
- Retention policy.
- Periodic restore tests.

## 25.3 Qdrant Recovery

Two recovery options:

1. Restore Qdrant snapshots.
2. Rebuild vectors from PostgreSQL metadata and stored normalized content.

The second option is essential to prove that Qdrant is a derived index.

## 25.4 Object Storage Backups

Original sources are critical and should be replicated or backed up to independent storage.

## 25.5 Recovery Documentation

Document procedures for:

- PostgreSQL restore.
- Object storage restore.
- Qdrant restore or rebuild.
- Secret restoration.
- Full environment recreation.

---

## 26. CI/CD Pipeline

A typical pipeline should include:

1. Check formatting.
2. Run static analysis.
3. Run unit tests.
4. Run contract tests.
5. Run frontend tests.
6. Build application images.
7. Scan dependencies and images.
8. Generate SBOM.
9. Run integration tests with containers.
10. Run a small RAG regression suite.
11. Publish versioned images.
12. Deploy to staging.
13. Run smoke tests.
14. Require approval for production.
15. Deploy production.
16. Run post-deployment health checks.

Database migrations should be backward-compatible where possible and should have a rollback or recovery plan.

