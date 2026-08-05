---
name: kb-architecture-map
description: Root architecture doctrine for KnowledgeBot AI — the Laravel control plane vs FastAPI data plane split, who owns which data, the monorepo layout, and ADR-001…010. Use whenever deciding which service or top-level directory a change belongs in, adding a Compose service, network, or Traefik route, or when one component needs data another owns. Defines boundaries only — never tenant filters, wire formats, retrieval stages, or error classes. Pairs with kb-internal-api-contracts (how the planes actually talk).
---

# KnowledgeBot AI — Architecture Map

Laravel (control plane) · FastAPI (AI data plane) · PostgreSQL · Qdrant · Valkey · SeaweedFS · Traefik · Docker Compose.
**Authoritative spec:** docs/06-architecture.md §10–11, docs/05-tech-stack.md §9, docs/19-repo-structure-adrs.md §27–28, docs/12-api-areas.md §17

## Non-negotiables

- **Browsers, the widget, and mobile talk to Laravel. Only Laravel talks to FastAPI.** (§11.1) Authentication, org resolution, bot authorization, quota, and rate limiting exist exactly once, in Laravel. The moment a second caller can reach `ai-api`, every one of those checks has a bypass, and provider credentials plus internal topology are on the public side of the boundary. The internal AI API has no Traefik router and no published host port — not in dev, not behind a "temporary" override (§17.5).
- **PostgreSQL is truth; Qdrant is a derived index that must survive being dropped.** (§10.3, ADR-010, §15.5) Nothing may live in a Qdrant payload that cannot be recomputed from `chunks`, `source_versions`, `bots`, and the normalized artifacts in SeaweedFS. If a rebuild changes what the bot answers, Qdrant had become authoritative and the ADR is already broken.
- **Laravel decides, FastAPI executes.** FastAPI never re-derives *who is asking* or *whether they may*. It receives an authorized, resolved instruction — org, bot, source scope, config snapshot — and performs work. It does not open a session, consult `organization_users`, or fall back to "look up the bot and check its status."
- **Valkey and SeaweedFS hold nothing authoritative.** Valkey is queues, locks, rate-limit counters, idempotency keys, and short-lived session/cache data (§9.8) — a `FLUSHALL` must cost throughput, never data. SeaweedFS holds originals and derived artifacts behind an S3-compatible abstraction so it is replaceable without touching business logic (§9.9).
- **One responsibility, one top-level area.** A change lands in exactly one of `apps/`, `services/`, `packages/`, `infrastructure/`. If it must land in two, the shared part is a versioned contract in `packages/contracts` — not a copied type, not a duplicated constant.

## How we use it

### Component map

```
 browser ─┐
 widget  ─┼─ HTTPS ─▶ ┌── PUBLIC EDGE — traefik, ports 80/443 only ──────────┐
 mobile  ─┘           │  web (Next.js)     sdk (static widget + iframe)      │
                      │  laravel-api  ◀── the only door that leads inward    │
                      └──────────────────────────┬───────────────────────────┘
                                                 │ signed internal HTTP (sync)
   ══════ TRUST BOUNDARY ═════════════════════   │ + Valkey job handoff (async)
                      ┌── PRIVATE — no host ports, no Traefik router ────────┐
                      │  ai-api (FastAPI)   laravel-worker  laravel-scheduler│
                      │  ai-worker-{ingestion,crawl,embedding,evaluation}    │
                      └──┬──────────────┬──────────────┬──────────────┬──────┘
                         ▼              ▼              ▼              ▼
                    postgres         qdrant         valkey        seaweedfs
                   SOURCE OF       DERIVED,        EPHEMERAL      BLOBS +
                     TRUTH        REBUILDABLE      (droppable)   ARTIFACTS
```

### Who owns what

