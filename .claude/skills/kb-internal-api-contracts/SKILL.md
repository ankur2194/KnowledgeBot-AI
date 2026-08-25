---
name: kb-internal-api-contracts
description: The Laravel↔FastAPI seam — the signed internal transport, the metadata every internal request carries, the sync/async split, and the normalized SSE event schema clients receive. Use whenever adding or changing an internal endpoint, a streaming event, a job callback, or an idempotency key in services/core-api/ or services/ai-service/. Browser, widget, and mobile clients never call FastAPI; this skill owns that boundary. Pairs with kb-error-taxonomy (the classes it carries) and fastapi-service (routing).
---

# Internal API Contracts — Laravel ↔ FastAPI

Laravel control plane (`services/core-api`) ↔ FastAPI AI data plane (`services/ai-service`), HTTP/1.1 over the private `application` Docker network. Internal contract version `v1`, path prefix `/internal/v1`. The spec pins no framework versions; the runtime skills do — Laravel **13.x** (`laravel-control-plane`) and FastAPI **0.141.1** / Starlette **1.3.1** (`fastapi-service`). This contract is deliberately version-independent of both: it is a wire shape, and a framework upgrade that changes it is a breaking change to `v1`.
**Authoritative spec:** docs/06-architecture.md §11, docs/12-api-areas.md §17, docs/14-reliability.md §19.5, docs/17-testing-performance.md §22.2, docs/08-ingestion-pipeline.md §13.3

## Non-negotiables

- **No client ever reaches FastAPI.** Next.js, the Preact widget, and the React Native app speak only to Laravel over HTTPS (docs/06 §11.1). That single rule is what buys centralized authentication, centralized authorization, one rate-limit surface, provider credentials that never leave the server, a hidden internal topology, and versionable public APIs. `ai-api` joins `application` and `data` only — never `edge`, never a Traefik router label, never a host `ports:` mapping.
- **Every internal request carries the full metadata header set below.** A request without `X-KB-Org-Id` is rejected at the FastAPI dependency — `error_class: validation`, which renders **`422`** — never defaulted and never inferred from the body, because FastAPI cannot build a tenant-safe Qdrant filter without it (`kb-tenancy-isolation`). **The status is not a free choice on this seam:** every refusal is an `ErrorClass` from `kb-error-taxonomy` and the status is whatever that class renders. There is **no `400` row in the taxonomy at all**, so a contract that specifies one specifies a status no correct implementation can produce.
- **Contracts are versioned from day one.** `/internal/v1/...`, mirrored by `X-KB-Contract-Version`. A shipped shape is never silently changed; add a field or add `/internal/v2`. Public APIs are versioned independently (docs/12 §17) — the two version numbers move at different rates and must not be conflated.
- **Provider credentials cross this wire as a top-level field beside `config`, never inside the configuration snapshot.** Decision 1 in docs/22 is resolved as ADR-011: Laravel decrypts per request and attaches the key beside `config`, not within it, typed `SecretStr` on the FastAPI side (`pydantic-contracts`, `laravel-control-plane`). Credentials are excluded from the snapshot hash and from every idempotency fingerprint, and they are on the never-forward list below — never an SSE frame, an error envelope, a span attribute, a log field, or an audit detail (CLAUDE.md non-negotiable 9). **The field is a keyed map, `provider_credentials: {connection_id: SecretStr}`, ruled on 2026-08-12 and specified below — one turn can need up to three keys.** ADR-011's three properties are unchanged by that; only the cardinality is. The trust-boundary argument that permits sending a key at all, and the condition that reopens it, belong to `kb-security-baseline`.
- **Every mutation carries an idempotency key, and a legitimate reprocess must survive it.** For most operations that means the configuration version is in the fingerprint (docs/08 §13.3). **Ingestion is the exception and it is not a relaxation:** the sender cannot know a configuration version, so the transport key carries a force nonce instead and the config-change case is caught one layer down by the data plane's `ingest_key` — *Two keys, not one*, below. A key derived from content alone, with neither, silently swallows the reprocess.
- **Error classes and retry eligibility cross the wire verbatim.** Laravel relays the class FastAPI assigned; it never re-derives one from an HTTP status. `kb-error-taxonomy` defines the classes and the retry policy.
- **HTTP contracts are OpenAPI documents in `packages/contracts/`, and both sides are covered by contract tests** (docs/17 §22.2). The FastAPI-generated schema is exported into that package; Laravel's client is validated against it in CI.

## How we use it

### Boundaries — named, not redefined here

- Provider request/response translation → `kb-provider-adapter-contract`.
- Error classes, retry policy, circuit breakers → `kb-error-taxonomy` (we carry them across the wire; we do not define them).
- Tenant filtering and scoping → `kb-tenancy-isolation`.
- FastAPI routers, dependencies, Pydantic models → `fastapi-service`.
- Laravel controllers, HTTP client configuration, queue wiring → `laravel-control-plane`.

### Transport and service authentication

Plaintext HTTP on the private network; **HMAC-SHA256 signed requests**, not bare bearer tokens and not mTLS. The network already gives confidentiality-by-isolation; the signature gives per-request integrity and a replay window, at zero certificate-rotation cost. Both directions are signed — FastAPI's progress callbacks into Laravel use their own key id.

```
canonical = "KB1\n" + METHOD + "\n" + PATH + "\n" + X-KB-Timestamp + "\n" + sha256_hex(raw_body) + "\n"
          + "\n".join(f"{k}:{v}" for k, v in sorted(kb_headers))   # every X-KB-* header except the signature,
                                                                   # name lowercased, value stripped
X-KB-Signature = key_id + ":" + hex(hmac_sha256(secret[key_id], canonical))
```

