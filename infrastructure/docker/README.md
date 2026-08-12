# `infrastructure/docker` — the Compose stack

Docker Compose **v5.x** on Docker Engine **≥ 29**. There is no `version:` key in any file here;
the spec keeps it "only informative" and Compose prefers the most recent schema regardless.

> "Compose v3" in a blog post is the **2017 file format**, not this tool. Compose v5.0.0 skipped
> v3 and v4 deliberately, *"to avoid confusion with obsolete docker-compose file format versions
> 2.x and 3.x"*. A `Compose v2.x` badge in the reference docs marks when a field was *introduced*.

---

## Which `-f` combination, and when

| Environment | Command |
|---|---|
| **Development** | `docker compose up -d` — `compose.override.yaml` is **auto-loaded** |
| Development + file watching | `docker compose watch` (or `up --watch`) |
| **Production** | `docker compose -f compose.yaml -f compose.prod.yaml up -d` |
| Telemetry backend | add `--profile observability` |
| Local mail / DB UI | add `--profile dev-tools` |
| Test dependencies | add `--profile test` |

`make deploy` hardcodes the production line. That is the entire justification for the Makefile:
a script can drift to `docker compose up -d`; a Make target that is one command cannot.

---

## The production footgun

**`docker compose up -d` in this directory brings production up with development configuration,
and nothing warns you.**

`compose.override.yaml` is loaded automatically whenever **no `-f` is passed**. Passing any `-f`
disables that auto-load — which is the whole reason production always passes explicit files. A
deploy script that drifted to a bare `up -d`, or an operator who ran it by hand during an
incident, gets:

- **bind-mounted source** over the image, so the running code is whatever is on that host's disk;
- **`127.0.0.1:5432:5432`** on PostgreSQL — plus Qdrant, both Valkey instances and the S3 gateway;
- **Let's Encrypt staging certificates**, because the dev overlay sets `caServer`;
- `APP_DEBUG=true` on Laravel and `fastapi dev` on the AI service;
- `opcache.validate_timestamps` behaviour intended for a bind mount.

None of that produces an error. `docker compose ps` is green.

Two habits make it hard to do by accident: deploy through the one sanctioned target, and read the
production render before you do.

