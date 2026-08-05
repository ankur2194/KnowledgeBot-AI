# Self-hosted single host vs production, and dev file-watching — reference

Depth for `docker-compose-stack`, moved verbatim from its `## How we use it`. Spec: `docs/18-deployment-backup-cicd.md` §24–25.

### Self-hosted single host vs production

One host: everything on one Compose project, `restart: unless-stopped`, `observability` and `dev-tools` off (the telemetry stack costs ~2 GB before it stores anything), Postgres and Qdrant sharing a disk, no replicas. Resource limits become *more* important, not less — they are the only thing stopping an embedding worker from OOM-killing Postgres. Production adds digest pins, `read_only: true` with `tmpfs` for scratch, `security_opt: [no-new-privileges:true]`, `cap_drop: [ALL]`, non-root `user:`, and moves Postgres and object storage to hosts with their own backup schedule. Neither profile publishes a database port; dev binds `127.0.0.1:5432:5432` explicitly and prod binds nothing.

Dev uses `develop.watch` (`sync` for app source, `rebuild` on lockfiles, `sync+restart` for config), which needs a `build:` section on the service and `stat`/`mkdir`/`rmdir` in the image. Run it as `docker compose watch` or `up --watch`.