| Concern | Owner | Store | Notes |
|---|---|---|---|
| Users, orgs, roles, invitations | Laravel | PostgreSQL | §9.4 |
| Bots, appearance, allowed origins, retrieval config | Laravel | PostgreSQL | FastAPI receives a snapshot, never edits |
| Provider connections + **encrypted credentials** | Laravel | PostgreSQL | Never in a response, log, or audit detail (§18.2) |
| Knowledge-source metadata, versions, lifecycle state | Laravel | PostgreSQL | The 15-state machine is control-plane state (§8.9 enumerates 15; `kb-source-lifecycle` owns it) |
| Conversations, messages, usage, audit, quotas | Laravel | PostgreSQL | Written on the Laravel side of the stream |
| Public + admin APIs, session issuance, rate limits | Laravel | — | §17.1–17.4 |
| Parsing, OCR, chunking, embedding, sparse vectors | FastAPI | → PG `chunks` + SeaweedFS | §10.3 |
| Crawling and page fetch | FastAPI (`ai-worker-crawl`) | → PG + SeaweedFS | Egress-restricted (§18.8) |
| Retrieval, fusion, rerank, context + prompt build | FastAPI | reads Qdrant + PG | §10.3 |
| Provider calls and stream normalization | FastAPI | — | Laravel forwards approved events (§11.3) |
| Vector upsert, index verification, index deletion | FastAPI | Qdrant | §10.3, §15.5 |
| Evaluation execution | FastAPI | → PG `evaluation_results` | §10.3 |

Rule of thumb for a new capability: **if it answers "may this happen?" it is Laravel; if it answers "what is the content?" it is FastAPI.**

<!-- UNVERIFIED --> The spec never says whether FastAPI writes `chunks`/`document_elements` to PostgreSQL directly or reports them back through the ingestion status callback (§17.5). Until an ADR settles it, treat direct writes by FastAPI as limited to the derived tables above — never to `bots`, `knowledge_sources`, `conversations`, or anything a Laravel policy guards.

### Monorepo layout (§27)

```
apps/web          Next.js admin + hosted chat   — an API client, not a second backend (§9.1)
apps/widget       Preact loader + iframe app    — ADR-007
apps/mobile       React Native / Expo
services/core-api Laravel control plane
services/ai-service FastAPI + RAG + workers
packages/contracts  shared schemas / generated clients — the only cross-boundary types
packages/design-tokens
infrastructure/docker         Compose, Traefik, local env
infrastructure/observability  dashboards, OTel config
docs · samples · scripts
```

### The boundary is enforced by Compose, not by discipline

```yaml
# infrastructure/docker/compose.yaml — the map above, as configuration
services:
  laravel-api:
    networks: [edge, application, data]   # the ONLY service on edge *and* application
    labels: ["traefik.enable=true", "traefik.http.routers.api.rule=Host(`api.${DOMAIN}`)"]

  web:                                    # Next.js is a client of Laravel, not a peer of ai-api
    networks: [edge]                      # deliberately NOT on `application` — see Gotcha 1
    labels: ["traefik.enable=true"]

  ai-api:
    networks: [application, data]
    labels: ["traefik.enable=false"]      # no router exists; there is no URL to guess
    # NO `ports:` key here or in ANY override file, ever — see Gotcha 2
    healthcheck: { test: ["CMD", "curl", "-fsS", "http://localhost:8000/health/ready"] }

  ai-worker-crawl:
    networks: [application]               # NOT `data` — this is the one worker that fetches
                                          # attacker-chosen URLs from inside the private
                                          # network, so it is the SSRF pivot (Gotcha 3), and
                                          # Qdrant's REST API needs no credentials: a single
                                          # coerced GET/DELETE against http://qdrant:6333
                                          # would be unauthenticated index destruction.
                                          # It reaches the broker (valkey-core joins
                                          # `application` for exactly this) and hands results
                                          # to `ai-api`; it never speaks to PostgreSQL,
                                          # Qdrant or object storage directly.
                                          # + egress allow-list denying private ranges.
```

§24.3 sketches `web` as a member of `application`. We narrow that deliberately: with `web` on that network, a Next.js route handler can reach `ai-api` and the "clients never call FastAPI" invariant becomes a convention instead of a fact.

### Keeping Qdrant genuinely derived

Four mechanics, not aspirations. Together they are what ADR-010 actually costs.

