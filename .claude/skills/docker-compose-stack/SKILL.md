---
name: docker-compose-stack
description: The Compose topology in infrastructure/docker/ — four networks, profiles, volumes, healthchecks, resource limits and stop grace periods, the layer where the other skills' invariants become enforceable. Use whenever adding a service, network, volume, profile, healthcheck or depends_on condition, or when a worker is SIGKILLed mid-job, a container is unexpectedly reachable, or `docker compose up` starts nothing. Edge routing is traefik-routing; there is no CI in this repo. Pairs with kb-architecture-map (the boundaries these networks encode).
---

# Docker Compose Stack — `infrastructure/docker/`

Docker Compose **v5.4.0** (2026-08-03) on Docker Engine **29.7.1** (2026-07-31). Images pinned by tag in the base file and by **digest** in the production overlay.
**Authoritative spec:** docs/18-deployment-backup-cicd.md §24–25, docs/06-architecture.md §10.2 §11.1, docs/19-repo-structure-adrs.md §27 ADR-009, docs/14-reliability.md §19.6

**Compose is on v5, not v2.** The v2 line ended at v2.40.3 (2025-10-30); v5.0.0 skipped v3 and v4 *"to avoid confusion with obsolete docker-compose file format versions 2.x and 3.x"*. So "Compose v3" in a blog post is a 2017 **file format**, not this tool, and the `Compose v2.x` badges in the reference docs mark when a field was *introduced*. There is no `version:` key here: the spec keeps it *"only informative"* and Compose *"prefers the most recent schema"* regardless, warning if you write one.

## Non-negotiables

