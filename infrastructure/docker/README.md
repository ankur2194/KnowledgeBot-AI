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
| Local mail | Mailpit starts with the default dev stack; its UI is `http://127.0.0.1:8025/` |
| Local DB UI | add `--profile dev-tools` for Adminer |
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

**Nothing renders the production config automatically, and since 2026-08-17 nothing checks it at
all.** A `compose-invariants` CI job asserted the port, network, profile and volume invariants by
parsing the four compose *files* statically; it was deleted with `.github/`.
`scripts/ops/preflight.sh` does render the production pair, but at **deploy**
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

1–5 and 8 **were** enforced by a `compose-invariants` CI job, which parsed all four compose files
and compared the union of what it found against the expected sets. That job was deleted with
`.github/` on 2026-08-17, so **every invariant in this list is now a review property**: 6 was only
partly enforced and 7 never was, and now none of them is. Each says so below. 9 is enforced from a
checkout by the `config-delivery` job and against a live host by `scripts/ops/preflight.sh` §3b.
Read all of them before editing anything — and do not restate the count in this sentence, which is
what went stale when 9 was added.

1. **No `ai-*` or `laravel-*` service has a `ports:` key in any of the four files**, and in
   `compose.yaml` and `compose.prod.yaml` `traefik` is the only service with one at all.
   Published ports are DNAT'd in the iptables `nat` table, *ahead of* the `INPUT` chain a host
   firewall uses — so an "internal-only" debug port on `ai-api` is a complete authorization
   bypass that `ufw status` reports as blocked.

   `compose.override.yaml` **publishes two ports on loopback** for local development, and the
   sentence that used to be here — "five data services … `postgres` `127.0.0.1:5432`, `qdrant`
   `6333`, `valkey-cache` `6380`, `seaweedfs` `8333`/`9333`" — went **false on 2026-08-10**, when
   those four lines were deleted after being measured to bind nothing: a container attached only to
   `data` (`internal: true`) has no route to the host bridge, so Docker records the binding and
   never makes it. Read the file, not this paragraph:
   `grep -n 'ports:' compose.override.yaml`. Today it returns

   - `valkey-core` `127.0.0.1:6379` — binds **only** because `valkey-core` also joins
     `application`, which is not internal;
   - `mailpit` `127.0.0.1:8025` — the `dev-tools` mail catcher's UI, which needs a published port
     because a *browser* has to reach it. Its SMTP `1025` is deliberately **not** published: the
     senders are `laravel-worker` and `laravel-scheduler` on `application`, and nothing on the host
     sends mail.

   So "traefik is the only `ports:` key" is true of production and false of the dev overlay. That
   gap is the entire reason `make deploy`
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

9. **The `edge` network's subnet is pinned, and it is the same scalar as `TRUSTED_PROXIES`.**
   `x-edge-subnet` is a YAML anchor aliased in exactly two places: `networks.edge.ipam.config[0]`
   and the `environment:` of `laravel-api` and `laravel-api-stream`. It is the only network with a
   pinned range, and it is pinned because **Laravel has to name it in configuration** — Traefik is
   the direct TCP peer of every request from the internet, so `$request->ip()` comes from
   `X-Forwarded-For` or it comes from nowhere, and Laravel believes that header only from a CIDR it
   is told to trust.

   Unpinned, Docker allocates the range from its address pool in creation order, interleaved with
   every other Compose project on the host. Measured on the development host: `edge` `172.24.0.0/16`,
   `application` `172.25`, `data` `172.26`, `observability` `172.28` — with the gap at `.27` taken by
   an unrelated network *between two of ours*. Prune the networks, or bring another project up
   first, and every value moves while the configured CIDR does not. Nothing errors; the five per-IP
   auth rate limiters simply stop being per-IP.

   Two things to know before touching it:

   - **Never `trustProxies(at: '*')`.** It expands to `0.0.0.0/0`, which makes `X-Forwarded-For`
     fully attacker-controlled — *strictly worse* than trusting nothing, because a forged address
     per request evades every per-IP limiter instead of sharing one bucket, and `audit_logs`
     faithfully records whatever it was told.
   - **Pinning or changing the subnet recreates the network once**, which stops and restarts the six
     containers on `edge`. This happens **even when the pinned value equals the one Docker had
     already auto-assigned** — Compose compares the network's `com.docker.compose.config-hash`
     label, and "auto" is not the same recorded config as "explicit with the same value". Measured
     against Compose v5.3.1. It is a clean no-op on every `up` after that. If the range collides
     with a host route or a VPN, `up` fails loudly with *"Pool overlaps with other one on this
     address space"*; set `KB_EDGE_SUBNET` in `.env` rather than deleting the `ipam:` block, which
     silently un-pins the CIDR that `TRUSTED_PROXIES` depends on.

   `scripts/ops/preflight.sh` §3b checks the whole chain, including the live network's range against
   the declared one. A `config-delivery` CI job checked the compose half from a checkout; it is gone.

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
                          RENDERED ONCE AND NEVER UPDATED: bootstrap.sh will not clobber an
                          existing copy (that is where your values live), so a template that
                          later GAINS a key delivers it to new deployments and to nobody else.
                          Both scripts now compare NAME SETS through scripts/lib/env-drift.sh —
                          bootstrap.sh refuses to `up` on drift, preflight.sh §2b fails the
                          deploy. Neither writes a value: for these keys "present but empty" and
                          "absent" behave differently in both directions, so there is no safe
                          filler. Add the names by hand and choose each value.
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