- **Application code never names a concrete collection** — always an alias. Alias updates in Qdrant are atomic (*"no concurrent requests will be affected during the switch"*), so build the new collection in the background and swap in one `update_aliases` call. Retrofitting the alias later is itself an outage.
- **The payload is a projection, not a record.** Build it through one serializer derived from primary rows; ban ad-hoc `set_payload` outside it. If a field can't be produced from PostgreSQL, it can't enter the payload.
- **Between rebuilds, sync through a transactional outbox, not dual writes.** No transaction spans PostgreSQL and Qdrant, so a dual write that half-fails is silent until a user searches. Writing the chunk row and its index event in one PG transaction turns a correctness problem into a lag problem you can alert on.
- **Rebuild is a CI check, not a recovery script.** A seeded fixture rebuilt from PostgreSQL must reproduce the same point count, payload keys, and payload values. Snapshot restore is the RTO tool (§25.3 option 1); rebuild is the correctness proof (option 2). Only the second one is a test.

### Owned elsewhere — cite, do not restate

- Tenant scoping of queries, filters, storage paths, and cache keys → `kb-tenancy-isolation`.
- Wire format, signing, headers, and versioning of Laravel↔FastAPI calls → `kb-internal-api-contracts`.
- The retrieval pipeline stages and their ordering → `kb-rag-query-contract`.
- Error classes, retry eligibility, and degradation rules → `kb-error-taxonomy`.

### ADRs (§28) and what each one costs

| ADR | Decision | The cost you inherit |
|---|---|---|
| 001 | Direct official provider APIs, no LiteLLM gateway | Per-provider implementation and maintenance; capability drift handled in adapters (spec-stated) |
| 002 | Laravel is the control plane | Business logic in PHP; anything AI-adjacent needs a network hop |
| 003 | FastAPI for AI and RAG | Two runtimes, two test stacks, one contract surface to keep versioned |
| 004 | PostgreSQL is the source of truth | Chunk text and normalized artifacts must be persisted, not only indexed |
| 005 | Qdrant, not pgvector alone | A second stateful system to back up, upgrade, and keep consistent |
| 006 | Hybrid retrieval + rerank | Reranker latency and memory on the critical path; a degradation policy is mandatory |
| 007 | Iframe-isolated widget | `postMessage` plumbing and origin checks instead of direct DOM access |
| 008 | SSE for streaming | Every proxy and buffer between client and provider must be stream-safe — Gotchas 8–10 |
| 009 | Compose before Kubernetes | Manual scaling; boundaries enforced by network membership, not by policy engines |
| 010 | Vector data is rebuildable | Rebuild must be exercised, not assumed — Gotchas 5–7 |

<!-- UNVERIFIED --> Trade-offs for ADR-002…010 are not stated in the spec; only ADR-001's is. The rest are this skill's assessment of the consequences, not spec text.

## Gotchas

1. **A widget call works in dev and 404s in prod, or admin analytics show requests with no org attribution and no audit row.** Something reached `ai-api` without going through Laravel — usually a Next.js route handler or a dev proxy pointed at `http://ai-api:8000`, which resolves because both containers share the `application` network. Prod has no Traefik router for `ai-api`, so the same code dies at the edge. The requests that *did* succeed skipped auth, quota, and `usage_events` entirely. Fix: keep `web` off `application`, and make one contract test assert that the public surface is reachable only via the Laravel host.

2. **A debug port added to `ai-api` is reachable from the internet even though `ufw` denies that port and `ufw status` looks correct.** Docker publishes ports by DNAT in the iptables `nat` table, and *"packets are diverted before [they reach] the `INPUT` and `OUTPUT` chains that ufw uses"* — the firewall rule is real and simply never consulted. Because the internal API is built to trust its caller, an open port is a complete authorization bypass, not an information leak. Fix: `ai-api` gets no `ports:` key in the base file or any override. Debug through `docker compose exec` or an SSH tunnel.

3. **A crawl of a customer-supplied sitemap ingests documents nobody uploaded, or returns a 200 from an internal hostname.** `ai-worker-crawl` runs *inside* the private network and fetches URLs an attacker chooses, so it is the one component that can be aimed at `http://ai-api:8000/internal/…`, at Qdrant's unauthenticated port, or at a cloud metadata endpoint — the Capital One shape exactly, where SSRF reached `169.254.169.254` and *"since the connectivity to the metadata service is local there is no additional authentication required."* "Only Laravel can reach FastAPI" is a statement about ingress; this worker breaks it from the inside. Fix: an **egress** allow-list on the crawl worker plus DNS and redirect re-validation and no private-range destinations (§18.8) — ingress isolation alone is half a boundary.