**The `X-KB-*` headers are in the canonical string because `X-KB-Org-Id` is the tenant scope for the entire data plane.** A signature over method, path, timestamp and body alone leaves it forgeable: flip one header and a legitimately signed request executes against another organization, which defeats every downstream layer at once — `kb-tenancy-isolation` builds its filter from `ctx.org_id` precisely *because* it arrives signed. The verifier recomputes the set from **all `X-KB-*` headers actually present** rather than from a caller-supplied signed-headers list; a list is itself attacker-controlled, so anything omitted from it can be added or dropped freely. Adding, removing, or altering any `X-KB-*` header therefore breaks the signature by construction.

- Reject when `|now − timestamp| > 60s`, and reject a repeated `X-KB-Request-Id` seen inside `120s` (Valkey `SET NX EX 120`). Skew tolerance and replay TTL must be set together — a wide window with no nonce is not a replay defence.
- Compare with `hash_equals()` / `hmac.compare_digest()`. Never `===`/`==` — a byte-wise compare leaks the signature one byte at a time.
- Method and path are in the canonical string on purpose: a body-only signature is replayable against a *different* endpoint that accepts the same body.
- Sign the **exact bytes sent**. Serialize once into a string, hash that string, send that string. Re-encoding JSON to hash it is not byte-stable and produces intermittent 401s.
- Request bodies are always fully buffered and small — only *responses* stream — so body signing never conflicts with SSE. HMAC gives integrity and authenticity, not confidentiality; that comes from the network being private. If the seam ever spans hosts, add TLS underneath rather than changing the signing scheme.
- Two key ids are live at once during rotation; verifiers accept both, signers use the newer. `key_id` in the signature is what makes that possible.

**The prefix is `KB1` and it stays `KB1`.** Spec defect 13 rewrote the canonical string to cover every `X-KB-*` header before any signer had ever been deployed, so `KB1` has never meant anything on the wire; that rewrite is **absorbed into `v1`** (docs/22 decision 8, resolved). A wire version describes *deployment* history, not authoring history — bumping to `KB2` for a shape nothing ever spoke turns the prefix into a changelog of drafts, which is the opposite of what it is for. From the first deployed release, the rule takes effect:

- Any change to the canonical string, to the covered header set, or to the hash function bumps the prefix.
- During a bump the **verifier accepts both prefixes for one release window** while the signer emits only the new one. Signer and verifier deploy at different times; that window is the only thing that keeps a rolling deploy from 401-ing every internal call.
- The accepted-prefix set is **configuration, not a constant**, and dropping the old prefix is a deliberate second deploy — never part of the first.

### Required internal request headers

| Header | When | Value and rule |
|---|---|---|
| `traceparent` | always | W3C Trace Context, injected by the OTel SDK. One trace spans client → Laravel → FastAPI → provider (docs/15 §20.1). |
| `X-KB-Request-Id` | always | ULID, one per inbound client request. Doubles as the replay nonce. Logged by both services (docs/15 §20.3). |
| `X-KB-Org-Id` | always | Organization ULID. Absent → `validation` → **`422`**. Never defaulted, never read from the body. |
| `X-KB-Bot-Id` | bot-scoped ops | Chat, retrieval diagnostics, evaluation. **Absent on ingestion, crawl and source deletion** — see below — and on `provider.test` and health. |
| `X-KB-Actor-Id` | human-initiated | Admin user ULID. Absent for anonymous widget sessions and scheduler-initiated work. |
| `X-KB-Actor-Type` | always | `user` \| `anonymous_session` \| `scheduler` \| `system`. Drives what the diagnostics contracts may return. |
| `X-KB-Operation` | always | `<group>.<op>` — `chat.execute`, `ingestion.submit`, `deletion.submit`. Metric labels, rate-limit buckets, and log fields key off this, not the URL path. |
| `X-KB-Config-Version` | always | **A hash of the resolved configuration snapshot sent in the body**, rendered as a decimal integer. **Not a counter and not ordered** — comparable for equality only. See below. |
| `X-KB-Idempotency-Key` | every mutation | See below. Absent on a mutation → `validation` → **`422`**. |
| `X-KB-Contract-Version` | always | `v1`. Must match the path prefix; a mismatch is refused, never a best-effort guess. **The status is owed a taxonomy row — see below.** |
| `X-KB-Deadline` | always | **Absolute** Unix epoch milliseconds at which the caller stops caring, not a duration. Set by Laravel from its own remaining budget. FastAPI checks the remainder before each provider attempt and never constructs a fresh duration downstream. Timeout budgets and the nesting arithmetic: `kb-error-taxonomy`. |
| `X-KB-Timestamp`, `X-KB-Signature` | always | Above. `X-KB-Signature` is redacted from every log line. |

**A knowledge source is organization-owned, so ingestion carries no bot id** (ADR-067). The row above used to list ingestion, crawl and deletion among the bot-scoped operations, and that was wrong in the direction that stops correct code: a source is assigned to **zero or many** bots, and at first upload it is assigned to none, so there is no value to send. **A router written from the old row would `422` every submission from every tenant** — and because the header set is inside the canonical signing string, neither signer can be corrected on its own. `app/api/deps.py` and `InternalAiClient::submitIngestion()` already agree with each other and disagreed with this table.

