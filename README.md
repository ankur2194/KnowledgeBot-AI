# KnowledgeBot AI

Self-hostable, multi-model RAG chatbot platform. Upload documents, images, spreadsheets and
presentations, or point it at a website, and it turns them into source-grounded conversations with
citations back to the exact passage each answer came from — under multi-tenant isolation, with your
own provider credentials, on your own infrastructure.

---

## Status

**Past skeleton, unevenly. Read the grep below before assuming a module is empty.**

Every top-level area exists and is configured: the four applications, both services, the two workspace
packages, the Compose topology, the observability stack, the security tooling and the developer
scripts. Since then, parts of the product have been written — the scaffolding phase declared six
Non-goals (business logic, migrations, controllers, retrieval stages, provider adapters, CI workflows)
and all six have been crossed, mostly forced by
[ADR-030](docs/19-repo-structure-adrs.md) and its consequences ADR-031…035.

**The line as it stands (redrawn 2026-08-10): build what is self-contained; do not build the five
provider wire adapters, the chat router, or the `rt/v1` / `sdk/v1` control plane.**

**Which parts are real is a grep, not a list**, and a list would rot within the week:

```bash
grep -rc NotImplementedError services/ai-service/app/<subtree>
```

Zero across `core/`, `observability/`, `evaluation/`, `worker/` and `maintenance/` — those are written
and typed. Dense in `providers/`, `crawl/` and `deletion/` — those are the deliberate stubs.
`ingestion/`, `retrieval/`, `rag/` and `api/` are mixed **file by file**, so grep the file rather than
the directory. In `services/core-api` the same split falls between configuration, routes and tests
(present) and controller bodies (mostly not).

What is still owed:

- **Open findings.** [`docs/22-spec-findings-and-decisions.md`](docs/22-spec-findings-and-decisions.md)
  § _Open after scaffolding_ (O1–O27) and § _Open after ADR-030_ (C1–C3) list what is still
  unresolved. Nothing there is blocking today — O1 was, and ADR-029 closed it. C2 no longer decides
  whether retrieval keeps a sparse arm; **ADR-032** restored it with locally computed BM25, and what
  remains open under C2 is the tokenizer, which is a reindex to change (ADR-034) and therefore worth
  settling before the first large ingest.
- **The `ALLOWED_TABLES` gate.** `gates.yml` still asserts the write allow-list holds four names; it
  holds six ([ADR-033](docs/19-repo-structure-adrs.md)), so that assertion is red against the tree
  until it is rewritten as a membership check.

Every lockfile is committed (`composer.lock`, `uv.lock`, `requirements.lock`, and one
`pnpm-lock.yaml` per workspace importer); an un-bootstrapped checkout no longer fails a build on a
missing `COPY` source. Model revisions in `services/ai-service/models.manifest.toml` are resolved —
three rows, Docling's layout and TableFormer models plus RapidOCR's ONNX weights. It is deliberately
short: under ADR-030 embedding and reranking are provider API calls, so no embedding or reranking
weights are downloaded here at all.

---

## Quickstart

Requires Docker Engine with Compose v2, plus `openssl`. Node, PHP and Python are only needed if you
intend to work outside the containers.

### 1. Bootstrap

```bash
./scripts/dev/bootstrap.sh      # or: make bootstrap
```

It generates local secrets, renders the two credential files from the templates that ship with
well-known development values, starts the stack, and then **asserts that an anonymous `ListBuckets`
against the object store is refused**. That last step is why this is a script and not a README
section: an S3 gateway whose config *parses* but declares nobody — `{}` or `{"identities": []}` —
starts cleanly, logs nothing, and serves every request. Only an anonymous request can tell you
that, which is why the check is executed rather than described. Safe to re-run; it never
overwrites an existing secret.

Which failure belongs to which service is the opposite of the obvious guess, and both were
documented backwards here until [ADR-037](docs/19-repo-structure-adrs.md). Measured against the
pinned tags: **`seaweedfs` fails _closed_** — an absent, directory-shaped or zero-byte
`identities.json` is a fatal config load and the container exits **255** and crash-loops.
**`valkey` fails _open_** — an absent or zero-byte `users.acl` starts as
`user default on nopass ~* &* +@all` and takes unauthenticated writes. The SeaweedFS Allow-All state
is real but is reached only by dropping `-s3.config` from the command or by a config that parses to
`"identities": []`, so **never silence a `seaweedfs` crash-loop by writing `{}` into that file** —
that is the wide-open state, not a repair.

### 2. Hostnames

The stack routes entirely by `Host` header, so `https://localhost` matches no router and returns a 404
from the edge that looks exactly like a broken deployment. You need four names resolving to
`127.0.0.1`:

```
127.0.0.1  api.knowledgebot.localhost
127.0.0.1  app.knowledgebot.localhost
127.0.0.1  chat.knowledgebot.localhost
127.0.0.1  kb-widget.localhost
```

There are four rather than one for security reasons — hosted chat renders model output and must not
share an origin with the admin console, and the widget must sit on a different _registrable_ domain
than the admin session cookie. [`scripts/dev/hosts.md`](scripts/dev/hosts.md) has the full argument,
the WSL2 caveat (edit the hosts file on the **Windows** side), the wildcard-resolver alternative, and
what to do about certificates.