4. **The AI service authorizes a request that Laravel would have rejected, and the trace looks completely normal.** FastAPI trusted a caller-supplied org header because "only Laravel can reach us." That assumption fails three ways, all with precedent: an IP allow-list checked against the wrong address (Grafana's auth-proxy allow-listed the *client* IP instead of the proxy's, grafana#10707); a framework-internal header accepted from outside (CVE-2025-29927, one header skipped all Next.js middleware); and headers smuggled through a proxy that believed it had stripped them, via parser differentials. Fix: the tenant claim must be **verified** by FastAPI, not merely received — signed by Laravel, short-lived, with the org id inside the signed payload. Network position is a second control, never the first. Signature format and headers: `kb-internal-api-contracts`.

5. **A Qdrant rebuild finishes clean, chunk counts match, and the bot starts answering differently.** A payload key was written at index time that exists nowhere in PostgreSQL — a curated title, a boost weight, a language guess, a hand-fixed excerpt. Counts cannot detect it: every vector is present, only the ranking moved. Fix: every payload key maps to a named column in `chunks`/`source_versions`/`bots`, asserted by the rebuild job, and the rebuild runs in CI against a fixture (§25.3). Snapshot restore does not prove ADR-010 — it hides the violation, because it copies the drift forward.

6. **After a rebuild-and-swap, results are still correct but p95 latency jumps 20×; after a snapshot restore, every query fails with "collection not found."** Two different rebuild traps. Payload indexes are per-collection and are *not* created by uploading points, so the new collection filters by full scan instead of using the tenant index — correct, silent, and slow. And Qdrant snapshots contain a collection's points and config but **not its aliases**, so a restore leaves the alias unbound. Fix: creating payload indexes is a precondition of the alias swap, asserted before it; and the restore runbook recreates aliases as an explicit step.

7. **Chunk counts in PostgreSQL and point counts in Qdrant diverge for one tenant, and nobody notices until a customer says "that document isn't searchable."** Indexing was a second write after the PG commit, and no transaction spans the two systems — so a failed, retried, or reordered upsert leaves the index short, silently and asymmetrically (the DB write succeeded, which is the one people check). Fix: write the chunk row and its index event in **one PG transaction** and drain that outbox into Qdrant, so lag — a metric you can alert on — replaces silent loss; plus a scheduled per-tenant count/checksum reconciliation.

8. **Streaming works when you `curl` `ai-api` directly, but through the edge the browser sees nothing for 20 seconds and then the whole answer at once.** Something between FastAPI and the client is accumulating the SSE body: PHP's own output buffer (`output_buffering` defaults to 4096 bytes), `zlib.output_compression`, or the proxy's compressor. Fix: return `response()->eventStream(…)` or `response()->stream($fn, 200, ['X-Accel-Buffering' => 'no'])` — when the closure is a **Generator**, Laravel flushes between yields and disables Nginx buffering for you; a plain closure needs `ob_flush(); flush();` after every event. Add `text/event-stream` to Traefik's Compress `excludedContentTypes`, and set a small `responseForwarding.flushInterval` on that service. Never open an `ob_start()` inside the callback — a nested buffer swallows every flush, which is how a debug bar or profiler middleware silently converts a stream into one blob. Test through the full edge; this bug is invisible in the one place people test it.

9. **A long stream starves the pool: one PHP-FPM child is pinned for the whole generation, and enough concurrent chats exhaust `pm.max_children` while the app looks idle.** Streaming is the one place where request duration is measured in minutes, so the stream routes need their own FPM pool sized independently of the admin API — a shared pool means a burst of chats takes the dashboard down with it. Note that the widely-repeated *session-lock* version of this warning ("one tab blocks the others, fix with `Session::save()`") is **cargo on this stack**: it describes native PHP sessions, and Laravel does not use them — its handlers take no request-long lock. The rule that does survive is keep session middleware off the token-authenticated stream routes entirely (`laravel-control-plane`). Related, and worth checking before committing to SSE-through-Laravel: Laravel Octane's RoadRunner handler buffers streamed output regardless of `flush()` (open issue laravel/octane#903). Verify streaming on your chosen runtime *first* — this is the one credible technical threat to the "clients only talk to Laravel" rule.

10. **A user closes the tab, the provider bill keeps rising, and `provider_calls` never gets a row — so the tenant is never charged.** Two independent mechanisms. PHP notices a dropped client only *"the next time your script tries to output something,"* so a loop blocked reading the FastAPI stream learns nothing; and the provider's token `usage` block arrives in the **final** chunk, so a stream that dies before it logs zero for tokens that were generated and billed. Fix: meter incrementally in FastAPI and persist the partial count from a `CancelledError`/`finally` handler; treat cancellation as a normal terminal state (§19.1). Do not rely on TCP close propagating through PHP → Starlette → provider — give each stream an id and an explicit control-plane cancel call, and put a hard deadline on the FastAPI side. <!-- UNVERIFIED --> End-to-end propagation of a PHP client abort into cancellation of the upstream provider stream is untested here; assume it does not work until proven.

11. **`php artisan cache:clear` makes queued ingestion jobs disappear, or Celery workers crash on payloads they cannot decode.** Laravel queues and the Celery broker were pointed at the same Valkey logical database, so their keyspaces overlap and a cache flush takes the other system's queue with it. Fix: separate logical DBs (or instances) and distinct key prefixes per workload, as §9.8 requires — decided at Compose time, because by the time you notice, the jobs are gone.

12. **A "shared" type is edited in `services/core-api` and the TypeScript client silently keeps the old shape.** Cross-boundary types copied into two services drift, and nothing fails until a field reads as `undefined` in production. Fix: the shape lives in `packages/contracts`, both sides generate from it, and CI's contract-test step (§26 step 4) fails the build on a mismatch.

## Official docs

- [Docker packet filtering and firewalls](https://docs.docker.com/engine/network/packet-filtering-firewalls/) — the `nat`-table diversion that makes host firewalls irrelevant to published ports (Gotcha 2).
- [Docker port publishing](https://docs.docker.com/engine/network/port-publishing/) — why publishing is insecure by default; `expose:` vs `ports:`; loopback binding.
- [Docker Compose networking](https://docs.docker.com/compose/how-tos/networking/) — service-name DNS; network membership *is* reachability.
- [Traefik Docker provider](https://doc.traefik.io/traefik/providers/docker/) and [Compress middleware](https://doc.traefik.io/traefik/reference/routing-configuration/http/middlewares/compress/) — per-router opt-in; `excludedContentTypes`.
- [Qdrant collections and aliases](https://qdrant.tech/documentation/concepts/collections/) — atomic alias switch for rebuild and re-embed cutover (§15.4–15.5).
- [Qdrant snapshots](https://qdrant.tech/documentation/concepts/snapshots/) — restore path, version locking, and the alias exclusion in Gotcha 6.
- [Qdrant multitenancy](https://qdrant.tech/documentation/guides/multiple-partitions/) — tenant payload indexes; the swap precondition in Gotcha 6.
- [Laravel streamed responses](https://laravel.com/docs/12.x/responses#streamed-responses) — `eventStream`, generator auto-flush, `X-Accel-Buffering`.
- [PHP connection handling](https://www.php.net/manual/en/features.connection-handling.php) — abort detection requires an output attempt (Gotcha 10).
- [MDN: Server-Sent Events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events) — event framing and reconnection (ADR-008).

## Definition of done

- [ ] The change lives in exactly one of `apps/`, `services/`, `packages/`, `infrastructure/`; anything shared is in `packages/contracts`.
- [ ] `grep -rn "ai-api\|ai-service" apps/ infrastructure/docker/` shows no client, route handler, or dev proxy targeting the AI service.
- [ ] `docker compose config` shows no `ports:` and no `traefik.enable=true` on `ai-api` or any `ai-worker-*`, in base or any override.
- [ ] `docker compose config` shows `laravel-api` as the only service on both `edge` and `application`.
- [ ] Every new Qdrant payload key maps to a named PostgreSQL column, and the rebuild job asserts it.
- [ ] A rebuild-from-PostgreSQL of the touched sources reproduces the same point count, payload keys, and top-k for the evaluation dataset.
- [ ] No code names a concrete Qdrant collection; payload indexes exist on the new collection *before* any alias swap.
- [ ] Any new internal endpoint verifies the signed tenant claim itself — it does not infer authority from the caller's address.
- [ ] New authorization decisions were added in Laravel, not in FastAPI.
- [ ] Any invariant restated here is cited to its owning `kb-*` skill, not redefined.