```bash
make deploy   # the only sanctioned production start

# Which service publishes a host port? Exactly one line, and it says traefik.
make prod-config | awk -F: '/^  [a-z0-9_-]+:$/{s=$1} /^    ports:$/{print "publishes ports:" s}'

# What is bind-mounted? 15 distinct sources today, every one of them read-only: the third-party
# config files enumerated under "Read-only config mounts" below, plus /var/run/docker.sock and
# /var/lib/docker/containers. A path under services/ or apps/ means the dev overlay loaded.
make prod-config | grep -A1 'type: bind' | grep 'source:' | sort -u

# Which hostnames does the edge actually route? Exactly four, and none of them ends in a bare
# dot — `Host(`api.`)` is the unset-${DOMAIN} render described below.
make prod-config | grep -oE 'Host\(`[^`]*`\)' | sort -u
```

A plain `grep -E 'ports:|bind'` on this render returns **33 lines on a correct config** — one
`ports:` and 32 lines belonging to the 16 legitimate `:ro` config mounts. An instruction that fires
on correct output teaches people to ignore it, which is why the three commands above are specific.

**No CI job renders the production config.** `compose-invariants` in `.github/workflows/gates.yml`
asserts the port, network, profile and volume invariants by parsing the four compose *files*
statically with `yq`; `scripts/ops/preflight.sh` does render the production pair, but at **deploy**
time, with `--quiet`, only to prove it parses and that no variable is unset. Neither inspects bind
mounts or router rules. The three commands above are a human check with no automated substitute
today — see "Read-only config mounts" below for why an outright no-bind-mounts assertion cannot be
written as stated.

### The second half of the same trap: `.env`

Compose reads `.env` from the **project directory**, which is *the directory of the first `-f`
file* — here, `infrastructure/docker/`. **There is deliberately no `.env.example` at the
repository root, and adding one is a defect.** A root file would look canonical, would be the one
everyone edits, and would never be read.

With `DOMAIN` unset, `${DOMAIN}` interpolates to the empty string and the Traefik label renders as
``Host(`api.`)`` — a syntactically valid hostname no client will ever send. The router is created,
it is healthy, it appears in the dashboard, and it matches nothing. Traefik logs nothing, because
nothing is wrong. The only symptom is a 404 from the edge for every request.

Also note the two mechanisms that look identical and are not:

| File | Feeds | Read by |
|---|---|---|
| `./.env` | `${VAR}` **interpolation inside the compose files** | Compose |
| `./env/*.env` (`env_file:`) | the **environment of a container** | the process in the container |

A `SEAWEEDFS_ENDPOINT` set in `env/ai-service.env` and referenced as `${SEAWEEDFS_ENDPOINT}` in a
compose file interpolates to `""` — a client pointed at localhost, not an error.

---

## The invariants this directory enforces

1–5 and 8 are enforced by the `compose-invariants` job in `.github/workflows/gates.yml`, which
parses all four compose files and compares the union of what it finds against the expected sets.
6 is enforced only in part and 7 is not enforced at all; each says so below. Read all eight before
editing anything.

1. **No `ai-*` or `laravel-*` service has a `ports:` key in any of the four files**, and in
   `compose.yaml` and `compose.prod.yaml` `traefik` is the only service with one at all.
   Published ports are DNAT'd in the iptables `nat` table, *ahead of* the `INPUT` chain a host
   firewall uses — so an "internal-only" debug port on `ai-api` is a complete authorization
   bypass that `ufw status` reports as blocked.

   `compose.override.yaml` **deliberately publishes five data services on loopback** for local
   development — `postgres` `127.0.0.1:5432`, `qdrant` `6333`, `valkey-core` `6379`,
   `valkey-cache` `6380`, `seaweedfs` `8333`/`9333` — so "traefik is the only `ports:` key" is
   true of production and false of the dev overlay. That gap is the entire reason `make deploy`
   hardcodes the `-f` pair instead of letting the override auto-load, and the reason the two
   halves above are stated separately: the `ai-*`/`laravel-*` half holds in **all four** files,
   the traefik-only half holds in **two**. `compose-invariants` check (6) asserts exactly that
   pair, and explicitly skips `compose.override.yaml` for the second half.

2. **`laravel-api` and `laravel-api-stream` are exactly the services on both `edge` and
   `application`.** That set is closed: a third one fails the check, and so does a missing one.

3. **`ai-worker-crawl` is never on `data`.** It fetches attacker-chosen URLs from inside the
   private network, and Qdrant's REST API needs no credentials.

4. **`valkey-core` is the one store that also joins `application`** — solely so the crawl worker
   can reach its broker. Moving it back to `[data]` silently stops the crawl queue.

5. **`web` is on `edge` only** (ADR-013), so a Next route handler cannot resolve `ai-api`.

6. **`valkey-cache` has no volume**, runs `--save ""` and `--appendonly no`, and appears in no
   backup job. That absence is the erasure control. *Partly enforced:* `compose-invariants`
   check (3) asserts the no-named-volume half in all four files. The persistence flags and the
   backup scope are not machine-checked — `scripts/ops/backup.sh` is read by a human.



7. **Four public hostnames, never a fifth**: `app.`, `chat.`, `api.` on `${DOMAIN}`, and
   `${WIDGET_DOMAIN}` on a *different registrable domain*. `otel-collector` joins `edge` for
   network reachability only (ADR-025) — no router, no `traefik.enable`, `kb.edge: "false"`.
   Being on a network and being routable are independent, and conflating them is exactly what
   the two latches exist to prevent. The browser posts same-origin to `/telemetry/v1/traces` and
   a Next route handler forwards it.

   *Not enforced by CI.* No job reads Traefik labels or router rules; `compose-invariants` reads
   `networks:`, `ports:`, `profiles:` and `volumes:` only. The hostname count is checked by hand,
   with the `Host(...)` grep above, and by the spoofed-`Host` curls below.

8. **No service carries a `profiles` key except the profiled tier**, and nothing always-on
   `depends_on` a profiled service. There is no `core` profile, and no `gpu` profile or overlay
   — nothing reserves a device now that embeddings and reranking are provider API calls.

### Proving 1–3, actively

A config review is not a substitute. From outside the host:

```bash
# Must NOT reach FastAPI. Expect a 404 from Traefik, never a JSON health body.
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: ai-api'         https://<public-ip>/health/ready
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: ai-api'         https://<public-ip>/internal/v1/health
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: knowledgebot-ai-api-1' https://<public-ip>/
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: laravel-worker' https://<public-ip>/horizon
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: qdrant'         https://<public-ip>/collections
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: otel-collector' https://<public-ip>/v1/traces

# Must be 403 from the EDGE, independently of Laravel's own gate:
curl -sk -o /dev/null -w '%{http_code}\n' https://api.<domain>/internal/v1/health
curl -sk -o /dev/null -w '%{http_code}\n' https://api.<domain>/horizon

# Object store must reject anonymous access:
aws --endpoint-url http://127.0.0.1:8333 --no-sign-request s3 ls   # expect AccessDenied
```

The container-name spoof matters as much as the service-name one: the Docker provider's
`defaultRule` is ``Host(`{{ normalize .Name }}`)``, so with `exposedByDefault: true` every
container gets a router keyed on its **generated** name.

---

## Layout

```
infrastructure/docker/
  compose.yaml            base — 21 always-on services + three profiles; every image tag pinned here
  compose.override.yaml   dev — AUTO-LOADED; bind mounts, develop.watch, loopback port binds
  compose.prod.yaml       explicit -f; digests, read_only, cap_drop, non-root, restart_policy
  .env.example            Compose INTERPOLATION only  ->  copy to .env
  env/*.env.example       CONTAINER environment via env_file:  ->  copy to *.env
  secrets/                file-sourced Compose secrets; gitignored except .gitkeep
  php/                    php.ini, two FPM pools, two nginx configs — COPY'd into the core-api image
  traefik/                static config + dynamic/tls.yaml
  valkey/                 core.conf (noeviction+AOF), cache.conf (allkeys-lru, no persistence),
                          users.acl (GITIGNORED, rendered from users.acl.example by bootstrap.sh;
                          mounted into BOTH instances; NO comment syntax — the reasoning lives in
                          valkey/README.md because it cannot live in the file). MISSING IT IS A
                          FAIL-OPEN: the server starts with `default on nopass +@all`.
  otel/                   collector.yaml + metric-allowlist.yaml (separate so CI can diff it)
  seaweedfs/              identities.json (GITIGNORED, rendered from identities.json.example;
                          absent it is a FATAL startup error, not Allow-All — the Allow-All hazard
                          is dropping -s3.config, see the template's __README) +
                          circuit_breaker.json
  postgres/               postgresql.conf shim, conf.d/kb.conf, initdb/ (extensions ONLY)
  qdrant/                 config.yaml
```

`infrastructure/observability/` — Prometheus rules, Alertmanager routing, Loki, Tempo and Grafana
provisioning — is a sibling directory owned by the observability work. The `observability` profile
here mounts it; the profile will not start until it exists.

### Why nginx is inside the `core-api` image

Traefik v3 has **no FastCGI provider**. Something must translate HTTP to FastCGI, and a separate
`nginx` service would have to sit on both `edge` (to receive from Traefik) and `application` (to
reach php-fpm) — making it a **third** member of the closed `edge ∩ application` set. Collapsing
nginx and php-fpm into one container keeps that set at two. The cost is two processes per
container, handled by `kb-serve` with explicit signal forwarding.

Both FPM pools and both nginx configs are `COPY`'d into the image, not bind-mounted, because
production forbids source bind mounts — the pool sizing must ship with the artifact it describes.
`command: ["kb-serve", "api"]` and `["kb-serve", "stream"]` select between them.

### Read-only config mounts vs "no bind mounts in production"

Production forbids **source** bind mounts. It does not forbid bind mounts: the rendered production
config carries **16 of them, every one `read_only`**. Third-party images (Traefik, Valkey,
PostgreSQL, Qdrant, the OTel Collector, SeaweedFS) cannot have our config `COPY`'d into them
without rebuilding upstream images, so their configuration is mounted `:ro` in the base file.

The closed set, as `make prod-config` renders it today — 14 from this directory:

| Service | Source |
|---|---|
| `traefik` | `traefik/traefik.yaml`, `traefik/dynamic/` |
| `valkey-core` | `valkey/core.conf`, `valkey/users.acl` |
| `valkey-cache` | `valkey/cache.conf`, `valkey/users.acl` |
| `postgres` | `postgres/postgresql.conf`, `postgres/postgresql.conf.d/`, `postgres/initdb/` |
| `qdrant` | `qdrant/config.yaml` |
| `seaweedfs` | `seaweedfs/identities.json`, `seaweedfs/circuit_breaker.json` |
| `otel-collector` | `otel/collector.yaml`, `otel/metric-allowlist.yaml` |

…plus two **host** paths that are not config files and are easy to forget when writing a check:
`/var/run/docker.sock` (Traefik's Docker provider) and `/var/lib/docker/containers` (the
Collector's log tailer). `users.acl` is mounted twice, so the 16 mounts have 15 distinct sources.

A CI assertion that forbade bind mounts outright would go red on this correct config; it would
have to allow-list exactly the paths above. **No such assertion exists today** — nothing in
`.github/workflows/gates.yml` renders the production config, and `scripts/ops/preflight.sh` renders
it only with `--quiet`. Anything bind-mounted from `services/` or `apps/` therefore reaches
production undetected by machine, which is why the render is read by a human before every deploy.

---

## Operating notes that are easy to get wrong

**Grace periods are long, and that is the feature.** `ai-worker-ingestion` is 1320 s. A deploy can
genuinely take twenty-two minutes; the alternative is SIGKILLing a worker mid-upsert and
corrupting the thing the deploy was shipping. Do not shorten them to make deploys feel fast.

**`start_period` is not a grace window.** Probe failures inside it are not counted toward
`retries` — but **the first success ends the period early**, and every failure after that counts.
`ai-api` probes `/health/ready`, not `/health/live`; a live probe answers instantly and collapses a
300 s window to two failures. What keeps `/health/ready` red long enough for that window to mean
anything is **three real round trips** — Qdrant, the Valkey cache, and PostgreSQL — cached for a
few seconds and re-probed after that. It is emphatically **not** "the embedder object is loaded",
which is what this line used to say: [ADR-030](../../docs/19-repo-structure-adrs.md) moved embedding
and reranking to provider APIs and there has been no embedder object in this process since. That
also fixes the shape of the rule going the other way — readiness must never probe an external
provider, so a slow vendor can never take this container out of the edge.

**`restart:` reacts to process exit, never to health.** Traefik excludes unhealthy containers from
its load balancer by default, and Docker will not restart them — so a readiness probe that trips
on a transient dependency blip pulls every replica out of the edge with nothing to recover it.

**`qdrant` has no healthcheck** and `ai-api` therefore depends on it with `service_started`. The
image ships no shell utilities, so an in-container probe exits 127 forever and every
`service_healthy` dependent hangs. Readiness is asserted one layer up, by `/health/ready`.

**Container memory limits sit *above* each worker's `worker_max_memory_per_child`.** Below it, the
cgroup killer fires before Celery recycles the child — and a signal-killed child requeues **without
incrementing `request.retries`**, so the job redelivers forever.

**ACME**: `acme.json` is on a **named volume**. A bind mount whose host path does not exist makes
Docker create a *directory* there, the store can never be written, and the only symptom is
"TRAEFIK DEFAULT CERT" in every browser. When moving from staging to production certificates,
remove the `caServer` line **and delete the `acme` volume** — the staging account persists.

---

## Backup scope

`pgdata` **and the SeaweedFS volume. Nothing else.** The scope *is* the invariant:

- **Qdrant** is snapshotted for RTO but is rebuildable by contract — and a snapshot restore does
  not prove ADR-010, it copies the drift forward.
- **`valkey-core`'s AOF is restart durability, not a backup.** Restoring it resurrects stale
  reservations and spent idempotency records.
- **`valkey-cache` has nothing to back up, by construction.**

`scripts/ops/backup.sh` implements exactly that, and `scripts/ops/restore-drill.md` is the drill.
An untested backup is an assumption.