- **Network membership is the trust boundary, and it is the only one Compose can enforce.** `ai-api` and every `ai-worker-*` get no `ports:` key in any file or overlay and no `kb.edge=true` label; `laravel-api` and `laravel-api-stream` are the only two services on both `edge` and `application` — that set is closed, and a third service joining both is the boundary being dissolved one convenience at a time (`kb-architecture-map`). Published ports are DNAT'd ahead of the host firewall, so an "internal-only" debug port is a complete authorization bypass (`kb-architecture-map` gotcha 2). Traefik joins `edge` only (`traefik-routing`).
- **`web` is deliberately NOT on `application`.** §24.3 puts it there; `kb-architecture-map` narrowed it, and this file implements the narrowed version — with `web` on `application`, a Next route handler resolves `http://ai-api:8000` and *"clients never call FastAPI"* degrades from a fact to a convention. **This deviates from §24.3 as written and is ratified as ADR-013** — settled, not a proposal, so restoring `web` to `application` is a regression rather than a spec correction.
- **Two Valkey *services*, never two logical databases.** `maxmemory-policy`, `save` and `appendonly` are server-level, so `SELECT n` cannot separate them: `valkey-core` is `noeviction` + AOF (queues, broker, locks, idempotency, breakers, sessions, Laravel's *default* cache store); `valkey-cache` is `allkeys-lru` with **`--save "" --appendonly no` and no volume at all** — it is the only place tenant text lives in Valkey, and never writing an RDB or AOF is the only provable way to keep cached answers out of a backup (`valkey-keyspaces`).
- **`stop_grace_period` exceeds the worker's own timeout on every worker service.** The default is **10 s**, then SIGKILL — short enough to kill a Laravel job mid-`UPDATE` or a Celery child mid-upsert, which is exactly the state both skills' idempotency is paying to avoid (`laravel-queues-valkey`, `celery-workers`).
- **The PHP image contains `ext-pcntl` and `ext-posix`; every Celery command line says `--pool=prefork`.** Without pcntl, `Worker::supportsAsyncSignals()` is false and `--timeout` silently does nothing, so `retry_after` loses the ceiling the whole arithmetic depends on; a non-prefork Celery pool removes the hard time limit the same way. Same class of trap, two runtimes, no error message in either.
- **The backup scope is `pgdata` and the SeaweedFS volumes. Nothing else.** Qdrant is snapshotted for RTO but is rebuildable by contract (ADR-010, §25.3), and `valkey-core`'s AOF is *restart* durability — restoring it from a backup resurrects stale reservations and spent idempotency records. `valkey-cache` has nothing to back up by construction.
- **No credential in an image layer, a committed `.env`, or a `docker compose config` dump.** Compose `secrets:` mount files at `/run/secrets/<name>`; the KEK, the HMAC signing secrets and the DB password arrive that way (`kb-security-baseline`).

## How we use it

### Files

```
infrastructure/docker/
  compose.yaml            base — every service; no ports, no bind mounts, no secrets values
  compose.override.yaml   dev — AUTO-LOADED; bind mounts, develop.watch, 127.0.0.1 port binds
  (there is no compose.gpu.yaml — ADR-030 removed it; see "No GPU overlay" below)
  compose.prod.yaml       explicit -f; digests, resource limits, restart_policy, read_only
  php/{fpm-api.conf,fpm-stream.conf,php.ini}  traefik/  valkey/  otel/  .env.example
```

Production is `docker compose -f compose.yaml -f compose.prod.yaml up -d`. Passing any `-f` disables auto-loading of `compose.override.yaml`, which is the whole point — see Gotchas.

### Networks and membership

| Network | `internal:` | Members |
|---|---|---|
| `edge` | no | `traefik`, `web`, `sdk`, `laravel-api`, `laravel-api-stream`, **`otel-collector`** |
| `application` | **no** — `ai-api` and the crawl worker need egress to providers and the public web | `laravel-api*`, `ai-api`, all `ai-worker-*`, `ai-beat`, `laravel-worker*`, `laravel-scheduler`, `valkey-core`, plus the two profiled dev/test services (`mailpit`, `corpus-www`) |
| `data` | **yes** | everything above that queries state, plus `postgres`, `qdrant`, `valkey-core`, `valkey-cache`, `seaweedfs` |
| — | | `valkey-core` is the **one** store that also joins `application`. `ai-worker-crawl` is deliberately kept off `data` (it fetches attacker-chosen URLs, and Qdrant's REST API needs no credentials), but it is still a Celery worker and must reach its broker. Without this it cannot consume any queue and the crawl pipeline never starts. `valkey-cache`, `postgres`, `qdrant` and `seaweedfs` stay `data`-only. |
| `observability` | yes | `otel-collector` + the telemetry stack (`prometheus-grafana-loki-tempo`) and every service that exports |

**`otel-collector` is on `edge` and that is not a leak (ADR-025).** Membership only — no `ports:`,
no Traefik router, no public hostname. `apps/web` is on `edge`, so its server-side exporter reaches
`http://otel-collector:4318` directly, and the browser posts same-origin to `/telemetry/v1/traces`
which a Next route handler forwards over the same network; CSP stays `connect-src 'self'` and the
public hostname count stays at four. It is on `edge` and **not** on `application`, so the closed set
that actually matters — `edge ∩ application == {laravel-api, laravel-api-stream}` — is unmoved. A
gate written on *`edge` membership* instead of on that **intersection** false-positives on exactly
this service, which is why `compose-invariants` asserts both facts about it by name.

**Membership of `application` and `data` was pinned exhaustively in CI, by name, until the workflows were deleted on 2026-08-17.** `gates.yml`'s
`compose-invariants` check (5b) carries the full list for both, and check (5c) carries
`laravel-migrate`'s set on its own. Joining either network therefore costs an explicit edit to the
workflow — that is the review a trust boundary is supposed to get, and it is deliberately a
maintenance cost, because the alternative admits new members silently. Read the lists out of the
workflow rather than out of this table:
That check no longer exists, so membership is read straight off the compose files — `infrastructure/docker/README.md` § *The invariants this directory enforces* lists all twelve and marks each as a review property.

`laravel-api-stream` is a second container from the same image running an FPM pool sized for concurrent **streams**: one streamed answer pins one FPM child for its entire life, so a shared pool means a burst of chats takes the admin dashboard down (`laravel-control-plane`, `kb-architecture-map` gotcha 9). Traefik routes the stream paths to it (`traefik-routing`). `laravel-worker` runs Horizon, which gets no `kb.edge=true` label and never joins `edge` — its dashboard renders every tenant's job payloads (`laravel-queues-valkey`).

### The parts that carry the reasoning

```yaml
# infrastructure/docker/compose.yaml — no `version:` key, deliberately.
name: knowledgebot

networks:
  edge: {}
  application: {}            # NOT internal: providers and crawl targets live on the internet
  data: {internal: true}
  observability: {internal: true}

volumes:
  pgdata: {}                 # BACKED UP — source of truth (§25.2)
  seaweed: {}                # BACKED UP — originals + derived artifacts (§25.4)
  qdrant: {}                 # snapshot for RTO only; rebuildable by contract (ADR-010)
  valkey-core: {}            # AOF for restart durability; NEVER restored from a backup
  beat-schedule: {}          # celery beat must persist its schedule (celery-workers)
  models: {}                 # HF weights, mounted :ro — baked in prod, cached in dev
  # valkey-cache: absent on purpose. Nothing is written, so nothing can be recovered.

services:
  # ALL FOUR data-store tags live here and nowhere else — the versions `postgresql-patterns`,
  # `qdrant-hybrid-search`, `seaweedfs-s3` and `valkey-keyspaces` agreed. CI copies three and a drift check
  # a tag written only in a workflow was how CI and prod drifted. There are no workflows now, so
  # this file is the single copy — which is what the deleted drift check existed to protect.
  postgres:  {image: postgres:18-alpine,       networks: [data], volumes: [pgdata:/var/lib/postgresql/data],
              healthcheck: {test: ["CMD-SHELL", "pg_isready -U $$POSTGRES_USER"], interval: 10s, retries: 5}}
  qdrant:    {image: qdrant/qdrant:v1.18.3,    networks: [data], volumes: [qdrant:/qdrant/storage]}   # minor tracks qdrant-client==1.18.0
  seaweedfs: {image: chrislusf/seaweedfs:4.40, networks: [data], volumes: [seaweed:/data]}            # floor 4.30; a bump is a restore drill

  valkey-core:
    image: valkey/valkey:9.1.1
    command: ["valkey-server", "--maxmemory", "1gb", "--maxmemory-policy", "noeviction",
              "--appendonly", "yes", "--appendfsync", "everysec"]
    networks: [data, application]         # `application` ONLY so ai-worker-crawl can reach its
                                          # broker — that worker is off `data` on purpose
                                          # (SSRF pivot, unauthenticated Qdrant REST API).
                                          # Do not "tidy" this back to [data]: the crawl queue
                                          # silently stops being consumed.
    volumes: [valkey-core:/data]
    healthcheck: {test: ["CMD", "valkey-cli", "ping"], interval: 10s, retries: 5}

  valkey-cache:
    image: valkey/valkey:9.1.1
    # --save "" disables RDB; --appendonly no disables AOF. Both, plus no volume: the
    # erasure control for the only tenant text in Valkey (valkey-keyspaces).
    command: ["valkey-server", "--maxmemory", "2gb", "--maxmemory-policy", "allkeys-lru",
              "--save", "", "--appendonly", "no"]
    networks: [data]
    healthcheck: {test: ["CMD", "valkey-cli", "ping"], interval: 10s, retries: 5}

  ai-api:
    build: {context: ../../services/ai-service}
    command: ["fastapi", "run", "--host", "0.0.0.0", "--port", "8000", "app/main.py"]
    networks: [application, data, observability]   # never `edge`; never a `ports:` key
    labels: ["kb.edge=false"]                      # traefik-routing's second latch
    environment: {WEB_CONCURRENCY: "1"}            # >1 forks a second copy of every model
    volumes: [models:/models:ro]
    healthcheck:
      # No curl in the runtime image — a CMD curl probe fails forever and every
      # service_healthy dependent hangs until its own timeout. Use the interpreter.
      test: ["CMD", "python", "-c",
             "import urllib.request as u; u.urlopen('http://localhost:8000/health/ready')"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 30s       # NOT 300s — ADR-030 deleted the multi-GB load this was sized for,
                              # and an oversized window only delays the moment a genuinely broken
                              # container is reported unhealthy. Failures inside it are uncounted…
      start_interval: 5s      # …but the FIRST success ends the period early. Engine >= 25.0
    stop_grace_period: 90s    # > the 60 s chat deadline, so an in-flight stream finalizes usage
    depends_on:
      # `service_started`, NOT `service_healthy`: the Qdrant image ships no shell utilities, so it
      # carries no healthcheck at all, and `service_healthy` against a service that declares none
      # is an invalid model. ai-api's own /health/ready is the real gate.
      qdrant: {condition: service_started}
      valkey-core: {condition: service_healthy}
      laravel-migrate: {condition: service_completed_successfully}
    deploy: {resources: {limits: {memory: 2G}}}    # clients, pools and heap — no resident models

  ai-worker-ingestion:
    build: {context: ../../services/ai-service}
    command: ["celery", "-A", "app.worker", "worker", "-Q", "ingest", "-c", "4",
              "--pool=prefork"]                    # any other pool voids task_time_limit
    networks: [application, data, observability]
    stop_grace_period: 1320s   # task_time_limit 960 + worker_soft_shutdown_timeout 300 + margin
    deploy: {resources: {limits: {memory: 8G}}}    # MUST exceed worker_max_memory_per_child

  laravel-worker:
    build: {context: ../../services/core-api, target: fpm}   # image carries ext-pcntl/ext-posix
    command: ["php", "artisan", "horizon"]
    environment: {HORIZON_ENVIRONMENT: worker-fast}
    networks: [application, data, observability]
    labels: ["kb.edge=false"]                      # the dashboard is cross-tenant
    stop_grace_period: 180s                        # > queue:work --timeout 120 (valkey conn)

  laravel-migrate:                                 # one-shot; api and workers gate on it
    build: {context: ../../services/core-api, target: fpm}
    command: ["php", "artisan", "migrate", "--force", "--isolated"]
    restart: "no"
    networks: [data, observability]   # `observability` IS LOAD-BEARING — see below. Not [data].
    depends_on: {postgres: {condition: service_healthy}}
```

**`laravel-migrate` is `[data, observability]`, and "tidying" it to `[data]` is the regression
`compose-invariants` check (5c) exists to catch.** This block read `networks: [data]` until
2026-08-12 and that was wrong: `env/core-api.env` points **all six** core-api containers at
`http://otel-collector:4318`, so without the network this one-shot's shutdown flush resolves a name
against a network the Collector is not on, retries, and charges the resolver latency to the ~ten
services gated on its `service_completed_successfully`. `OTEL_EXPORTER_OTLP_TIMEOUT` bounds one HTTP
attempt, not the retry loop. It costs nothing a threat model can see — `observability` is
`internal: true`, grants no egress, and its only listener is the Collector's OTLP receiver, which
holds no credential and returns no data — and it touches neither `edge` nor `application`, so both
closed sets are unmoved. The fix is a route, not a mute: setting `OTEL_SDK_DISABLED` here would make
the one container everything waits on the only one that emits no span. The full argument, with the
eight preserved runs it was measured from, is the comment above `networks:` in `compose.yaml`.

### Profiles — and the two things §24.5 gets wrong

Services **without** a `profiles` key are always enabled, so §24.5's `core` profile is not merely redundant, it is harmful: tag the required services `core` and a bare `docker compose up` starts nothing. Core services carry no `profiles` key. Real profiles: `observability`, `dev-tools`, `test`.

Second, **a profile cannot patch a service, only include or exclude it** — it can add or remove a service, never add a key to one. That constraint is why §24.5's `gpu` profile could never have worked as written, and it is worth remembering the next time a profile is proposed as a way to change a service's shape. And a `depends_on` pointing at a service in an inactive profile is *"an invalid model"* — Compose errors rather than auto-enabling it, which is why nothing `depends_on` `otel-collector`; telemetry export fails soft by design (§19.6).

### No GPU overlay, and where the weights actually are

**ADR-030 removed local model inference**, so there is no `compose.gpu.yaml`, no device reservation, and no `gpu` profile. Embedding, reranking and generation are external API calls (`bge-m3-embeddings`, `bge-reranker`, `kb-provider-adapter-contract`). `ai-api` holds clients and pools, not weights — the ~2.3 GB embedder and ~2.3 GB reranker that used to be resident per process are gone, and with them the reason `ai-api` ran one worker process per container. Anyone re-tuning `WEB_CONCURRENCY` should know the original number was justified by models that no longer exist.

**The `/models` volume stays, and deleting it breaks parsing.** Docling's layout and TableFormer models and RapidOCR's ONNX weights are still local and still mounted, with `HF_HOME` pointed at the same path and `HF_HUB_OFFLINE=1` set so a missing weight is a loud first-parse failure rather than a silent runtime download. The rule is *no local model inference for embedding or reranking*, **not** *no local computation*.

§24.8's 8–16 GB floor now holds without an asterisk: nothing here needs 16 GB for a model, and a self-hoster needs no GPU. What the retrieval budget spends instead is **round-trips** — see `bge-reranker` on why stage 11 must state its cost before it starts.

Limits are `deploy.resources.limits.{memory,cpus,pids}` and `deploy.resources.reservations.{memory,devices}`, all of which `docker compose up` honours; `placement`, `mode`, `update_config` and `rollback_config` do not apply outside Swarm. <!-- UNVERIFIED: the ignored-key list is read from docker/compose source, not from prose docs -->

**Self-hosted single host vs production, and `develop.watch` in dev: [`references/deployment-profiles.md`](references/deployment-profiles.md)** — one-host trade-offs, the production hardening set (digest pins, `read_only`, `cap_drop`, non-root), and why neither profile publishes a database port.

## Gotchas

- **A production deploy silently comes up with dev bind mounts and a published Postgres port.** `compose.override.yaml` is loaded automatically whenever no `-f` is passed, and a deploy script that drifted to `docker compose up -d` in the repo directory gets it. Nothing warns. Always pass explicit `-f compose.yaml -f compose.prod.yaml`, and let CI assert the port and network invariants.
- **Any re-automation of this must not use `docker compose config`, and this used to say it should.** Three independent reasons, all measured on 2026-08-12. (i) **It does not run on a checkout.** The four `infrastructure/docker/env/*.env` files are gitignored — only their `.example` twins are committed — so `docker compose -f compose.yaml -f compose.prod.yaml config` exits **1** with `env file …/env/core-api.env not found` before rendering a byte. The same is true of `secrets/*`. The "fix" that suggests itself, running `bootstrap.sh` on the runner first, materialises real generated credentials into the CI workspace, which is precisely what the deleted secret-scan job refused to do. (ii) **It renders interpolated values in full**, which is the leak the Gotcha five lines down warns about — an assertion whose output must not be logged is a bad assertion. (iii) **It merges the three files into one document**, and *which file* introduced a network or a port is the finding: `compose.override.yaml` deliberately publishes five data-service ports for local dev while `compose.yaml` and `compose.prod.yaml` must have none outside `traefik`, and an overlay quietly adding `data` to a worker is the failure mode the union across files exists to catch. What the deleted `compose-invariants` gate did was `yq -o=json` each of the three files separately and compare sets with `jq`, needing no `.env`, no secret, and no daemon.
- **A `networks:` gate passes while checking nothing, because the key has two shapes.** `networks:` is a **list** for most services and a **map** (names → `aliases:`) for `seaweedfs` and `corpus-www`. A naive `contains(["edge"])` is false for every map-form service, so the intersection comes out empty and the gate goes green having compared nothing. Normalise both shapes (`if type=="object" then keys elif type=="array" then . else [] end`), and carry the control that proves the normaliser still works: at least one map-form block must still exist, and no service may resolve to zero networks.
- **Every provider call fails with a DNS or connect error that reads exactly like a provider outage, minutes after a network cleanup.** Someone added `internal: true` to `application`. An internal network gives its members no route off the host, and `ai-api` plus `ai-worker-crawl` are the two services that must reach the internet. `data` is the internal one; `application` never is.
- **`depends_on: service_healthy` waits forever on a container that is running fine.** The healthcheck is `["CMD", "curl", …]` and the runtime image has no curl — a distroless or `-slim` base usually does not. The probe exits 127, is counted as unhealthy, and after `start_period` ends nothing ever flips it. Probe with the language runtime (`python -c`, `php -r`) or with the server's own client binary (`valkey-cli ping`, `pg_isready`).
- **A slow-starting container is marked unhealthy at 40 seconds, restarted, and never converges.** `start_period` is not a grace *window* in the way people read it: probe failures inside it are not counted, but *"if a health check succeeds during the start period, the container is considered started and all consecutive failures will be counted."* A `/health/live` probe answers before the pools are built, ends the start period early, and hands the next three failures straight to the retry counter. Probe `/health/ready`, which stays red until the datastore clients exist (`fastapi-service`). `ai-api`'s `start_period` was 300 s, sized for a multi-GB model load ADR-030 removed; it is **30 s** today, because an oversized window buys nothing once the load is gone and only delays the moment a genuinely broken container is reported unhealthy.
- **A dependency-blip takes the whole edge down instead of degrading.** Traefik's Docker provider excludes unhealthy containers from the load balancer by default (`allowEmptyServices: false`), so a readiness probe that fails on a transient Qdrant error removes every `ai-api` replica at once — and Docker Engine will *not* restart it, because `restart:` reacts to process exit, not to health. Keep required-vs-optional dependencies exactly as `kb-observability-conventions` splits them, give `retries` enough room to outlive a normal blip, and cache the readiness result 5 s.
- **`${SOMETHING}` in the Compose file resolves to empty, even though the variable is set in the file listed under `env_file:`.** Two different mechanisms: `env_file:` supplies the *container's* environment, while interpolation inside the Compose file reads the shell and the project-root `.env`. A `SEAWEEDFS_ENDPOINT` that quietly interpolates to `""` produces a client pointed at the local host, not an error.
- **A jobs container is SIGKILLed mid-write on every deploy and the source is stuck in `Parsing`.** The service inherited the 10 s default `stop_grace_period`. Laravel's `queue:work` finishes the current job on SIGTERM and Celery's soft shutdown requeues in-flight work — both need more than 10 s, and the ingestion worker needs 22 minutes in the worst case. The honest cost is that deploys can take that long; the alternative is corrupting the thing the deploy was shipping.
- **A worker loops forever on one document, redelivering after every OOM kill.** The container memory limit was set below `worker_max_memory_per_child`, so the cgroup killer fires before Celery's own recycling ever triggers — and a signal-killed child requeues without incrementing `request.retries` (`celery-workers`). The container limit must sit *above* the per-child ceiling, and the durable delivery counter is what actually stops the loop.
- **`docker compose up` starts nothing, or errors with an invalid model.** Two profile traps. A `core` profile was added to the required services, which are only enabled when that profile is active. Or an always-on service `depends_on` a profiled one: Compose *"returns an error"* rather than enabling it. Required services carry no `profiles` key, and nothing depends on a profiled service.
- **The stack is healthy but the app 500s on a missing table, or two migration runs deadlock.** `depends_on` ordering only applies at `up` time and does nothing about schema. Gate `laravel-api` and every worker on a one-shot `laravel-migrate` with `condition: service_completed_successfully`, and use `migrate --isolated` so a scaled-out `up` cannot run it twice (`postgresql-patterns` owns lock-safe migration shape).
- **`docker compose config` in a CI log leaks the database password.** Interpolated values are rendered in full. Secrets are `secrets:` entries mounted at `/run/secrets/<name>` — the `postgres` image reads `POSTGRES_PASSWORD_FILE`, and Laravel/FastAPI read the KEK and HMAC secrets from a path, never from an env var (`kb-security-baseline`, `security-scanning-toolchain`).

## Official docs

- [Compose file reference — services](https://docs.docker.com/reference/compose-file/services/) — `depends_on` conditions, `healthcheck` fields, `stop_grace_period`, `deploy`; and [version and name](https://docs.docker.com/reference/compose-file/version-and-name/) for the obsolete `version` key.
- [Compose — profiles](https://docs.docker.com/compose/how-tos/profiles/) and [compose-spec/15-profiles.md](https://github.com/compose-spec/compose-spec/blob/main/15-profiles.md) — always-enabled services, `COMPOSE_PROFILES`, `--profile "*"`, and the cross-profile `depends_on` error.
- [Compose — file watch](https://docs.docker.com/compose/how-tos/file-watch/) — the five `action` values, `initial_sync`, `include`, and the `build:` requirement.
- [Compose — secrets](https://docs.docker.com/compose/how-tos/use-secrets/) — `/run/secrets/<name>`, `file` vs `environment` sources, and why `_FILE` is an image convention.
- [Docker — packet filtering and firewalls](https://docs.docker.com/engine/network/packet-filtering-firewalls/) — the `nat`-table diversion that makes a host firewall irrelevant to a published port.
- [Dockerfile reference — HEALTHCHECK](https://docs.docker.com/reference/dockerfile/#healthcheck) — the defaults Compose inherits and the exact `start-period` retry semantics.
- [docker/compose releases](https://github.com/docker/compose/releases) — the v2 → v5 renumbering and the current line.

## Definition of done

- [ ] No `ports:` and no `kb.edge=true` on `ai-api`, any `ai-worker-*`, or `laravel-worker`, in base or any overlay; `laravel-api*` are the only services on both `edge` and `application`; `web` is on `edge` only; `otel-collector` is on `edge` and **not** on `application`. Verified by parsing the three files individually — a `compose-invariants` gate did this until 2026-08-17, and it is a review step now — **never** by `docker compose config` — see Gotchas for the three measured reasons.
- [ ] Two Valkey services exist; `valkey-cache` has no `volumes:` key, runs `--save ""` and `--appendonly no`, and appears in no backup job. A test asserts both instances' `maxmemory-policy` via `CONFIG GET` against the running containers.
- [ ] Every worker service sets `stop_grace_period` greater than its own timeout; a test parses the compose file and the worker command lines and compares them.
- [ ] Every container memory limit exceeds that service's `worker_max_memory_per_child` / model footprint; `WEB_CONCURRENCY` is `1` on `ai-api`.
- [ ] The PHP image reports `pcntl` and `posix` in `php -m`; every Celery command line contains `--pool=prefork`. Both asserted in CI.
- [ ] No required service carries a `profiles` key, and no service `depends_on` a profiled one; `docker compose up` with no flags brings up a working stack.
- [ ] Every healthcheck runs a binary that exists in that image; `ai-api`'s probe is `/health/ready` (never `/health/live`, which answers before the pools exist and ends the start period early), and its `start_period` is sized on **measured** startup — 30 s since ADR-030 removed the model load, not the 300 s that load justified.
- [ ] `laravel-api*` and all workers gate on `laravel-migrate` with `service_completed_successfully`.
- [ ] The backup job covers `pgdata` and the SeaweedFS volumes and nothing else; a restore drill rebuilds Qdrant from PostgreSQL rather than restoring a snapshot (§25.3 option 2).
- [ ] No secret value appears in any committed file or in `docker compose config` output; all arrive via `secrets:` at `/run/secrets/`.
- [ ] The production deploy command passes explicit `-f` files; CI asserts — over `compose.yaml` and `compose.prod.yaml` parsed individually, not over a rendered merge — that no service outside `traefik` carries a `ports:` key, and that every data-store tag matches the pin written here — `postgres:18-alpine`, `qdrant/qdrant:v1.18.3`, `chrislusf/seaweedfs:4.40`, `valkey/valkey:9.1.1` — with no floating `latest` anywhere.