Repo-level scripts that read this directory live in `scripts/`:

```
scripts/lib/env-drift.sh    the template-vs-rendered NAME-SET comparison, in ONE place.
                            Sourced by scripts/dev/bootstrap.sh and scripts/ops/preflight.sh.
                            It offers no "repair" function on purpose; the header records the
                            per-key measurement of why no automatic filler is safe.
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
have to allow-list exactly the paths above. **No such assertion exists today** — there is no CI at
all, and `scripts/ops/preflight.sh` renders the production config only with `--quiet`. Anything bind-mounted from `services/` or `apps/` therefore reaches
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

**The stop signal must be one PID 1 handles, and for five of six core-api services it wasn't.** The
image inherits `STOPSIGNAL SIGQUIT` from its `php:*-fpm*` base — right when PID 1 *is* php-fpm, and
it isn't: PID 1 is `kb-serve` (traps `TERM INT`) or `php artisan horizon` (traps `TERM USR1 USR2
CONT`). A signal PID 1 has no handler for is **discarded**, so `docker stop` did nothing, Docker
waited out the whole `stop_grace_period` and SIGKILLed — 26 minutes for `laravel-worker-long`, and
every "graceful drain" those grace periods pay for never ran. With `stop_signal: SIGTERM` the same
five services stop in **15 s, all exit 0**. `laravel-scheduler` is exempt (`schedule:work` traps
`INT TERM QUIT`) and `laravel-migrate` traps nothing, so no signal helps it. Gated by
`compose-invariants` check (9d).

**The `edge` subnet is pinned outside Docker's dynamic address pool.** It used to be `172.24.0.0/16`,
inside the daemon's built-in pool, so after a `down` freed all four project networks the next `up`
handed 172.24 to our own unpinned `data` network and failed with *"Pool overlaps with other one on
this address space"* — reproducibly, on every down/up cycle. `10.0.0.0/8` is in neither built-in
pool, so nothing dynamic can ever take it; that is also why the other three networks stay unpinned.
Override with `KB_EDGE_SUBNET` if a host route or VPN really uses that range.

**…and `unless-stopped` is production-only.** `compose.override.yaml` overrides every service to
`restart: "no"`, so in development a stopped container stays stopped across a Docker Desktop or WSL
restart. `unless-stopped` exempts only containers that were explicitly stopped *before* the daemon
went down; anything still running when the daemon stops comes back when it starts, which is the
"I stopped them and they restarted themselves" report. The full reasoning is in that file under
**SHUTDOWN DETERMINISM**. A `compose-invariants` CI job used to assert both directions — dev never
self-restarts, and the base file never loses `unless-stopped` — and it was deleted on 2026-08-17,
so the two measuring greps in that file's footer are what remain.

**`qdrant` has no healthcheck** and `ai-api` therefore depends on it with `service_started`. The
image ships no shell utilities, so an in-container probe exits 127 forever and every
`service_healthy` dependent hangs. Readiness is asserted one layer up, by `/health/ready`.

**Container memory limits sit *above* each worker's `worker_max_memory_per_child`.** Below it, the
cgroup killer fires before Celery recycles the child — and a signal-killed child requeues **without
incrementing `request.retries`**, so the job redelivers forever.

**Mailpit is the local mail path, not a convenience — and it is never routed.** `config/mail.php`
defines exactly two mailers, `smtp` and `array` (the test transport), and **no `log` mailer**:
`MAIL_MAILER=log` writes a live single-use password-reset URL into `storage/logs`. So password
reset, email verification and organization invitation all go over SMTP, and in local development
that means this container.

```bash
docker compose up -d mailpit          # no --profile: mailpit is in the default dev set
docker compose port mailpit 8025        # -> 127.0.0.1:8025  (check the IP, not just the port)
xdg-open http://127.0.0.1:8025/         # remote host: ssh -N -L 8025:127.0.0.1:8025 <host>
```

The UI and its API have **no authentication** and the API returns every captured message body, so
mailpit carries `kb.edge: "false"`, has no `ports:` key in `compose.yaml`, and appears in no Traefik
router — the two latches, because otherwise the Docker provider's `defaultRule` would give it a
router keyed on ``Host(`mailpit`)``. Its only published port lives in the dev overlay, bound to
loopback, so a production render (`make deploy`) publishes nothing for it even with the profile on.
It is on `application` only. Note that `ai-worker-crawl` is also on `application` and fetches
attacker-chosen URLs, so while `dev-tools` is up the mailbox is reachable from the SSRF pivot —
dev-only mail, dev-only profile, and one more reason not to run `dev-tools` anywhere real.

**For a real deployment, do not enable this profile — configure a relay** in `env/core-api.env`:
`MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` on a domain whose
SPF/DKIM you control, and `MAIL_EHLO_DOMAIN` (unset, Symfony sends the container ID as its HELO name
and a strict relay refuses the session). If the relay authenticates, add `MAIL_USERNAME` and a
`MAIL_PASSWORD_FILE=/run/secrets/<name>` secret — never a password value in an env file. Without a
relay **and** without the profile, every auth mail fails in the `notify` queue worker with a
connection error to host `mailpit`; that is the intended loud failure, and it is why mailpit is
profiled rather than always-on (always-on would swallow production mail into a UI nobody watches).
`FRONTEND_URL` is the other half of a working mail: it is the **SPA's** base URL, `app.<domain>`,
not `APP_URL`, which is `api.<domain>`. Every emailed link is built from it, and with the key absent
Laravel falls back to `http://localhost:3000`.

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