The rule underneath it is worth stating separately, because it is a non-negotiable and not a header detail: **bot access is a query-time payload filter, never an index-time scope.** One indexed chunk is visible to whichever bots the source is assigned to *at the moment of the query*, so reassigning a bot must never require re-indexing — and a submission scoped to a bot at index time is that requirement, arriving silently.

**Statuses on this seam are rendered from `ErrorClass`, never chosen per endpoint.** `kb-error-taxonomy` has exactly 18 rows and `app/core/errors.py`'s `_STATUS` renders them; a status that is not in that table cannot be produced by a correct implementation, and a contract line demanding one fails a review for correct code. Two consequences here, both verified against `app/core/errors.py`:

- **There is no `400`.** A missing or malformed required header is `validation`, which renders `422`. Every place this document used to say `400` now says `422`.
- **There is no `409` either**, so the `X-KB-Contract-Version` mismatch rule and the concurrent-in-flight idempotency rule below are both **specified and not implementable today**. `app/api/deps.py`'s `request_context` stores `contract_version` and deliberately does not compare it, and its docstring says why: implementing the rule means adding a nineteenth class to a table Python, PHP **and** TypeScript each transcribe (now guarded by two parity tests), and raising `validation` instead would put a third spelling of one rule into the tree. `tests/unit/test_request_context_and_deadline.py` carries a tripwire — `test_the_taxonomy_still_has_no_row_that_renders_409` — that fails the day the row exists. **Until then, do not write a Definition-of-done line asserting a `409`**: the check would stop a review for code that is correct against the taxonomy. Both values also derive from the same Laravel configuration key today, so the mismatch is unreachable.

**Configuration snapshot vs version.** Laravel resolves bot settings, provider connection, model, pipeline parameters and pinned model versions, and sends the **whole snapshot in the request body**; the header carries only its version. FastAPI never queries Laravel's tables and never caches config across requests — the snapshot is the input, so a replayed job reproduces byte-identically and the playground can run a temporary override without mutating anything (docs/04 §8.24).

**The version is a hash, so it compares for equality and never for ordering — read this before writing a consumer.** `X-KB-Config-Version` is a digest of the snapshot bytes with the credential excluded, and the exclusion is the point: ADR-011 property 1 (docs/22) is that rotating a key must **not** move the version. The shipped derivation is `InternalAiClient::snapshotVersion()` — a 56-bit slice of `sha256(body)`, 56 bits and not 64 so the decimal always fits the ≤18-digit bound `app/api/deps.py` parses it against. Three consequences a consumer has to design around:

- **There is no "older" and no "newer".** Two values that differ tell you the configuration changed; they tell you nothing about which way. Anything wanting staleness — "refuse a job resolved against a snapshot older than the current one" — needs a **separate ordered field on the wire**, and adding one is a contract change on both planes, not an inference from this number.
- **Equality is enough for what actually consumes it.** `valkey-keyspaces` keys `cfg:{org}:{bot}:{config_version}` and the answer-cache fingerprint on this value, and a cache key only ever asks "same or not". This is also why a constant would be a defect rather than a placeholder: a value identical for every organization is a cache-key component that distinguishes nothing.
- **A collision is a stale cache hit, not a security failure.** Nothing authenticates on this number — `sha256(raw_body)` inside the canonical string does that — so truncation is a cache-identity trade, not a signature one.

Nothing on either plane reads the value today. `app/api/deps.py`'s `request_context` parses it, range-checks it and stores it on `RequestContext`; no code compares it. That is a recorded deferral (5B-S5), not an oversight — but the deferral was blocked on this definition, so a consumer is now writable against **equality semantics only**.

### Credentials on the wire — a keyed map, specified and not yet wired

**Credentials ride beside the snapshot, not in it.** They are excluded from whatever is hashed to produce `configuration_version` and from every idempotency fingerprint, because two requests that differ only in a rotated key are the *same configuration*: hash one in and every key rotation invalidates every cached answer and stops every replayed job reproducing byte-identically. Sending a key at all is safe only because `sha256(raw_body)` is inside the canonical string (integrity in transit) and the channel is the private `application` network with no public route — `kb-security-baseline` owns that argument, the two options it rejected, and the deployment change that reopens it.

**The shape is a map keyed by `connection_id`** (finding F12 in docs/22; ruled by Ankur on 2026-08-12):

```jsonc
{ …, "config": { … },
  "provider_credentials": {      // connection_id (ULID) -> the decrypted key for THAT connection
    "01J8A4…7QK": "sk-…",        // the chat connection named by config.connection
    "01J8B2…9XR": "sk-…"         // the embedding connection, when it is a different one
  } }                            // ids are elided; a key that is not a ULID is a 422, not an
                                 // ignored entry — see the rules below
```

**Why it stopped being singular.** It was `provider_credential: "sk-…"` — one field, one vendor, which is what a turn meant when ADR-011 was written. Post-ADR-030 a single chat turn can need **three** keys: the chat credential, the **embedding** credential to embed the question at stage 5, and the **rerank** credential. ADR-031 explicitly permits the embedding connection to be a *different* connection from the chat one — that is the entire content of the designation — and `EmbeddingRequest` / `RerankRequest` in `app/providers/contract.py` each already carry their own `provider_connection_id`, which is the contract saying so in its own types.