### 3. Run

```bash
make up        # development stack
make ps        # what is running, and whether it is healthy
make logs      # follow everything
make down      # stop, keeping volumes
```

`make help` lists every target. `make up` deliberately runs a bare `docker compose up -d`, which
auto-loads `compose.override.yaml` — correct in development and only in development. See below.

---

## Production

**This is the production start command.** It passes both files explicitly:

```bash
docker compose \
  --project-directory infrastructure/docker \
  -f infrastructure/docker/compose.yaml \
  -f infrastructure/docker/compose.prod.yaml \
  up -d --remove-orphans
```

or, equivalently and preferably, `make deploy` — which hardcodes those `-f` flags in one place, in
version control, where a diff shows them.

> **A bare `docker compose up -d` in production silently brings up the development overlay.** Compose
> auto-loads `compose.override.yaml` whenever no `-f` flag is passed, which means bind-mounted source,
> a published PostgreSQL port, Let's Encrypt **staging** certificates, `APP_DEBUG=true` and
> `fastapi dev` — none of which errors, and all of which leaves `docker compose ps` green.

Read the rendered configuration before every deploy with `make prod-config`. Look for `ports:` under
`traefik` and nowhere else, no bind mounts, and no Traefik rule whose host ends at a bare `api.` — an
empty `${DOMAIN}` renders a router that is valid, healthy, and matches nothing. That output
interpolates every `${...}` in full, so never paste it into a ticket.

---

## Layout

| Path                           | Contents                                                          |
| ------------------------------ | ----------------------------------------------------------------- |
| `apps/web`                     | Next.js admin console (`/*`) and hosted chat (`/c/[publicBotId]`) |
| `apps/widget`                  | Preact host-page loader and the iframe chat application           |
| `apps/mobile`                  | React Native / Expo application                                   |
| `services/core-api`            | Laravel control plane — one image, six services                   |
| `services/ai-service`          | FastAPI data plane, Celery workers, RAG, ingestion, providers     |
| `packages/contracts`           | The SSE frame parser, `KbError`, shared types, generated OpenAPI  |
| `packages/design-tokens`       | Design tokens shared by web and widget                            |
| `infrastructure/docker`        | Compose topology, Traefik, data services, the OTel Collector      |
| `infrastructure/observability` | Prometheus rules, Alertmanager, Loki, Tempo, Grafana provisioning |
| `docs`                         | The specification split, the ADRs, the findings log               |
| `samples`                      | Safe sample documents and the golden evaluation corpus            |
| `scripts`                      | Developer, operational and security scripts                       |

There are exactly two workspace packages and a root `package.json` that declares zero dependencies on
purpose. `.npmrc` sets `shared-workspace-lockfile=false`, so each workspace carries its own
`pnpm-lock.yaml` — the number of them that _should_ exist is
[finding O26](docs/22-spec-findings-and-decisions.md), which is open.

---

## Documentation

- **[`docs/00-index.md`](docs/00-index.md)** — start here. The specification split into 21 sections,
  a table of the invariants most likely to be violated, and where each is defined.
- [`docs/06-architecture.md`](docs/06-architecture.md) — the control-plane / data-plane split, the
  component map, and how the two services talk. This README does not restate it.
- [`docs/19-repo-structure-adrs.md`](docs/19-repo-structure-adrs.md) — ADR-001…030.
- [`docs/22-spec-findings-and-decisions.md`](docs/22-spec-findings-and-decisions.md) — why each
  decision went the way it did, what it ruled out, and what is still open.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — how to make a change here, including the ADR process.

**Working with AI agents?** [`CLAUDE.md`](CLAUDE.md) is the agent entry point: it carries the ownership
table, the shared-directory rules, and the nine non-negotiables. It is written for agents and is not a
substitute for this file.

---

## Security and licence

Security issues go through GitHub's private vulnerability reporting — please do not open a public
issue. See [`SECURITY.md`](SECURITY.md) for supported versions, the triage timeline, what is in scope,
and where per-release SBOMs are published.

Licensed under the Apache License 2.0 ([`LICENSE`](LICENSE)), with third-party attributions in
[`NOTICE`](NOTICE). The reasoning, including why AGPL and BUSL were rejected, is ADR-019.

External LLM providers are separate services operated by third parties under their own terms. This
project calls them with credentials you supply; it neither includes nor resells them.

**What runs locally and what does not.** Parsing and OCR are local: Docling's layout and table models
and RapidOCR's ONNX weights read your files inside your own infrastructure and send nothing anywhere.
**Embedding and reranking are provider API calls** ([ADR-030](docs/19-repo-structure-adrs.md)) — which
means chunk text from your documents is sent to the embedding provider each organization configures,
under that organization's own credential. There is no GPU requirement and no model server to operate;
the trade is that ingestion has an outbound dependency and a per-document cost. An air-gapped
deployment is not currently a supported shape, and that is the stated condition for revisiting the
decision.