**Why the key is a `connection_id` and not a surface name.** It is the identity **both planes already compute**, so the wire change costs neither of them a line. `app/providers/embedding_selection.py` resolves to an `EmbeddingConnection` — a `connection_id` plus the `(provider, model)` pair that names the vector space — and holds **no** credential, with an import-time check that refuses any field whose name looks like a secret; `services/core-api/app/Services/Embedding/EmbeddingCandidate.php` holds the same line on the Laravel side and serializes `connection_id` as its first key. Nothing in either module changes when this shape lands. If something did, that would be the signal the selection had been designed as a FastAPI-internal value rather than a transmissible one.

Two shapes were rejected. **Per-surface siblings** (`provider_credential` / `embedding_credential` / `rerank_credential`) is smaller but does not generalize: the fourth surface is another wire change on both planes. **Keeping it singular and requiring every surface of a turn to resolve to one connection** is not a wire decision at all — it is a **product restriction that forbids exactly the configuration ADR-031 was written to support**, and an Anthropic-only organization could then not ingest at all. A third idea is ruled out for a different reason and stays ruled out: FastAPI must never read `provider_connections` from PostgreSQL to fill the gap (`kb-provider-adapter-contract`'s "resolves nothing from storage", ADR-012/ADR-033's ownership rule, and ADR-011's own rejected option (b)).

**Rules the map carries that a single field did not have to:**

- **Keys are ULIDs and the map is closed.** A non-ULID key is `validation` → `422`, not an ignored entry. A plain object has no `extra="forbid"` to protect it, so the key type is what keeps this from becoming a free-form bag.
- **It carries only the connections *this turn* needs** — the chat connection named by `config.connection`, plus the embedding and rerank connections when they differ. Never every connection the organization owns: that widens the blast radius of one request to the whole tenant for no benefit.
- **A surface whose `connection_id` is absent from the map is a refusal, not a fallback to the chat key.** Falling back would send one tenant's key to a vendor they did not choose for that surface, and would silently embed a question in the wrong vector space. Fail closed.
- **Rotating any one key must move nothing.** The whole map is excluded from the snapshot hash and from every idempotency fingerprint, so `X-KB-Config-Version` and `X-KB-Idempotency-Key` are unchanged when any single entry is replaced.
- **The never-forward rule now has to cover a map's *values*, not one field name** — see below. This is the part that silently loosens when a shape changes.

**Status: specified, not implemented, and nothing is blocked on it.** No code carries a second credential today, because the five provider wire adapters and the chat router are both explicitly out of scope, so the inbound chat model this section describes does not exist in any form yet. **Measure it, do not read it here.** This sentence used to cite `ls services/ai-service/app/contracts/internal/` returning `__init__.py` and `.gitkeep`, and that went false the day the ingestion document was exported into that directory — an absence-claim with nothing watching for the absence ending. The honest check is `grep -n 'ChatRequest\|provider_credentials' services/ai-service/app/contracts/internal/openapi.json`, which finds nothing while this remains true. **Revisit condition:** it stops being deferrable the first time a query path calls `embed()` against a connection that is not the chat connection.

### Idempotency keys

`X-KB-Idempotency-Key = sha256(org_id | operation | fingerprint)` where the fingerprint is operation-specific:

| Operation | Fingerprint components |
|---|---|
| `ingestion.submit` | source id, then each item's `(id, canonical_key, content_hash)` in ULID order, then the force nonce. **No configuration version — see *Two keys, not one* below.** Joined by `\x1f`, because `\|` is legal inside a canonical key |
| `crawl.submit` | source id, crawl run id, normalized root URL, include/exclude rule version |
| `deletion.submit` | source id, version id set, deletion mode |
| `evaluation.run` | dataset id, dataset revision, bot config version |
| `chat.message` / `usage.finalize` | conversation id + client message id / message id |
| `source.status.sync` | source id, target status (`ready` or `disabled`) |
| `bot.access.sync` | bot id, direction (`grant`/`revoke`), the source-id scope or the literal `all` |

#### Two keys, not one

**The `ingestion.submit` row above used to cite `docs/08` §13.3 for its components, and §13.3 is a list for a different key** (ADR-068). There are two, they dedupe different things, and following §13.3 here makes an OCR retune a silent no-op.

| | **transport idempotency key** | **ingest key** |
|---|---|---|
| Built by | `IngestionSubmission::fingerprint()` (Laravel) | `app/ingestion/identity.py`, `INGEST_KEY_PARTS` (FastAPI) |
| Dedupes | **delivery** — a retried HTTP request | **work identity** — a version that has already been produced |
| Holds the `*_cfg_version` values | **no** | **yes**, all of them |
| Lives in | a Valkey record, 24 h | `source_versions.ingest_key`, forever, under a unique index |

The split is forced rather than chosen: **the control plane structurally cannot know a configuration version.** `parser_cfg_version`, `ocr_cfg_version` and `chunker_cfg_version` are produced by the data plane *during parsing*, which is also why §13.3's list names a "source version id" that the key itself decides the existence of. A sender cannot fingerprint on values it will not learn until after the request it is fingerprinting.

So the guarantee §13.3 is after is real and is delivered one layer down: **a reprocess forced by a configuration change dedupes on `ingest_key`, which does carry the config versions, and therefore does not dedupe.** What the transport key adds on top is the `force_nonce` — without it a resubmission of unchanged content is byte-identical to the previous one and replays a stored response, which is exactly what an admin pressing *reprocess* did not ask for.

FastAPI stores `key → {method, path, body_hash, status, response}` in Valkey for 24h (Stripe's floor). Same key + same method/path/body hash → replay the stored response verbatim, including its status. Same key + **different** body → `validation` → `422`; key still in flight → the IETF `Idempotency-Key` draft §2.6–2.7 shape, `409` with `Retry-After` — **which no `ErrorClass` renders today**, so it is owed the same taxonomy row as the contract-version mismatch above and must not be asserted by a test until that row exists. Storing method and path is what stops a key minted for one endpoint being replayed against another.

### Synchronous vs asynchronous

| Contract group | Mode | Endpoint | Result path |
|---|---|---|---|
| Chat request + streaming events | sync, SSE | `POST /internal/v1/chat/stream` | streamed to Laravel, relayed to client |
| Provider configuration reference | sync | `POST /internal/v1/providers/test` | response body |
| Retrieval request + result | sync | `POST /internal/v1/retrieval/diagnose` | response body (playground/admin only) |
| Ingestion command + progress events | async | `POST /internal/v1/ingestion/jobs` | `202 {job_id}` + progress callbacks |
| Crawl command + page result | async | `POST /internal/v1/crawl/jobs` | `202 {job_id}` + page callbacks |
| Deletion command + verification result | async | `POST /internal/v1/deletion/jobs` | `202 {job_id}` + verification callback |
| Evaluation command + metric result | async | `POST /internal/v1/evaluation/runs` | `202 {run_id}` + metric callback |
| Source status → Qdrant payload | **sync** | `POST /internal/v1/maintenance/source-status` | response body: the verification counts |
| Bot access → Qdrant payload | **sync** | `POST /internal/v1/maintenance/bot-access` | response body: the verification counts |

**The two maintenance operations are synchronous on purpose, and it is the one place in this table
where that needs defending** (ADR-069). They mutate the retrieval index, so the obvious reading is
that they belong with the async rows. They do not, because **the response body IS the proof**:
`set_payload` returns the same acknowledgement whether it rewrote ten thousand points or none, and
`kb-deletion-and-verification`'s rule — a filtered count is the only evidence any of this happened —
means a `202` would hand the caller exactly the acknowledgement that proves nothing. The caller is a
Laravel queued job (`SyncSourceStatusJob`, `SyncBotAccessJob`), not a browser, so it can wait; what
it cannot do is compensate for a failure it was never told about. A report whose counts disagree is
therefore an **exception on both sides**, never a `passed: false` body.

Three things about their bodies that are easy to get wrong:

- **No `X-KB-Bot-Id`, on either route.** A knowledge source is organization-owned (ADR-067), and on
  `bot-access` the bot is the *subject* of the change rather than the scope of the request — it may
  already be deleted. It travels in the signed body. The `x-kb-*` header set is inside the canonical
  string, so a router written from the bot-scoped row of the table above would fail every signature.
- **`embedding_model_versions` is every DISTINCT `source_versions.embedding_model_version` of the
  source, not just the active version's.** One identity is one Qdrant collection, so a source
  re-indexed after an embedding-model change has points in two of them; addressing one leaves the
  other half answering, and the rewrite that missed it reports a passing count for the collection it
  did address. Laravel resolves the set because Laravel owns the table — the handler opens no
  database connection. An **empty** set is a refusal, not a no-op.
- **`source_ids: null` on `bot-access` means every point in the organization and is revoke-only.** It
  is the deleted-bot case, where the assignment rows are already gone. A grant with that scope is
  refused three times over — in the job's constructor, in `InternalAiClient`, and at the router.

Async jobs call back into `POST /internal/v1/callbacks/{group}` on Laravel — **Laravel never polls FastAPI**. Every callback carries `(job_id, sequence, stage, status)`; Laravel applies it under `WHERE sequence > progress_sequence` so a Celery retry cannot rewind the job. A nightly reconciliation sweep re-queries jobs that have gone silent past their timeout; that is the only place a poll is legal.

### The public chat request body

The seam has two halves and both are pinned here. The **public** body is exactly `{client_message_id, content}` with `extra` rejected — why `content` and not `text`, why `client_message_id` is client-minted, and what the losing client sees → **[references/public-chat-request-body.md](references/public-chat-request-body.md)**. It is not an internal endpoint, but it is the input side of the same contract, and leaving it unowned is how two clients ship two different field names against one FormRequest.

### Client-facing SSE event schema

FastAPI emits normalized events; Laravel forwards an **approved subset** to the client. This is the contract every client codes against — widget, hosted chat, mobile, and playground all parse exactly this.

```
event: message.start      data: {"message_id":"01J…","conversation_id":"01J…","created_at":"2026-08-04T09:15:02Z"}
event: status             data: {"stage":"retrieving"|"reranking"|"generating"}
event: citations          data: {"citations":[{"index":1,"source_id":"01J…","source_version_id":"01J…",
                                  "chunk_id":"01J…","title":"Refund policy","url":null,"score":0.83}]}
event: token              data: {"text":"Refunds are "}
event: message.complete   data: {"message_id":"01J…","finish_reason":"stop"|"length"|"cancelled"
                                  |"insufficient_evidence"|"error","usage":{"prompt_tokens":1841,"completion_tokens":96}}
event: error              data: {"error_class":"provider_rate_limit","message":"…","retryable":true}
: ping
```

- `citations` is emitted **before the first `token`** — citations are assigned from retrieved evidence pre-generation, never parsed out of model output (docs/07 §12.16–12.17).
- `token` carries text and nothing else; it is the only high-frequency event and every extra key is paid per token.
- `id:` is a per-stream sequence for gap detection only — **token streams are not resumable** (see gotchas). `: ping` is an SSE comment, not an event: it keeps proxies awake without firing `onmessage`.
- Exactly one terminal event per stream: `message.complete` or `error`. Never both, never neither.

**The non-streaming HTTP error envelope is the same shape**, so one parser serves both surfaces and one discriminator drives every retry decision:

```json
{"error_class": "provider_rate_limit", "message": "…", "retryable": true, "request_id": "01J…"}
```

`error_class` is the only field callers branch on — never the HTTP status, which varies by surface for one class (`kb-error-taxonomy`, the 403/404 footnote). `message` is operator-facing and safe to log but never rendered verbatim to an end user. `request_id` echoes `X-KB-Request-Id` so a failure is greppable across both services.

**`validation` is the one class that adds a field, and it adds it as a superset**, so a single parser still serves every error:

```json
{"error_class": "validation", "message": "…", "retryable": false, "request_id": "01J…",
 "errors": {"name": ["The name may not be greater than 120 characters."]}}
```

`errors` is `Record<string, string[]>` keyed by input field name, present **only** on `validation`, and absent everywhere else — never `null`, never `{}`. Without it a 422 cannot be rendered against the field that caused it, which is the difference between a form that explains itself and one where Save silently does nothing (`rhf-zod-forms`). A key that matches no rendered field must still surface somewhere; an error nobody can display is an infinite retry loop the user drives by hand.

**Internal-only events Laravel consumes and does not forward:** `provider.usage` (token counts and cost → `usage` table; it carries the provider-native `input_tokens` / `output_tokens` / `cached_tokens` triple, which is deliberately **not** the `prompt_tokens` / `completion_tokens` pair on the client-facing `message.complete` — cache hit ratios are cost data and stop at Laravel, so the two are separate models on the FastAPI side, `pydantic-contracts`), `provider.fallback` (a fallback model ran → analytics), `retrieval.trace` (candidate and reranker scores → returned only to the playground when `X-KB-Actor-Type=user` and the actor holds the diagnostics permission), `heartbeat`. Leaking any of these to a widget exposes internal topology and cost data.

**The never-forward list is fields, not just events.** A provider credential appears in exactly one place — the internal request body — and never in an SSE frame, an error envelope, an OpenAPI example, a span attribute, a log field, a job payload, or an audit detail, in either service. `X-KB-Signature` is likewise redacted from every log line. Both are excluded at the point of construction rather than filtered on the way out: a redaction filter that has to *recognise* a value has already had the value in a string, one `str()` away from a log.

**Since the credential became a map, that rule covers a map's *values*, and the difference is not cosmetic.** State it this way in every place credential handling is described, because it is exactly the kind of rule that loosens silently when a shape changes underneath it:

- **The keys are not secrets and must never become the reason the values get treated as if they were not.** A `connection_id` is a ULID Laravel already exposes; it may appear in a log field or a span attribute. Every **value** under it is a `SecretStr` and may not.
- **A check that names one field no longer checks anything.** `grep -n 'provider_credential'`-shaped redaction, a serializer exclusion listing a single key, or a test asserting one field is absent from an SSE capture all pass vacuously against a map that has three entries. The test walks the map and asserts **every value** is absent from the capture, the envelope, the exported spans, the log output and every persisted snapshot — with more than one entry in the fixture, so a check that happens to catch only the first fails.
- **One comprehension can unwrap the whole map.** With a single field, `get_secret_value()` appeared exactly once in the tree and was greppable. `{k: v.get_secret_value() for k, v in creds.items()}` — written to "make a lookup" — unwraps every key at once, and the resulting plain `dict[str, str]` has no masking left in its `repr()`, so a single `str()` of it in an exception message or a debug line prints all three. Unwrap at the one call site that makes the provider call, per credential, never into a container.

### Worked example — one streamed answer, including cancellation

The seven-step trace from widget POST to finalized usage row, and Laravel's relay loop with the cancellation path in full → **[references/streamed-answer-walkthrough.md](references/streamed-answer-walkthrough.md)**. Cancellation is a first-class outcome there, not an error: `finish_reason: "cancelled"`, usage finalized from the running tally, `user_cancellation` recorded (docs/14 §19.1).

## Gotchas

- **The stream hangs, then the whole answer lands at once.** Something buffered it. Each of these defeats streaming independently and none of them errors: a Traefik `buffering` middleware attached to the chat router (Traefik does not buffer unless you ask it to — so this is always something someone added); a `compress` middleware gzipping `text/event-stream` (gzip withholds bytes until it has a worthwhile block — exclude that content type); PHP `output_buffering`/`zlib.output_compression` left on; a missing per-event `ob_flush(); flush()`; a missing `X-Accel-Buffering: no` if any nginx sits in the path. `response()->eventStream()` fixes the last two for you and is the reason to prefer it. CI never catches any of this because test clients read the full body — assert *inter-event wall-clock gaps*, not just the final payload.
- **Usage is never finalized because the client vanished.** Three compounding traps. (1) A browser closing an `EventSource` sends nothing; PHP learns of it only when a *write fails*, so `connection_aborted()` stays `0` through an arbitrarily long silent wait — the heartbeat is what makes disconnect detectable at all, not just what keeps proxies awake. (2) Unless Laravel explicitly cancels the upstream request, FastAPI never sees `http.disconnect` and the provider keeps generating billable tokens nobody will read. Starlette only emits `http.disconnect` for a `StreamingResponse`, and polling `request.is_disconnected()` is flaky. Do **not** hand-roll the receive-channel watch: Starlette's `StreamingResponse` already runs that task group, and FastAPI's native SSE path adds the cancellation checkpoints and the 15 s heartbeat on top — reimplementing it duplicates the machinery and loses the checkpoints (`fastapi-service`). What this contract requires of you is to let the resulting `CancelledError` propagate into the provider client rather than swallowing it. (3) Do not make finalization depend on the provider's terminal usage event: streaming usage is opt-in or last-message-only depending on provider (`kb-provider-adapter-contract`), so a cut stream may never deliver it. Tally incrementally, commit in `finally`, key the write on `message_id` so the abort path and the normal path cannot double-write.
- **A retried ingestion submission creates a duplicate job — or worse, silently skips a needed one.** A reprocess triggered *by a config change* must not dedupe against the original job and return its stale `job_id`: no error, no new version, retrieval quality quietly regresses. **Check the right key.** This gotcha used to say "include every component in docs/08 §13.3", which is the *ingest* key's list and cannot be built by the sender; the transport key's protection is the **force nonce**, and the config-change case is caught by `ingest_key` in the data plane (*Two keys, not one*). Auditing the transport fingerprint for a `parser_cfg_version` finds a defect that is not there and misses the two that are: a missing force nonce, and an `INGEST_KEY_PARTS` that has fallen behind a newly added `*_cfg_version`. The mirror-image bug is a retry wrapper that mutates the body (a timestamp, a re-resolved config) while reusing the key — that must be `422`, never a merge, which is why the stored record keeps a body hash.
- **The internal API is reachable from the internet.** Three ways in, all one line each: `ai-api` added to the `edge` network "temporarily"; a `traefik.enable=true` default at the Compose level with no per-service `traefik.enable=false`; or `ports:` instead of `expose:` on `ai-api`, which publishes to the host and bypasses Docker networks entirely. Assert it in CI: an external `curl https://<public-host>/internal/v1/health` must not return 200, and `docker compose config` must show `ai-api` off `edge` (docs/18 §24.1, §24.3).
- **`Last-Event-ID` reconnection replays or double-bills.** `EventSource` auto-reconnects on *any* drop and resends the last `id:`. If Laravel treats that as "resume", it either re-runs the provider call (billed twice, different answer) or replays buffered tokens the user already saw. Decision: token streams are not resumable. On a reconnect carrying `Last-Event-ID`, Laravel returns the *persisted* state — `message.complete` when generation finished, otherwise an `error` event carrying **one of the 18 classes** (`kb-error-taxonomy`) — and the client closes the source and offers Retry. A server that can still write a terminal event knows *why* the stream ended and names that cause; `stream_lost` is **not** a wire class and must never appear in an `error_class` field. It is a **client-local sentinel** for the one case the server cannot report — no terminal event arrived at all, because the connection itself died. Clients may raise it internally; nothing serializes it, nothing metrics it, and it does not make the taxonomy 19. Send `retry: 10000` on stream open so a mass drop does not reconnect-storm at the browser's 3s default.
- **Long silences kill the connection before the first token.** Retrieval + rerank runs seconds before generation starts (docs/17 §23 targets ≤1.5s retrieval, ≤4s first token) and idle timers sit lower than people assume — though **not the one usually blamed.** Traefik's `respondingTimeouts.idleTimeout` is the maximum a *keep-alive* connection stays idle **between** requests; an in-flight SSE response is not idle, so a long silence mid-stream is not cut by it (verified against the pinned v3.7 release by `traefik-routing`, which owns the edge settings that genuinely do cut a stream — `writeTimeout` and `lifeCycle.graceTimeOut`). What the heartbeat actually defends against here is nearer and more certain: PHP learns the client is gone only on a failed write, so with no writes there is nothing to fail (`laravel-control-plane`); and a self-hoster's own proxy — nginx defaults `proxy_read_timeout`/`fastcgi_read_timeout` to 60s — is a hop we do not control. Emit `: ping` every 15s from **both** FastAPI and Laravel — Laravel must generate its own, because the silent window can sit inside Laravel's validation and quota checks before a single upstream byte exists. 15s is chosen to stay well under the tightest hop, not to match any one of them.
- **Six tabs and the seventh request hangs.** Over HTTP/1.1 the browser allows ~6 connections per origin, and every open SSE stream holds one — a user with several hosted-chat tabs blocks ordinary XHR on the same origin, which reads exactly like a backend stall. TLS through Traefik negotiates HTTP/2 and the limit disappears; plain-HTTP local development does not, so reproduce this in dev before blaming the API.
- **Out-of-order progress callbacks rewind a job.** A Celery retry re-emits stage 6 after stage 9 has landed. Without the `sequence` guard the admin progress bar runs backwards and, far worse, a `ready` source can be flipped back to `processing`, taking an already-published version out of retrieval. Guard on `(job_id, sequence)` in the `UPDATE`, and treat callbacks as idempotent.
- **`Http::fake()` makes SSE bugs invisible.** A faked response body is a string, so the relay loop drains it instantly and every buffering, heartbeat, ordering, and disconnect bug passes. The Laravel↔FastAPI contract test must run against a real SSE fixture server that emits events with delays and can hang up mid-stream; the FastAPI side asserts its emitted event names and payloads against the same OpenAPI schema Laravel's client is generated from (docs/17 §22.2).
- **One client can never send a message and the other three are fine.** The public request body was never pinned, so each client picked its own name for the message text — `text` in one, `content` in another — and Laravel's FormRequest can only accept one. The loser 422s on *every* send while the shared suite stays green, because the fixtures were written per client from the same source of truth the client was. Pin the body here, generate every client's type from it, and make the contract test post the canonical body against the real FormRequest rather than against a fixture.
- **A plaintext provider API key is sitting in a `retrieval_traces` row, in a column nobody classified as sensitive.** The credential was modelled as a member of the configuration snapshot instead of a sibling of it, and two things follow, both silent. It is hashed into `configuration_version`, so it becomes part of every cache key and of replay identity — rotate a key and every cached answer misses and every replayed job stops reproducing. And the snapshot is *designed* to be persisted and replayed, so every path that stores one — the playground request record, the retrieval trace, the queued job body — stores the key with it, into columns no redaction fixture covers and no operator greps. Top-level `provider_credentials`, `SecretStr` **values**, the whole map excluded from the hash. **The map shape adds a second-order version of the same bug:** a fixture carrying one credential, or a redaction rule naming one field, passes while the second and third entries persist — so fixture more than one, and assert on every value.
- **A `429` is not one thing.** Tenant quota, Laravel rate limit, and provider rate limit are all plausibly `429`, and their retry policies differ (never / after a window / after `Retry-After` with backoff). FastAPI must put the class in the body and Laravel must relay it unchanged — see `kb-error-taxonomy`.

## Official docs

- [WHATWG HTML — Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html) — wire format, `id`/`retry`/comment semantics, the reconnection algorithm. [MDN — Using server-sent events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events) — `EventSource` behaviour, `Last-Event-ID`.
- [Laravel — Streamed responses & event streams](https://laravel.com/docs/responses#event-streams) — `response()->stream()`, `eventStream()`, flushing. [Starlette — Requests](https://www.starlette.io/requests/) / [Responses](https://www.starlette.io/responses/) — `is_disconnected()`, `StreamingResponse`.
- [W3C Trace Context](https://www.w3.org/TR/trace-context/) — `traceparent` propagation across the seam.
- [IETF `Idempotency-Key` header draft](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/) and [Stripe idempotent requests](https://docs.stripe.com/api/idempotent_requests) — replay, conflict, and TTL semantics.
- [Traefik — EntryPoints & timeouts](https://doc.traefik.io/traefik/routing/entrypoints/), [Buffering](https://doc.traefik.io/traefik/middlewares/http/buffering/), [Compress](https://doc.traefik.io/traefik/middlewares/http/compress/) — the three settings that break streaming.
- [Compose file — networks](https://docs.docker.com/reference/compose-file/networks/) — `expose` vs `ports`, network membership. [OpenAPI 3.1](https://spec.openapis.org/oas/latest.html) — the publication format for internal contracts.

## Definition of done

- [ ] Endpoint lives under `/internal/v1/`, `X-KB-Contract-Version` is present and parsed (the *comparison* against the path prefix is deferred with its `409` — see the note under the header table; today `request_context` stores the value and does not compare it, deliberately), and the OpenAPI document in `packages/contracts/` is regenerated and committed with the Laravel client generated or validated from it.
- [ ] The public chat request body is `{client_message_id, content}` and nothing else: every client's type is generated from `packages/contracts`, `rg -n "client_message_id" apps/` shows no sibling key other than `content`, and a test posts each client's exact body against the real FormRequest.
- [ ] Contract tests exist on **both** sides — FastAPI asserts what it emits, Laravel asserts what it parses (docs/17 §22.2). All required headers are asserted present by a FastAPI dependency; missing `X-KB-Org-Id` returns `error_class: validation` rendered as **`422`** (test proves it, and asserts on the class rather than only the number — the status is the taxonomy's to render).
- [ ] Signature verified with a constant-time compare, timestamp skew ≤60s, `X-KB-Request-Id` replay-blocked in Valkey; a replayed request test returns `401`. The prefix is `KB1`, and the verifier reads its accepted-prefix set from configuration — a test proves it accepts a two-prefix set while the signer emits one.
- [ ] `provider_credentials` is a top-level body field — a map keyed by `connection_id`, never a member of `config` — excluded whole from the snapshot hash and from every idempotency fingerprint: a test rotates **one** entry and asserts `X-KB-Config-Version` and `X-KB-Idempotency-Key` are both unchanged. A second test greps a full SSE capture, the error envelope, the exported spans, the log output and any persisted snapshot for **every value** in a fixture holding **more than one** entry — a single-credential fixture cannot fail this check. A third asserts a surface whose `connection_id` is absent from the map is refused rather than falling back to the chat credential. **All three are written against the specified shape and none of it is implemented** (the chat router and the wire adapters are out of scope; the exported document under `app/contracts/internal/` describes the ingestion seam and carries no chat path), so do not treat their absence as a regression — they land with the router.
- [ ] Mutations require `X-KB-Idempotency-Key` whose fingerprint includes every configuration version listed for the operation; tests cover same-key/same-body replay and same-key/different-body `422`. The concurrent-in-flight case is **deferred, not skipped**: it wants a `409` and no `ErrorClass` renders one, so it lands with the taxonomy row, not before it.
- [ ] Async jobs return `202` with an id and report via signed callbacks; no polling loop added; callbacks guarded on `(job_id, sequence)`.
- [ ] For streaming changes: an integration test asserts inter-event timing (not just the final body), heartbeat presence, exactly one terminal event, and correct finalization after a mid-stream client hangup.
- [ ] No internal-only event (`provider.usage`, `retrieval.trace`, `provider.fallback`) is forwarded to a non-admin client; a test asserts the widget stream's event-name allow-list.
- [ ] `docker compose config` shows the service off the `edge` network with no Traefik router labels and no host `ports:`.
