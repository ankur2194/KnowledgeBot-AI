# Spec Findings and Open Decisions

> **⚠ EVERY MENTION OF A CI GATE BELOW IS HISTORICAL.** `.github/` was deleted on 2026-08-17 and
> nothing replaced it — the record is § *Removing CI/CD* at the foot of this file. The gate references
> throughout were deliberately **not** rewritten: this document describes what was true when each
> finding was written, and erasing the gates from it would make it less accurate rather than more. So
> read "the gate asserts X" as "a gate asserted X until 2026-08-17, and nothing does now", and take
> `CONTRIBUTING.md` § *The invariants that used to be enforced, and now are not* as the live list.
>
> Not part of the original specification. This records defects, ambiguities, and decisions surfaced while authoring the skill library — the places where implementing `KnowledgeBot-AI.md` literally would produce a bug, and the places where it leaves a real choice open.
>
> Items marked **DECIDED** were resolved during authoring and should be ratified as ADRs. The eight
> questions the specification left open are resolved below as ADR-011…018; the ten that scaffolding
> forced are ADR-019…028; **ADR-029** closes finding O1, the one decision forced by code that was
> already written rather than by a file that had to exist. **ADR-030** is different again: a scoping
> decision taken by the product owner — no local model inference for embedding or reranking — which
> supersedes spec text rather than filling a gap in it, and left three consequences open as
> **[C1–C3](#open-after-adr-030--c1c3)**.
>
> **Those three are now largely closed, as [ADR-031…035](#the-decisions-adr-030s-consequences-forced--adr-031035)**
> — a fifth category: decisions nobody chose to take, forced because code that had to exist could not be
> written without choosing. Each C-finding keeps its heading, its number and its original text, with the
> resolution recorded above it and the parts that are **genuinely still open stated precisely** rather
> than swept up in the closure. **ADR-033 is the register's first supersession**: it replaces ADR-012's
> table list and nothing else in ADR-012.
>
> **This file has open items again.** Building the skeleton against the skills surfaced findings the
> reading passes could not: contradictions only visible when two skills are implemented by two agents,
> and CI gates that are unsatisfiable as written. They are collected in **[Open after
> scaffolding](#open-after-scaffolding)**, immediately below. The first of them, O1, was the one
> **blocking** item; it is now closed as ADR-029 and left in the list with its reasoning intact.
>
> **A sixth category was added on 2026-08-10: [the scope
> re-baseline](#the-scope-re-baseline--2026-08-10), R1–R8.** Every other finding here records
> something wrong in the *specification* or in the *code*. Those eight record documents that went
> false about the *repository* — the scaffolding phase's six Non-goals were all crossed and nobody
> redrew the line. Six of the eight are the same defect wearing different clothes: a **count** stated
> in prose, which decays on the next commit and decays silently.

## Open after scaffolding

Twelve implementation agents built the skeleton against the skill library. What follows is what that
produced which reading could not: places where two skills disagree and only an implementer standing
between them notices, CI gates that cannot pass on correct code, and questions that need a ruling
before a body of code is written against a guess.

Numbered **O1…O27**, a namespace of their own — the *Spec defects* list below is numbered 1–27 and has
already been renumbered once after an insertion in the wrong place. Nothing here renumbers anything;
**O22…O27 were appended, never inserted**, which is the only reason that is still true.

**A third open namespace exists and is not part of this one:
[C1–C3](#open-after-adr-030--c1c3)**, the consequences of ADR-030. They are C-numbered because the
data-plane code already cites "finding C2" by that name in six places. Do not renumber them into this
list. *(Those three are now largely closed as
[ADR-031…035](#the-decisions-adr-030s-consequences-forced--adr-031035); the namespace rule stands
regardless, and what remains open under each is stated in the table at the head of that section.)*

**None of these is closed by an ADR-019…028**, and exactly one — O1 — is closed by **ADR-029**. Where
an item was resolved in place during the build it is marked *(resolved — recorded so the reasoning
survives)* and left in the list, because the log is a history of what was not obvious at the time.

**O22…O27 came from a different instrument than O1…O21.** The first twenty-one were what building a
skeleton against the skills surfaced. These six are what **running the toolchain and building the
images for the first time** surfaced — resolving eight lockfiles, and putting `RUN` lines in front of a
real interpreter. Three of them (O25, O26, O27) are facts about a resolved dependency graph that could
not have been read off any manifest, and one (O22) is the observation that the assertions catching the
other kind run in no workflow at all. **Resolving a dependency graph is a distinct detector**, and it
found things fifty reading passes and one scaffolding pass did not.

### O1 — an unhandled internal bug renders differently in each plane *(RESOLVED — ADR-029)*

> **Resolved as [ADR-029](19-repo-structure-adrs.md#adr-029-internal_dependency-takes-an-origin-axis-the-taxonomy-stays-at-18).**
> Resolution **(a)**, scoped as an explicit sub-case rather than a 19th class — the recommendation
> below, taken. The argument, the rejected alternatives and the revisit condition are at
> [§ ADR-029](#adr-029--internal_dependency-takes-an-origin-axis-and-the-taxonomy-stays-at-18) further
> down this file.
>
> **The substantive conclusion, stated because "both were defensible" is not the finding:** the
> **FastAPI 503 was the side that was wrong.** Not because Laravel's handler was better reasoned — it
> was not; it reached 500 by way of a status comparison under a `TODO(taxonomy)` admitting the question
> was open — but because a 503 with `retryable: true` was *telling a client something false*. Nothing
> downstream had failed. The one test that separates two locally-defensible renderings is which of them
> makes a false statement to someone who has to act on it, and only one of these did.
>
> This is no longer blocking. Everything below is the record of what was not obvious at the time and is
> left unedited.

**Symptom, in the client's hands:** the same defect — an unmapped exception in our own code — returns
**500 with `retryable: false`** from Laravel and **503** from FastAPI. A client obeying the envelope
retries the FastAPI one, on a full backoff ladder, against a defect that cannot succeed on any attempt;
the Laravel one it reports immediately. Two planes, one failure, two contradictory instructions.

**Why it happened, and why neither side is simply wrong.** The taxonomy is **closed at 18 classes** and
has no class for *our own defect*. Both planes reached for the nearest neighbour, `internal_dependency`,
which the taxonomy documents at **503** — a status whose whole meaning is "come back shortly". Laravel's
render closure overrode the status to 500 and forced `retryable: false` to stop exactly that retry
storm; FastAPI's `_handle_unexpected` emitted the documented 503. Each is locally defensible and the
pair is incoherent.

**This must not be left split.** Two resolutions are available and both are real work:

- **(a) Grow the taxonomy a documented sub-case** — an `internal_error`-shaped 19th class, or an
  explicit `internal_dependency @ 500, retryable: false` sub-case for *self*-origin failures, keeping
  the 503 reading for a genuine downstream dependency. Costs: the class count is asserted in tests and
  quoted across at least five skills; the metric label allow-list and the taxonomy's Page column both
  move; and every adapter's mapping table gains a row.
- **(b) Both planes pick one rendering** — almost certainly 500 / `retryable: false`, since that is the
  honest instruction — and `internal_dependency`'s documented status changes, which makes every existing
  "503 means retry" statement about it wrong until edited.

**Recommendation, not a decision:** (a), scoped as an explicit sub-case rather than a 19th class, so the
count stays 18 and `provider_billing`'s footnote pattern is reused. **Not decided here** — this is
`kb-error-taxonomy`'s to own, and it needs an ADR that supersedes nothing but adds the sub-case, plus a
single edit landing in both planes' handlers in the same change. Until then, treat the FastAPI 503 as
the bug: it is the one telling a client something false.

**Owner:** the taxonomy. **Blocks:** any client-side retry predicate written against `internal_*`.

### O2 — payload index type: `keyword`, not `uuid` (skill contradiction, unresolved)

`qdrant-hybrid-search` shows `UuidIndexParams` on the five identifier fields. `kb-chunking-rules` types
every payload identifier as a 26-character ULID and carries, as its top gotcha, that a UUID-shaped index
satisfies no `must` term. A UUID index over ULID values matches **nothing**, and matching nothing is
indistinguishable from an empty corpus: HTTP 200, normal latency, zero candidates, no exception. This is
S1 wearing a different hat.

`retrieval-engineer` shipped `keyword`. Either the skill's index params are wrong or the identifier type
is; pick one, and edit the losing skill. Affects `ingestion-engineer` (upsert) and `deletion-engineer`
(delete-by-filter), both of which build filters against whatever the index actually is.

### O3 — the reranker's passage text has no named read path

ADR-010 keeps chunk body text out of the Qdrant payload, and `bge-reranker` must score the exact
embedded string, heading prefix included. That implies an org-scoped **read** of `chunks` from
PostgreSQL — but `app/db/writes.py` is described as the only SQL module in the data plane and its
allow-list is a *write* allow-list. There is no named read path anywhere.

Rule on it before an implementation invents one, because the invented one will not carry the org scope
by construction: a rerank fetch keyed only on chunk ids is a cross-tenant read that reviews as a
performance optimisation. Whatever is chosen needs the same allow-list treatment the writes got.

### O4 — model revision shas are unresolved, and the licence gate rejects the placeholder

`models.manifest.toml` carries `TODO-RESOLVE-SHA` for `bge-reranker-v2-m3`, and the same is true of the
Docling pipeline models and the RapidOCR weights. The licence gate rejects `main` by design (see
implementation trap *"Every Docling model spec pins `revision="main"`"*), so this blocks the **first
image build** — and separately blocks any threshold tuning, because `evidence.min_score` is a function
of the revision that produced the scores.

Not a decision: someone must resolve the shas and commit them.

### O5 — the deletion relational step has no seam endpoint

`kb-deletion-and-verification`'s `purge_source` sample removes rows from `source_versions`,
`source_items` and `bot_source_assignments`, and ADR-015's erasure sweep names `citations.excerpt`. All
four are Laravel's tables, so ADR-012's CI table allow-list fails that code exactly as the skill writes
it. `deletion-engineer` split it correctly — the data plane removes `chunks` and `document_elements`
only and routes the rest over the core-api seam — but **no endpoint for that seam exists in
`kb-internal-api-contracts`**.

It must be named before a body lands, or two agents will each invent one. Related: the same skill's
sample is now un-implementable as written and should say so.

### O6 — `references/delete-key-allow-list.md` contradicts CI, twice

1. It lists **five** permitted payload keys, with a singular `bot_id` and no `source_status` or
   `chunk_id`. CI, `kb-tenancy-isolation` and `github-actions-pipeline` all say **seven**, with
   `bot_ids` plural — see S16 for why seven and not six. The implementation follows CI.
2. That reference's own grep matches the bare words `text`, `content`, `title`, `heading` and `excerpt`
   anywhere under `app/deletion/` — including the sentence explaining why you must never match on text,
   and including `citations.excerpt`, which the same file lists as permitted.

Two documents specify two different gates and only the one in `workflow-jobs.md` is satisfiable. The
reference needs to quote the workflow rather than restate it; a second copy of a gate drifts, and the
drifting copy is always the one that ships.

### O7 — "the four checks" is five

§8.17 names chunks, vectors, assignments and cache. The artifact inventory separately requires a
`ListObjectVersions` over the object prefix — which A8 promoted from mechanism to *detector* and is the
only call that sees someone enabling bucket versioning later. Five are implemented. The spec, and every
skill quoting it, should stop calling it four.

### O8 — deletion has no metrics in the closed catalog

A grep for "deletion" across `kb-observability-conventions` returns zero rows: nothing for purge lag,
nothing for verification failure. So the one workflow whose correctness is *defined* as "verified
afterwards" has no series a dashboard or alert can read, and a verification job that stops running looks
identical to one that always passes.

The catalog is closed and owned, so this is a **metric-catalog PR first, then code** — names, labels
(`disposition`, not `outcome`, if the results are not the shared four), and the allow-list entry in the
same change.

### O9 — beat entry names are unreconciled

`celery-workers` declares beat owns **exactly six** entries and names a different six from the tasks
actually registered. `beat_schedule` ships empty, with the reason stated in
`app/worker/schedule.py`: an entry naming a task that does not exist crashes beat on boot. The count
test the skill mandates cannot be written until the six names and the six registered tasks are the same
six.

### O10 — `provider_billing` is mapped three different ways across three skills

- `openai-api` — prose and Definition of done say `insufficient_quota → provider_auth`; **its own table
  in the same file** says `provider_billing`.
- `anthropic-api` — `billing_error` → `provider_auth`.
- `deepseek-api` — 402 → `provider_permanent_request`.

All three predate the class existing, and the adapters as built map every one of them to
`PROVIDER_BILLING` per the taxonomy. Left as written, two of those skills would make an exhausted
account run the full backoff ladder and then silently fall back — the precise failure `provider_billing`
was created to prevent. Needs a `contract-steward` pass over the three files; the taxonomy is right.

### O11 — `stream()`'s shape is specified two ways, and the difference is cancellation

`fastapi-service` shows `async with st.provider.stream(...) as chunks`, and the `async with` is
load-bearing there: cancelling it closes the socket, which is what actually stops the provider
generating billable tokens. `kb-provider-adapter-contract` says `AsyncIterator`. The adapters implement
the contract.

Resolve before the SSE route is written. The two shapes differ in exactly the behaviour that matters —
an `AsyncIterator` abandoned mid-stream may leave the upstream request open, so a user who navigates
away keeps billing.

### O12 — `KbError` carries no `tokens_emitted`, and the skills call it with the wrong signature

Three provider skills pass `tokens_emitted` and the taxonomy's retry loop gates on it, but the base
`KbError` in `app/core/errors.py` has no such field. `providers/errors.py` adds
`ProviderCallFailed(KbError)` carrying `tokens_emitted`, `native_code` and `provider_request_id`. **If
the fallback router — which lives outside the providers package — needs the field, it belongs on the
base class instead**, and that is the ruling owed.

Separately and independently: the skills write `KbError("provider_temporary", detail=…)`; the real
signature is `(ErrorClass, message)`. Every copied snippet is a `TypeError` at the call site.

### O13 — DeepSeek's REASONING/SAMPLING exclusion breaks the `provider_models` data model

`capability_flags` must resolve per `(model, thinking_enabled)` — DeepSeek rejects sampling parameters
when thinking is on — but `provider_models` has one row per model, so there is nowhere to put the second
set. Reported rather than worked around, because a data-plane workaround would put a capability decision
outside the control plane that owns provider configuration.

A control-plane question: a second column, a per-mode row, or a computed override. Owner:
`control-plane-engineer` with `kb-provider-adapter-contract`.

### O14 — the crawler politeness key drops the org segment, deliberately

`crawl4ai-crawler` specifies `crawl:rl:{org_id}:{registrable_domain}`, which gives **every tenant its
own private "1 req/s" promise to the same host**. Ten tenants crawling the same site is 10 req/s from
one IP range, and we are the abusive client — the target sees one origin, not ten.

Implemented as `crawl:rl:{registrable_domain}`, with per-org concurrency in a separate limiter so a
single tenant still cannot monopolise the crawler. This is a real divergence from the skill and needs a
`crawl4ai-crawler` / `valkey-keyspaces` edit rather than standing silently.

### O15 — Crawl-delay arithmetic is ambiguous, and one reading is abusive

"Refilled at `min(configured_delay, robots Crawl-delay)`" read as *two delays* selects the **faster**
one, which lets a tenant configure 0.5 s past a site's declared 10 s. Read as a *refill rate* it selects
the slower. Implemented as `max()`, which is the polite reading. Wants a skill edit that states the
units, because the sentence is correct under one reading and a robots-violation under the other.

### O16 — five new Valkey key families are absent from the catalog

`crawl:frontier:*`, `crawl:seen:*`, `crawl:conc:host:*`, `crawl:conc:org:*`, `crawl:robots:*`.
`valkey-keyspaces` names only `crawl:rl:` today. The catalog is what makes an org-wide purge a
deterministic walk of known prefixes instead of a `SCAN MATCH` whose guarantee does not cover keys
written mid-iteration — so a family missing from it is a family that survives a tenant deletion.

### O17 — `ScopeMode.REGISTRABLE_DOMAIN` has no resolver

Correct eTLD+1 resolution needs a public-suffix source and no such package is in `pyproject.toml`. The
default is `HOST_AND_SUBDOMAINS`, which is correct without one, so this is a **dependency gap and not a
security hole** — the address check is the boundary, not the scope mode. Adding the dependency is a
`pyproject.toml` change plus a licence-gate pass.

### O18 — no lockfile can exist yet, and three Dockerfiles copy one *(RESOLVED)*

> **Resolved by running the toolchain.** Every lockfile now exists and is committed:
> `services/ai-service/uv.lock` (**292** packages at the time this was written; **264** as of this
> commit — the 28-package drop is ADR-030 removing `FlagEmbedding`, `sentence-transformers`,
> `transformers`, `peft`, `datasets`, `ir-datasets`, `scikit-learn`, `sentencepiece`, `pyarrow` and
> their transitive closure, and it is the cheapest available confirmation that the removal actually
> resolved rather than merely being edited out of `pyproject.toml`),
> `services/core-api/composer.lock` (**152** — 101 runtime, 51 dev, re-counted and unchanged), and
> **six** `pnpm-lock.yaml` files. `apps/mobile`'s two deliberate `"*"` versions
> are resolved; no `"*"` remains in that manifest. The Dockerfile comments that asserted the opposite
> were rewritten rather than deleted — `services/core-api/Dockerfile:263` now opens
> *"NEVER DROP `composer.lock` FROM THE `COPY` LINE ABOVE"* and keeps the half of the original
> paragraph that is still true: `composer install` without a lock is not a build failure but a
> **successful install of a graph nobody reviewed**, so two images built a week apart from one commit
> contain different code and the SBOM describes neither.
>
> **Six pnpm lockfiles, not the four this finding assumed.** That discrepancy is not cosmetic and is
> not closed here — it is [O26](#o26--six-pnpm-lockfiles-exist-where-the-contract-documents-four-and-the-gate-that-guards-the-count-fails-on-the-current-tree-gate-half-closed).
>
> Original text below, unedited.

`services/core-api/Dockerfile`'s vendor stage does `COPY composer.json composer.lock`, and the same
shape exists for `uv.lock` and the four `pnpm-lock.yaml` files. Someone must run `composer install` on
PHP 8.4, `uv lock`, and `pnpm install` before any image builds. Recorded because the failure is a
confusing one: BuildKit reports a missing COPY source, which reads as a broken Dockerfile rather than an
un-bootstrapped repository. `apps/mobile/package.json` additionally carries two deliberate `"*"`
versions that must be resolved by `npx expo install --check` before the first lockfile is committed.

### O19 — the OKLCH validation grammar in `tailwind-shadcn` rejects its own palette

The pinned regex requires a decimal in every component, so it does not match `oklch(0.205 0 0)` — the
exact shape of shadcn's neutral palette, integer chroma and integer hue. It **fails closed**, so it is
safe; what it does is silently discard legitimate tenant brand colours.

It was transcribed verbatim into `apps/web/src/lib/theme.ts` and flagged there, because it must equal
Laravel's validator **byte for byte** — a client and server that disagree about which colours are valid
produce a form that accepts a value the API rejects, or worse the reverse. Fixing it therefore requires
`admin-web-engineer` and `control-plane-engineer` to move in the same commit, which is why it is not
fixed.

### O20 — the client-facing SSE `error` frame carries no `request_id`

The HTTP error envelope has one. The streamed `error` event's documented payload is
`{error_class, message, retryable}`. So a failure *mid-stream* — which is where provider failures
actually land — leaves the user holding a class-mapped sentence and nothing support can grep, while the
identical failure before first byte is fully traceable.

Modelled as optional on `KbErrorEnvelope` pending a ruling. Two options: the SSE frame gains the field
(a contract change in `kb-internal-api-contracts`, and Laravel forwards frames verbatim so it costs
nothing downstream), or every chat UI captures the request id from the response headers at stream open
and holds it for the life of the stream (three clients, three chances to forget). The first is one
change in one place.

### O21 — the five pinned browser OTel packages cannot register instrumentations *(RESOLVED)*

> **Resolved by dropping the meta-package, not by adding a sixth pin under it.**
> `@opentelemetry/auto-instrumentations-web` is gone; `apps/web/package.json` now declares six explicit
> packages — `api`, `sdk-trace-web`, `exporter-trace-otlp-http`, **`instrumentation`**,
> `instrumentation-document-load`, `instrumentation-fetch`. `registerInstrumentations` therefore comes
> from a **declared** dependency and pnpm's strict layout resolves it.
>
> **The resolution was forced by a second problem the finding had not seen.** The meta-package
> re-declares its transitive **`zone.js`** dependency at its own `peerDependencies` — an Angular
> async-primitive monkey-patch, pulled in because `auto-instrumentations-web` bundles
> `instrumentation-user-interaction`. So the peer was unavoidable for as long as the meta-package
> stayed, and no amount of adding pins underneath it would have helped. `zone.js` now appears in
> neither `apps/web/package.json` nor `apps/web/pnpm-lock.yaml`.
>
> It also removes a contradiction nobody had reconciled: `opentelemetry-instrumentation/SKILL.md`
> **bans `user-interaction` by name** (it spans every click with DOM targets, tripling trace volume),
> while the manifest was pinning the one package that guarantees its presence. The pinned set is that
> skill's to own, which is why the fix was a manifest change agreeing with the skill rather than a
> sixth pin appearing silently underneath a meta-package — exactly the outcome the finding argued for.
>
> Original text below, unedited.

`registerInstrumentations` lives in `@opentelemetry/instrumentation`, which is only *transitive* via
`auto-instrumentations-web` — and pnpm's strict `node_modules` layout will not resolve a transitive
package from application code. This is the same trap that makes `@tanstack/react-store` a declared
dependency of `apps/web`.

Left as a TODO rather than a sixth pin added silently, because the pinned five are
`opentelemetry-instrumentation`'s to own and a sixth appearing without a skill edit is exactly the drift
the pin exists to prevent.

### O22 — no workflow builds any image, so every build-time assertion is unenforced *(CLOSED — `ci.yml` builds six)*

> **Closed on 2026-08-10, by a workflow rather than a decision.** `.github/workflows/` now holds
> **two** files. `ci.yml` (seven jobs) carries an `images` job with **six**
> `docker/build-push-action@v7` steps — `core-api`; `ai-service` at `runtime`, `runtime-crawl` and
> `runtime-evaluation`; `web`; `widget` — each `push: false`, each with its own GHA cache scope, and
> the two that need one passing a named build context (`infra=infrastructure/docker`,
> `workspace=.`). Every `RUN`-line assertion listed below therefore runs in CI now, including the
> `runtime-evaluation` stage's `import ragas` probe, which was the one with the silent failure mode.
>
> **What is *not* closed by this**, and is the reason the finding stays rather than being deleted:
> a stage that no job targets is still unasserted. `runtime-gpu` is named below and no longer
> exists (ADR-030 removed the GPU deployment); if a future stage is added to a Dockerfile without a
> matching step in the `images` job, this finding recurs with a different stage name and no signal.
> **Revisit condition:** a `--target` in any Dockerfile with no corresponding step in `images`.
>
> The original text is retained unedited below. It was right, and the tense is the only thing about
> it that changed.

`.github/workflows/` contains exactly one file, `gates.yml`, and its scope is deliberately install-free:
no lockfile, no service container, no image. There is no `--target`, no `docker build`, and no
`build-push` anywhere under `.github/`. **Every assertion written into a `RUN` line therefore runs only
on a developer's machine, and only when that developer happens to build that stage.**

What is currently unenforced, all of it real and all of it well written:

- **`import ragas` must FAIL in `runtime` and `runtime-gpu`** (`services/ai-service/Dockerfile:204`,
  `:266`) — an `importlib.util.find_spec` probe that exits 1 with a named message.
- **`kb-assert-torch-backend cpu|cuda`**, which counts the `nvidia-*` distributions actually installed
  rather than reading the manifest, because every way this regresses leaves `pyproject.toml` looking
  correct.
- **The six PHP extensions** (`services/core-api/Dockerfile:134`): five checked by `php -m` grep
  (`pcntl`, `posix`, `opentelemetry`, `redis`, `pdo_pgsql`) plus `Zend OPcache` checked separately by
  `extension_loaded()`, because `php -m` prints it under a different name and adding it to the grep
  loop is the obvious-looking edit that always fails.

**The evaluation containment has the nastiest failure mode, and it is worth stating on its own.** If
ragas leaks into `runtime` nothing breaks loudly — but if the containment breaks the *other* way, or
the evaluation extra fails to resolve, `ai-worker-evaluation` **boots normally, consumes `evaluate`
jobs, and fails every one of them at import.** There is no answer regression, no chat impact, and no
alert: the regression gate simply stops reporting, and a gate that stops reporting looks exactly like a
gate that has nothing to report. The `runtime-evaluation` stage's own
`RUN python -c "import ragas; print(...)"` (`:341`) is the check that catches it, and nothing in CI runs
it.

**Owner:** `platform-devops-engineer` (the `ci.yml` image-build job). **Related:** E1/E2 — this is the
same "specified, not wired" bucket, but it is listed as a finding rather than folded into them because
those two are about *diff* gates, and this is about assertions that already exist in shipped files.

### O23 — `is_global` does not exclude multicast or 6to4 anycast, so the deny tuples are not its complement

`app/crawl/fetch.py` states the rule twice: the module comment at `:182` says the check in code is
`ipaddress.ip_address(a).is_global` on every resolved record, and `is_fetchable_address`'s docstring
at `:421` says *"``ip.is_global`` after unwrapping ``.ipv4_mapped``, **and nothing else**."* The deny
tuples beside them list, among others, `224.0.0.0/4` (multicast), `ff00::/8` (multicast) and
`192.88.99.0/24` (6to4 relay anycast).

**CPython's `is_global` returns `True` for all three.** Multicast is carried on a *separate* flag —
`is_multicast` — which `is_global` does not consult, and `192.88.99.0/24` is simply not a member of
`_private_networks`. Spot-checked locally: `224.0.0.1`, `239.255.255.250`, `ff02::1` and `192.88.99.1`
all report `is_global=True, is_private=False`. (The local interpreter was 3.8.10 rather than the pinned
≥ 3.12.4, but the reasoning is structural rather than version-dependent: `is_global` is defined off
`is_private`, and neither range is in `_private_networks` in any version.)

**The direction of the error matters.** The comment at `:185` already documents the CGNAT gotcha —
`is_private` and `is_global` are **not** complements, `100.64.0.0/10` being neither (CVE-2024-4032).
This finding is the *same non-complementarity pointing the other way*: there, a range is excluded from
both; here, three ranges the tuples deny are ranges `is_global` **admits**. So an implementation that
reads the docstring literally — `is_global` and nothing else — silently drops three of the tuples on
the floor, and the tuples read as though they are still doing work.

**Latent today**, on two independent grounds: `is_fetchable_address` is still
`raise NotImplementedError("TODO(crawler-engineer)")`, and the egress allow-list on `ai-worker-crawl`
(`kb-architecture-map` Gotcha 3) denies these ranges at the network layer regardless. **The resolution
when it is implemented:** `is_global` **and** `not is_multicast` **and** not in the tuples — three
terms, and the docstring's "and nothing else" has to go with them.

**Owner:** `crawler-engineer`. **Blocks:** nothing today; blocks the first real implementation of
`is_fetchable_address`.

### O24 — the retryable set is maintained twice, in two languages, with nothing comparing them

`services/core-api/bootstrap/app.php:189` carries an `in_array($errorClass, [...])` list of the classes
whose envelopes render `retryable: true`. It is a hand transcription of
`services/ai-service/app/core/errors.py`'s `RETRYABLE` mapping. **Nothing compares the two.**

This is **ADR-029's exact shape, waiting to recur on a different row.** That finding was one failure
rendering two ways because two handlers were written independently and neither author could see the
other. The invariant ADR-029 established — *a consumer cannot tell which plane produced an envelope, so
any divergence is a bug regardless of which side looks more defensible* — is enforced today by nothing
but the fact that both lists were written on the same afternoon. Add a class to one and not the other,
or flip one entry, and the two planes disagree about whether a client may retry, silently, in exactly
the way that took fifty reading passes to notice the first time.

Two lower-grade instances of the same duplication sit beside it and should be fixed in the same change:
the status map, and the 5xx message string — which is byte-identical across the planes **on purpose**
(`app.php:208` says so, citing ADR-029) and is therefore a third hand-maintained constant.

**The fix is a contract test, not a refactor.** Neither plane can import the other's table, and putting
the taxonomy in `packages/contracts` would make a PHP service depend on a TypeScript package. What is
available is a test that reads both files and asserts the sets agree — cheap, and it is the regression
guard ADR-029 does not itself provide.

**Owner:** `test-engineer`, with `control-plane-engineer`. **Related:** ADR-029.

### O25 — `unclecode-litellm` ships in the `runtime` image, transitively, against ADR-001

ADR-001 states there is no LiteLLM gateway: each provider is called through its official API. But
`crawl4ai==0.9.2` depends on `unclecode-litellm` (verified in `uv.lock`: `unclecode-litellm 1.81.13`,
listed among crawl4ai's 33 dependencies), and `crawl4ai` is a **main** dependency in
`services/ai-service/pyproject.toml:56` — not an extra. So the package is installed in `runtime`,
`runtime-gpu` and `runtime-crawl`, which is to say the image that serves chat.

**Nothing imports it, and there is no gateway.** ADR-001 is not violated in behaviour; it is
contradicted in the SBOM, which is a different and smaller problem. Recorded for the reason it may stop
being smaller:

**ragas has historically routed judge calls through litellm.** It does not today — `ragas`'s 19
dependencies in `uv.lock` do **not** include it, so the evaluation stack is clean, and the two packages
reach the tree by unrelated paths. But a future `ragas` bump that reintroduces that route would turn an
inert transitive package into a **live, unmetered egress path inside the one image that holds a judge
credential** — outside the provider adapter contract, outside usage accounting, and outside every
`provider_*` error class. The change that causes it would be a version bump in a dependency block, with
nothing in the diff naming litellm at all.

**Owner:** `provider-adapter-engineer` (the ADR-001 boundary) with `rag-eval-engineer` (the ragas pin).
**Cheapest guard:** assert `find_spec("litellm")` and `find_spec("unclecode_litellm")` are un-imported
at runtime, or add an import-ban lint — not a dependency removal, which would mean dropping crawl4ai.

### O26 — six pnpm lockfiles exist where the contract documents four, and the gate that guards the count fails on the current tree *(GATE HALF CLOSED)*

> **The gate half is closed; the prose half is not.** `gates.yml` no longer asserts the root lockfile
> is absent. It now parses the root `pnpm-lock.yaml` and asserts the **root importer resolves nothing**
> — "the invariant that was actually wanted", in this finding's own words — and the file carries a
> comment recording that the old `[ ! -e pnpm-lock.yaml ]` form "was wrong about pnpm itself".
>
> **Still open: every document that states the number.** `CONTRIBUTING.md` was corrected on 2026-08-10
> (finding **R2**) to *one lockfile per workspace importer*, with the empty-versus-absent distinction
> stated. `.npmrc`'s own comment and `packages/design-tokens/package.json`'s description still assert
> that zero dependencies prevents a lockfile appearing, which this finding measured and disproved;
> both are outside `docs/`' remit and are owned by `platform-devops-engineer` and
> `admin-web-engineer` respectively. `services/core-api/Dockerfile:265` repeats it too.
>
> The four-versus-six distinction the finding drew is the part worth carrying forward: **four scan
> targets, six files.** Both numbers are correct about different things, and conflating them is what
> made "four lockfiles" sound verified.

`.npmrc` sets `shared-workspace-lockfile=false` and states the consequence: *"the ROOT manifest must
declare zero dependencies and zero devDependencies, or a fifth lockfile appears at the root.
`packages/design-tokens` is dependency-free for the same reason."*

**Both manifests do declare zero dependencies, and both lockfiles appeared anyway.** Verified: root
`package.json` and `packages/design-tokens/package.json` each total 0 across `dependencies`,
`devDependencies`, `optionalDependencies` and `peerDependencies`; and `pnpm-lock.yaml` exists at both
paths, 169 bytes each, containing `importers: {'.': {}}` and no packages at all. With
`shared-workspace-lockfile=false`, pnpm writes a lockfile **per importer unconditionally** — the
zero-dependency rule does not prevent the file, it only keeps the file empty.

**The consequence is a required check that fails on correct state.** `gates.yml`'s
*The root manifest declares zero dependencies* step (`repo-artifact-consistency`) ends with:

    [ ! -e pnpm-lock.yaml ] || flag "a root pnpm-lock.yaml exists; that is the fifth lockfile the contract forbids"

That condition is false against the working tree today, so the step flags and the job exits 1. It is
green at `HEAD` only because the entire skeleton — both stub lockfiles included — is still uncommitted;
it goes red the moment this tree is committed. And it is a **false positive**: it fires on a 169-byte
stub with zero packages, which is precisely the state the rule exists to guarantee. The step's first two
checks (root dependency count is zero; `package.json` actually parsed) are correct and should stay.

**This is E3's rule arriving from the other direction.** E3 says a required check that cannot pass gets
disabled within a week and never comes back; this one *can* pass, but only if someone deletes a file
pnpm regenerates on the next install — so the cheapest way to green it is to delete the check.

**What is actually true, stated so the next reader does not re-derive it:** there are six
`pnpm-lock.yaml` files and **four scan targets**. `.npmrc`'s "four" is correct about what
`osv-scanner --lockfile` runs against (`apps/web`, `apps/widget`, `apps/mobile`, `packages/contracts`);
it is the *file count* that is wrong. `services/core-api/Dockerfile:265` repeats the four-file claim and
is imprecise in the same way. The check should assert the root lockfile is **empty** (no `packages:`
key, no importer beyond `.`), which is the invariant that was actually wanted, rather than absent.

*(Note against a plausible misreading: `gates.yml:5`'s "the six lockfiles" is **not** the tree
contradicting itself. That six is one `uv.lock` + one `composer.lock` + four `pnpm-lock.yaml` — the
cross-ecosystem total, consistent with the four-pnpm contract, not a competing pnpm count.)*

**Owner:** `platform-devops-engineer` (the gate and `.npmrc`), with `admin-web-engineer` (the
`packages/` half).

### O27 — `langchain-community` is sunset upstream, and ragas declares no bound on it at all

`services/ai-service/pyproject.toml:143` pins `langchain-community<0.4.2`. That ceiling is a holding
action against someone else's bug, and it is worth recording as a finding rather than leaving as a
comment on a pin, because the comment will read as over-caution to whoever tries to raise it.

`ragas==0.4.3` requires `langchain-community` — and `langchain`, `langchain-core`, `langchain_openai` —
as **bare names with no version specifier at all**. It then performs an unconditional module-level
`from langchain_community.chat_models.vertexai import ChatVertexAI`. That module exists in 0.3.31, 0.4
and 0.4.1 and is **gone from 0.4.2**. Unbounded, uv resolves 0.4.2 and `import ragas` dies with
`ModuleNotFoundError` before any evaluation runs. That is why the resolution broke, and it is why the
ceiling is `< 0.4.2` — the exact release that removed the module — rather than `== 0.4.1`.

**There is no forward fix available:** 0.4.3 *is* the latest ragas and the import is still there. And
the dependency being pinned is itself being **sunset upstream** — it emits a `DeprecationWarning`
saying so — which makes this a ceiling on a package that will stop receiving fixes, held in place by a
third party's missing version bound. Two clocks running in opposite directions.

**Watch condition:** a ragas release that removes the `ChatVertexAI` import or bounds its langchain
requirements. Until then the pin stays, and raising it without checking that import is a broken
evaluation worker — see [O22](#o22--no-workflow-builds-any-image-so-every-build-time-assertion-is-unenforced-closed--ciyml-builds-six),
because the build-time `import ragas` assertion that would catch it runs in no workflow.

**Owner:** `rag-eval-engineer`.

### Resolved in place during scaffolding — recorded, not open

- **Qdrant collection name.** `Settings.qdrant_collection` was removed from `app/core/config.py`; the
  name is now a module constant in `app/retrieval/collection.py`. An env-overridable collection name can
  disagree between the indexer and the reader, which raises nothing and reads as an empty corpus. Test
  isolation uses an xdist-worker suffix instead — a different mechanism, noted in the config comment and
  still owed to the pytest `conftest.py`, which does not exist yet.
- **Credential plumbing was specified three mutually exclusive ways.** Resolved to
  `stream(req, caps, credential)`, passed per call — never a field on `ChatRequest`, and never resolved
  from storage inside the adapter, which is what `anthropic-api` shows and what ADR-011 forbids.
  Recorded in the Protocol docstring so the next adapter copies the right shape.
- **OTel Collector metric labels.** `resource_to_telemetry_conversion: false` was correct (`true`
  promotes `service.version`, a git sha, onto every metric family), but nothing else projected
  `service` or `env` either — so every `kb_*` series would have carried only `job` and `instance`, and
  every `sum by (service, env, …)` in the rules would have merged the whole fleet under empty labels
  **without failing**. A `transform/metric_labels` processor now projects exactly those two, ordered
  before the allow-list filter. Verified against the real collector binary.
- **Celery task registration.** `autodiscover_tasks([...])` imports only `<package>.tasks`, so the
  embed-queue and deletion-verification tasks would never have registered — and an unregistered task
  does not raise, it leaves the message sitting in the queue. Replaced with an explicit `conf.imports`.
- **The 404 deny *body* was still an enumeration oracle, one layer below the status split.** The
  403-admin / 404-public split (`kb-error-taxonomy` ¹) was correctly implemented and closed nothing on
  its own: an `AuthorizationException` on a public route rendered `404` with
  `"This action is unauthorized."`, while a genuine route miss rendered `404` with `""`. Two identical
  statuses, two trivially distinguishable bodies, and the oracle the split exists to close wide open.
  **405 was worse** — Symfony's message names the route *and* its allowed verbs, so a probe learned the
  URI existed and its method list. Resolved in `bootstrap/app.php:213`: one constant per rendered
  status, so a denied record, a record in another organization, a record that never existed, and a URI
  with no route are **one byte-identical response**. The admin 403 gets the same treatment even though
  admin deliberately admits existence, on the grounds that one rule is one thing to get right and no
  client branches on a policy's free-form message (`error_class` is what they branch on). Guarded by
  `toDenyAsNotFound()` in `tests/Pest.php`, which does not compare against a copy of the strings — it
  fires a real request at a path that certainly has no route and requires the two bodies to match byte
  for byte, so the assertion cannot drift with the constants.
- **The hosted-chat shell cannot be Full-Route-Cached while carrying a CSP nonce.** Next inlines its
  bootstrap script, so a nonce is required; a nonce baked into a cache entry no longer matches on the
  next request. Resolved by making the chat *page* dynamic and keeping caching where the key is honest —
  the tagged bot-config fetch and `theme.css`. The alternative, `'unsafe-inline'`, is refused on the one
  surface that renders model output. Recorded as a consequence in **ADR-027** rather than as its own ADR.

### CI gate defects found by writing code against the gates

These are not spec defects. They are Definition-of-done greps that **cannot pass on correct code**, or
that pass on incorrect code — found only because an implementer ran them. Each needs an edit to the
owning skill before `.github/workflows/` is authored against it.

1. **Several DoD greps are unsatisfiable as written.** `rg
   "rollupOptions|esbuild:|EventSource|preact/compat|localStorage" apps/widget` must return nothing —
   but `eslint.config.mjs` has to *name* those identifiers in order to ban them, so the file that
   enforces the rule fails the check for the rule. The same shape breaks the origin-check greps over
   `bridge*`. **Fix:** scope these greps to `apps/*/src` and exclude `eslint.config.mjs`.
2. **The `errorClass` / `retryAfter` grep matches prose.** Three comments explaining the camelCase trap
   spelled the trap and so failed the check. All three were reworded, but a comment is not a durable
   fix: the grep must ignore comments or be scoped to source directories.
3. **The `EXPO_PUBLIC_USE_RN_FETCH` grep is self-defeating** — it forbids the literal anywhere under
   `apps/mobile`, including in a comment explaining the ban. Replaced there with an allow-list in
   `app.config.ts` that rejects unexpected `EXPO_PUBLIC_*` keys, which catches the flag without naming
   it. That is the better shape generally: enumerate what is allowed, not what is forbidden.
4. **The `Prefetch(` gate greps the wrong parameter.** `github-actions-pipeline` greps `Prefetch(` for a
   missing `query_filter=`, but qdrant-client's **leaf** parameter is `filter=`; only the top level is
   `query_filter=`. As specified the gate passes on a correctly-filtered prefetch *and* on an unfiltered
   one alike — so the check protecting non-negotiable 2 on the prefetch path currently protects nothing.
   Reconcile before that workflow is authored. (This is also open item O2's neighbour: both are the
   qdrant-client API surface documented from the wrong level.)

### Verification capability, discovered mid-run

Docker **is** available in this environment — Engine 29.6.2, with `php:8.4-cli` already local. Recorded
because the plan assumed otherwise and several checks were deferred that did not need to be:

- `docker compose -f compose.yaml config` validates the whole topology, interpolation, and overlay
  merge. The single highest-value check available, and what `make lint-compose` runs.
- `docker run prom/prometheus promtool check rules` **and** `promtool test rules` — the observability
  unit tests actually execute. Note the CWD trap recorded at doctrine item 4.
- `docker run php:8.4-cli php -l` — already used across 31 control-plane files.
- `docker run node:22` / `python:3.13-slim` for JSON, TOML and syntax validation without installing
  anything into the repository.

## The eight architectural decisions — resolved

These were the genuine choices the spec left unresolved. Each is now decided, and each carries the
argument that ruled the alternatives out, because an unrecorded reason gets re-litigated the first
time the decision is inconvenient. ADR numbering continues from ADR-010.

### ADR-011 — the provider credential crosses the wire, as its own field, outside the snapshot

**Decided: option (a), with three constraints that are the actual substance of the decision.** Laravel
decrypts the credential and sends it in the internal request body as a top-level `provider_credential`
typed `SecretStr` — deliberately **not** a member of the configuration snapshot.

The two rejected options both looked safer than they are. **(b) FastAPI holds the KEK and receives a
credential reference** forces FastAPI to read `provider_connections` from PostgreSQL, breaking *both*
"Laravel owns the relational store" and "FastAPI never reads Laravel's tables" — and it does not reduce
blast radius at all, since an AI service holding the KEK yields plaintext keys on compromise anyway.
**(c) a separate audited fetch endpoint** adds a synchronous round trip to every generation against
§23's 4-second first-token target, and relocates the same plaintext key onto a different request.

What makes (a) acceptable is the separation, not the transport:

1. `provider_credential` is **excluded from the configuration snapshot hash**, so it never contributes
   to `configuration_version`. Two requests differing only in a rotated key must produce the same
   snapshot version — otherwise every rotation invalidates every cached answer and every replayed job
   stops reproducing byte-identically.
2. `SecretStr` means `repr()`, `str()`, and `model_dump()` all yield `**********`. Serializing it is an
   explicit, greppable act.
3. It is on the **never-forward list** — no SSE frame, no error envelope, no span attribute, no log
   field, no audit detail. That is CLAUDE.md non-negotiable 9, unchanged.

The symptom if the credential rides *inside* the snapshot instead: it is hashed into
`configuration_version` and so silently becomes part of cache keys and replay identity — and every path
that persists the snapshot for the playground or the retrieval trace persists a plaintext API key into a
column nobody treats as sensitive.

**Revisit condition:** the whole argument rests on Laravel and FastAPI sharing one trust boundary. If the
AI service is ever deployed outside it — multi-tenant hosting, a third-party inference host, a shared
cluster — option (c) becomes mandatory.

### ADR-012 — FastAPI writes four tables directly; the ownership rule is narrowed, not broken

> **The table list below is superseded by
> [ADR-033](#adr-033--the-write-allow-list-is-allowed_tables-and-the-invariant-is-not-the-number).**
> The rule, the argument and both safety constraints stand and are unedited. What moved is only the
> membership: ADR-032 added `sparse_version_statistics` and `sparse_term_frequencies`, and ADR-033
> restates the invariant as **three admission properties** rather than a count, with the list itself
> living in `services/ai-service/app/db/writes.py`. Read "four" below as the membership at the time,
> not as the invariant.

**Decided: direct write, against a closed allow-list.** `services/ai-service` writes `chunks`,
`document_elements`, `retrieval_traces`, and `evaluation_results` — and nothing else. Every other table
is Laravel's. The rule is restated precisely: **Laravel owns every table the public API reads or writes,
and owns all migrations.** The four are derived, rebuildable artifacts — the relational half of the same
index whose vector half FastAPI has always written, into a schema Laravel still defines.

The callback lost on arithmetic, not on principle. Per-chunk it is thousands of round trips per
document; bulk, it ships every chunk's full text through a PHP request body — the payload PHP-FPM memory
limits are worst at — to be written verbatim to a table Laravel never reads.

The argument that actually settles it is atomic publication: FastAPI writes chunk rows against the
**new, not-yet-active `source_version_id`** while the previous version keeps serving. A direct write
cannot corrupt live state, because nothing reads those rows until the pointer flips.

**And the flip is Laravel's** — which closes unenforceable-doctrine item 5 below. FastAPI writes rows,
then reports terminal state through §17.5's ingestion status callback; Laravel performs the activation.
Getting this backwards puts two writers on the one column deciding which version is live, so the partial
unique index enforcing "at most one active version per item" turns a lifecycle bug into a constraint
violation inside a Celery task, retried forever.

Gated in CI as an allow-list, never a deny-list of bad table names — a deny-list cannot see a table
invented tomorrow.

### ADR-013 — `web` is on `edge` only. Ratified

`kb-architecture-map`'s deviation from §24.3 is now the topology, not a deviation. `apps/web` has no
Server Actions and produces no organization-scoped byte on the server, so the Next.js server has nothing
it could legitimately ask `ai-api` for. Leaving it on `application` would make "clients never reach
FastAPI" a convention enforced by nobody: one route handler and it is false, with no network to stop it
and nothing in review reliably catching a `fetch('http://ai-api:8000/...')` in a file full of ordinary
fetches. The existing set-equality DoD check is the enforcement.

### ADR-014 — §8.7's fallback trigger is reachable via capacity signals only; `model_not_found` stays permanent

**Decided: split the signal, add no class.** The provider is telling us two different things under one
heading, and §8.7 means only the first.

- **Provider-side capacity or overload** → `provider_temporary`: retryable, **fallback-eligible**. This
  is what §8.7's bullet actually means and what makes it reachable. Anthropic's `overloaded_error`,
  OpenAI's 503, NIM's 503 while a model loads or is scaled to zero, OpenRouter's upstream 502,
  DeepSeek's 503. Each adapter owns its own mapping table; the internal class is the same.
- **A model name the provider does not recognise** (`model_not_found`, a retired OpenRouter slug) →
  stays `provider_permanent_request`: non-retryable, **not** fallback-eligible.

The second branch is the one worth arguing. That model name came from the bot's configuration snapshot,
so an unrecognised model is a **misconfiguration the tenant needs to see** — a deprecated pin, a typo, a
model the vendor retired. Falling back would silently serve every answer from a different model at a
different price and a different quality, with a `Ready` bot and no error anywhere; the admin finds out
from a bill or a quality complaint months later. The fallback ladder is the wrong tool for a
configuration defect. So §8.7's "model temporarily unavailable" is **clarified to mean provider-side
capacity**, not a missing model.

Adapters must distinguish the two by the vendor's **error code**, never by string-matching the message:
vendors change error prose without notice, and a substring match on "unavailable" reclassifies on a copy
edit — in the dangerous direction, turning a config error into a silent fallback.

### ADR-015 — deletion and erasure are two workflows with different reach

**Decided: the interim break-the-link rule is ratified for deletion; erasure is a strict superset.**

**Source deletion (§8.17)** breaks the link and does not cascade: null `citations.chunk_id`, keep
`citation_label` / `display_title` / `location_metadata`, and **retain** the three verbatim-text columns.
A conversation records what the bot said and what it said it from; destroying that because an admin
removed a stale PDF rewrites history and leaves the audit log describing answers nobody can inspect.

**Data-subject erasure (§18.10)** does everything deletion does, plus a sweep over
`citations.excerpt`, `retrieval_traces.selected_evidence`, and `evaluation_results.retrieved_evidence` —
the three places verbatim source text survives outside §8.17's artifact list, which is now extended to
name them, each marked *source deletion: retain / erasure: purge*.

Four rules govern the sweep. It **overwrites in place and keeps the row**, because deleting rows would
silently move historical evaluation scores and retrieval metrics for a compliance action nobody would
ever reconcile. It runs under the same **two-phase-plus-proof** contract as every other deletion here —
erasure without verification is a compliance claim with no evidence. **Scope follows §18.10's four
workflows**, and a *source*-scoped request reaches those columns only when raised explicitly as an
erasure, which is the entire reason there are two workflows. And an erasure colliding with a **legal hold
is refused and recorded as a conflict, never partially executed** — a half-erased subject satisfies
neither obligation and destroys the evidence needed to explain which parts ran.

Ownership: `kb-deletion-and-verification` and `deletion-engineer`. The `UNVERIFIED` marker saying no spec
section assigned the sweep is retired.

### ADR-016 — Haystack is dropped

**Decided: drop it entirely**, superseding `docs/05-tech-stack.md` §9.5 and the `docs/21` glossary entry.
It appears three times in 3,816 lines with no ADR, no §12 stage, and no §21 role, while the spec
independently assigns every job it would do: Ragas for evaluation, Docling for parsing, Crawl4AI for
crawling, BGE for embedding and reranking, ADR-001 for providers.

The §9.5 fence — *"an internal pipeline toolkit, not the application's domain architecture"* — is not
buildable. Verified against `deepset-ai/haystack@main` and `haystack-core-integrations@main`:

- **`filter_policy` defaults to `REPLACE`**, so a runtime `filters=` on any Qdrant retriever replaces the
  init filter outright — and the `MERGE` alternative explicitly raises on a native `models.Filter`.
  **There is no configuration in which our tenant filter and a facet filter both survive.** The first
  facet-filtered query returns another org's chunks, and every test that omits runtime filters passes.
- **Fusion destroys the trace.** `DocumentJoiner._reciprocal_rank_fusion` hardcodes `k = 61` with no
  parameter and returns `replace(doc, score=fused)`, discarding the per-branch score. Stage 9 and the
  §8.24 playground both require both input ranks *and* both scores per candidate.
- **Identity is not ours.** Point ids are `uuid5(<a constant in their package>, document.id)` over a
  SHA-256 of content+meta+embedding, and payload nests every field under `meta.*`. Delete-by-filter on
  `source_version_id` matches nothing **and reports success** — which `kb-deletion-and-verification`
  reads as proof of removal.
- Incidental but disqualifying for a self-hostable product: `HAYSTACK_TELEMETRY_ENABLED` defaults to
  `"true"`, posting the component inventory to `eu.posthog.com` with errors swallowed.

The `haystack-pipelines` skill is **retained and retargeted** rather than deleted: it is now the record
of why not, the mapping of each job to what does it instead, and the labelled counter-example carrying
the banned `kb.rag.*` span names. No agent-roster change — no `haystack-engineer` was ever defined.

### ADR-017 — ADR-001/003/005 are reaffirmed against Laravel 13's first-party AI APIs

**Decided: the control plane uses none of them.** Laravel 13's AI SDK, `Str::toEmbeddings()`, and
`DB::whereVectorSimilarTo()` are out of bounds in `services/core-api`.

The reason is **custody, not API quality** — stated that way deliberately, so this is not reopened
per-feature every time one of them gets a good release note. The AI SDK would put provider credentials,
spend accounting, and token normalization inside the control plane, outside the adapter contract, so a
call made that way appears in no `provider_calls` row, no `usage_events` row, and no quota check.
`Str::toEmbeddings()` is the same provider call wearing a string helper's clothes. And
`DB::whereVectorSimilarTo()` is the serious one: a **second retrieval path with none of the four
mandatory filters**, no rerank, and no evidence threshold — a cross-tenant read that reviews as one line
of ORM.

**Revisit condition:** reopen only if Qdrant is dropped for pgvector as the vector store — and even then
the AI SDK stays out, because credential custody is a separate argument from where vectors live.
Enforced by a CI grep over `services/core-api`.

### ADR-018 — the signing change absorbs into `v1`; the prefix stays `KB1`

**Decided: absorb.** Spec defect 13 rewrote the canonical string to append every `X-KB-*` header. No
signer has ever been deployed, so `KB1` has never meant anything on the wire, and bumping to `KB2` before
`KB1` ever ran would make the version prefix describe authoring history instead of deployment history —
the opposite of what a wire version is for.

The rule that takes effect from the first deployed release, which is the reason this was worth recording
at all: any change to the canonical string, the covered header set, or the hash **bumps the prefix**; the
**verifier accepts both prefixes for one release window** while the signer emits only the new one,
because signer and verifier deploy at different times and that window is the only thing keeping a rolling
deploy from 401-ing every internal call; and dropping the old prefix is a deliberate second deploy, never
part of the first.

## The ten scaffolding decisions — ADR-019…028

ADR-011…018 answered questions the specification *posed*. These ten answer questions it never asked,
because they only become questions when a file has to exist: what the licence file says, which version
of a linter runs, where a Dockerfile sits, which network a collector joins. Each was decided while the
skeleton was built and is already cited from the artefact it governs, so a silent change leaves a
comment lying.

Summaries and the pointer table are in [`docs/19-repo-structure-adrs.md`](19-repo-structure-adrs.md)
§28. The argument is here.

### ADR-019 — Apache-2.0 plus a NOTICE file

**Decided: Apache-2.0, with `NOTICE` as the accumulation point for third-party attribution.**

Three candidates were real. **MIT** is shorter and more widely understood, and was rejected for one
missing clause: it grants no patent licence. We ship self-hostable software to operators we do not vet
and cannot indemnify, and Apache §3's express patent grant — with its defensive termination on
patent litigation — is the term they actually need from us. That is also why the trade is not "Apache is
more permissive than MIT"; on the axis that matters here Apache grants *more*.

**AGPL-3.0** and **BUSL-1.1** were rejected on an argument this repository already makes against
itself. `security-scanning-toolchain`'s licence gate **denies** both for our dependencies, with the
stated reason that "we hand images and source to self-hosters we do not vet, so an AGPL/SSPL/OpenRAIL
term is one *they* inherit without agreeing to it." Adopting one of them as our outbound licence would
refute, in the same repository, the policy the gate enforces — and would make every self-hoster's own
deployment a distribution question. A licence gate whose own project violates its stated principle is a
gate nobody trusts the next time it blocks something.

The **NOTICE** half is not decoration: §35 requires "third-party notices" and names no mechanism.
Apache §4(d) is that mechanism — a file with defined propagation semantics, which a README section does
not have. It is also where the model-weight licences go, and those are the attributions most likely to
be missed because they appear in no SBOM (`security-scanning-toolchain`, non-negotiable 4).

**What it costs, stated plainly:** Apache-2.0 permits a competitor to run this as a managed service,
compete with the operator, and contribute nothing. BUSL exists precisely to prevent that. Accepted,
because the deny-list argument above is worth more than a hypothetical competitor, and because a
source-available licence would exclude the project from the ecosystems (distro packaging, corporate
self-hosting policies) where self-hostable software is actually adopted.

**Revisit condition:** a hosted commercial offering. Relicensing after external contributions arrive
requires a CLA or every contributor's agreement, so if a hosted offering is ever the plan, the CLA
question must be answered *before* the first outside pull request — not after.

### ADR-020 — the toolchain pins §9 left open

**Decided:** pnpm 10.x declared in root `packageManager` (currently `pnpm@10.15.1`), Node 22 LTS
(`engines: >=22.11.0 <23`, `.nvmrc` 22), TypeScript 5.9, **ESLint 9 flat config per workspace** plus
`eslint-plugin-security`, Prettier 3, ruff 0.14 + mypy 1.18 for Python, **Larastan level 8 with no
baseline**, and `jest-expo`'s major locked to the Expo SDK major.

§9 names frameworks and no versions. Left open, each of the four workspaces resolves its own and the
monorepo acquires four TypeScript versions that disagree about what a type error is — while
`packages/contracts` is compiled by one of them and consumed by the other three.

Two entries carry arguments rather than preferences.

**Larastan level 8, not level 9.** Level 9 turns every `mixed` into an error, and Eloquent produces
`mixed` structurally — `$model->attribute`, every `->pluck()`, every JSON cast. On a Laravel codebase
the honest outcome of level 9 is a baseline file inside a week. **A baselined analyser is a disabled
one**: it reports on the code nobody is writing any more, the baseline grows on every merge because
growing it is easier than fixing the finding, and the check stays green while covering less each month.
Level 8 is the highest level this codebase can hold at zero baseline entries, and "no baseline file
exists" is the actual invariant — the level is just the number that makes it achievable. The cost is
real and must be named: unchecked `mixed` survives in precisely the Eloquent surface where a tenancy bug
would hide. That gap is covered by the CI tenancy greps and the two-organization Pest harness, which
are the layers that would catch it anyway; the analyser was never the tenancy control.

**`jest-expo` major = Expo SDK major.** SDK 57 means `jest-expo@~57`. A `jest-expo` from a different
major ships the wrong Babel transform and the wrong module mocks, and the failure is not an error — it
is a suite that passes while testing a module graph the app does not have. Two dependencies
(`jest`, `react-test-renderer`) are deliberately left as `"*"` with a note, because their correct values
are whatever `jest-expo@57` peers against and hand-pinning them from memory is how a mobile suite starts
failing on a patch nobody changed. **A `"*"` surviving into a committed lockfile is a review finding**,
not the intent.

The rest are ordinary pins with one shared consequence: `.npmrc` sets `shared-workspace-lockfile=false`,
so there are **four** `pnpm-lock.yaml` files and the root `package.json` declares zero dependencies.
A single root dependency writes a fifth lockfile and breaks the four-lockfile contract that
`security-scanning-toolchain` scans and `github-actions-pipeline` caches on.

**Revisit condition:** Node 24 entering LTS, or Larastan gaining a per-directory level — the second
would let the Eloquent surface stay at 8 while everything else moves to 9, which is the outcome this
decision actually wants.

### ADR-021 — `qdrant-client` is pinned at 1.18.0, matching server v1.18.3

**Decided: 1.18.0.** `qdrant-hybrid-search` and `kb-tenancy-isolation` disagreed — one written against
1.18.0, one against 1.19.0 — and the tie-break is not recency: `docker-compose-stack` and the CI service
images both pin the **server** at `qdrant/qdrant:v1.18.3`, and the client that matches the server we
actually run is the one that ships.

The reason a mismatch is worth a pin rather than a range: Qdrant's client and server move together, and
a newer client's fields are not rejected by an older server — they are **ignored**. A `query_points`
carrying a field v1.18.3 does not understand executes successfully, returns plausible results, and
silently drops the parameter. On this codebase the parameters at risk are the fusion constant and the
filter shape, so the failure mode of a client/server skew is "retrieval quietly got worse" or "a filter
was not applied", neither of which raises.

`qdrant-hybrid-search` additionally carries a **W**-class unverified marker on v1.19.0's GA status,
inferred from a Docker `latest` digest rather than an announcement (`docs/23`). Pinning to the version
whose release we can point at is the conservative half of the same argument.

**Revisit condition:** the server pin moves. Client and server are bumped in one change or not at all,
and the bump is a restore-drill-grade event because `qdrant-hybrid-search`'s tenancy claims were read
from source at `master` and are marked for re-verification against the pinned version.

### ADR-022 — `pyproject.toml` + `uv.lock` are authoritative; `requirements.lock` is a generated export

**Decided: one resolver, one lockfile, one generated artefact.** uv resolves and `uv.lock` is
authoritative. `requirements.lock` is a committed, `--generate-hashes` export produced *from* it, and it
exists for exactly two consumers: `pip-audit -r requirements.lock --strict` and the lockfile-arm SBOM.

The rejected alternative is the one that looks tidier — maintain `requirements.lock` by hand (or by
`pip-compile`) and let uv read it. That gives one dependency graph two authorities, and the divergence
is invisible in the worst possible direction: **the image builds from `uv.lock` while the scanner reads
`requirements.lock`**, so the tree that was audited is not the tree that shipped, and the licence gate
and pip-audit both return green about a build that does not exist. Nothing errors; the two files simply
describe different software.

What makes the export safe is the guard, not the format: CI **regenerates `requirements.lock` and
diffs it**. A non-empty diff fails the job. That converts "someone edited the generated file" and
"someone changed `pyproject.toml` without re-exporting" from silent states into a red check.

Consequence to state, because it will surprise someone: `requirements.lock` is in `.prettierignore` and
must never be hand-edited, including to add a comment. The only correct edit to it is a regeneration.

**Revisit condition:** pip-audit and Syft both gaining first-class `uv.lock` support, at which point the
export is pure cost and should be deleted rather than left to rot.

### ADR-023 — Dockerfiles live beside their service; core-api is one image, ai-service is three

**Decided: colocation, one core-api image for six services, three ai-service targets.**

**Colocation** was decided against a plausible alternative — all Dockerfiles under
`infrastructure/docker/`, next to the Compose files that reference them. Rejected because the build
*context* is the service directory: a Dockerfile two directories away makes every `COPY` a `../..`, and
the moment the context widens to the repository root the service's `.dockerignore` stops protecting
anything. A wide context is also slow and leaks: `.git`, `secrets/`, and every other service land in the
build cache.

**One core-api image, six services** — `laravel-api`, `laravel-api-stream`, `laravel-worker`,
`laravel-worker-long`, `laravel-scheduler`, `laravel-migrate` — differing only by `command:`. Six
artefacts of the same source would be six digests, six CVE scans, and six chances for the worker and the
API to run different code against the same database. One digest is one thing to scan and one thing to
roll back.

**nginx and php-fpm are in that image together**, which is the entry most likely to be "fixed" later, so
the reason is stated twice: **Traefik v3 has no FastCGI provider.** It cannot speak to php-fpm at all,
so something must translate HTTP to FastCGI. The alternative — a separate `nginx` service in front of a
`php-fpm` service — puts a third member into the `edge ∩ application` set, which A3's Definition-of-done
check closes as **exactly** `{laravel-api, laravel-api-stream}`. That check is what keeps "clients never
reach FastAPI" enforceable at the network layer, and a proxy service straddling both networks is the
first step to it not being.

**Three ai-service targets** (`runtime`, `runtime-crawl`, `runtime-evaluation`) over a fourth
alternative, a single fat image. Rejected on two separate containments, each of which a test asserts:

- **ragas is absent from `runtime`, and `import ragas` must FAIL there.** ragas is an LLM-judged
  harness that makes real provider calls on its own quota. Importable from the chat path, it is one
  careless import from billing a tenant's credential for a quality score nobody asked for, inside a
  request whose entire SLO is first-token latency. A containment that is merely conventional is not one.
- **Chromium (~500 MB) belongs only to the worker that fetches attacker-chosen URLs.** In the base image
  it would sit inside `ai-api`, which is on the `data` network — and the crawl worker is deliberately
  kept *off* `data` for exactly that reason.

**Costs:** a change for one core-api role rebuilds all six; three Python images is three SBOMs and three
scans; and the two extra targets duplicate the dependency-install layer, which is why the shared `base`
stage exists and is never shipped.

### ADR-024 — the Compose profile corrections §24.5 needs

**Decided: no `core` profile, GPU as an overlay file, `otel-collector` always on.** All three are
corrections to §24.5 rather than preferences, and each has a failure that does not raise.

**No `core` profile.** Compose enables every service *without* a `profiles:` key. A service *with* one
is enabled only when that profile is selected. So tagging the required services `core` — which §24.5
suggests — means a bare `docker compose up` starts **nothing**: exit code 0, no containers, no error. It
is not a redundant profile, it is a harmful one. Required services carry no `profiles:` key. Exactly
three profiles exist: `observability`, `dev-tools`, `test`.

**GPU is `compose.gpu.yaml`, not a profile.** A profile can include or exclude a whole service; it
cannot patch a `deploy.resources.reservations.devices` block onto an existing one. `ai-worker-embedding`
must be *the same service* with a device reservation added, which is an overlay merge. Written as a
profile it would have to become a second, duplicated service definition — and two definitions of one
worker drift.

**`otel-collector` has no `profiles:` key and ships in every deployment,** including a single-host
self-hosted one that runs no Prometheus, Loki, Tempo or Grafana. Behind the `observability` profile it
would be absent by default, and an absent collector does not fail: the SDK exporters retry into a DNS
name that does not resolve, spans are dropped at the exporter, and the operator sees a healthy stack
with no telemetry. It is also load-bearing beyond dashboards — tail sampling cannot exist without a
collector, and under ADR-025 it is the only endpoint a browser may post to.

The §24.8 sizing floor of 8–16 GB is noted as inconsistent with running BGE-M3 and the reranker locally,
which want ~16 GB between them. That number is stated for an external-LLM showcase and should be read
that way.

### ADR-025 — `otel-collector` joins `edge`, with no Traefik router

**Decided: network membership only.** `otel-collector` is on `[edge, observability]`, carries no
Traefik labels, no router, and `kb.edge: "false"`. It is not publicly routable; it is merely
*resolvable* from `apps/web`.

This replaces a first draft that routed browser OTLP through a Traefik route on `api.<domain>`. One
membership solves both halves the draft needed two mechanisms for:

- **Server-side.** `apps/web` is on `edge`, so `instrumentation.ts` reaches
  `http://otel-collector:4318` directly. No new network, no route.
- **Browser-side.** The page posts **same-origin** to `/telemetry/v1/traces` and a Next route handler
  forwards over `edge`. No CORS block in the collector config (its absence there is a consequence of
  this decision, not an omission), no extra Traefik route, and the browser never learns the collector's
  address.

The rejected alternative is the one that reads as more natural and is the actual hazard: **put `web` on
the `observability` network.** That network also carries `ai-api`'s exporter path, so `ai-api` becomes
resolvable from a Next route handler — reopening exactly the hole ADR-013 closes, and reopening it in
the file where a `fetch('http://ai-api:8000/…')` would sit among a dozen ordinary fetches with no
network left to stop it. ADR-013 is preserved unchanged here: **`ai-api` is not on `edge`.**

**Cost, accepted:** the collector sits on a non-`internal` network and therefore has egress it does not
need. That is cheaper than a fifth network and far cheaper than the alternative above. Still owed:
`apps/web/src/app/telemetry/v1/traces/route.ts`. It is org-neutral — it forwards spans and reads no
tenant state — so it does not violate "clients never reach FastAPI".

**Revisit condition:** the collector needing a second consumer outside `edge`, or `web` acquiring Server
Actions. The second would reopen ADR-013 first, and this decision follows it.

### ADR-026 — one SeaweedFS container with `-s3.config`; two Valkey services

> **The fail-open reasoning below is superseded by
> [ADR-037](#adr-037--the-fail-open-pair-is-the-other-way-round-and-the-probe-that-would-have-caught-it-could-not),
> and by nothing else in this section.** Both decisions stand; the whole `maxmemory-policy` argument
> stands. What is wrong is one factual claim, and it is wrong in **both** directions at once: measured
> against the pinned tags on 2026-08-11, an absent, directory-shaped or zero-byte `identities.json` is a
> **fatal** startup error (exit 255) rather than Allow-All, and `valkey:9.1.1` starts **wide open**
> without its `aclfile` rather than refusing to start. The paragraph below is left unedited because the
> *shape* of the mistake is the finding — a plausible reputation, repeated in every file that had
> reason to mention it, that nobody had put on a host. ADR-037 carries the measurement and the site
> list.
>
> One sentence below survives intact and is worth separating out: **`-s3.config` really is the
> fail-open path.** Dropping the flag, or a config that parses to `"identities": []`, does produce a
> wide-open gateway that starts cleanly and logs nothing. It is the *missing file* half that is
> backwards.

**Decided together** because both are places where the tidier-looking configuration fails silently.

**SeaweedFS collapses §24.2's four services (master, volume, filer, s3) into one container** running all
four in-process. The four-service topology is explicitly simplifiable for this deployment shape, and one
container is one healthcheck, one volume, and one restore drill.

The part that is not a simplification is `-s3.config=/etc/s3/identities.json`. **Without an identities
file the S3 gateway runs in Allow-All mode.** It **fails open**: a mistyped path, an unreadable file, or
a forgotten mount produces a wide-open object store that starts cleanly, logs nothing, and serves every
operation successfully. You cannot notice this by looking at the stack — `docker compose ps` is green
and every application call works. So the file is mandatory *and* `scripts/dev/bootstrap.sh` asserts an
anonymous `ListBuckets` returns 403. That assertion is the reason bootstrap is a script rather than a
README section.

The **`seaweedfs-s3` network alias** is the second half: both storage clients are configured with
`endpoint_url=http://seaweedfs-s3:8333` while the service is named `seaweedfs`, so without the alias
every client gets NXDOMAIN. No skill creates that name; the alias is where it comes from.

**Two Valkey *services*, not two logical databases.** `valkey-core` is `noeviction` with AOF;
`valkey-cache` is `allkeys-lru` with persistence entirely off. The rejected alternative — one server,
`SELECT 0` for queues and `SELECT 1` for cache — is not merely inferior, it is **incapable**:
`maxmemory`, `maxmemory-policy`, `save`, and `appendonly` are all **server-level** settings, and
`SELECT n` changes none of them. The conflict is "the queue must never evict" against "the cache must",
and a database index cannot express it at all. Under one server with `allkeys-lru`, queue entries are
evictable — jobs vanish with no error, which is the failure that produces "the crawl just never ran".

Disabling persistence on `valkey-cache` is an independent argument: it is the only place tenant text
lives in Valkey, and turning RDB and AOF off is the only *provable* way to keep cached answers out of a
backup. The split needs Valkey 9.1, because `DELIFEQ` (9.0) and database-level ACL (9.1) are what turn
it from a naming convention into an enforced boundary.

Consequence recorded elsewhere and worth repeating: `valkey-core` is the one store also on the
`application` network, because `ai-worker-crawl` is kept off `data` and still needs its broker.

### ADR-027 — `apps/web` route namespaces: admin at `/*`, hosted chat at `/c/[publicBotId]`

**Decided: admin owns the root namespace; hosted chat lives under one prefix.** `proxy.ts` maps `Host`
to a namespace and rewrites a bare `/<publicBotId>` on `chat.<domain>` under `/c/`.

The collision this resolves is concrete and has no configuration answer. Spec defect 17 gives the app
three origins on one Next.js application, so `apps/web` carries **two root layouts** — `(admin)` and
`(chat)` — as sibling route groups. **Two sibling groups cannot both own `/`**: Next cannot resolve
which layout serves the root path, and the build fails or, worse, resolves arbitrarily. Giving chat a
prefix is the only way both surfaces exist in one application.

The second half is subtler and is why the prefix cannot simply be dropped for prettier URLs. With chat
unprefixed at `/[publicBotId]`, Next's static-beats-dynamic route precedence means the admin console's
static `/bots` route wins on **every** host — so `chat.<domain>/bots` renders the admin bots page. A
visitor typing a plausible URL on the public chat origin gets an admin surface. Nothing errors; the page
renders.

**`Host` → namespace is ROUTING and never AUTHORIZATION**, stated in the file and restated here because
it is the change someone will propose. `proxy.ts` is Next middleware under its Next 16 name, and
**CVE-2025-29927 skipped every Next.js middleware with a single request header**. Any design where the
proxy is the gate fails open on the next equivalent bug. Laravel returning 401/403/404 is the gate;
`proxy.ts` decides which page renders and what CSP it carries, nothing more.

The rejected alternatives: **two Next applications** (two builds, two images, two deploys, and
`packages/contracts` consumed twice — for two surfaces that share a design system and an API client);
and **`Host`-based rewriting with no prefix** (which is the precedence bug above, plus a rewrite table
that has to enumerate every admin route to avoid shadowing).

**Cost:** hosted-chat URLs carry a `/c/` segment that means nothing to a visitor, and the local
development host has to stand `Host` enforcement down (`localhost` resolves to an `unrestricted`
namespace) or hosted chat is untestable locally — which is a deliberate divergence between dev and
production behaviour in a security-adjacent code path, and is commented as such.

**Consequence, decided here rather than separately:** hosted chat's CSP nonce must be per-response, and
Next reads that nonce from the *request's* `Content-Security-Policy` header. A nonce cannot be baked
into a Full-Route-Cache entry — it would no longer match on the next request. So **chat pages render
dynamically**, and caching is kept only where the key is honest: the tagged bot-config fetch and
`theme.css`. `'unsafe-inline'` was refused outright; hosted chat renders model-generated Markdown, the
highest-risk sink in the product.

### ADR-028 — `packages/contracts` ships two subpath exports, and Zod is an optional peer

**Decided: `"@kb/contracts"` is zero-dependency; `"@kb/contracts/forms"` carries the Zod schemas.**
`zod` is declared as an **optional peer** so `apps/widget` can depend on the package without installing
it, and `apps/widget/eslint.config.mjs` bans the `/forms` import with a message naming this ADR.

The rejected alternative is the one everybody proposes: **one entry point, and let tree-shaking keep Zod
out of the widget.** It is exactly the silently-passing size gate `preact-vite-library` warns about. It
works right up until an import shape defeats it — a re-export barrel, a `sideEffects` mistake, a
dynamic import, a bundler upgrade — and then it does not, with no error anywhere. The first signal is
`size-limit` failing on a pull request that touched **no file in `apps/widget`**, because the package is
billed to the widget's 30 kB brotli shell (decided earlier: `packages/contracts` carries runtime code
and is budgeted at ≤ 1 kB brotli inside that shell). Zod 4 is an order of magnitude larger than the
entire budget, so the failure is not marginal — it is the whole shell.

A subpath makes the exclusion **structural rather than emergent**: the widget cannot pull Zod in without
writing an import that a lint rule rejects by name, and the boundary survives a bundler upgrade because
it is not a bundler behaviour.

`peerDependenciesMeta.zod.optional = true` is what makes it installable three ways from one manifest:
`apps/web` and `apps/mobile` install Zod and use `/forms`; `apps/widget` installs neither and uses the
root entry. A hard dependency would put Zod in the widget's `node_modules` whether or not it is
imported, which is a supply-chain surface and an SBOM row for code that never ships.

**Costs, all real:** two build entries and two type-resolution surfaces in `tsup`; a peer dependency the
two consuming apps must remember to install (a missing optional peer is a warning, not an error, so the
symptom is a type error in a form file rather than a clear message); and `apps/mobile` typechecks
against the **built** output rather than source, so `pnpm contracts:build` must run before the mobile
typecheck — in CI and locally.

**Revisit condition:** the widget acquiring a form. At that point either the budget argument changes or
the widget needs a validator that is not Zod, and this ADR should be superseded rather than amended.

## The decision code forced — ADR-029

ADR-011…018 answered questions the specification *posed*. ADR-019…028 answered questions it never asked,
because a file had to exist. **ADR-029 answered a question two agents had already answered differently**,
in shipped handlers, without either of them being able to see the other's answer. That is a third
category and worth naming: the finding was not a gap in the spec, it was a *divergence* — and a
divergence is invisible from inside either half, which is why fifty reading passes did not find it and
building the skeleton did.

Summary and the pointer entry are in [`docs/19-repo-structure-adrs.md`](19-repo-structure-adrs.md) §28.
The argument is here. The finding it closes is [§ O1](#o1--an-unhandled-internal-bug-renders-differently-in-each-plane-resolved--adr-029).

### ADR-029 — `internal_dependency` takes an `origin` axis, and the taxonomy stays at 18

**Decided: option (a), scoped as an explicit sub-case rather than a nineteenth class.**
`internal_dependency` carries a second axis, `Origin`:

| `origin` | means | status | `retryable` |
|---|---|---|---|
| `downstream` (default) | a genuine dependency of ours is briefly unavailable | **503** | **true** |
| `self` | an unmapped exception in our own code — a defect | **500** | **false** |

`ErrorClass` stays at **exactly 18**. The `assert len(ErrorClass) == 18` at the bottom of
`services/ai-service/app/core/errors.py` stays, and every skill sentence reading "the 18-class error
taxonomy" stays true. **This ADR is what makes those sentences true**, rather than what threatens them.

**The invariant it creates, which is the actual deliverable:** the two planes render an unhandled
internal exception **identically** — `500`, `error_class: "internal_dependency"`, `retryable: false`. A
consumer cannot tell which plane produced an envelope, so any divergence is a bug regardless of which
side looks more defensible in isolation.

#### Why not a nineteenth class

The obvious shape — an `internal_error` class — was rejected on cost that is entirely mechanical and
entirely real. The count is asserted **at import**, not in a test, so a nineteenth entry is a review
stop by construction. It is quoted in at least five skills' prose and in one skill's `description`
front-matter. Every provider adapter's mapping table grows a row it will never map anything into,
because no provider can report *our* bug. And the metric label allow-list is closed, so `error_class`
gains a value that has to be added to the allow-list, the catalog, and the recording rules. All of that
to express a **rendering** difference on one row — which the taxonomy already has an idiom for.

#### Why not collapse both planes to 500

The tempting simplification, and the one that destroys information. `downstream`'s 503 is not a
compromise: it is the honest "come back shortly" that a real PostgreSQL, Valkey, or embedding-service
outage deserves, and it is what makes a client's bounded retry correct rather than wasteful. Collapsing
it into 500 would make every genuine dependency brownout un-retryable and would have silently rewritten
the retry policy of five other skills that read `internal_dependency` as the retryable-dependency row.
The defect was never that 503 is wrong; it was that 503 was being applied to something that is not a
dependency failure at all.

#### The idiom this reuses, deliberately

`authorization` already renders **403 on admin surfaces and 404 on public and SDK surfaces**, because a
403 on a foreign identifier confirms the row exists and turns the endpoint into an enumeration oracle.
One class, one row key, a second input that selects the rendering. `Origin` is the same construction on
the same terms, and the rule that comes with it is the same rule: **never branch on the second axis in
retry, fallback, or breaker logic.** Those are keyed on `error_class`, which is identical either way.
Reusing an idiom the taxonomy already contains is what keeps this a sub-case instead of the first step
toward a matrix.

#### What actually landed in the data plane

Read the code, not this paragraph, if the two ever disagree —
`services/ai-service/app/core/errors.py` and `app/main.py` both cite ADR-029 by number:

- `Origin(StrEnum)` with `DOWNSTREAM = "downstream"` and `SELF = "self"`.
- `status_for(error_class, surface=Surface.ADMIN, origin=Origin.DOWNSTREAM)` returns 500 for
  `INTERNAL_DEPENDENCY` + `SELF`, and is otherwise unchanged — the `authorization`/`Surface` branch sits
  immediately above it in the same function, which is where the parallel is meant to be read.
- `retryable_for(error_class, origin=Origin.DOWNSTREAM)` returns `False` for that same pair. The
  `RETRYABLE` mapping **keeps `INTERNAL_DEPENDENCY: True`** — that entry is the *downstream* row, and
  editing it to `False` is the plausible-looking change that breaks the other half.
- `KbError.__init__` defaults `retryable` through `retryable_for(error_class, origin)` rather than
  through `RETRYABLE`, so **a `self`-origin error cannot be constructed as retryable by omission.**
  That is not defensive coding: constructing it by omission is exactly how O1 happened.
- `_handle_unexpected` in `app/main.py` passes `origin=Origin.SELF` explicitly, and `_envelope` passes
  `exc.origin` through to `status_for` rather than letting it default. Dropping either argument
  restores the split in full, silently.

**The envelope is unchanged.** `origin` is **not** a wire field: it selects a status and a `retryable`
value, both of which the envelope already carries. Adding a field would be a contract change across
three clients for information no client can act on — a client's only correct behaviour is to read
`retryable`, which is precisely the fix.

#### Consequences, including the ones that hurt

- **Two materially different failures share one `error_class`, and `error_class` is a metric label.**
  The label allow-list is closed and has no `origin`. So `kb-observability-conventions`' catalog cannot
  separate "we have a bug" from "Postgres blipped" on any dashboard, in any alert, at any point — they
  are one series. This is the real price of keeping the count at 18, and it is paid in the place where
  it is least visible.
- **The Page column does not move, and that follows rather than being decided here.** Paging is keyed
  on `error_class`; `internal_dependency` pages; both origins therefore page. Combined with the point
  above, an operator woken by that page cannot tell from the alert which of the two they are looking at
  and must go to the logs. Recorded as a consequence, not as a preference.
- **A client retry predicate keyed on `error_class` alone is now wrong for this row** — it is the
  specific client-visible bug O1 named. `retryable` off the envelope is the only correct input;
  `packages/contracts` and any helper deriving retryability from the class must read the field.
- **Laravel was already rendering 500 / `retryable: false` — by accident, which was its own defect.**
  It got there through a `$httpStatus === 500` arm plus a hand-maintained `$retryable` expression,
  under a `TODO(taxonomy)` recording that the question was unresolved. Right answer, no stated reason,
  and an expression that reads as a status comparison is one that gets "simplified" by someone who
  cannot see what it is for. `services/core-api/bootstrap/app.php` now derives an explicit `$origin`
  (`$httpStatus >= 502 ? 'downstream' : 'self'`) and reads it in **both** the status arm and the
  `$retryable` expression, because — as in Python — the 500/503 split and the retryable split are one
  decision and must take one input.
- **The retryable set is now written twice, in two languages.** PHP's `in_array($errorClass, [...])`
  is a hand-maintained transcription of Python's `RETRYABLE`, and nothing compares them. Both planes
  rendering one envelope is the whole invariant, so a class added to one list and not the other
  reproduces O1's exact shape on a different row. A contract test that asserts the two agree is the
  regression guard this ADR does not itself provide; until it exists, this is a review item.

**Revisit condition, and it is observable:** the first time either (i) a dashboard or alert genuinely
needs to separate self-origin from downstream-origin failures, or (ii) a **second** error class wants an
`origin`. Either one means "a second axis on exactly one row" has stopped being an idiom and become an
undeclared dimension of the taxonomy — at which point the honest move is to restructure it (or add the
label, with the cardinality argument made properly), never to grant a third one-row exception.

**Supersedes nothing.** It adds a sub-case that ADR-011…028 neither assumed nor forbade.

#### Corrections this ADR owes to the skill library

The skills are the source documents the rest of the repo cites, and four of them now describe a
rendering that the landed code does not produce. They are recorded here rather than edited, because
`docs/` does not rewrite `.claude/skills/**` — the owning agent does. **Each is a documentation defect,
not a code defect; the code is correct.**

1. **`.claude/skills/kb-error-taxonomy/SKILL.md`** — the owner. Its `internal_dependency` row reads
   `503` / `bounded, owning tier only` with no qualification. It needs the sub-case (a footnote in the
   `provider_billing` ² / `authorization` ¹ style, which is the pattern this ADR reuses), the Page
   column consequence above, and an explicit sentence that **the count is still 18**.
2. **`.claude/skills/fastapi-service/SKILL.md`** — its exception-handler sketch constructs
   `KbError("internal_dependency", ...)` in the catch-all `Exception` handler with no `origin`, and
   renders through a bare `STATUS_FOR[exc.error_class]` lookup. That is the pre-ADR-029 code, and it is
   the one snippet a reader would copy back into `app/main.py`.
3. **`.claude/skills/tanstack-query-table/SKILL.md`** — `CLIENT_RETRYABLE` is a `Set` of `error_class`
   values including `internal_dependency`, deriving client retryability from the class alone. It is
   *deliberately* narrower than the envelope's flag for `provider_temporary`, and that argument still
   holds — but for this row the class no longer determines the answer, so a self-origin 500 is retried
   by the admin console.
4. **`.claude/skills/expo-react-native/SKILL.md`** — a comment on the mobile envelope parser reads
   "*NOT `internal_dependency`: that class is retryable and pages*". True of `downstream` and false of
   `self`, and the surrounding argument (an unmapped class is permanent) is unaffected. Lowest priority
   of the four, listed so the sweep is complete rather than approximately complete.

**One stale pointer outside `docs/`:** `CONTRIBUTING.md:159` reads *"Next free number (ADR-029 at the
time of writing)"*. It is self-dating, so it is stale rather than false — but 029 is now taken, and the
next ADR is **030**. Owner: whoever owns the root files; noted here because `docs/` does not edit them
and the sentence is the one a contributor reads before picking a number.

**Swept and clean, recorded so nobody re-sweeps them:** `kb-internal-api-contracts` names neither
`internal_dependency` nor `503` anywhere; `nvidia-nim-api`, `celery-workers`, `bge-reranker`,
`nextjs-app-router` and `prometheus-grafana-loki-tempo` all use the class without asserting a status,
and every one of those statements survives the sub-case unchanged.

## The decision that came from outside — ADR-030

ADR-011…018 answered questions the specification *posed*. ADR-019…028 answered questions it never
asked, because a file had to exist. ADR-029 settled a divergence between two handlers already written.
**ADR-030 is a fourth category: a scoping decision taken by the product owner, which invalidated a
model stack the specification names by name.** No amount of reading `KnowledgeBot-AI.md` produces it,
because it is not a fact about the specification — it is a decision about what this project is for.

> **Ankur, 2026-08-06, restated 2026-08-07:** *"We are not going to code machine-learning or embeddings,
> etc LLM work here in this project — for each of those tasks we will use external LLMs via API."*

Summary and the pointer entry are in [`docs/19-repo-structure-adrs.md`](19-repo-structure-adrs.md) §28.
The argument is here, and so are the three consequences it leaves open —
[C1](#c1--reranking-is-no-longer-universally-available),
[C2](#c2--hybrid-search-loses-its-sparse-arm-resolved--adr-032-adr-033-adr-034-one-part-open) and
[C3](#c3--embedding-model-identity-is-a-vendor-alias-and-an-alias-can-move-resolved-as-a-mechanism--adr-035-three-policy-items-open)
— each of which now carries its resolution at the top and its original text below it.

### ADR-030 — embedding and reranking are provider API calls; document processing stays local

**Status: `Accepted`.** Supersedes nothing in ADR-001…029. It **narrows ADR-006** (hybrid retrieval +
rerank), which remains accepted: the design still fuses two arms and still reranks, but reranking is
now capability-gated and the sparse arm has lost its producer. It **supersedes the model choices in
§9.12** and the §9.12-derived mentions listed under *[Spec text ADR-030
supersedes](#spec-text-adr-030-supersedes)* below.

#### Two scoping answers, because "no ML" alone does not draw a line

The rule as first stated would delete Docling and RapidOCR along with the embedder, since layout
analysis and OCR are machine-learning models too. Both answers are the product owner's, and both are
load-bearing:

1. **The line falls below document processing.** `docling`, `docling-core`, `rapidocr`, `onnxruntime`,
   `pymupdf`, `pillow`, `opencv-python-headless` and `lxml` all **stay**, with the `/models` volume,
   `HF_HOME`, `HF_HUB_OFFLINE=1`, `models.manifest.toml` (shrunk to the Docling and RapidOCR rows),
   `picklescan`, and the model-weights arm of the licence gate. They extract text from files, need no
   credential, cost nothing per call, and **never send a customer document to a third party**.
   `onnxruntime` here is **RapidOCR's**, not torch's. The rule is *no local model inference for
   embedding or reranking* — **not** *no local computation*.
2. **Embeddings and reranking route through the EXISTING provider adapter layer.** The same
   abstraction that serves chat (OpenAI, Anthropic, DeepSeek, NVIDIA NIM, OpenRouter), the same
   per-organization encrypted credentials, the same quota accounting, the same error taxonomy. Two new
   capabilities: `embed(texts, *, model) -> list[list[float]]` and
   `rerank(query, passages, *, model) -> list[float]`.

**The second answer is the one that carries the design.** A new credential path for embeddings would
have been the easy build and it was refused: it would put customer chunk text on a vendor the
organization did not choose, under a credential no organization can rotate, outside the quota
accounting, outside `provider_calls`, and outside the error taxonomy every other outbound call already
maps into. Reusing the adapter layer means an embedding failure is already a classified, retryable-or-not,
billed, traced event on the day it is written.

#### The motivation, stated as the number it actually was

The local stack put a **2 GB embedder and a 1.5 GB reranker resident in every worker process**. That is
what forced `ai-api` to `WEB_CONCURRENCY=1` and made container replicas the only scaling unit —
`--workers 4` meant roughly 14–18 GB of models for one container serving an I/O-bound workload. (The
range is not hedging: `app/main.py:50` records the pair as 2 GB + 1.5 GB and
`services/ai-service/Dockerfile:223-224` records it as ~2.3 GB each. **Neither was measured on this
hardware and the two comments disagree** — recorded rather than reconciled, because the argument does
not turn on which is right and inventing a third number would be worse than reporting both.) And it
made a **GPU a prerequisite for the specified 1.5 s retrieval budget** (see `docs/23` § *the 1.5 s
retrieval budget holds on the GPU profile only*), which every self-hoster had to provision for a
product benefit no tenant can see.

That constraint is now gone, and it is worth stating plainly because the constant it justified survives:
`WEB_CONCURRENCY=1` **stays**, for a different and weaker reason recorded at
`services/ai-service/Dockerfile:222-235` — one lifespan per worker means N Qdrant and N psycopg pools
against limits sized for one. Raising it is now a legitimate question rather than an obvious mistake,
and it must be answered together with the pool sizes. Anyone re-deriving the old reason from the old
number will reach the wrong answer.

#### What left, and the one thing that did not

**Removed:** `FlagEmbedding`, `sentence-transformers`, `transformers` *as a direct dependency*, `peft`,
`datasets`, `ir-datasets`, `scikit-learn`, `sentencepiece`, `pyarrow`; `KB_EMBEDDING_DEVICE`; the
`cpu`/`cuda` extras and the `[tool.uv] conflicts` block; `compose.gpu.yaml` and the ADR-024 `gpu`
overlay; the `runtime-gpu` Dockerfile stage; the `embedding_model` readiness check
(`app/api/health.py` records why a readiness probe must never replace it with a provider call); and the
300 s `start_period` that model loading justified.

**Not removed, and this is the one that catches people:** `torch` and `torchvision` are still in
`services/ai-service/uv.lock` and still declared in `pyproject.toml`. **They are Docling's:**
`docling==2.118.0 -> docling-slim[standard] -> docling-ibm-models -> torch, torchvision`. They are
declared explicitly only because `[tool.uv.sources]` is not applied to purely transitive dependencies,
and they are redirected to the `pytorch-cpu` index — a redirect that is now the **only** thing standing
between this image and ~3.4 GB of CUDA wheels nothing here can execute. Read the comment at
`services/ai-service/pyproject.toml:151-200` before writing anything about this. `pip uninstall torch`
does not produce a leaner image; it produces a service that cannot read a PDF.

#### Consequences, including the ones that hurt

- **Customer chunk text now leaves the deployment.** Local embedding sent nothing anywhere; every
  chunk is now the body of an HTTPS request to the organization's configured embedding provider. That
  is a privacy-posture change for a self-hostable product and it is not neutralized by "the org chose
  the provider" — it needs to be stated in the deployment documentation, and `docs/13` §18 has no
  paragraph for it yet.
- **Two stages moved onto the network, inside a 1.5 s leg.** Query embedding is a round trip before
  retrieval can start, and reranking is one or more round trips after fusion, capped by each vendor's
  per-request passage limit. The old failure mode was a CPU forward pass blocking the event loop; the
  new one is latency variance we do not control and cannot profile.
- **Per-call cost is now per-chunk and per-query.** Ingesting a large corpus has a bill attached, and
  `plan_batches`' determinism in `app/ingestion/embedding/embedder.py` is a **cost** property before it
  is a correctness one: a batch boundary that moves between runs re-embeds and re-bills text already
  paid for.
- **The truncation hazard did not go away; it moved somewhere nothing here can see it.**
  FlagEmbedding silently truncated at 512 tokens. An API model with a 512-token window does exactly
  the same thing and returns HTTP 200 with a plausible vector, and the window is **not discoverable
  from a response** — which is why `check_window` reads it from the control-plane capability row before
  any text is sent and `PROVIDER_TRUNCATION_POLICY` is `"reject"`. We want the 400.
- **Three consequences are large enough to be their own findings and are not decided here:** C1, C2 and
  C3 below.

#### Revisit condition

Any one of these makes the decision wrong, and each is observable:

1. **An air-gapped deployment becomes a supported shape.** With no provider egress the platform cannot
   embed at all, so it cannot ingest. A local embedder would return as an optional deployment
   *profile* — never as the default, and never by re-introducing a GPU requirement for everyone else.
2. **Measured embedding spend per ingested document exceeds the amortized cost of the GPU this
   removed** for a typical self-hoster's corpus. That is an arithmetic comparison, and neither side of
   it has been measured yet.
3. **The query-embedding round trip's p95 pushes the retrieval leg past its 1.5 s budget** on the
   default configuration. The old budget needed a GPU; the new one needs a provider that is close and
   fast, which is equally a deployment property and equally unproven.

#### Spec text ADR-030 supersedes

`docs/01`–`21` are verbatim extracts and are **not edited**. Every line below is superseded by ADR-030
and left in place; this list is the record, and it is exhaustive as of this commit.

| File § | Text | What ADR-030 makes of it |
|---|---|---|
| `05` §9.12 (`:209-220`) | "**BGE-M3** for multilingual dense embeddings…", "**BGE reranker family**, with `bge-reranker-v2-m3`…" | **Superseded outright.** Neither model is loaded. The two surrounding sentences — that the architecture must allow replacing embedding and reranker models without rewriting ingestion, and that changing the embedding model requires a controlled re-index — are *not* superseded; they are now load-bearing and C3 is what they cost. |
| `05` §9.5 (`:91`) | Python preferred because the "embedding, reranking … ecosystem is substantially stronger" | Half the stated reason is void. The choice stands on document processing, OCR and the RAG orchestration; it is not re-opened. Note this is the **same section** whose toolkit fence already carries the ADR-016 correction. |
| `05` §9.7 (`:123`, `:131`, `:136`) | The vector-database requirements naming sparse vectors, sparse retrieval and reranking workflows | Sparse now has no producer — C2. Qdrant is still required for the dense half either way; the sparse requirement is what is in question. |
| `07` §12.11 (`:173-184`) | "The fused candidates **will be** reranked with a cross-encoder reranker", "Rerank approximately 20 to 30 candidates" | **Superseded on two counts:** there is no cross-encoder, and reranking is no longer unconditional — C1. The candidate depth survives as an intent; the per-request passage cap is now a vendor property. |
| `07` §12.8 (`:127-141`), §12.9 (`:145`, `:152-154`) | Sparse retrieval, ~20 sparse candidates, RRF fusion, sparse score and sparse rank in the trace | Depends on a sparse producer that no longer exists — C2. Dense-only retrieval leaves RRF with one arm to fuse. |
| `07` §12.11 (`:184`), `04` §8.24 (`:201-203`) | Reranker score visible in the admin playground; "view sparse retrieval results", "view reranker scores" | The playground must render **absent** for both, with the C1 skip reason, rather than a zero — a zero is a score. |
| `08` §13.2 (`:37`) | Ingestion stage 13, "Sparse representation creation" | No producer — C2. The stage is not removed from the pipeline definition; it has nothing to run. |
| `10` §15.2 (`:27`) | "The recommended showcase configuration uses an **open-weight embedding model hosted within the KnowledgeBot infrastructure**" | **Superseded outright**, and the section title — *"Local Versus Hosted Embeddings"* — is the spec posing exactly the question ADR-030 answers, the other way. This is the single sentence that stated the old deployment model most directly. |
| `14` §19.6 (`:80`) | "If reranking is unavailable, the bot **may** use fused retrieval only when the bot policy permits it and the event is recorded" | **Partially superseded, and the surviving half matters.** Written for a transient outage of a stage that normally works; under ADR-030 unavailability is the *steady state* for three of five providers. The "event is recorded" half is exactly what `RerankSkipReason` implements. Whether the bot-policy gate still applies to a permanent capability gap is the open half of C1. |
| `17` §23 (`:101`), `21` §34 (`:22`) | "Retrieval and reranking: target below 1.5 seconds"; OCR risk mitigated by an "optional GPU" | The 1.5 s budget is now a network budget, not a GPU one (`docs/23`). The OCR GPU line is untouched by ADR-030 — OCR stayed local — but no GPU overlay exists to enable it (ADR-024, and `compose.gpu.yaml` is gone). |
| `18` §24.5 (`:90`), §24.8 (`:132`) | The `gpu` profile "local embedding or OCR acceleration"; "Local embedding, reranking, OCR … may require … a supported GPU" | **Superseded.** There is no GPU profile and no GPU overlay file. Only OCR and layout remain local, and both are CPU ONNX/torch-CPU by pin. §24.8 is the **server-sizing** section, so this is also the one place a self-hoster reads a hardware requirement that is no longer true. |
| `16` §21.4 (`:63-64`) | Experiments compare "different embedding models" and "different rerankers" | Survives and gets sharper: a different embedding model is now a different `EmbeddingSpace` and therefore a different collection (C3), so an experiment is a reindex, not a config edit. |
| `21` §35 (`:46-47`), §38 (`:128`), §40 (`:209-210`) | The licensing policy lists "Embedding models" / "Reranker models"; the glossary defines a reranker as "a model that scores…"; the final recommendation names BGE-M3 and BGE reranker as the initial models | The licensing rows are now empty of local weights (the manifest holds Docling and RapidOCR only); the glossary definition survives as a definition; the two "initial model" lines are superseded. |
| `01` §4.2 (`:131`) | **Secondary goal** — not a non-goal, which is worth reading twice: "Allow future support for local models and self-hosted inference" | **Inverted in status, unchanged in letter.** The spec listed local inference as something to grow *toward*; ADR-030 makes it a **revisit condition** to grow *back to*, and only as an optional deployment profile. Anyone reading §4.2 alone will reach the opposite conclusion about the project's direction. |
| `02` §8.3 (`:64-65`) | Bot configuration includes "Embedding model configuration" and "Reranker configuration" | Survives, and is now doing more work than it looks: those two rows are where a `(provider, model)` embedding space and a rerank calibration are chosen, and the second may legitimately be *none*. |
| `11` §16.4 (`:161`), `08` §13.3 (`:56`) and §13.4 (`:69`), `09` §14.6 (`:89`) | `embedding_model_version` on `source_versions`; the ingest-key components; "Embedding model changes" as a re-version trigger; `Embedding model identifier` in the chunk metadata schema | **Unchanged fields, changed meaning.** They used to hold a pinned local revision; they now hold a *measured* provider identity string. That substitution is the whole of C3 and is why no new column was added. §13.4's "embedding model changes ⇒ new version" is the spec already anticipating C3 — for a change someone makes, not one a vendor makes. |
| `19` ADR-006 (`:65-69`) | "Combine dense and sparse retrieval, then rerank" | **Narrowed, not superseded.** Still the design; both halves are now conditional. `docs/19` is otherwise verbatim through §27 and ADR-001…010 — the ADR-030 entry is appended in §28, which is where new ADRs go. |

**Not in this table on purpose:** `docs/03` §8.17 (`:306`, "Sparse vectors" among the deletion
targets), `docs/15` §20.1 (`:19-21`) and §20.2 (`:43-44`, sparse and rerank spans and metrics),
`docs/20` §29–33 (MVP scope, phase list and demo script), and `docs/01` §2 (`:56`), §3 (`:97`) and §7
(`:281`, `:307-308`). Each names a stage that still exists; what changed is
whether it always runs, which C1 and C2 own. Deletion in particular is **unaffected**: it removes
points by identifier, and a point with no sparse vector deletes exactly like one that has one.

## Open after ADR-030 — C1…C3

**A third finding namespace, and it is deliberate.** The *Spec defects* list below is 1–27, the
scaffolding findings are O1–O27, and these are **C1–C3** — the consequences of ADR-030. They are C-
rather than O-numbered because the code already cites them by these names before this file existed:
`app/ingestion/embedding/embedder.py:77`, `:236`, `:379`, `app/ingestion/embedding/__init__.py:20` and
`app/ingestion/indexing/upserter.py:98`, `:140` all say "finding C2". Renumbering them into the O
sequence would break every one of those references, and the O list's own rule is that it is appended
to, never inserted into.

**None of the three is decided here.** Each states the problem, the options, and a recommendation, and
each is Ankur's to rule on. Where the skeleton already implements a shape, that is recorded as *what
landed* — implementing the recommended shape early is not the same as the decision having been taken,
and the code says so too.

> **That paragraph is now history for two and a half of the three, and the resolutions are
> [ADR-031…035](#the-decisions-adr-030s-consequences-forced--adr-031035).** The original text of each
> finding is left unedited below a resolution block, in the O1/O18/O21 pattern. In one line each:
>
> | | Status | Resolved by | Still open |
> |---|---|---|---|
> | **C1**, embedding half *(the half the code cites; see the note under C1)* | **closed** — ADR-031 | `app/providers/embedding_selection.py`, `app/api/internal/v1/embedding.py`, migration `…000500` | nothing |
> | **C1**, rerank half | **open** | — | the `docs/14` §19.6 bot-policy gate; where the capability is resolved |
> | **C2** | **closed** — ADR-032, ADR-033, ADR-034 | `app/retrieval/sparse.py`, `app/retrieval/collection.py`, `app/db/writes.py`, migration `…000600` | `tokenize` is unimplemented — the arm produces nothing until segmentation is decided |
> | **C3** | mechanism **closed** — ADR-035 | `app/ingestion/embedding/embedder.py` | `CANARY_PRECISION` unmeasured; where the disagreement counter lives and what pages; **the drift runbook, which is `docs`'** |
>
> The one thing that did *not* survive contact: **"finding C2" is now cited from more places than the
> six listed above**, including `app/db/writes.py:73` and `app/retrieval/collection.py:52`. The naming
> rule held; the count did not, and no attempt is made to keep it current.

### C1 — reranking is no longer universally available

> **C1 HAS TWO HALVES, AND ONLY ONE OF THEM IS WRITTEN BELOW. Read this before following a code
> comment here.** The text under this heading is the **rerank** half — the half ADR-030 was thinking
> about when the finding was written. The data plane cites *"finding C1"* by name for a **different**
> question: *which of an organization's provider connections supplies the embedding credential* —
> `services/ai-service/app/providers/embedding_selection.py:3-4`,
> `services/ai-service/app/api/internal/v1/embedding.py:3`, and
> `services/core-api/database/migrations/2026_08_07_000500_add_embedding_designation_to_organizations_table.php:9`.
>
> **That is not a mislabel to be corrected; it is the same finding one level up.** ADR-030 made *every*
> ML capability a property of the organization's provider catalogue, so "the platform requires a
> capability its tenant's providers may not have" is the finding, and reranking and embedding are its
> two instances. They differ in exactly one way, and it is the one that matters: **a missing reranker
> is a cheaper mode of operation; a missing embedder means the organization cannot ingest a single
> document.** Every chunk is embedded before it is indexed and there is no fused-order-instead
> fallback.
>
> The heading is left alone deliberately — it is linked from `docs/00-index.md` and from this file, and
> the C-namespace exists precisely because code cites it by name. Renaming it to fix a label would break
> both.
>
> * **The embedding half is CLOSED, as [ADR-031](19-repo-structure-adrs.md#adr-031-the-organization-designates-the-embedding-connection-disagreement-is-a-refusal).**
>   Argument, rejected options and revisit condition at
>   [§ ADR-031](#adr-031--the-organization-designates-the-embedding-connection-and-disagreement-is-a-refusal).
> * **The rerank half is still open, in the two places recorded below**, and `app/rag/rerank.py` was
>   re-read rather than assumed. Item 1, the `docs/14` §19.6 **bot-policy gate**, is untouched:
>   `rerank_gate` decides *whether stage 11 runs* and has no branch in which a bot **refuses to answer**
>   rather than serve fused order. Item 2, **where the capability is resolved**, has moved without
>   closing — `calibration_for` raises `RerankNotCalibrated` at configuration time, which is the
>   placement the finding argues for, but `rerank_gate` still takes `provider_supports` as a parameter,
>   so nothing yet says whether a capability gap is caught when a connection is saved or per query.
>
> Original text below, unedited.

**Status: open. Recommendation stated, not decided. The recommended shape is already in the tree.**

The cross-encoder was local, so it always worked, and `docs/07` §12.11 could write stage 11 as
unconditional: *"the fused candidates **will be** reranked"*. That is now false. Of the five configured
providers, **NVIDIA NIM is the only one that exposes a ranking endpoint**; OpenRouter reaches one only
through specific models; OpenAI, Anthropic and DeepSeek do not offer one at all.
`services/ai-service/app/providers/contract.py` states the same count structurally — *"only two of the
five vendors can embed and only two can rerank"* — and enforces it the one way a type checker can
prove: `RerankAdapter` is a **separate Protocol**, and the gate is the **absence of the method**, not a
boolean flag a well-meaning edit can set to `True`.

So `rerank` must be an **optional, capability-gated stage**, not a mandatory one.

**Recommended default:** when the organization's provider cannot rerank, serve the RRF-fused order
directly and **record on the trace that reranking was skipped**. Recorded, never silent: an unrecorded
skip means every answer-quality investigation starts by trying to determine which pipeline actually ran.

**Explicitly not recommended: a silent LLM-scoring fallback.** Scoring passages by asking a chat model
is a legitimate *configuration* and an illegitimate *fallback*. It changes cost and latency by an order
of magnitude, on the request path, in whatever volume the traffic happens to be, and a fallback nobody
chose does that invisibly. If it is ever wanted it is switched on deliberately, per bot, and it is then
**the configured reranker** rather than a substitute for one.

**What landed already**, in `app/rag/rerank.py`, and it is worth reading before ruling because it
answers half the question by construction:

- `RerankSkipReason` is a **closed enum containing only pre-call reasons** —
  `PROVIDER_LACKS_CAPABILITY`, `PROVIDER_SCALE_UNCALIBRATED`, `MODEL_NOT_CONFIGURED`,
  `DISABLED_BY_CONFIGURATION`, `INSUFFICIENT_DEADLINE`. There is deliberately **no member meaning
  "the provider errored" or "the call timed out"**: those are errors in `kb-error-taxonomy` terms and
  propagate as errors. A skip degrades ranking visibly; an outage laundered into a skip degrades
  ranking *and* hides an incident, and the only symptom of the second is answer quality drifting for
  as long as nobody looks.
  - **The fifth member landed later, and its absence had been an inaccuracy rather than a gap.**
    `PROVIDER_SCALE_UNCALIBRATED` is finding #47's third eligibility axis reaching the metric: the
    vendor publishes a ranking route and *this platform* cannot cut against its scores. Until it
    existed, `can_rerank` answered `False` on that axis and `rerank_gate` reported
    `PROVIDER_LACKS_CAPABILITY` for OpenRouter — a false statement about a vendor that documents
    `POST /api/v1/rerank`, and one whose remedy (move to a provider that ranks) is the opposite of
    the true one (run the bot without reranking; no evaluation run can fill a per-provider cell for
    a per-upstream quantity). `rerank_gate` therefore takes the three eligibility axes separately —
    `provider_supports` (the AND, from `can_rerank`), `provider_publishes_endpoint` and
    `provider_scale` — because one boolean cannot say *why* it is `False`.
- The outcome type refuses to be both: a skipped rerank carrying scores, or an applied rerank carrying
  no calibration, raises at construction. Downstream code cannot read a fused ordering as a reranked
  one.
- `CALIBRATIONS` is **empty on purpose**. The old `evidence.min_score = 0.30` was a property of
  `bge-reranker-v2-m3` under `normalize=True`; NVIDIA's ranking models return an **unbounded logit**
  and Cohere-shaped APIs return a bounded relevance score. `0.30` is a valid float on every one of
  those scales and nothing raises when they are swapped — only the refusal rate moves, and only in
  aggregate. `RerankScale` therefore makes the scale a first-class field and **only `PROBABILITY` may
  be thresholded**; `LOGIT` and `UNCALIBRATED` may be used for **ordering only**.

**What is genuinely still open, and it is not "should we skip":**

1. **Does `docs/14` §19.6's bot-policy gate still apply?** The spec says a bot may serve fused
   retrieval *"only when the bot policy permits it"* — written for a transient outage. Under ADR-030,
   no-rerank is the **steady state** for three of five providers, so a bot whose policy forbids it
   would refuse to answer permanently. Options: (a) the gate applies only to a *failed* rerank and a
   capability gap is not a policy event; (b) the gate applies to both, and a bot on a non-reranking
   provider must be configured for it or is a configuration error at save time; (c) the gate is
   dropped. **Recommendation: (a)**, because it preserves the spec's intent — the policy exists to let
   a strict bot refuse rather than degrade during an incident — without turning a provider's product
   catalogue into a permanent refusal.
2. **Where the capability is resolved.** A bot's rerank model lives in the control plane
   (`docs/02` §8.6), but only the data plane knows whether the adapter defines the method. A
   capability gap discovered at request time is a per-query skip; discovered at connection-save time it
   is an error someone can act on. `RerankNotCalibrated` already raises at configuration time, which
   argues for the same placement.

**The two skills that described reranking as unconditional have been brought into line by their owners
while this was being written, and were checked rather than assumed.** `kb-rag-query-contract/SKILL.md`
now marks stage 11 **(capability-gated)**, gives it a closed `RerankSkipReason` plus a degraded marker
as its trace output, carries the "answer quality degrades for weeks on one tenant and nothing alerts"
gotcha, and has a Definition-of-done line requiring a traced skip with a reason — *"never a silent
pass-through, never a threshold on the fused score, and never a skip standing in for an error."*
`bge-reranker/SKILL.md` is now the record of why the cross-encoder is not loaded. **`docs/` did not
edit either file**, and the only reason this paragraph is not a staleness report is that the check was
run against the files rather than written from memory.

What that leaves genuinely stale is **spec text, which is never edited**: `docs/07` §12.11's *"the
fused candidates will be reranked with a cross-encoder reranker"* is superseded by this ADR and listed
above. Its four `UNVERIFIED` markers in `docs/23` still measure a local model's latency on hardware
this project no longer requires; that sweep needs regenerating, and `docs/23` says so.

**Owner:** `retrieval-engineer` for the stage, the taxonomy for nothing (a skip is not an error).
**Blocks:** any evidence-threshold tuning, because there is no calibrated scale to tune on.

### C2 — hybrid search loses its sparse arm *(RESOLVED — ADR-032, ADR-033, ADR-034; one part open)*

> **Resolved as option (b), local BM25.** Three ADRs, because the resolution turned out to be three
> decisions with three different costs:
> [ADR-032](19-repo-structure-adrs.md#adr-032-restore-the-sparse-arm-with-local-bm25-corpus-statistics-ride-the-query-vector)
> (BM25, and where the corpus statistics live),
> [ADR-033](19-repo-structure-adrs.md#adr-033-the-write-allow-list-is-allowed_tables-and-it-now-holds-six-names)
> (the two statistics tables, which supersede ADR-012's table list), and
> [ADR-034](19-repo-structure-adrs.md#adr-034-sparse_analyzer-is-part-of-the-embedding-space-so-an-analyzer-change-is-a-reindex)
> (the analyzer joins the collection name). The arguments are at
> [§ ADR-032](#adr-032--restore-the-sparse-arm-with-local-bm25-and-keep-the-corpus-statistics-on-the-query-vector)
> and the two sections after it.
>
> **Closed by:** `services/ai-service/app/retrieval/sparse.py` (the whole arithmetic),
> `services/ai-service/app/retrieval/collection.py` (`SPARSE_ANALYZER_VERSION`, `SPARSE_MODIFIER`,
> `EmbeddingSpace.sparse_analyzer`, `assert_analyzer`),
> `services/ai-service/app/db/writes.py:68` (`ALLOWED_TABLES` — read the tuple; ADR-033 forbids
> restating its length, and ADR-036 generalizes that), and
> `services/core-api/database/migrations/2026_08_07_000600_create_sparse_corpus_statistics_tables.php`.
>
> **The finding's own trap was answered rather than stepped in.** The text below warns that *"if BM25
> lands, turn IDF on"* is wrong as stated, and lists three options. The one taken is the second —
> compute IDF ourselves, per organization, on the **query** vector, leaving `SPARSE_MODIFIER` at
> `None` — so ADR-021's Qdrant pin is not re-opened and no cross-tenant statistic is ever computed.
>
> **Still open, and it is the whole producer:** `sparse.tokenize` is `NotImplementedError`. Until a
> segmentation and normalization decision is made per script, the lexical arm produces nothing —
> `encode_passage` and `encode_query` both raise on their first line. The trap is recorded in the
> function itself: whitespace segmentation makes an entire CJK sentence one term, and a branch that
> matches nothing is indistinguishable from a corpus that contains nothing. Under ADR-034 that decision
> must be made **before the first large ingest**, because changing it afterwards re-embeds the dense
> vectors too, at a provider's per-token price.
>
> Also unclosed and smaller: `BM25_K1`, `BM25_B`, `BM25_AVGDL` and `MAX_QUERY_TERMS` are defaults
> awaiting an evaluation run, and each is an input to the analyzer identity — see `docs/23`.
>
> Original text below, unedited.

**Status: open. Two live answers. Not decided.**

`bge-m3` produced dense **and** learned-sparse vectors from a single local forward pass, which is
precisely what made `qdrant-hybrid-search`'s dense + sparse + RRF design work at no extra cost.
**API embedding endpoints return dense vectors only.** So one of two things has to be true, and neither
is free:

- **(a) Drop the sparse arm.** Retrieval becomes dense-only, and RRF has nothing to fuse — a fusion
  over one list is that list. What is lost is exactly what `docs/07` §12.8 says sparse is *for*: exact
  terms, product codes, error codes, part numbers, surnames, API symbols. `app/retrieval/search.py:44`
  records the compounded version of this: with reranking now also optional, a dense-only run without a
  reranker is **cosine similarity and nothing else**.
- **(b) Produce sparse vectors locally with BM25.** **BM25 is a statistical ranking function, not a
  machine-learning model** — no weights, no training, no inference — so computing it locally does not
  violate ADR-030 and is the obvious candidate. It costs a term-statistics store, a tokenizer decision
  per language, and an IDF question that is not cosmetic (below).

**Recommendation: (b), with the IDF question answered before it lands, not after.** But it is a real
build and it is Ankur's ruling.

**The trap in (b), and it is the one worth writing down.** `SPARSE_MODIFIER` in
`app/retrieval/collection.py` is `None`, and **the tree gives two reasons where the brief gives one**:

1. The original reason, which does die with BGE-M3: IDF is for BM25-style vectors carrying raw term
   frequencies, and BGE-M3's lexical weights were *already* learned term importance, so the modifier
   double-counted rarity. Raw term frequencies are exactly what IDF is for, so this reason inverts
   under BM25 and the setting must be revisited.
2. **A second reason that survives BGE-M3's removal entirely, and it is a tenancy one.** With the
   modifier on, Qdrant computes document frequencies **collection-wide, across every tenant**. That is
   both a relevance error — one organization's corpus skewing another's ranking — and a weak
   cross-tenant statistical oracle: a term's score reveals how common it is in documents the caller
   cannot read. Server **1.19.0** adds `SearchParams(idf=models.IdfCorpusParams(corpus=<org filter>))`
   to scope it per tenant; **we are pinned to 1.18.3** (ADR-021), where that parameter does not exist.

So "if BM25 lands, turn IDF on" is **wrong as stated**. The three real options are: move the server to
≥ 1.19.0 and carry the org-scoped corpus parameter on every sparse query (which re-opens ADR-021's pin
against a `qdrant-client` version); compute IDF ourselves per organization and apply it to the *query*
vector, leaving `SPARSE_MODIFIER` at `None`; or accept collection-wide IDF, which is a tenancy
regression and should not be accepted.

**What landed already:** the `sparse` named vector stays **declared and unfed**. Adding a named vector
to a populated collection is a re-index, and declaring one that no point carries costs nothing — so the
cheap option is held open deliberately. `to_sparse_vector` is retained unimplemented for the same
reason: local BM25 term weights would arrive in exactly its shape. `ChunkVectors.sparse` is `None`-able
**pending this finding**, and `app/ingestion/indexing/upserter.py:140` records that its sparse
assertion is *suspended, not deleted* — because an empty sparse vector is accepted by Qdrant, matches
nothing forever, and silently halves the hybrid branch for that chunk.

**If (a) wins**, the removal is not one module's: the vector comes out of the collection config
(a re-index), `to_sparse_vector` and the `ChunkVectors.sparse` branch go, `docs/07` §12.8–12.10's trace
fields lose their sparse score and rank, and `docs/15`'s sparse-latency metric leaves the catalog —
which is a metric-catalog PR *before* the code, per `CONTRIBUTING.md`.

**Owner:** `retrieval-engineer` with `ingestion-engineer`. **Blocks:** the upserter's sparse assertion,
and any recall claim about exact-term queries.

### C3 — embedding-model identity is a vendor alias, and an alias can move *(RESOLVED as a mechanism — ADR-035; three policy items open)*

> **The mechanism is now a decision rather than an implementation:
> [ADR-035](19-repo-structure-adrs.md#adr-035-embedding-model-identity-is-measured-by-a-fixed-probe-and-the-digest-never-names-a-collection).**
> Argument, rejected alternatives and revisit condition at
> [§ ADR-035](#adr-035--embedding-model-identity-is-measured-by-a-fixed-probe-and-the-digest-never-names-a-collection).
> **Closed by:** `services/ai-service/app/ingestion/embedding/embedder.py` — `CANARY_TEXTS`,
> `CANARY_INPUT_TYPE`, `canary_digest`, `resolve_identity`, `CanaryVerdict`, `classify_canary`,
> `enforce_canary` — with `EmbeddingSpace` and `assert_dimensions` in
> `services/ai-service/app/retrieval/collection.py`.
>
> **What ADR-035 settles that the text below left as description:** that the digest is measured rather
> than declared and stays *out* of the collection name; that `DIFFERENT_SPACE` is decided before the
> digest is compared; that two consecutive disagreements are required; that nothing is ever repaired
> automatically; and three details the finding did not have — the probe is taken as `PASSAGE` because
> vendors embed the two sides of a retrieval pair differently, the digest is formatted fixed-point
> rather than `round()`-ed, and negative zero is folded onto positive zero. Each of those three is a
> false-positive source that would have re-versioned a whole corpus for nothing.
>
> **The three policy items below are still open, and item 2 has moved rather than closed.**
> `embedder.py` now states that the last-known-good digest *is* the most recently activated source
> version's `embedding_model_version`, and that a second store for it would be a second truth — so
> "where the digest lives" is answered. What is **not** answered is where the *consecutive-disagreement
> counter* lives (`classify_canary` takes it as an argument and says explicitly that a counter it kept
> would reset on every worker restart, which is the single most likely event during a provider
> incident), who runs the comparison, and what pages. Items 1 and 3 are untouched: `CANARY_PRECISION`
> has still never been measured against a live provider, and **the drift runbook still does not
> exist** — it is `docs`', it is the thing that decides whether a tenant's bot answers from a
> half-migrated corpus, and it needs items 1 and 2 answered before it can say anything true.
>
> Original text below, unedited.

**Status: open as a *policy* question; the mechanism has landed. Recorded because the mechanism is
worth understanding before anyone rules on the policy.**

Two facts collide.

**First: the vector width is now provider- and model-dependent** — 1024 for bge-m3, **3072** for
`text-embedding-3-large` — and a Qdrant collection is created at a fixed width and distance. Nothing
rewrites that in place. So **changing embedding model is a reindex, not a config edit**, and the same
model at two widths is two spaces, because several APIs take `dimensions` as a *request* parameter
(Matryoshka truncation) and the model id therefore does not imply the width.

**Second, and worse: a vendor model id is an *alias*.** `text-embedding-3-large` has no dated snapshot
to pin to. If a vendor re-trains or re-quantizes behind that name, every vector already in Qdrant is
from a different space than every vector written afterwards — and **cosine distance is defined between
any two vectors of equal width**, so nothing raises, no total moves, no metric changes, and there is no
diff in this repository. The only symptom is ranking that quietly degrades for the older half of the
corpus, forever. Embedding-model identity used to be pinned by commit sha in `models.manifest.toml`;
it now has to live with **source-version identity**, which is why `embedding_model_version` on the
source version and `embedding_model_id` on the chunk kept their names and changed their meaning rather
than a new column being added — a partially-populated chunk schema is the one defect that breaks
citation and deletion at the same time.

**This is load-bearing for ADR-010 and for atomic publication.** ADR-010 says Qdrant is rebuildable
from PostgreSQL and object storage. A rebuild re-embeds; if the alias has moved since the original
ingest, the rebuild produces a *different index* that passes every count and checksum check ADR-010
specifies — the point count matches, every payload key maps to a column, and the ranking is different.
That is Gotcha 5 in `kb-architecture-map` arriving through a door that skill does not have. And
atomic publication depends on the old space continuing to serve every query until the new one is fully
indexed, which is only possible because **the collection name encodes the space** and two spaces
co-exist.

**The implemented mitigation, described accurately** — `app/ingestion/embedding/embedder.py` and
`app/retrieval/collection.py`:

- **Identity is measured, not declared.** `EmbeddingModelIdentity` is two parts: an `EmbeddingSpace`
  (provider, model, width, distance, schema version) and a `canary_digest`.
- **`EmbeddingSpace` is what names the collection**, through a readable prefix plus a blake2b digest
  over every field — because slugging is lossy, and `embed-v1.5` and `embed_v1_5` collapsing to one
  prefix would put two spaces in one collection, which is the single failure the module is shaped to
  prevent.
- **`canary_digest` is a sha256 over the provider's actual output for `CANARY_TEXTS`** — five short,
  dull, in-repo ASCII strings owned by nobody, so the probe is never customer text. Five rather than
  one because one string is one point on a manifold and a re-quantization can leave a single vector
  unmoved. It is **the only available detector of a silent weight swap**, since the served-model string
  is just the alias echoed back.
- **The digest is deliberately kept *out* of the space.** This is the load-bearing half. If the digest
  were in the collection name, a vendor blip would silently spawn a second collection, the corpus would
  divide itself between two of them, and the automatic repair would look exactly like a healthy
  bootstrap. Instead the space names the collection and the digest tells us the space's claim stopped
  being true.
- **A digest change is never repaired automatically.** `CANARY_CONFIRMATIONS = 2` consecutive
  disagreements is a reindex *conversation*, not a re-embed. Writing new-space vectors into the
  existing collection is precisely the failure above.
- `IDENTITY_SCHEME = "emb/v1"` exists so the *composition* of the identity string can be corrected
  without the correction being mistaken for drift, and `assert_dimensions` guards width on both the
  write path and the query path.

**What is still open:**

1. **`CANARY_PRECISION = 4` has never been measured against a live provider.** Embedding APIs are not
   bit-reproducible across their own fleet — different accelerators, batch shapes and kernel versions
   move the last bits — so the rounding is what stops a vendor's own deploy from re-versioning our
   whole corpus. Four places is a judgement, not a measurement. Recorded in `docs/23`; **if the digest
   flaps, the digest belongs in telemetry only and must come out of the ingest key.**
2. **Where the recorded digest lives and who compares it.** The mechanism describes detection; nothing
   yet says which table holds the last-known-good digest, whether the maintenance sweep or the worker
   owns the comparison, or what pages.
3. **What an operator actually does on a confirmed drift.** "A full reindex into a new collection" is
   the shape; the runbook does not exist, and it is the runbook — not the enum — that decides whether
   a tenant's bot answers from a half-migrated corpus in the meantime.

**Owner:** `ingestion-engineer` with `retrieval-engineer`; item 3 is a runbook and is `docs`'.
**Blocks:** any claim that the ADR-010 rebuild drill proves rebuildability, because it currently
proves it only for a corpus embedded under an alias that has not moved.

## The decisions ADR-030's consequences forced — ADR-031…035

ADR-011…018 answered questions the specification *posed*. ADR-019…028 answered questions it never
asked, because a file had to exist. ADR-029 settled a divergence between two handlers already written.
ADR-030 came from outside the specification entirely. **These five are a fifth category: decisions
nobody chose to take.** ADR-030 was accepted with C1, C2 and C3 explicitly left open — and then code
that had to exist could not be written without answering them. Every one of the five was settled by an
implementer standing in front of a function signature, not by anyone deciding it was time.

That origin is worth naming because it sets the standard of proof for reopening one. These are not
provisional. Each is already load-bearing in a module that cites it, and three of them — ADR-032's
statistics placement, ADR-034's analyzer identity, ADR-035's digest placement — have a **reindex** as
their cost of being wrong, which for a corpus embedded through a metered API is a bill rather than a
maintenance window.

Summaries and the pointer entries are in [`docs/19-repo-structure-adrs.md`](19-repo-structure-adrs.md)
§28. The argument is here.

**One of them supersedes.** ADR-033 replaces ADR-012's table list — the first supersession in this
register. ADR-012's rule, its reasoning and its constraints are untouched, and its entry in `docs/19`
carries the pointer rather than being edited.

### ADR-031 — the organization designates the embedding connection, and disagreement is a refusal

**Status: `Accepted`.** Closes the embedding half of C1. Supersedes nothing.

The specification never asked this question because under §9.12 there was nothing to ask: one local
model embedded everything, for every tenant, with no credential and no vendor. ADR-030 replaced it with
"the organization's configured embedding provider" and left the definite article undefined. The
consequence is not a degraded mode. `capabilities.PROVIDER_TASKS` records exactly **one** vendor —
OpenAI — as `SUPPORTED` on the embedding surface, so an organization whose only connection is Anthropic,
DeepSeek, NIM or OpenRouter has a chat provider and **no embedding provider**, and therefore cannot
ingest a single document. Before `app/providers/embedding_selection.py` that failure had no name, no
selection rule and no save-time check: the first upload would parse, OCR, chunk, and then fail on an
`AttributeError` somewhere below `app/ingestion/`, after the spend.

**The decision, in the order the rule runs** (`resolve_embedding_connection`,
`services/ai-service/app/providers/embedding_selection.py:542`):

1. **An explicit designation wins and is never substituted.** It is a `(connection_id, model)` **pair**,
   not a bare connection, because one credential legitimately carries several embedding rows —
   `text-embedding-3-large` and `text-embedding-3-small` are two vector spaces on one key, so naming the
   connection alone leaves the space undecided.
2. **Ineligible candidates are dropped with a recorded reason.** `EmbeddingIneligibility` is closed at
   three members and every one is **pre-call** — the vendor axis, the row's capability flag, and row
   coherence. There is deliberately no member meaning "the provider errored", for the same reason
   `RerankSkipReason` has none: a vendor outage recorded as a configuration state tells an organization
   to fix a connection that was never broken.
3. **No eligible candidate is a named, hard refusal**, carrying every connection examined, why each was
   rejected, and which vendors do publish the endpoint.
4. **Eligible candidates that disagree on `(provider, model)` are also a hard refusal.**
5. **Otherwise all eligible candidates name one space**, and the smallest `order_key` wins.

**Step 4 is the decision; everything else is bookkeeping.** That pair *is* the vector space —
`EmbeddingSpace` derives the Qdrant collection name from it — so a tiebreak by convention (first saved,
richest capability row, alphabetical vendor) would let an unrelated connection edit move the space under
an already-indexed corpus. Cosine distance is defined between any two vectors of equal width, so the
symptom is not an error: it is plausible neighbours that are simply wrong, no metric movement, and no
diff in this repository. Step 5 looks like the same kind of arbitrary rule and is not, which is why the
two steps are separated rather than collapsed: step 5 chooses **which credential pays** among
connections that already agree on the space, and paying from the wrong one of two keys to the same model
is a billing question, not a correctness one.

**The alternatives, and what each costs:**

- **Per-bot designation** — ruled out on the contract rather than on taste. `EmbeddingRequest` carries
  no `bot_id`, and cannot: a source belongs to the organization and has not been assigned to any bot
  when its chunks are embedded (`bot_source_assignments` is written later and is many-to-many). A
  per-bot model would have to be resolved from a bot that does not exist yet at embed time, and when a
  source is later assigned to two bots with different designations its already-written chunks sit in one
  space while one of the two bots queries another. The one thing per-bot would buy — two bots in one
  organization on different embedding models — **is not a feature, it is two corpora.**
- **Derive it from the connection set alone, with a convention tiebreak** — step 4's failure, adopted
  deliberately.
- **Laravel computing the answer locally.** Two halves of the rule are unavailable on that side. The
  vendor axis is repository-level data with a source per cell and lives in the data plane; and the
  question that actually decides ingestion is not "is one eligible" but "do the eligible ones agree",
  which a looser local check cannot ask. A looser check passes an ambiguous configuration at save time
  and fails it at the first upload — the late discovery this finding is about. So the control plane
  **asks** (`POST /internal/v1/embedding/readiness`) rather than re-deriving, and the handler is a
  verbatim call to the same function the indexer uses.
- **A platform-default embedding credential** — refused, and not on cost. A platform-funded key sends
  tenant chunk text to a vendor the organization never authorised. That is a privacy boundary, not a
  convenience. If it is ever offered it is opt-in per organization, audited, and has an ADR in front of
  it. The migration deliberately adds no column for it.
- **The error class.** `validation` (422, not retryable, not fallback-eligible), because this is a
  configuration state and the next identical attempt fails identically. `provider_permanent_request`
  (502) renders a vendor fault and pages an operator for a tenant that has not finished configuring
  itself. `tenant_quota` (403) claims something is exhausted. `internal_dependency` (503) is wrong under
  **both** ADR-029 origins, and the class name alone does not say which: `DOWNSTREAM` tells a client to
  retry a configuration that can never succeed, and `SELF` blames our own code for a row the tenant
  created. A nineteenth class was not considered seriously — the condition is expressible, and the
  taxonomy stays at 18.

**Two design properties that are decisions, not implementation detail.** *"Not ready" is a 200*: a
well-formed body describing an organization that cannot embed is a **successful computation whose result
is "no"**, and only a malformed body is a 422. A readiness endpoint that errors whenever the answer is
"not ready" forces the control plane to catch an exception in order to draw a banner, and the reflexive
fix for *that* is a second, looser local rule — the exact defect this closes. And *the readiness verdict
carries no credential and cannot*: both modules assert at import that no field name on any of their
models looks like a secret, so the well-meaning edit that attaches the decrypted key "so the caller does
not have to look it up twice" is a startup failure rather than a plaintext value in a log line
(CLAUDE.md non-negotiable 9, ADR-011).

**Where the designation is stored, and the three constraints that are the substance of it** —
`services/core-api/database/migrations/2026_08_07_000500_add_embedding_designation_to_organizations_table.php`:

1. **A composite foreign key** `(id, embedding_connection_id) -> provider_connections (organization_id,
   id)`. This is tenancy enforced in the database, and the consequence it prevents is not a leak in the
   usual direction — a plain FK to `provider_connections(id)` would let one organization designate
   another's connection, which sends **this** tenant's chunk text to **that** tenant's provider account,
   billed to them and readable in their dashboard.
2. **`CHECK num_nonnulls(embedding_connection_id, embedding_model) <> 1`** — both columns or neither. A
   connection with no model names no space; a model with no connection names no credential. Either half
   alone is discovered at the first upload.
3. **`ON DELETE RESTRICT`** rather than `SET NULL`. `SET NULL` would silently return the organization to
   "resolve by rule", and if a second embedding-capable connection existed the rule could then pick a
   different `(provider, model)` than the corpus was indexed under. The operator undesignates first, and
   sees what that means.

**Consequences, including the ones that hurt:**

- **Adding a connection can break ingestion.** An organization with one embedding-capable connection
  that adds a second on a different model stops being able to ingest until someone designates one.
  A working configuration is broken by an unrelated addition, and the error message is the only place
  that explains why. Accepted, because the alternative is silently indexing into a different space.
- **The refusal is only as good as the capability matrix.** Four of the five embedding cells are
  `UNVERIFIED`, which fails closed — correct as a default and wrong as a fact if a vendor does publish
  an endpoint. NIM is the likely case: `bge-reranker`'s own official-docs entry is titled *"NVIDIA NIM
  retrieval / ranking models"* and *retrieval* is NVIDIA's word for its embedding family, but no skill
  states it and a link title is not enough to write an adapter method against. So an organization can be
  refused ingestion **for a missing fixture**. Recorded in `docs/23`, and it is the cheapest thing on
  this list to close.

  > **That consequence closed on 2026-08-10 and the bullet is left standing rather than rewritten,
  > because the prediction inside it was right and is the reason anyone went and read four
  > documentation pages.** Measured 2026-08-11: `/usr/bin/grep -c UNVERIFIED
  > services/ai-service/app/providers/capabilities.py` → **10**, and **not one of them is a cell** —
  > every occurrence is prose, the `Support.UNVERIFIED` member's own docstring, or the `_UNKNOWN`
  > fallback returned for a provider name that is not one of the five. All fifteen
  > `PROVIDER_TASKS` cells are `SUPPORTED` or `UNSUPPORTED` with a `source=` naming a page that was
  > read (`capabilities.py:46`), and `TaskSupport.__post_init__` now **refuses** a cited `UNVERIFIED`
  > cell outright, so the state this bullet describes is no longer merely absent — it is
  > unconstructible. NIM's embedding cell resolved `SUPPORTED`, which is the cheap direction. **The
  > corresponding sentence in [ADR-031's `docs/19` entry](19-repo-structure-adrs.md#adr-031-the-organization-designates-the-embedding-connection-disagreement-is-a-refusal)
  > § *Trade-off* — "four of the five embedding cells are `UNVERIFIED` and fail closed" — is therefore
  > false against the tree.** It is an accepted ADR's trade-off and is not edited here; it is recorded
  > as [F11](#f11--three-adr-030-family-decision-records-describe-a-state-the-code-now-refuses-to-construct)
  > with the other two of its kind. The *rest* of the trade-off — that the refusal is only as good as
  > the matrix — is untouched and is still the honest statement.
- **The seam still cannot serve.** `verify_hmac` and `request_context` are `NotImplementedError` stubs,
  so every request to the readiness endpoint currently raises and renders 500. That is the correct
  direction to be broken in — an unverified caller is refused rather than served — but it means Laravel
  cannot ask the question yet.

**Revisit condition.** Either of: **a second vendor's embedding cell becomes `SUPPORTED`**, at which
point ambiguity stops being an exotic state and "designate one" starts firing on ordinary
configurations — the rule is still right but the UI burden changes; or **one organization legitimately
needs two embedding spaces at once** (a migration in flight, or two corpora with different languages),
which the organization column cannot express and which is the one shape per-bot designation would have
served.

### ADR-032 — restore the sparse arm with local BM25, and keep the corpus statistics on the query vector

**Status: `Accepted`.** Closes the substance of C2, minus the tokenizer. Narrows nothing; it restores
what ADR-030 removed by a different mechanism.

C2's option (b) is taken. BM25 has no weights, no training and no inference — it is arithmetic over term
statistics — so computing it locally does not touch ADR-030's rule, which CLAUDE.md already states as
*no local model inference for embedding or reranking, not no local computation*. Option **(a), dropping
the sparse arm**, was rejected on what it costs: dense-only retrieval leaves RRF with one list to fuse,
and with reranking now also capability-gated, a dense-only run without a reranker is **cosine similarity
and nothing else**. What it loses is exactly what `docs/07` §12.8 says sparse is for — exact terms,
product codes, error codes, part numbers, surnames, API symbols — the queries where a user knows the
literal string and the embedding does not care.

**The decision inside the decision is where the corpus statistics live**, and it is the one worth the
space.

BM25 needs two numbers per query term: the documents in scope (`N`) and how many contain the term
(`df`). Three places could hold them, and two are wrong:

- **Qdrant's own `modifier=IDF`** computes document frequencies **collection-wide, across every
  tenant**. That is a relevance error — one organization's corpus skewing another's ranking — and a weak
  cross-tenant statistical oracle, because a term's contribution to a score reveals how common it is in
  documents the caller cannot read. Server 1.19.0 adds
  `SearchParams(idf=IdfCorpusParams(corpus=<org filter>))` to scope it; **ADR-021 pins 1.18.3**, where
  the parameter does not exist. Moving the pin to reach it re-opens ADR-021 against a `qdrant-client`
  version, for a feature nobody here has run — see `docs/23`, where the 1.19 claim is still `S`-class.
- **Baked into the document vector.** This is the tempting one, because it is where the textbook puts
  it, and it is the one that would have been discovered late. A document vector carrying `N`, `df` or a
  live `avgdl` is a function of the corpus **at the moment it was written**: every subsequent ingest
  invalidates the ranking of everything already indexed, and an ADR-010 rebuild produces a *different*
  index that passes every count and checksum check the drill specifies. The point count matches, every
  payload key maps to a column, and only the ranking moved. That is non-negotiable 4 failing in the one
  way its own verification cannot see.
- **On the query vector, per request, under the caller's own scope.** Taken. `passage_weights` carries
  term saturation and length normalization only; `query_weights` carries the IDF factor, read from
  statistics scoped to `(org_id, allowed_version_ids)` — the identical scope the four mandatory payload
  filters express. Qdrant scores a sparse pair as a dot product over shared indices, so splitting the
  formula this way is **exact rather than an approximation**: the two halves multiply back to BM25 term
  for term.

Three properties fall out of that placement, and the third is what makes it the right answer rather than
merely the available one:

1. No cross-tenant statistic is ever computed, on the server we actually pin.
2. The collection stays rebuildable. The document-side vector is a pure function of *(chunk text,
   analyzer version, three pinned parameters)*, so a re-encode during an ADR-010 rebuild reproduces
   byte-identical `indices` and `values` — which lets a rebuild be verified by **comparison** instead of
   by trust.
3. A deletion or a disable takes effect on the statistics at the same instant it takes effect on
   retrieval, because both are scoped by the same resolved active-version set. Nothing is cached against
   the organization as a whole, so there is no window in which a deleted source still weights a query.

**`BM25_AVGDL` is a fixed constant for reason 2, not because measuring it is hard.** The textbook
`avgdl` is a live corpus average, which reintroduces exactly the corpus dependence the split removes.
Pinning it makes the length normalization approximate; that is defensible only because the chunker
already bounds chunk size, so the spread the normalization exists to correct is small. It is stated as
an approximation rather than presented as a value.

**The IDF form is Lucene's, not classical Robertson/Sparck-Jones**, and this is a one-line decision with
a ranking-inverting failure behind it. RSJ omits the outer `1 +` and **goes negative once a term appears
in more than half the documents in scope**. Under a dot-product scorer a negative weight means a
*matching* term subtracts from the score, so a document containing every query term can rank below one
containing none of them. In a tenant-scoped corpus — which is small, and where a handful of documents
about one product make "invoice" or "policy" majority terms — that is not a pathological case, it is
Tuesday.

**A statistics read that fails is an error, never a degradation.** The tempting fallback is uniform IDF,
a plain term-frequency dot product, and it must not exist: it produces a complete, plausible ranking in
which common words weigh as much as part numbers, forever, with nothing in the trace to say so. That is
the same shape as an outage laundered into a rerank skip, and worse here, because there is no capability
gap it could be honestly confused with. An organization whose scope holds no documents is a different
thing: the load succeeds and reports zero.

**Two states that must never be confused, both of which Qdrant accepts silently.** An *empty* sparse
vector matches nothing forever and halves the hybrid branch for that chunk with no error on either side.
So a chunk that analyzes to no terms raises `EmptySparsePassage` and must be written **without** a sparse
vector as a recorded decision, and a query that analyzes to no terms raises `EmptySparseQuery` and is a
**dense-only run that must be traced as one** — not a lexical branch that found nothing, which is a
strong signal, where "there was nothing to ask" is no signal at all.

**Consequences, including the ones that hurt:**

- **BM25 is not what was lost.** BGE-M3's lexical head was *learned* term importance with subword
  generalization; BM25 is exact-token matching over a hash. Term overlap across scripts is zero, so
  cross-lingual retrieval now rides entirely on the dense arm. The lexical arm is for exact tokens in
  the language they were written in, and it should not be described as a replacement for the old one.
- **A second write path and an extra read.** Two tables (ADR-033) written in the ingestion transaction,
  and one PostgreSQL read per query inside a 1.5 s leg that already contains provider round trips.
- **Term ids are a 32-bit hash**, because Qdrant's sparse indices are unsigned 32-bit. Collisions merge
  two terms' postings — a mild relevance error at realistic vocabulary sizes, never a tenancy one, since
  a collision cannot move a point across the payload filter. Unmeasured; recorded in `docs/23`.
- **The arm produces nothing yet.** `tokenize` is `NotImplementedError` and that is the open half of C2.

**Revisit condition.** Either: the pinned server moves to ≥ 1.19.0 **and** `IdfCorpusParams` is
confirmed to scope document frequencies by filter — at which point query-side IDF becomes a choice
rather than the only tenant-safe route, and the trade turns into one round trip against one PostgreSQL
read; or an evaluation run shows the measured chunk-length distribution sitting far from `BM25_AVGDL`,
which makes the fixed constant a measurable relevance cost rather than a rounding one. Note that either
change is an analyzer bump, so ADR-034's price applies.

### ADR-033 — the write allow-list is `ALLOWED_TABLES`, and the invariant is not the number

**Status: `Accepted`. Supersedes ADR-012's table list**, and only that. ADR-012's rule, its argument and
its two safety constraints stand exactly as written.

BM25 needs a document-frequency rollup, and that rollup is a **fifth and sixth table** in a service whose
allow-list ADR-012 closed at four. Something had to give, and the honest question is not "may we add
two" but "what was the four ever standing for".

**It was standing for three properties, and the count was only what made a violation visible in a
diff:**

1. The row is **derived and rebuildable** in the ADR-010 sense — a pure function of content already held
   in PostgreSQL and object storage, reproducible by a rebuild, authoritative for nothing.
2. **No public API path reads or writes it.** This is the one that actually bites: a data-plane write
   into a table Laravel serves lands beside Laravel's own writer with no policy, no audit row and no
   framework-applied tenant scope — and it fails nowhere. The row is simply there.
3. **Laravel owns the migration.** Schema is never defined in the data plane, in any form.

The two sparse tables satisfy all three. Every row in them is a pure function of the version's chunk text
under `SPARSE_ANALYZER_VERSION` — the same property, for the same reason, as the `chunks` rows they are
computed from; nothing in the public API touches either; and
`services/core-api/database/migrations/2026_08_07_000600_create_sparse_corpus_statistics_tables.php`
defines them. **They were admitted on that reasoning and not on "one more is fine"**, which is the
distinction this ADR exists to preserve.

**So the invariant is restated: the list is `ALLOWED_TABLES` in `services/ai-service/app/db/writes.py`,
and documentation names the three properties and points at the module.** No document restates the
membership or the count. "Exactly four" became wrong the moment a legitimate table was added, and
"exactly six" will become wrong the same way; a number written into seven files is seven things to
forget, and every copy that survives an addition is a document that now contradicts the code.

**Two names and not one, which is itself a decision.** The per-version document total is the IDF
numerator and is meaningless outside the scope its frequencies are summed over, so it is keyed
identically — `(organization_id, source_version_id, analyzer)` — rather than stored as a column on
`source_versions`. Which it could not be in any case: **`source_versions` does not exist in this
repository**, no migration creates it, and inventing it to hang a counter on would put a table
`kb-source-lifecycle` owns inside a change about BM25. The analyzer is in the key because term ids are
`blake2b(term)` under a fixed personalization, so two analyzers are two id spaces over the same text and
summing across them yields a number that is not a document frequency of anything, with nothing raised.

**The rejected alternatives:**

- **A Laravel callback**, as ADR-012 already rejected for chunks — and the arithmetic is worse here, not
  better. Chunks are one row each with a large body; the statistics are one row per `(version, term)`,
  which is tens of thousands per document, shipped through a PHP request body into a table Laravel never
  reads.
- **Keeping four by computing document frequencies from Qdrant at query time** — the collection-wide,
  cross-tenant statistic ADR-032 exists to avoid.
- **Keeping four by dropping the statistics and accepting uniform IDF** — the silent-ranking failure
  ADR-032 refuses.

**The consequence that hurts, and it is live.** The gate that enforces this asserts the *cardinality*:
`.github/workflows/gates.yml` runs `[ "$t" = "4" ] || flag "ALLOWED_TABLES has $t table(s)…"`. Against
the working tree that is now **red** — a required check failing on a change this ADR accepts. Updating it
to `= "6"` restores green and restores nothing else: the check would still be measuring the property that
just changed. It should assert **membership** against the three admission criteria's observable proxy —
every name in `ALLOWED_TABLES` has a Laravel migration creating it, and no name is written by
`services/core-api`'s HTTP layer — and keep a cardinality tripwire only as a "somebody added something"
signal. Owner: `platform-devops-engineer`, and it is E3's rule waiting to happen: a required check that
fails on correct state gets deleted rather than fixed.

**Revisit condition:** a proposed seventh name that satisfies all three properties and still feels
wrong. That is evidence the properties are incomplete — most likely missing a fourth about *volume* or
about who is on the other end of a rebuild — and the response is to write the missing property, not to
relitigate the count.

### ADR-034 — the sparse analyzer is part of the embedding space, so changing it is a reindex

**Status: `Accepted`.** A consequence of ADR-032 with a cost large enough to need its own number.

Under BGE-M3 the sparse vector needed no identity of its own: same model, same forward pass, so anything
that changed the lexical weights changed the dense vectors too and the space already covered it. Local
BM25 breaks that coupling. The analyzer is now an independent thing that can change on its own — a
tokenizer fix, a normalization change, a different term-id hash, a re-tuned `BM25_K1` — while the dense
side is untouched.

**The failure that forces the decision: two analyzers sharing one collection do not error.** Sparse
indices are unsigned integers and every integer is legal, so a term id from another analyzer matches no
posting and the query simply returns nothing from that branch. There is no width to disagree about, so
unlike a dense mismatch nothing is rejected at the wire and nothing is rejected at upsert either. The
observable result is that the older half of the corpus quietly loses its lexical recall while dense
retrieval keeps every panel populated and every metric green. It is the same class of silent, permanent
wrongness as two dense spaces sharing a name — narrower, and quieter.

**Decision:** `SPARSE_ANALYZER_VERSION` is a field of `EmbeddingSpace` and is folded into the blake2b
digest that names the collection. A bump therefore names a different collection, which makes the
migration the one this repository already knows how to do: a second collection, both live, the
active-version pointer moving once, exactly as atomic publication requires. `assert_analyzer` guards the
write path and the query path, at the same two points `assert_dimensions` guards width.

**The alternatives:**

- **An `analyzer` payload field, filtered at query time.** Keeps both encodings in one collection, costs
  a payload index and one more term on every query — and **fails open**: a query path that forgets the
  term gets a mixed result set with no error, which is the original failure with extra steps.
- **A collection-name suffix outside the digest.** Reproduces the lossy-slug collision the digest exists
  to close: `bm25/v1` and `bm25_v1` are one prefix.
- **A one-off in-place re-encode sweep** of every point's sparse vector. There is a window during it in
  which the corpus is half-migrated, and nothing can detect which half a given answer came from.
- **Nothing.** The status quo failure, above.

**The trade-off, stated because it is paid in the wrong currency.** The collection name covers *both*
vectors, so bumping the analyzer re-embeds the **dense** vectors too — and those are billed provider
calls now (ADR-030). A tokenizer decision revisited after the first large ingest is a corpus-wide
re-embed with a bill, not a configuration edit. That is the real reason `tokenize` being unimplemented
is urgent rather than merely incomplete: **settle the tokenizer before the first large ingest**, because
afterwards the cheapest correct fix has a price per token.

**Revisit condition:** Qdrant gaining the ability to re-encode one named vector of a collection in
place, or the sparse and dense branches being split into two collections. Either makes the analyzer's
identity independent of the embedding space's, at which point this coupling costs provider money for no
benefit and should be undone.

### ADR-035 — embedding-model identity is measured by a fixed probe, and the digest never names a collection

**Status: `Accepted`.** Turns C3's mechanism into a decision. Three policy items in C3 remain open and
are not settled here.

The problem C3 states: a vendor embedding model id is an **alias**, `text-embedding-3-large` has no dated
snapshot to pin to, and if the vendor re-trains or re-quantizes behind that name then every vector
already in Qdrant is from a different space than every vector written afterwards. Cosine distance is
defined between any two vectors of equal width, so nothing raises, no total moves, no metric changes, and
there is no diff in this repository. The only symptom is ranking that degrades for the older half of the
corpus, forever. Under a locally pinned model this was impossible by construction — a commit sha in a
manifest cannot change under a running worker.

**Decision, in the five parts that each rule something out:**

1. **Identity is measured, not declared.** `EmbeddingModelIdentity` is an `EmbeddingSpace` plus a
   `canary_digest` over the provider's actual output for `CANARY_TEXTS` — five short, dull, in-repo
   ASCII strings owned by nobody, so the probe is never customer text. Five rather than one, because one
   string is one point on a manifold and a re-quantization can leave a single vector unmoved. This is
   the **only** available detector: the served-model string is the alias echoed back.
2. **The digest is deliberately outside the space.** This is the load-bearing half. A digest inside the
   space would be inside the collection name, so a vendor blip would silently open a second collection,
   the corpus would divide itself between two of them, and the automatic repair would look exactly like
   a healthy bootstrap. Instead the space names the collection and the digest says the space's claim
   stopped being true.
3. **The probe is taken as `PASSAGE`, fixed forever.** Several vendors embed the same string differently
   depending on which side of the retrieval pair it is declared to be — NVIDIA's own schema warns that
   the wrong value causes large drops in retrieval accuracy. So the input type is a **fingerprint
   input**: probing as `QUERY` one day and `PASSAGE` the next moves every component of every probe and
   reads as a weight swap. `PASSAGE`, because that is what ingestion embeds, and a probe taken through a
   different code path than the corpus can agree while the corpus's own path has changed.
4. **The digest composition is specified exactly, because three of its details are false-positive
   sources.** Components are formatted **fixed-point** rather than `round()`-ed — `repr(round(x, 4))` is
   shortest-repr and emits `0.1` for one float and `0.09999999999999999` for its neighbour, so a
   "rounded" digest still moves on the last bits, which is the entire failure the rounding exists to
   absorb. **Negative zero is folded** onto positive zero, because a component sitting on zero otherwise
   flips the digest on fleet noise. **Non-finite components raise**, because a NaN formats to a
   perfectly stable `nan` and would give a reproducible fingerprint of garbage. The scheme, the
   precision, the probe index and the width are inside the hashed payload, so a reordered or re-widened
   response cannot collide with an unchanged one and a change to the composition is visible rather than
   mistaken for drift.
5. **Five verdicts, `DIFFERENT_SPACE` decided before the digest, and nothing repaired automatically.**
   Order matters: a reconfigured model changes the space *and* the digest, and reporting it as drift
   would page someone for a change an operator made five minutes earlier — while the reverse mistake,
   reporting genuine drift as a reconfiguration, is the corpus-splitting failure. One disagreement is a
   warning (`SUSPECTED_DRIFT`), because embedding fleets are not bit-reproducible and stopping ingestion
   platform-wide on a single probe hands a vendor an outage switch. Two consecutive disagreements
   (`CONFIRMED_DRIFT`) **raise** — `internal_dependency` / `DOWNSTREAM`, not retryable, since the next
   probe returns the same new vectors. Re-embedding into the existing collection, which is the obvious
   repair, is precisely the failure and is refused in the error message.

**Why detection, comparison and response are three separate functions.** `resolve_identity` measures
(one round trip, no memory), `classify_canary` compares (pure — no clock, no storage, no side effect, so
a worker and the maintenance sweep cannot reach different conclusions from the same facts), and
`enforce_canary` acts. None of them persists: the last-known-good digest is already carried by the most
recently activated source version's `embedding_model_version`, and a second store for it would be a
second truth.

**Consequences, including the ones that hurt:**

- **The digest is in the ingest key**, which is what makes a genuine drift re-version rather than dedupe
  against vectors from a space that no longer exists — and which also means a **false** positive is
  expensive: a flapping digest makes every resubmission a new source version.
- **The sensitivity is one unmeasured constant.** `CANARY_PRECISION = 4` has never been run against a
  live provider. Too fine and it flaps; too coarse and a real re-quantization lands inside the rounding
  and is never seen. This is the single largest known gap in the mechanism and it is cheap to close —
  the probe hourly for a day, per configured provider.
- **The probe is passage-side only**, so a vendor change affecting only query-side embedding is
  invisible to it. That asymmetry is accepted because the corpus is what cannot be re-created cheaply.
- **Detection is not response.** ADR-035 stops here on purpose; the counter's home, what pages, and the
  reindex runbook are C3's remaining open items.

**Revisit condition.** Either: **the digest flaps at precision 4** against any configured provider — and
the answer is then *not* a finer constant but removing the digest from the ingest key and keeping it in
telemetry only, with drift raised as an alert a human reads rather than an identity change a worker
acts on; or **a vendor publishes pinnable dated embedding snapshots**, which demotes the canary from
sole detector to cheap corroboration and makes the whole mechanism optional for that vendor.

## Decided during authoring — ratify as ADRs

| Decision | Rationale |
|---|---|
| **Rate-limit retries once, then falls back.** | §19.2 leaves `provider_rate_limit` retryable and §8.7 makes it fallback-eligible. Implemented literally, the full backoff ladder runs *then* fallback fires, blowing §23's 4-second first-token target. |
| **Internal service auth is HMAC-SHA256 with a `key_id` prefix.** | mTLS is the only option adding confidentiality, but for two services on one Compose network it means hand-rolling cert distribution, renewal, and reload-on-rotation with no mesh — making cert expiry the most likely incident class. A bearer token binds to nothing. HMAC gives per-request integrity and a bounded replay window, with rotation as an env-var change. Canonical string includes method and path (a body-only signature replays against any endpoint accepting the same body) **and every `X-KB-*` header — see spec defect 13, which is why**. Upgrade path is TLS *underneath*, not replacing the scheme. |
| **The active-version pointer is authoritative; timestamps are descriptive.** | §16.4 offers two competing representations of "which version is live" (`source_items.current_version_id` vs `source_versions.activated_at`/`retired_at`). A partial unique index enforces at most one active version per item; a boolean `is_active` lets two rows both claim it after a race. |
| **Configuration snapshot travels in the request body; only its version in the header.** | FastAPI never reads Laravel's tables and never caches config across requests, so a replayed job reproduces byte-identically and the playground can run a temporary override without mutating anything. |
| **Idempotency: `422` for same-key/different-body, `409` for a key still in flight.** | Follows the IETF `Idempotency-Key` draft rather than the looser convention of `409` for both. |
| **OCR engine: RapidOCR (PP-OCR via ONNX Runtime) default, Tesseract as the multi-script alternative.** | Accuracy on OmniDocBench's text track (PP-OCR 0.071 EN / 0.055 ZH edit distance vs Tesseract 0.096 / 0.551 — 10× better on Chinese), footprint (47 MB vs 195 MB for paddlepaddle, 502 MB for torch), and licence. Docling's own `OcrAutoOptions` resolves to the same engine on Linux CPU, so we agree with it — but by explicit pin, never by probe. |
| **PostgreSQL is pinned at 18.x.** | §9.6 says only "PostgreSQL". 18 is what the schema doctrine is written against, and three of its additions are load-bearing: low-lock `NOT NULL … NOT VALID` + `VALIDATE`, virtual generated columns as the default, and b-tree skip scan (which does *not* rescue a missing leading-`organization_id` index, because it enumerates distinct values of the skipped column). |
| **Identifiers stay ULID (`char(26) COLLATE "C"`), not UUIDv7.** | UUIDv7 is the better greenfield choice — native `uuidv7()` in 18, ~28 B vs ~44 B per index entry, `memcmp` comparison. We are not greenfield: ULID is already frozen into the internal headers, the Pydantic contracts, and Laravel's `HasUlids`. Storing 128 bits as `uuid` while shipping ULIDs on the wire creates a permanent dual representation across every log line and cross-service join, which is a worse bug class than 1.6× index bytes at our scale. `chunks.vector_point_id` is the one genuine `uuid`, because Qdrant point ids may only be u64 or UUID. `COLLATE "C"` is load-bearing: without it, text index ordering is a property of the ICU/glibc *version*, and a base-image bump can leave a b-tree whose order no longer matches its collation. |
| **No PostgreSQL RLS in production; RLS in the CI database only, as a tripwire.** | The usual `SET SESSION app.org_id` pattern leaks the *previous* tenant's value across PgBouncer transaction pooling, so RLS would authorize cross-tenant reads with a policy that reviews as correct. `SET LOCAL` is safe but only covers statements inside an explicit transaction, which neither Laravel's default request path nor a Celery task provides. Outside a transaction the GUC is NULL, the policy filters everything, and the reflexive fix (`COALESCE`, a permissive fallback) fails *open*. It would be an eighth layer replacing none of the seven. What we do adopt: RLS in CI against a non-owner role, where an unscoped query returns too few rows and fails the isolation test — a detector, never an authorization boundary. |
| **RBAC is hand-rolled; `spatie/laravel-permission` is declined.** | The package does scope roles per tenant, but through `PermissionRegistrar::setPermissionsTeamId()` — ambient process-global state, which is exactly the pooled-context failure mode already documented for queue workers and Octane, where the next occupant inherits the previous tenant's context. Its own docs require manually `unset()`-ing cached relations after every switch. We would carry that risk for a feature we do not have: the spec fixes the roles, so there is no per-tenant role CRUD to store. Reopen only if custom roles are sold. |
| **Two Valkey instances, not two logical databases.** | `maxmemory`, `maxmemory-policy`, `save`, and `appendonly` are all **server-level**; `SELECT n` changes none of them, so the queue-must-not-evict / cache-must-evict conflict is not resolvable by database index at all. `valkey-core` is `noeviction` with AOF; `valkey-cache` is `allkeys-lru` with persistence **entirely off**. The second half is an independent argument: `valkey-cache` is the only place tenant text lives in Valkey, and disabling RDB/AOF is the only *provable* way to keep cached answers out of a backup. Requires Valkey 9.1 — `DELIFEQ` (9.0) and database-level ACL (9.1) are what turn the split from a naming convention into an enforced boundary. |
| **Valkey keys are family-first: `{family}:{org_id}:…`.** | Valkey ACL key patterns match on prefixes, so family-first is what allows `ai-api` to be granted `~ans:*` without also being granted the queues, and it makes an org-wide purge a deterministic walk of known family prefixes instead of a `SCAN MATCH`, whose guarantee does not cover keys written mid-iteration. The single exception is the replay nonce, which is checked before any organization has been resolved. |
| **`EventSource` is never used; every client streams via `fetch()` + `getReader()`.** | Two independent disqualifications: it cannot set an `Authorization` header (only `withCredentials`), and it is GET-only while message submission is a POST with a body. The replacement also restores `AbortController` cancellation and avoids `Last-Event-ID` auto-reconnect, which the internal contract already forbids. React Native must use `expo/fetch`: Hermes' XHR-backed `fetch` exposes no `ReadableStream` and fails by delivering the whole answer at once rather than throwing — a silent degradation that reads as "streaming is just slow". |
| **The widget must be served from a separate registrable domain.** | A deployment constraint, not a preference. The Sanctum SPA guide's own advice — `'domain' => '.ourdomain.example'` — makes the admin session cookie cover `widget.ourdomain.example`, so a widget iframe embedded on a customer page would carry a real admin credential. Binds `traefik-routing` and `docker-compose-stack`. |
| **Horizon is adopted, with its dashboard gated on a platform operator.** | The dashboard renders every organization's job payloads side by side, so granting `viewHorizon` to a tenant administrator is a tenancy-isolation breach rather than a UX decision. Conditions: no Traefik label and no `edge` network, `ShouldBeEncrypted` on every tenant-bearing job so payloads render as ciphertext, and supervisor `timeout < retry_after` because the `auto` balancer force-kills "hanging" workers at that timeout during scale-down. |
| **A host page can never pass its signed-in user's identity across the widget bridge.** | Anything the loader can compute, any script on the customer's page can compute — including an XSS on their marketing site. The flow instead: the customer's **backend** signs `{sub,name,email,iat,exp}` (HMAC-SHA256, per-bot secret, `kid`, `exp` ≤ 5 min); the **loader** POSTs it to Laravel, because that single request is the only one carrying an unforgeable `Origin`; Laravel binds the resolved subject server-side; the loader then postMessages only the opaque session token. No identity claim ever crosses the channel, and the token travels by `postMessage` rather than the URL fragment — a fragment is still a URL, and the no-token-in-URLs rule has no fragment exemption. |
| **Widget origin binding is proven at mint only.** | The corollary of the above, and a live bug when it was written the other way: `mint()` sees the embedder's `Origin` because it runs on a POST from the customer's page, but **every request after the handshake comes from the iframe**, whose `Origin` is always our own widget domain. Re-comparing it to the stored embedder origin 404s every chat message ever sent. After mint we check that the caller is one of our own embed origins and re-validate the *stored* origin against the bot's live allow-list. A custom `X-KB-Embedder-Origin` header is not a substitute — page script sets fetch headers freely, so it would be forgeable. |
| **Two JavaScript test runners, on purpose.** | §9.3 pins mobile to Jest + React Native Testing Library; web and widget run Vitest 4 + Playwright. Both authors independently deferred to the spec rather than unifying. What transfers across the boundary is the SSE fixture server and the two-organization harness — not the runner. Whoever wires CI needs to know `packages/contracts` tests under a different runner than its mobile consumer. |
| **The SSE stream is tested against a real fixture server, never an interception layer.** | Playwright's `route.fulfill()` base64-encodes the whole body into one CDP call and Playwright intercepts only at the request stage, so it cannot chunk a `text/event-stream`; MSW's `sse()` silently buffers the entire stream when the resolver is `async`, and a client `abort()` never reaches its handler under `setupServer` — so the cancellation path, which is the whole reason the design exists, is untestable through it. The fixture must also cut with `socket.destroy()` rather than `res.end()`: a clean FIN reads as a normal end-of-stream, so the UI renders "complete" and the disconnect test passes while proving nothing. |
| **The crawl worker is removed from the `data` network.** | It was on `[application, data]` while the architecture doctrine's own gotcha calls it the SSRF pivot, and the security checklist said it must have no route to the data network — the two could not both hold. Resolved in favour of the security rule, for a reason neither file stated: **Qdrant's REST API is unauthenticated**, so a single coerced request to `http://qdrant:6333` from a worker fetching attacker-chosen URLs is unauthenticated index destruction, needing no credential at all. The crawler now reaches the broker only and hands results to `ai-api`. |
| **No presigned URL is generated anywhere, for upload or download.** | A presigned URL is an unrevokable bearer credential in a URL — the shape `laravel-sanctum-auth` bans — so it cannot participate in the six server-side checks. It would also require publishing the S3 gateway as a fifth public hostname in front of an auth surface that fails open, and direct-to-S3 upload stores bytes *before* MIME, size, decompression and malware validation, converting a validation problem into a quarantine problem. The question collapsed on network topology, not on TTL length. Cost accepted: PHP-FPM workers are held for the transfer; the named revisit condition is multi-GB downloads at concurrency. |
| **Object-storage buckets are unversioned; legal hold uses a separate pre-provisioned bucket.** | `kb-deletion-and-verification` presupposes a versioned bucket (listing object versions, object-lock governance), but versioning silently defeats the deletion contract — every delete becomes a delete marker while listings report clean and bytes stay billed — and in SeaweedFS **Object Lock can only be enabled at bucket creation and requires versioning, with no retrofit**. So: unversioned buckets, `ListObjectVersions` retained as the *verification* call precisely because it catches someone enabling versioning later, and a separate versioned lock-enabled bucket that held originals are copied into. **Provision that bucket up front, or the first legal hold becomes a bucket migration under time pressure.** |
| **Semantic conventions are pinned at 1.43.0, and GenAI conventions are pinned by package version instead.** | Core semconv shipped 1.44.0 but no SDK can emit it yet, so 1.43.0 is the newest all three runtimes share; PHP lags further at 1.38.0, so post-1.38 attributes have no PHP constant. The GenAI conventions repository has zero releases and zero tags and its schema URL is literally `TODO`, so `gen_ai.*` is pinned to the instrumentation package version plus a mapping date. Unpinned semconv is a silent renaming of every attribute a dashboard queries. |
| **Surya is disqualified on licence, not on quality.** | Code went Apache-2.0 in May 2026, but the **weights** remain modified AI Pubs OpenRAIL-M with a **$5M operator revenue/funding cap** and a clause 2(c) competing-product ban with no revenue floor. We ship self-hostable software to tenants we do not vet, so we cannot accept those terms on their behalf. Datalab's own docs contradict each other here (README says Apache-2.0 and $5M; the on-prem page still says GPL and $2M) — reopening this needs written confirmation. |
| **`packages/contracts` carries runtime code, and it is billed to the widget's brotli budget.** | §27 lists it with no stated contents, and every skill treated it as types-only. But three clients — `apps/web`, `apps/widget`, `apps/mobile` — read the same SSE stream, and three hand-written frame parsers are three ways to disagree about `: ping` and about where a multi-line `data` field ends. So the SSE frame parser, the client event union's guards, and `KbError` ship as **real runtime code** there. The consequence has to be stated or it gets discovered by a red CI run: the widget's `app shell br` limit now includes a package the widget team does not own, so a change in `packages/contracts` can fail a PR that touched no file in `apps/widget`. Budgeted explicitly at **≤ 1 kB brotli inside the 30 kB shell**, and the loader imports nothing from it at all. If it ever exceeds that, the fix is trimming the package — never raising the limit. `admin-web-engineer` owns it; the other two import and never fork. |
| **The public chat request body field is `content`, not `text`.** | `text` is the `token` **event's** field name, and the two were being used interchangeably across the web, widget, and mobile skills. Posting `{client_message_id, text}` 422s every send against the real FormRequest, and it 422s identically on all three clients, so the symptom reads as a server outage rather than a client bug. The body is exactly `{client_message_id, content}` and nothing else, typed from `packages/contracts`, with `kb-internal-api-contracts` as the owner. `client_message_id` is a client-minted ULID stable across re-renders and retries — it is the fingerprint half of the `chat.message` idempotency key, so a double-submit collapses instead of billing two generations. |
| **`KbError` is snake_case and defined exactly once.** | Two spellings of one field is the failure: a retry predicate reading `error.errorClass` off an instance that declares `error_class` gets `undefined`, `CLIENT_RETRYABLE.has(undefined)` is `false`, and every transient class renders as a permanent dead end — no retry, no backoff, no error, no log line. Nothing type-checks across a boundary typed `any` or a hand-rolled second class, and a forked class also breaks `instanceof`, which flips the `!(error instanceof KbError)` guard and turns *everything* permanent at once. One definition in `packages/contracts`, five fields, all snake_case because each is a straight carry of the envelope plus one header: `error_class`, `retryable`, `retry_after` (seconds, from the `Retry-After` **response header** — it is not in the JSON envelope), `request_id`, and the operator-facing `message`. |
| **The crawl and OCR metric families label on `disposition`, never an overloaded `outcome`.** | `outcome` is a closed four-value enum (`success` `error` `timeout` `cancelled`, plus `skipped` on the scheduler family) and the global error rate is `outcome=~"error\|timeout"`. `kb_crawl_pages_total` has six per-page results including `failed` and `missing`; OCR pages have a real third result, `partial`. Reusing `outcome` for either is valid PromQL that silently under-reports by exactly the traffic that failed: the series exist, the sum is just smaller, and the error-rate panel reads healthy through a crawl outage. So a family whose results are not the shared four names its label **`disposition`** — opt-out-by-name, declared in the catalog, carrying its own recording rule. Run-level crawl health stays on `kb_crawl_runs_total{outcome}`, which *is* the shared enum. |
| **A widget session refreshes by loader re-mint on a distinct bridge message — never a second `init`.** | Origin proof exists in exactly one place: the loader's `POST /api/v1/sdk/session` from the customer's page, the one request whose `Origin` the browser sets and page script cannot forge. Every request the *frame* makes carries our own widget origin, which proves nothing about who is embedding us, so there can be no refresh endpoint the frame calls. And the frame accepts exactly one `init` per instance, because re-initialisation is a state-machine reset an attacker would enjoy — so "just handshake again" is not available either. Renewal is therefore a distinct bridge message that asks the loader to repeat the boot mint from **its own** configuration; nothing in the message body becomes a request parameter. One refresh in flight at a time, sends queued in memory and flushed in order with their original `Idempotency-Key`, and a failed or 10-second-stalled refresh fails the queue **once, visibly**, as `authentication`. Binding after mint is unchanged: later requests re-validate the *stored* origin, never the refreshing request's. |

### Constraints discovered while choosing tooling

- **OmniDocBench is research-licensed.** Quoting its published numbers is fine; running it inside a commercial evaluation harness is not. This constrains `docs/16-evaluation.md`, which currently assumes benchmark suites are freely runnable.
- **PP-OCRv6 is a language regression against v5** — 50 languages versus 106, dropping Arabic, Cyrillic, Devanagari, Thai, Greek, Korean, Tamil, and Telugu. For a product whose spec expects multi-language sources this is a live constraint on the version we pin, not a footnote. PaddleOCR also states its own v6 metrics are not comparable to v5's, so the "+5.1% recognition" headline is unreproducible.
- **EU Cyber Resilience Act vulnerability-reporting obligations take effect 2026-09-11** — five weeks after this was written — with full application 2027-12-11 (verified from the Commission's page). For a product shipped as self-hostable software this is what makes SBOM-per-release and a documented vulnerability-handling process obligations rather than good practice, and it is the deadline behind spec defect 24.
- **A dependency's licence and a CI tool's licence are governed differently, and conflating them blocks the wrong things.** The licence gate reads *artifact* SBOMs, so a GPL-3.0 linter or an AGPL-3.0 secret scanner running as a separate CI process is out of scope by construction, while an AGPL library linked into a shipped image is a defect. Stating that distinction is what prevents someone "fixing" the toolchain later.
- **No official CER/WER exists for Tesseract, EasyOCR, RapidOCR, or Surya.** None of these projects publish accuracy figures, and OCRBench evaluates only multimodal LLMs. Any accuracy claim about them either cites a third-party benchmark or is unsourced.

## Spec defects

Implementing these as written produces a bug.

1. **§13.3's idempotency key omits `ocr_cfg_version`** while §13.4 makes an OCR configuration change a version trigger. Follow §13.3 literally and an OCR reprocess dedupes against the completed run: the admin sees "already processed" and the new settings never land. Silent, not an error.
2. **§13.3 lists "source version" as a key component** — circular, since the key is what decides whether a version should exist. Substitute `source_item_id`.
3. **§8.9 specifies no state transitions at all.** It is a flat list of 15 states. The entire transition table in `kb-source-lifecycle` is derived, including the invented edges `Disabled → Ready`, `Failed → Queued`, `Archived → Ready`, and the rule that `Ready → Deleted` must pass through `Deleting`.
4. **Three status columns, one enum, no partitioning** (§16.4 gives `knowledge_sources.Status`, `source_items.Status`, `source_versions.Processing status`). Ruled: processing states are version-level and roll up for display; `Draft`/`Archived` are source-level only.
5. **§13.2 orders cache invalidation (stage 18) after retirement (stage 17)**, but a cache entry keyed to the retired version is stale the instant the pointer moves. Reordered to invalidate immediately after the switch, before the delayed vector delete.
6. **`Archived` is named in §8.9 and never defined.** Working definition: metadata and objects retained, vectors dropped, not retrievable, restorable by rebuild. Marked unverified pending a decision.
7. **§19.2's "invalid input" is ambiguous across two tiers** — the caller's bad input versus the provider rejecting a request we built (§8.7's "context too large due to an application bug"). Same words, different class, different tier.
8. **Neither §19.2 nor §8.7 names "provider authorization."** A provider 403 (organization not entitled to that model) is literally neither "our authorization failure" nor "provider authentication failure". Folded into `provider_auth`.
9. **§19.4 names nine timeouts but sets no values.** All numeric defaults are derived to satisfy §23's targets with the nesting arithmetic made explicit, and are marked unverified pending measured p99.9.
10. **§8.9's state count.** 15 states, not 14 — early summaries collapsed `Ready` and `Ready with warnings`, which differ only in the warning summary and behave identically for retrieval.
11. **§21.3 lists "unanswerable-question refusal accuracy" as a single metric, but §21.5 gates on false answers.** One accuracy figure cannot separate a false refusal (the bot had the evidence and declined) from a false answer (the bot had nothing and answered anyway). They have opposite causes and opposite fixes, and averaging them hides both. Split into a false-refusal rate and a false-answer rate; `ragas-evaluation` adds CRAG's +1/0/−1 scoring so a refusal scores strictly better than a wrong answer.
12. **§21.4 requires an immutable configuration snapshot but never names the judge model as part of it.** LLM-judged scores are not comparable across judge models or even across judge-model versions, so an unpinned judge is the single largest source of unexplained score drift — "faithfulness fell from 0.82 to 0.79" is meaningless if the judge changed underneath. Pin a dated snapshot, never an alias, and store it with the temperature and the `ragas` version.
13. **§11.2's signed internal request does not sign `X-KB-Org-Id` — the tenant scope of every data-plane call was forgeable.** *(Security defect. Corrected in `kb-internal-api-contracts`.)* The canonical string was `KB1 | METHOD | PATH | X-KB-Timestamp | sha256(body)`, covering no headers. Every downstream isolation layer builds its filter from `ctx.org_id`, which arrives in a header — so altering one header on an otherwise valid, correctly-signed request executes it against another organization and defeats all seven layers at once. The tenancy contract's own claim that "`ctx.org_id` comes from the signed internal request" was false as the contract was written; the two documents were individually self-consistent, which is why this survived review. The canonical string now appends every `X-KB-*` header (name lowercased, value stripped, sorted), and the verifier recomputes that set from **all such headers actually present on the request** rather than from a caller-supplied signed-headers list — a list is attacker-controlled, so anything omitted from it could be freely added or dropped.
14. **§16 is a list of attribute names, not a schema, and the gaps are load-bearing.** The section opens "the exact schema will be finalized during implementation" and gives no types, nullability, keys, or indexes; there is no PK/FK graph anywhere in the spec, which is why the cascade question was open. The specific gaps that change behaviour: `chunks` has neither `organization_id` nor a bot linkage, yet every Qdrant point payload must carry six fields written "from the chunk's own FK chain" — a chain three joins deep, and the direct cause of the "rebuilt source becomes invisible to its own org" failure; `bot_source_assignments` has no `organization_id`, contradicting the requirement that it be denormalized with composite FKs; `citations` reaches an organization only via `messages → conversations`, a chain the spec never states is `NOT NULL`; there is no `idempotency_keys` table despite the error taxonomy requiring a durable record beside the Valkey entry; `provider_connections` has no `key_version`, which KEK rotation needs to avoid a migration; `audit_logs` carries no immutability statement even though §18.11 calls it append-only; `messages`, `provider_calls`, `usage_events` and `audit_logs` have retention semantics but no partitioning key, and `conversations` has a retention expiry while `messages` does not — so retention deletion has no direct predicate. Separately, §16.4 still lists **two** competing version pointers (`knowledge_sources.Current version ID` and `source_items.Current version ID`) after defect 4 resolved the item pointer as authoritative.
15. **§8.1 says "role-based access control" and enumerates no roles.** The six actors exist only in §6.1–6.6, and one-role-per-user-per-organization is inferable only from `organization_users.role` being a scalar in §16.1 — a schema accident standing in for a requirement.
16. **The error envelope had no slot for per-field validation errors.** *(Corrected in `kb-internal-api-contracts`.)* §17 defines the non-streaming envelope as exactly `{error_class, message, retryable, request_id}`, while `validation` is a defined class rendering 422. With no field map, a 422 cannot be rendered against the input that caused it: the form shows nothing, the user presses Save again, and the same request re-fires indefinitely. `errors: Record<string, string[]>` is now specified as a superset present **only** on `validation`, so one parser still serves every error shape.
17. **The spec never assigns hosted chat a hostname, and the answer is three origins, not one.** §27 pins a single `apps/web`, which reads as a single origin. But hosted chat renders model-generated Markdown — the highest-risk sink in the product — so on a shared origin one XSS there executes with the admin session cookie attached; and the widget must be on a separate *registrable* domain because a shared parent domain puts the admin cookie on the widget's origin (spec defect 15's neighbour, from `laravel-sanctum-auth`). The deployment therefore needs `app.<domain>`, `chat.<domain>`, and a distinct widget domain. Binds `traefik-routing` and `docker-compose-stack`.
18. **§9.1 names "shadcn/ui and Radix primitives", but Radix is no longer shadcn's default base.** Since July 2026 the CLI defaults to Base UI. The spec is being *honoured* — `"base": "radix"` is pinned and `-b radix` is mandatory on every `shadcn add` — but the line now describes a non-default configuration, and an unflagged add silently installs a Base UI component beside Radix ones. Migration is per-component, so reversing later is real work.
19. **§9.4's "OpenAPI generated from maintained contracts" is a third definition of the same request rules**, alongside the Laravel FormRequest and the client Zod schema. The spec never reconciles the three. Ruled: the drift manifest is dumped from executing `rules()`, never from the OpenAPI document — the FormRequest is what actually rejects a request, so it is the only defensible source.
20. **No browser-facing push channel exists for ingestion or crawl progress.** §17.5's status callback is FastAPI→Laravel only, and ADR-008's SSE is scoped to chat, so §8.16's "vector indexing status" and "last crawl time" views can only poll. The cost is real and should be a decision rather than a default: roughly 10 rps at 20 admins on a 2-second interval, each request paying a session lookup, a membership re-check, and a policy evaluation.
21. **ADR-008's wording invites the one API we cannot use.** "Use Server-Sent Events as the default response stream" is compatible with our transport — we speak the SSE *wire format* — but it reads as an endorsement of `EventSource`, which is disqualified twice over: it cannot set an `Authorization` header, and it is GET-only while sending a message is a POST with a body. One clarifying sentence would prevent a reasonable engineer reaching for it.
22. **Three §16/§27 gaps that a first migration would have to invent.** §16.3 lists "Theme configuration" with no schema (the six-key set is `tailwind-shadcn`'s invention and wants an ADR before the first migration); §27 lists `packages/design-tokens` with no stated contents or consumers; and §8.21 requires offline display of previously loaded messages without saying where they persist — transcripts are tenant content and SecureStore cannot hold them, so `expo-sqlite` in the app-private directory, org-scoped and wiped on logout, is a gap-fill rather than spec.

23. **§24.5's `core` profile stops the stack from starting, and its `gpu` profile cannot do what its name implies.** Compose services *without* a `profiles` key are always enabled, so tagging the required services `core` means a bare `docker compose up` starts nothing. And a profile includes or excludes a whole service — it cannot patch a device reservation onto `ai-api`, so GPU has to be an overlay file. Separately, §24.8's 8–16 GB sizing floor is stated for an external-LLM showcase and is inconsistent with running BGE-M3 plus the reranker locally, which want ~16 GB on their own.
24. **§26 assigns no pipeline step to licence scanning or secret scanning.** It lists dependency and image scanning and SBOM generation, while §35 requires a licence review before commercial release and §34 carries a "dependency or licence changes" risk row — so the obligation exists with nowhere to run. §18.11's redaction requirement has the same gap: no secret-scanning step exists. §26 also reads as one linear 16-step pipeline with "run a small RAG regression suite" as step 10, between integration tests and image publish, which would make a nondeterministic LLM-judged metric a correctness gate.
25. **§9.16 and §21 §39 recommend Semgrep, whose rules cannot be used in a published repository.** The engine is LGPL-2.1, but `semgrep/semgrep-rules` carries the Semgrep Rules License v1.0: *"This license does not allow you to distribute the rules, or to make them available to others as a service."* We publish this monorepo to self-hosters, so vendoring a `p/…` ruleset is distribution. Resolved by using Opengrep (the LGPL-2.1 fork) plus our own rules; the spec lines should be amended. The same class of finding disqualified Safety for Python — `safety-db` is CC BY-NC-SA 4.0, non-commercial — replaced with pip-audit.
26. **§25.4 is a single sentence for the only store holding bytes we cannot recompute.** "Original sources are critical and should be replicated or backed up to independent storage" is the entire backup specification for object storage. Two consequences the spec misses: `-defaultReplication` is not a backup, because SeaweedFS never re-establishes a lost replica on its own (`volume.fix.replication` is manual); and §25.3's claim that Qdrant is rebuildable depends on `derived/parse/` surviving, which §25.4 never mentions.
27. **§24.2 offers "tempo or jaeger" and §25.1 asks for Grafana dashboards to be backed up.** Jaeger would break the trace-to-logs correlation pair and the metrics-generator that the observability conventions assume, so the choice is not free. And backing up dashboards taken literally means preserving a SQLite file nobody diffs — provisioning-as-code satisfies §25.1 by making the repository the backup.

## Implementation traps that contradict the spec's intent

Not spec defects, but places where a faithful implementation using the chosen tools defeats a stated requirement.

- **§14.5's "removal rules must be conservative" is defeated by every mainstream content extractor.** trafilatura hard-deletes `<footer>`/`<aside>`/`<nav>` by tag before any scoring (no `favor_recall` recovers them); jusText marks sub-70-character and high-link-density blocks bad and treats document edges as bad; Crawl4AI's `fit_markdown` gives `<a>`/`<strong>` weight zero and advertises removing "disclaimers" as a feature. Legal text lives exactly where all three delete.
- **§14.2's 350–700 token target collides with the default embedder config.** `BGEM3FlagModel` defaults to `passage_max_length=512` with `truncation=True`, so a 700-token chunk is indexed from its first ~512 tokens with no warning. Compounding it, sentence-transformers reads `max_seq_length: 8192` from the same model's config — the two loaders truncate identically-configured models at different lengths. Resolved by asserting `passage_max_length >= MAX_TOKENS` at startup so the mismatch raises.
- **§13.5's "verify expected chunk counts" — the danger is the wire, not the Python client.** ~~`count` is approximate unless `exact=True`, and `upsert` defaults to `wait=false`.~~ **Both corrected on inspection of qdrant v1.18.3 and qdrant-client 1.18.0.** `CountRequestInternal::default_exact()` returns `true`, the gRPC handler unwraps to the same, and the client defaults `exact=True`; **Qdrant's prose documentation saying counts are approximate by default is a docs bug.** Likewise `qdrant-client` defaults `wait=True` and sends it explicitly, though the REST wire default really is `wait=false`. So the trap is narrower and sneakier than stated: Python verification code is safe by default, while a `curl` probe, a Laravel smoke check, or any hand-rolled REST call gets the wire defaults and reads a collection still absorbing writes. Pass both explicitly everywhere so the two paths cannot disagree.
- **§12.12's evidence threshold is unimplementable on a fused score.** Reciprocal Rank Fusion discards magnitude by construction, so the top fused candidate always scores `2/(k+1)` whether it is a perfect match or noise. The threshold must sit on the reranker's scale — and that scale is itself ambiguous, since `bge-reranker-v2-m3` returns unbounded logits unless `normalize=True` applies a sigmoid.
- **A Qdrant filter-delete is not replay-deterministic, and a Qdrant delete is not erasure.** Filter-based deletes store the *filter* in the WAL and re-resolve it against live segments on every apply including replay, so points deleted by filter can return after a restart (qdrant#9575) — every API call returned success. They also apply in 512-point batches that release the segment write lock between batches, so a concurrent search sees a half-deleted source. Separately, a delete only flips a soft-delete bit: raw vector bytes and the HNSW node persist until the vacuum optimizer rebuilds the segment, which requires that segment to be ≥20% deleted (`deleted_threshold`) **and** hold ≥1000 vectors (`vacuum_min_vector_number`) — so a small or lightly-deleted segment is never reclaimed on any timeline, and snapshots copy segments as-is. Search exclusion is immediate; attestable erasure needs a forced re-index plus backup expiry.
- **Qdrant's RRF constant defaults to `k = 2`**, not the `k = 60` used by Elasticsearch, OpenSearch, and the RRF literature. Roughly a 30× difference in how sharply rank-1 dominates. Set `fusion.k` explicitly and snapshot it with the retrieval configuration.
- **Every Docling model spec pins `revision="main"` — a moving branch — which defeats version identity.** `docling-tools models download` snapshots whatever `main` happens to be at image-build time, so a rebuild can change parse output while `docling.__version__` and `parser_cfg_version` are both unchanged. `kb-source-lifecycle` derives version identity from those values, so two versions that should differ are indistinguishable, and a reprocess that should trigger does not. Pin model revisions to commit shas at build time.
- **Docling 2.114.0 natively supports legacy binary Office formats (`.doc`/`.ppt`/`.xls`), which §9.10's LibreOffice conversion worker was introduced to handle.** Keeping that worker adds an attack surface `kb-security-baseline` has strong reasons to avoid. Needs an ADR either way; the fidelity comparison is unmeasured.
- **Docling's `HybridChunker(repeat_table_header=True)` silently does nothing on the default serializer.** The header method is implemented only on the Markdown and HTML serializers; the default `TripletTableSerializer` does not override it, so no header prefix is emitted.

## Claims deliberately not made

Recorded so nobody re-introduces them from a secondary source.

- **"Parent-document retrieval improves accuracy 15–30%"** — traces only to marketing material with no primary source. The measured figure available is ~+2 points nDCG@5 over flat retrieval (H-RAG).
- **Table serialization** — row-wise verbalization is used on measured evidence (RAGonite, WSDM '25: P@1 0.528 verbalized vs 0.382 markdown), which **contradicts Unstructured.io's published recommendation**. Noted so the deviation is deliberate rather than accidental.
- **Recursive character splitting is a strong baseline, not a strawman.** Several structure-aware wins in the literature are measured against fixed-*character* splitters that break mid-word. The structure-aware doctrine here should not be over-claimed.

---

## Pass 4 audit and the two remediation rounds that closed it

A read-only audit at the close of Pass 4 checked every shared surface for
contradictions. Nine blocking or high-value defects were fixed in that pass and
thirteen (A1–A13) plus the drift, ownership, and unenforceable-doctrine lists
were deferred. A second audit round then found seventeen more (S1–S17) plus
several structural defects. **All of them are now closed** — the last holdout was
open decision 2 wearing a different hat, and ADR-012 closed it. None was a spec
defect; each was drift between two skills, or doctrine with no enforcement.

This section is history, not a checklist: for each item it records the symptom,
which side won the contradiction, and where the resolution actually lives, so a
later reader can tell a deliberate ruling from an accident.

### First round — A1–A13, all closed

| # | What was wrong | Which side won | Where it landed |
|---|---|---|---|
| A1 | `qdrant-hybrid-search`'s DoD demanded `RRF_K` be "identical between the server-fusion path and the Python fusion path" while `kb-rag-query-contract` mandated 60 in Python and 61 in the eval harness — a check written from the DoD would fail a correct implementation | `kb-rag-query-contract`: the numbers are *supposed* to differ | `qdrant-hybrid-search` now names two constants with two owners — `RRF_K = 60` for our `1/(k + rank)` Python fusion, `QDRANT_SERVER_RRF_K = 61` for anything talking to Qdrant's `fusion.k`, because Qdrant scores `1/((pos+1)/weight + k − 1)` on a 0-based `pos` and its k is the literature's k **plus one**. The DoD now says the opposite of what it said: *"No test, comment or config asserts that two of these numbers are equal."* Each path snapshots the k it actually ran under into its `retrieval_configuration_version` |
| A2 | The canonical `hybrid_search()` example used server-side RRF — the shape the same file's gotcha and `kb-rag-query-contract` both ban, because a fused response is one score per point and the per-branch ranks §12.9 and §8.24 require are discarded server-side and unrecoverable | the gotcha | The example is now two filtered `query_points` calls fused in Python, keeping `dense_rank`, `dense_score`, `sparse_rank`, `sparse_score`, `fused_score` per candidate. The `prefetch` + `RrfQuery` form is retained deliberately, labelled *"the banned shape, so it is recognisable on sight"*, and survives only in the eval harness and the playground's comparison panel. A DoD grep for `RrfQuery(\|FusionQuery(\|prefetch=` under `app/rag` and `app/retrieval` must return nothing outside that comparison module |
| A3 | `kb-architecture-map`'s DoD asserted `laravel-api` was the only service on both `edge` and `application`; `docker-compose-stack` also puts `laravel-api-stream` there, so the check failed a correct topology | `docker-compose-stack` | The DoD now asserts the set is **exactly** `{laravel-api, laravel-api-stream}` — closed in both directions, so a third service there fails and so does a missing one — alongside `web` on `edge` only, `ai-worker-crawl` off `data`, and `valkey-core` as the one store also on `application` |
| A4 | **Blocking.** `preact-vite-library` baked `api.kbwidget.example`, putting the API on the widget's registrable domain and collapsing the separation `traefik-routing` establishes two lines after arguing for it; `kb-security-baseline`'s references used a third spelling | `traefik-routing` | Four hostnames, one spelling, one owner: `app.<domain>`, `chat.<domain>`, `api.<domain>`, and `<widget-domain>` as a **separate eTLD+1**. `preact-vite-library` and `kb-security-baseline` both now say they consume the names and own none of them. The reason is restated at each site so a future "tidy-up" reads as the regression it is — the admin session cookie is scoped to the main domain, so our API on the widget's eTLD+1 makes a hostile customer page's iframe same-site with a real admin credential, and CHIPS cannot help because the cookie is not the widget's. Enforced by a test parsing the deployed hostnames and asserting the eTLD+1 differs |
| A5 | The widget bootstrap did not compose: `preact-vite-library` called `attachBridge(frame)`, `iframe-postmessage-bridge` defined `attachBridge(frame, ch, o)`, and `__KB_API_ORIGIN__` was used but never declared in the `define` block | both files jointly; reconciled rather than one overruling the other | The signature is `attachBridge(frame, ch, o)` on both sides, with `LoaderOptions` exported from `protocol.ts` so both call sites type-check in CI, and a composition test running the real loader against the real frame bundle that a mutant dropping either argument must fail. The `define` gap became its own gotcha, because `define` is a text substitution rather than a binding: an undeclared `__KB_*` is not a build error, it survives verbatim into the IIFE and dies as a `ReferenceError` on a customer's live page. Gated by `rg -o '__KB_[A-Z_]+__' apps/widget/src \| sort -u` equalling the `define` keys, and by the built bundles containing no `__KB_` substring |
| A6 | `kb-architecture-map` prescribed an explicit control-plane cancel call and said not to rely on TCP close; the contract, Laravel, and FastAPI skills all build exactly the TCP-close path, and no cancel endpoint exists anywhere | the three implementers | The cancel endpoint is gone. The propagated-disconnect chain stands: PHP learns the client left on a failed write (the heartbeat is the probe), Laravel's abort propagates to Starlette, FastAPI meters incrementally and persists the partial token count from a `CancelledError`/`finally` handler, and cancellation is a normal terminal state (`user_cancellation`, 499). Removing the endpoint also retired the `UNVERIFIED` marker that said end-to-end propagation was untested — there is no longer a claim about an endpoint that does not exist |
| A7 | Internal-call backoff cap: `kb-error-taxonomy` said 5 s, `laravel-queues-valkey` implemented 30 s | `kb-error-taxonomy` owns retry policy | Unified at 5 s, and — more usefully — `laravel-queues-valkey` now **quotes** rather than restates: full jitter, base 0.2 s, cap 5 s, `sleep = uniform(0, min(cap, base × 2**attempt))`, with the note that two copies of a cap drift and the drifting copy is always the one that ships. Its DoD greps `app/Jobs/` for `min(` and expects only the quoted ladder. The genuinely non-obvious consequence is recorded beside it: `release()` takes whole seconds, so rungs below 1 s floor to an immediate retry, and it is `tries` plus the 10%-of-requests retry budget — not the sleep — that actually bounds the loop |
| A8 | `kb-deletion-and-verification` named S3 lifecycle rules and bucket versioning as deletion mechanisms; `seaweedfs-s3` bans both and documents that SeaweedFS lifecycle rules are a silent no-op | `seaweedfs-s3` | Non-negotiable 1 of the deletion skill is now *"deletion is always an explicit API call we make and then verify — never a rule handed to a store to execute on our behalf"*. Buckets are unversioned; `ListObjectVersions` is **kept**, repurposed from mechanism to detector, precisely because it is the only call that sees `Versions` and `DeleteMarkers` the day someone flips the posture. Legal hold is the one versioned, object-lock-enabled bucket, separate and pre-provisioned, governance mode only — a compliance-mode lock cannot be shortened by anyone, root included, so applying one to data that may later face an erasure request creates a conflict with no technical exit |
| A9 | The observability label allow-list was enumerated "and nothing else" while the catalog itself used `token_type`, `finish_reason`, `engine`, `task` and others, several consumed by shipped alerts — the allow-list unit test would have failed on day one | the catalog rows | The allow-list was rebuilt as the true union of every `Extra labels` column, and the two are declared **one artifact**: adding a label to a metric means adding it to the list in the same change or the test at the instrument wrapper fails. A second rule was added that outranks the list, because a union is not by itself a cardinality bound: no label may carry a tenant id, user id, URL, query string, or free text, and a proposed label whose values cannot be enumerated is refused rather than appended. (This is why OpenRouter's `upstream_slug` is a Valkey key component and never a label — the slug set grows without our involvement.) |
| A10 | `outcome` was documented as four values plus `skipped`, but `kb_crawl_pages_total` defined six including `failed` and `missing`, which escape the global error-rate matcher `outcome=~"error\|timeout"` | rename, not widen | Both offending families renamed: `kb_crawl_pages_total{disposition}` (`discovered` `changed` `unchanged` `skipped` `missing` `failed`) and `kb_ingestion_ocr_pages_total{engine,disposition}` (`success` `partial` `error`). Run-level crawl health stays on `kb_crawl_runs_total{outcome}`. The general rule now sits in `kb-observability-conventions` — a family whose results are not the shared four names its label `disposition`, opt-out-by-name — and is ratified as a decision above. What made it worth the rename is that nothing errors: the matcher is valid PromQL, the series exist, the sum is simply smaller, and the family drops out of the one rule meant to catch it |
| A11 | `kb_crawl_robots_blocked_total`'s `reason` enum dropped `scheme`, `credentials`, and `dns` in `crawl4ai-crawler`, so those rejections incremented no counter and the SSRF guard looked quieter than it was | the catalog | Restored to **eight** — `robots` `scheme` `credentials` `dns` `private_ip` `redirect` `content_type` `size` — in both files, with the symptom spelled out (a tenant probing `file://`, embedded credentials, or a rebinding host reads as zero traffic) and a DoD test asserting every `CrawlRejected` reason raised in `app/crawl/` is one of the eight. A ninth is a catalog PR first, never a label value invented at the call site |
| A12 | `kb.chat.relay` was used by `opentelemetry-instrumentation` but absent from the authoritative span tree, which declares span names permanent | add it to the tree | Present in the tree in `kb-observability-conventions` as an INTERNAL span for the Laravel→FastAPI relay hop, and referenced from `opentelemetry-instrumentation`'s instruction to open it in the controller before a byte of body is written |
| A13 | `provider_rate_limit` was marked "alert on sustained" with no route in the alerting rules — the most common real incident had no page | add the route | `KbProviderRateLimitSustained` exists with a recording rule `kb:provider_rate_limit_ratio:5m` and the threshold >10% of a provider's calls for 15 m. The class-level reasoning moved into `kb-error-taxonomy` footnote 3 (sustained means retry *and* fallback have both already run, so it is reaching users as failed turns; below that bar it is one org's plan boundary, shown on its own dashboard). And the general failure was gated: a CI check diffs the taxonomy's Page column against the named rules in `kb-alerts.yml`, *"so 'alert on sustained' cannot sit in doctrine with no route (this is how `provider_rate_limit` went unpaged)"* |

### Path and version drift — all closed

- **`apps/web/app/` vs `apps/web/src/`.** `tailwind-shadcn` was the outlier against four skills and CI. Unified on `src/`, with a DoD that is more than a spelling check: `components.json` must name `src/app/globals.css`, aliases must resolve `@/*` → `src/*`, and `rg -n 'apps/web/app/' apps packages` must return nothing. The reason it earned a check is in the same file — a `@source` pointing at a directory that does not exist is **not** an error in Tailwind v4; it scans nothing and produces the identical missing-CSS symptom you were trying to fix.
- **`packages/markdown-render`.** Invented by `preact-vite-library`; removed. The canonical set is `packages/contracts` and `packages/design-tokens` and nothing else. The replacement reasoning is the valuable part: markdown-it and DOMPurify are ordinary third-party dependencies installed per app and pinned by the root manifest, because `kb-security-baseline`'s *one renderer everywhere* is a rule about **configuration** and a workspace package would not have enforced it — one call site passing a looser `ALLOWED_ATTR` forks it just as completely. What enforces it is the shared §22.5 XSS corpus every renderer in the monorepo runs.
- **`kb_queue_depth`.** Was labelled `valkey`/`valkey-long`, which are Laravel **connection** names carrying two queues each — so two backlogs merged into one series and a wedged `exports` queue hid behind a healthy `maintenance`. Now the real queue names in both runtimes: Celery's `ingest` `embed` `crawl` `evaluate` `maintenance`, Laravel's `ai-dispatch` `notify` `maintenance` `exports`, with `maintenance` disambiguated by `service`.
- **CI service images.** Bumped to `postgres:18-alpine`, `valkey/valkey:9.1.1`, `qdrant/qdrant:v1.18.3`, matching the compose `test` profile, with a drift check diffing the two files. The symptom recorded is why this was not cosmetic: on `valkey/valkey:8-alpine` there is no `DELIFEQ` and no database-level ACL, so the test proving a cache credential *cannot* reach the queue DB passed because on Valkey 8 there is no mechanism to fail. Retiring this also retired the last `UNVERIFIED` marker in `github-actions-pipeline`.
- **The fourth network.** `observability` is in `kb-architecture-map`'s topology, `internal: true` alongside `data`, on every service that exports, with a DoD asserting all four exist and which two are internal.
- **Laravel docs links.** Moved to 13.x, with the pin stated inline — *"Laravel is pinned at 13.x across this library; a 12.x link is a stale link"* — so the next stale link is recognisable rather than plausible.

### Ownership gaps — closed

- **`packages/`, `samples/`, `scripts/`.** All three now have an owner row in `CLAUDE.md`: `packages/contracts` and `packages/design-tokens` to `admin-web-engineer` (import, never fork — *"a second copy of the frame parser is the drift `contract-steward` exists to catch"*), `samples/` to `rag-eval-engineer`, `scripts/` to `platform-devops-engineer`. Every agent that can write now carries the corresponding hard boundary: twelve name the directories explicitly, `platform-devops-engineer` names its allow-list plus the two exceptions, and `rag-eval-engineer` expresses it as a closed allow-list (*"anything outside `app/evaluation/` and `samples/`"*) which is stricter than a named denial. The two read-only reviewers, `security-auditor` and `contract-steward`, have no write tools at all. Two of the denials carry their own reason rather than a rule: a purge helper in `scripts/` would sit outside every gate that makes deletion provable, and a collector script parked there runs outside every gate that keeps the metric catalog closed.
- **The multipart-abort sweep** now has a beat home. `celery-workers` declares **exactly six** entries, all crontab, all routed to `maintenance`, with `sweep-abandoned-multipart-uploads` (`crontab(minute=17, hour=4)`, aborting uploads older than 24 h) as the sixth, and a test asserting the count and the entry names so a seventh fails CI until the skill is updated too. `kb-deletion-and-verification` points at it from the matching gotcha — abandoned multipart parts are invisible to both `ListObjectsV2` and `ListObjectVersions`, so `assert_prefix_empty()` passes and the deletion is attested while the parts of a half-uploaded original are still on disk.

### Doctrine stated but unenforceable — all four now gated or decided

1. ~~No Python-side tenancy grep in CI~~ — **fixed in Pass 4.**
2. ~~"Deletion never matches on text" has no gate.~~ **Gated.** `ai-service` CI greps for `MatchText(` and for `(delete|count)…(text|content|content_hash)=` anywhere under `services/ai-service/app`, as a required check. The interesting part is the exemption: `app/deletion/filters.py` is the single **named** file allowed to construct keys dynamically, because the original grep was defeatable by `FieldCondition(key=field, …)` inside a loop, and it earns the exemption by unit-testing its `DELETE_KEYS` tuple. Which produced a finding of its own — see S16 below on why that tuple has seven keys and not six. A second file appearing in the exemption list is a review stop, not a merge.
3. ~~ADR-010's Qdrant rebuild proof is scheduled, not a merge gate.~~ **Now a merge gate.** `qdrant-rebuild-proof` runs in the merge queue against a fixture org and asserts the reconstructed payload, not counts — the failure it catches is a payload key that exists nowhere in PostgreSQL, which counts cannot see. The full-corpus rebuild stays on the nightly.
4. ~~Three telemetry gates each side assumes the other runs.~~ **All three are in CI**, in an `observability-rules` job on `infrastructure/observability/**`: alert label/annotation completeness, Alertmanager route coverage, and `promtool check rules` + `test rules`. Two of the three needed a specific shape to be worth anything, and both traps are recorded: `amtool config routes test` **exits 0 for a severity that routes nowhere** because a fall-through to the default *is* a resolution, so the gate compares the resolved receiver name against a deliberately-named black hole `kb-unrouted`; and a line `grep` is unfit for the label check because a stray `severity:` anywhere in the file satisfies it while the alert beside it carries nothing, so that check is driven off the parsed document with `yq`. A third trap sits beside them: `promtool test rules` resolves `rule_files:` relative to the **CWD**, so a passing run of zero tests looks identical to a passing run.
5. ~~"Laravel decides, FastAPI executes" is unresolved for the lifecycle tables.~~ **Closed by ADR-012.** `kb-source-lifecycle` had FastAPI flip `source_items.current_version_id`, a column `kb-architecture-map` assigns to Laravel, while two other skills insisted FastAPI never queries Laravel's tables. The ruling splits it: FastAPI writes rows into the four allow-listed derived tables against the **new, not-yet-active** version, and **Laravel** flips the pointer on the §17.5 callback. This was a decision rather than drift, which is why no audit round could close it.

### Second round — S1–S17 and structural defects, all closed

| # | What was wrong | Where it landed |
|---|---|---|
| S1 | Chunk payload identifiers were untyped or written as UUIDs while the tenant filter builds `MatchValue` from the ULID in `X-KB-Org-Id`. Symptom: **every bot in every organization retrieves nothing** — HTTP 200, normal latency, zero candidates, no exception, no log line — on a corpus that indexed successfully | `kb-chunking-rules`' payload table types every identifier ULID `char(26)`, with `chunks.vector_point_id` named as the one genuine `uuid` anywhere in the system (a Qdrant point id may be nothing but a u64 or a UUID). Asserted at upsert against `^[0-7][0-9A-HJKMNP-TV-Z]{25}$`. The gotcha states the trap explicitly: **the tempting fix is to relax the filter, and that is the one change that must never happen** — a widened filter converts a total outage into a cross-tenant leak, so the outage is the *correct* failure of a positive `must` filter |
| S2 | The evidence threshold sat on an unnamed scale. `bge-reranker-v2-m3` returns unbounded logits (~±10) unless `normalize=True` applies a sigmoid, so `0.30` means "leans irrelevant" on one scale and "very relevant" on the other | `evidence.min_score = 0.30` **on the sigmoid scale**, with `evidence.scale = "sigmoid"` stored in the config snapshot beside the number and asserted equal to the loader's `SCALE` before thresholding. Every prose statement of the threshold in the repo now names the scale beside the number, because the swap is undetectable downstream: `0.30` is a valid float on both scales, the trace stores a plausible score either way, no test fails, and only the aggregate refusal rate moves. Demanding logit ≥ 0.30 is demanding sigmoid ≥ 0.57 — roughly double the bar |
| S3 | The SSE Pydantic models were named for themselves rather than for the wire — `n` for `index`, `input_tokens` for `prompt_tokens`, a `Citation` with no `title`/`url`/`score`, a `message.start` with no `created_at`. Every citation chip renders blank and every usage figure reads zero, with no error on either side | `Citation`, `Usage`, and `created_at` brought to `kb-internal-api-contracts`' wire block, and the mirror-image leak named: a `Usage` that inherits `Event` puts `event: "provider.usage"` *inside* `message.complete`, shipping an internal event name to every widget. `extra="forbid"` cannot catch any of this — it only rejects keys coming *in*, Laravel forwards frames verbatim, and a client reading a missing key gets `undefined`. So a contract test diffs the serialized key set against the SSE block instead |
| S4 | Two `KbError` definitions with two casings across the client skills | One definition in `packages/contracts`, five snake_case fields. Ratified as a decision above; DoD greps in four skills for `class KbError` outside the package and for `errorClass\|retryAfter` anywhere |
| S5 | Spans named `kb.rag.*` — off-catalogue, because the module is called `rag`. Nothing errors: the spans export fine, they simply match no rule, alert, or dashboard query in the repo, so the failure is silence in exactly the surface you would use to debug retrieval | Renamed to the catalogued tree: `kb.query.normalize`, `kb.retrieval.filters/.dense/.sparse/.fuse/.dedupe/.rerank/.threshold`, `kb.context.pack`, `kb.prompt.build`. The only surviving `kb.rag.*` strings are in `haystack-pipelines`, where they are the labelled counter-example. A `STAGE_SPANS` lookup that raises `KeyError` on an unknown stage is the defence, rather than minting a name |
| S6 | `provider_rate_limit`'s Page column read "alert on sustained" as prose, which is not a routable instruction | Now "alert; page if >10% of a provider's calls for 15 min", with footnote 3 carrying the reasoning and `prometheus-grafana-loki-tempo` mechanising exactly that row. The threshold and window live in the taxonomy; the PromQL lives in the observability skill |
| S7 | Hosted chat had no stated credential, which invited it to inherit the admin session cookie | Hosted chat carries **the same opaque chat-session token as the widget**, origin-checked against our own origin — a row in `laravel-sanctum-auth`'s four-mechanism table, not a fifth mechanism. The reason: most of its visitors are anonymous and have no session identity at all, and one auth path keeps the 404 rule, the rate-limit keys, and the abuse hooks identical across both public surfaces. When the visitor *does* hold an admin session, `mint()` resolves `user_id` from it server-side. `nextjs-app-router` NN8 follows: one `streamAnswer` serves both surfaces but takes the credential as an argument and never assumes the cookie |
| S8 | The public chat request body was unpinned, and `text` (the `token` event's field) was drifting into it | `{client_message_id, content}` and nothing else. Ratified as a decision above; asserted by posting each client's exact body against the real FormRequest |
| S9 | `packages/contracts` was treated as types-only by every skill that imported it | Established as runtime code with an explicit ≤ 1 kB brotli budget inside the widget's 30 kB shell. Ratified as a decision above |
| S10 | `docling-parsing` set `document_timeout=180.0`, below the taxonomy's ladder | Raised to **300 s**, taken from `kb-error-taxonomy` rather than chosen locally, with a DoD test asserting it is never below `ocr-pipeline`'s 10-page × 30 s cap — which is the same 300 s by construction. The symptom is why it mattered: `document_timeout` returns `PARTIAL_SUCCESS` rather than raising, so a 10-page scan published as `Ready with warnings` with only its first pages answerable, and the bot said a clause "is not in the document" while the clause was visibly on page 8. A distinct `parse_timeout` warning now distinguishes a *truncated* document from a merely blurry one |
| S11 | The ingestion `stage` label carries 17 values while docs/08 §13.2 walks 18 steps, and the mismatch read as a bug | Annotated in the metric catalog: both counts are right, because §13.2's step 1 *Source creation* is a control-plane act in Laravel that emits no ingestion stage at all. With the instruction not to "correct" it — 18 would permit a value nothing ever emits, and the allow-list unit test is the only thing that would notice |
| S12 | Three hand-written SSE frame parsers | One `parseFrame` in `packages/contracts`, imported by web, widget, and mobile, with the WHATWG field rules stated once. Three implementations are three chances to disagree about `: ping`. **Pointer, added 2026-08-11 so a code comment leads somewhere true — same reason C1 carries one:** `services/ai-service/app/providers/embedding_selection.py:143` cites *"finding **S12**, routed to `contract-steward`"* for a **different** question, the singular `provider_credential` on a wire that now needs up to three credentials. That question is [F12](#f12--the-internal-chat-body-carries-one-credential-and-one-turn-can-need-three), and it was **ruled on 2026-08-12** — option (a), the keyed map `provider_credentials: {connection_id: SecretStr}`; see [G7](#g7--27--f12-the-internal-body-carries-a-keyed-map-of-credentials-not-one-credential). The row here is closed and is not it; nothing is renumbered, because `docs/22`'s S-list is appended to and never edited under a name something else cites |
| S13 | `admin-web-engineer`'s instructions told it to render the error envelope's `message` to the user | Corrected: the user sees a sentence **mapped from `error_class`** plus the `request_id`, never the envelope's `message`. That field is operator-facing — it can carry an internal hostname, raw upstream provider text, or an identifier with no business in a tenant's UI, and it is the string a support engineer greps for, not one anybody wrote for a reader. Log it, show the class-mapped sentence |
| S14 | The widget's resumption cookie and iframe URL were spelled inconsistently across three files | `__Host-kbresume` (`Secure; SameSite=None; Path=/; Partitioned; Max-Age=1800`) and `?bot=…&origin=…&ch=…`, identical in `iframe-postmessage-bridge`, `preact-vite-library`, and `kb-security-baseline`'s widget reference. Both carry their reason: the cookie **is not the session token and carries no authority** — it names a conversation the frame may ask to resume and is useless without a live bearer, whereas a bearer parked in partitioned storage is replayable by anything that can read that partition; and `ch` is a per-frame `crypto.randomUUID()` that stops two widgets on one page, or a frame torn down and recreated, from answering each other's messages |
| S15 | No session refresh flow existed, so a 30-minute widget session simply died | Specified in `laravel-sanctum-auth/references/widget-session-service.md` § *Refresh*: loader re-mint on a distinct bridge message, one refresh in flight, sends queued in order with their original `Idempotency-Key`, visible single failure after 10 s. Ratified as a decision above |
| S16 | Two conflations. The Traefik long-stream test was written against the 60 s chat deadline; and the deletion-key allow-list was documented as the six mandatory payload fields | The proxy test is now a **synthetic** `text/event-stream` fixture emitting for **>120 s**, explicitly not a chat request and explicitly not to be reconciled with the deadline: the deadline caps tenant spend, the fixture proves the proxy imposes no ceiling of its own, and a real answer can never run long enough to exercise a `writeTimeout` regression. And the delete-key allow-list is **seven** keys — the six payload fields **plus `chunk_id`** — with the two sets declared deliberately unequal: the six are terms a *tenant filter* must express on every query, while deletion additionally addresses one chunk by its own identity, and `chunk_id` is never a query-filter term. Collapsing them to six silently removes single-chunk deletion; promoting `chunk_id` into the mandatory filter breaks retrieval instead |
| S17 | Candidates dropped by the rerank retain cap were not recorded as exclusions, so the playground showed fewer results than the trace implied with no explanation | `above_retain_limit` is recorded at the retain cap, in both `bge-reranker` and `kb-rag-query-contract`. It is the drop that gets forgotten because `[: cfg.rerank_retain]` looks like a slice rather than a filter; §8.24 requires "excluded results *and reasons*", and a stage that filters without recording makes the whole panel untrustworthy. A test asserts reranked-count minus packed-count is fully accounted for by exclusion reasons |
| — | Several skills exceeded the 200-line budget, which is what pushed detail out of sight of the file that owns it | Split into `references/` siblings, keeping `SKILL.md` as the map: `github-actions-pipeline` → `workflow-jobs.md`, `kb-observability-conventions` → `metric-catalog.md` + `logs-health-audit.md`, `kb-rag-query-contract` → `pipeline-stages.md`, `laravel-sanctum-auth` → `widget-session-service.md`, `nextjs-app-router` → `stream-read-loop.md`, `preact-vite-library` → `vite-config.md`, `tanstack-query-table` → `sources-table-example.md`, `vitest-playwright` → `sse-fixture-server.md`, and others. Every `SKILL.md` is now ≤ 200 lines. **Consequence for anyone auditing:** a grep restricted to `SKILL.md` now misses seven files carrying eleven `UNVERIFIED` markers — see `docs/23` |

### The eight numbered decisions — closed as ADR-011…018

The audit rounds could not close these, because they were decisions rather than
drift. They are decided now, at the top of this file, and propagated into the
skills that have to obey them:

| Was | Now | Landed in |
|---|---|---|
| 1. Provider key on the wire? | **ADR-011** — yes, as `provider_credential: SecretStr`, outside the snapshot hash, on the never-forward list | `kb-internal-api-contracts`, `pydantic-contracts`, `laravel-control-plane`, `kb-security-baseline` |
| 2. Who writes `chunks` / `document_elements`? | **ADR-012** — FastAPI, against a four-table allow-list; Laravel still flips the active-version pointer *(**ADR-033** superseded that list; the current membership is `ALLOWED_TABLES` in `app/db/writes.py` and is not restated here — the rule is unchanged)* | `kb-architecture-map`, `fastapi-service`, `github-actions-pipeline` |
| 3. `web` on `application`? | **ADR-013** — no; `edge` only, ratified | `kb-architecture-map` |
| 4. Is §8.7's fallback trigger reachable? | **ADR-014** — yes via capacity codes only; `model_not_found` stays permanent and non-eligible. Still 18 classes | `kb-provider-adapter-contract`, `kb-error-taxonomy` |
| 5. Deletion reach and erasure ownership | **ADR-015** — two workflows; deletion retains the three text columns, erasure purges them, legal hold refuses | `kb-deletion-and-verification` |
| 6. Haystack | **ADR-016** — dropped; the skill is retargeted as the record of why | `haystack-pipelines`, `docs/05`, `docs/21`, `retrieval-engineer` |
| 7. Laravel 13's AI APIs | **ADR-017** — ADR-001/003/005 reaffirmed; custody, not API quality | `laravel-control-plane` |
| 8. Signing scheme version | **ADR-018** — absorbed into `v1`; bump-plus-dual-accept rule applies from first deploy | `kb-internal-api-contracts` |

*One correction carried forward from the item as originally written:* decision 4
said the taxonomy "kept the taxonomy internally consistent rather than adding an
18th class". The taxonomy has since grown an eighteenth — `provider_billing`,
added for a different problem (an exhausted account arriving as OpenAI's 429
`insufficient_quota`, retried and then silently falling back). It does nothing
for `model_not_found`, so ADR-014 stands exactly as posed and the count stays at
18.

## The enforcement-claim audit — E1…E3

A sweep of every comment and doc statement in the repository asserting that
something is automatically enforced. It is recorded here rather than in a report
because the finding is not "some sentences were stale" — it is that **the repo
had no way to tell an enforced invariant from a specified one**, and both were
written in the same tense.

### The figures

**267 enforcement claims**, in six buckets, reconciled per area. Each row sums
exactly to its own total, and the columns sum to the six bucket totals:

| Area | Enforced | Gateable | Blocked | False | Ungateable | Preflight-only | Total |
|---|---|---|---|---|---|---|---|
| `apps/` + `packages/` | 0 | 44 | 14 | 14 | 6 | 0 | **78** |
| `services/` | 20 | 18 | 34 | 7 | 3 | 0 | **82** |
| `infrastructure/` + `scripts/` + `samples/` + root | 10 | 42 | 26 | 9 | 9 | 11 | **107** |
| **Total** | **30** | **104** | **74** | **30** | **18** | **11** | **267** |

- **Enforced** — actually checked before `gates.yml` landed.
- **Gateable** — checkable as a cheap text/file pass, needing no lockfile and no
  running stack. The largest bucket, and the reason `gates.yml` was worth
  authoring ahead of `ci.yml`.
- **Blocked** — nothing can check them until `composer install` / `uv sync` /
  `pnpm install` runs.
- **False** — claimed enforcement that exists nowhere. Three were reported in the
  root documentation and are corrected in place: `CONTRIBUTING.md` § *The
  enforcement greps* (now split into enforced-today and specified-not-yet-wired),
  `CHANGELOG.md`'s *Known gaps*, and `SECURITY.md` § *Why this is a policy and not
  a courtesy*. A **fourth of the same shape** was found while correcting them —
  `CHANGELOG.md` said unresolved model shas were rejected "by design" by a licence
  gate that nothing invokes. Recorded because it bears on how much the bucket
  totals can be trusted: the sweep missed at least one, and it was found only by
  someone editing the paragraph next to it. One more sits in **this file** and is
  handled at § *Corrections carried forward* rather than by deletion.
- **Ungateable** — a reviewer is the only mechanism, and saying so is the honest
  position rather than a gap to be closed.
- **Preflight-only** — see below.

**Read the per-area split, not just the totals.** `apps/` + `packages/` has
**zero** enforced claims across 78, and 44 of them are cheap text passes nobody
had written; that asymmetry is the actual state of the repo, and a single
whole-repo summary row hides it.

### Preflight-only — deploy-time checks read as CI

**Eleven claims are enforced by `scripts/ops/preflight.sh` at `make deploy`, and
by nothing on a pull request.** They appear only in the
infrastructure/scripts/samples/root area — the other two areas have no such
category, which is exactly why the bucket is easy to lose when three tables are
merged.

This is the second systemic defect the audit found, alongside the sixteen
self-tripping gates below, and it is more dangerous than a merely false claim
because the check is *real*. It runs, it fails loudly, it is well written — it
simply runs after review is over. The worked case: a `>plaintext` credential
added to the committed `infrastructure/docker/valkey/users.acl` is caught by
`preflight.sh` (which requires the `#<sha256 hex>` form), and by no gate in
`.github/workflows/gates.yml` — the only thing CI asserts about preflight is that
`deploy: preflight` exists in the `Makefile` as a *prerequisite*. So the credential
reaches `main` unchallenged and surfaces at the next deployment, in front of
whoever is deploying rather than whoever wrote it.

`deploy: preflight` being a prerequisite rather than a recipe line is the right
design and is itself gated; the defect is not in the Makefile. It is that a
deploy-time refusal and a merge-time gate protect different populations —
preflight protects the operator, a gate protects `main` — and eleven claims were
written as if the two were interchangeable. Wherever a preflight check reads a
**committed file**, a text-pass gate over the same file is cheap and belongs in
`gates.yml`; preflight keeps the checks that need the live host, the rendered
compose set, or the secrets directory, none of which exist on a runner.

**Revisit condition:** the first incident traced to a state that reached `main`
and was caught at deploy. If that happens, this bucket stops being a
classification and becomes an E-item.

### Self-tripping enforcement — the systemic pattern

**Sixteen of the claims are self-tripping.** A grep gate must name the token it
bans, so the file that *documents* the ban contains that token and the gate goes
red on its own explanation. In every one of the sixteen, the tripping line is the
documentation — which means the cheapest way to green the build is to delete the
sentence explaining why the rule exists, and a naive implementer will do exactly
that and leave a passing gate nobody can any longer justify.

This is why the mitigation has to ship in the same commit as the gate. A gate
landed first and mitigated later is a gate that gets deleted in between. Three
mitigations are in use, and the choice between them is not stylistic:

1. **Anchored pattern** — match the *construct*, not the string. `'use server'`
   only as a directive on its own line; `__KB_X__` only as a mapping key or a
   `declare const`; `outcome="error"` in application source only when the line <!-- outcome-matcher-exempt: prose documenting the gate itself -->
   also carries a PromQL aggregation. Prose then cannot match. Use this when the
   documenting file is *one of the files being compared* and so cannot be
   excluded — `apps/widget/vite.config.ts` and `src/env.d.ts` are the worked case.
   **The line above carries an `outcome-matcher-exempt:` marker. See the next
   subsection for why — it is not incidental.**
2. **Path scope** — scan only where a real call site can exist. ADR-017's grep
   scopes to `services/core-api`, and `CONTRIBUTING.md`, `docs/19`, `docs/22` and
   two skill files all name `Str::toEmbeddings()` and `DB::whereVectorSimilarTo()`
   freely because none of them is inside that scope. Use this when the ban has a
   natural home and the explanations live outside it.
3. **Exempt marker** — `tenancy-exempt:`, `deletion-key-exempt:`,
   `table-write-exempt:`, `outcome-matcher-exempt:`, `adr017-exempt:`, all on the
   same line. Use it for the legitimate exception that must be *read in review*,
   and — the case the next subsection is about — for a **documentation line that
   sits inside the scanned corpus** and can be neither anchored around nor scoped
   out. Put the reason in the marker text, because a marker on a doc line has no
   reviewer to read it.

A fourth technique is what makes the other three trustworthy: **the vacuity
ledger**. Every `gates.yml` job ends with a step that counts what its detector
matched *before* exemptions and prints `vacuous-today:` for any check whose corpus
is empty. Roughly a third of the landed gates cannot fail on today's skeleton, and
without the ledger their green is indistinguishable from coverage. Delete a ledger
line when the corpus grows a real call site; never delete the ledger.

### The seventeenth case, created while cataloguing the sixteen

**This section made the build red.** While it was being written, a parallel agent
extended the error-rate-matcher gate in `.github/workflows/gates.yml` to scan
`docs/` alongside `prometheus/rules/`, `grafana/dashboards/`, `apps/` and
`services/`. The bullet above explaining the *anchored pattern* mitigation spells
the banned literal in prose, so the gate went red on the sentence describing how
to keep gates from going red.

Neither agent could have seen it. One was editing prose in a file that was not in
the gate's corpus when the sentence was written; the other was widening a corpus
without reading every file it newly covered. **The defect is structural, not
careless: the documenting file and the scanned corpus overlapped, and that overlap
is invisible from inside either one.** It is the same shape as all sixteen — the
tripping line is the explanation — with the extra property that it was created
*by the audit that catalogued the pattern*, which is the strongest available
evidence that the sixteen are a live hazard rather than a historical curiosity.

Three fixes were available and two were wrong:

- **Reword the sentence to avoid the literal.** Rejected. The bullet is useful
  precisely because it names the real string; a paraphrase teaches nobody which
  token to anchor around, and it is the "green the build by weakening the
  explanation" move this whole section warns about — one step short of deleting it.
- **Exclude `docs/` from the gate.** Rejected, and it is the tempting one because
  it looks like scoping. It would delete real coverage: a re-derived matcher pasted
  into a **runbook** is exactly what the rule exists to catch, and runbooks live in
  `docs/`. Widening the corpus to `docs/` was correct; the cost of that correctness
  is one exemption, not a rollback.
- **The exempt marker**, on the line, with the reason in it. Taken. It is the
  mechanism that exists for this case, and using it here demonstrates the escape
  hatch the list above recommends.

Two things generalise. First, **widening a gate's corpus is a change to every file
newly in scope**, and the widening commit should grep the new corpus before it
lands — the failure otherwise surfaces in someone else's unrelated pull request.
Second, `CONTRIBUTING.md` line 104 carries the same literal today and is **not**
red, only because root-level files sit outside both arms of the gate. That is luck,
not design: it is a latent seventeenth-and-a-half case that goes red the day
anyone adds `.` or a root glob to scope (a). Recorded here rather than pre-emptively
marked, because an exemption on a line no gate scans is unreadable noise — but it
is the first thing to check if that gate is ever widened again.

**Re-verified after the consolidation pass that added O22–O27.** `CONTRIBUTING.md`
line 104 still carries the literal, is still unmarked, and is still green — scope
(a) remains `prometheus/rules`, `grafana/dashboards` and `docs`, with no root glob,
so root files stay outside both arms. Nothing changed; the point of saying so is
that "still green" here is a fact someone re-checked, not an assumption inherited
from the last person who looked.

### Corrections carried forward

*Two enforcement claims made earlier in this file are false against
`.github/workflows/gates.yml` as it stands. The findings are left where they are —
they were real, and both were about the right thing — with the tense corrected
here:*

- § *Doctrine stated but unenforceable*, item 3 says the ADR-010 Qdrant rebuild
  proof is "**Now a merge gate**". It is not. `gates.yml` deliberately excludes
  everything needing a running Qdrant or an installed dependency set, and `ci.yml`
  has not been authored. The proof is **specified, not wired**; the merge-queue
  shape and the assert-the-payload-not-the-count reasoning both stand and should
  be built as written.
- The same section's item 4, "**All three are in CI**" for the telemetry gates, is
  **true** as of the `observability-rules` job — recorded alongside its neighbour
  so the pair reads as a check that was made rather than a claim that was skipped.

### The last two false claims sat in skill files — E4 *(CLOSED)*

> **Closed.** Both skill files were corrected by their owner after this was
> written, and both took the *owed, not deleted* form this section argued for:
> `github-actions-pipeline`'s description and gate table now split **wired today**
> from ***owed***, its "every gate is a required check" non-negotiable is qualified
> with *"and so is one that was never written"*, and `security-scanning-toolchain`'s
> licence row reads ***owed*** — *will block both times; today it is invoked by no
> workflow and no `Makefile` target*. The neighbouring model-weights row was
> corrected in the same pass, which was the point of recording the pair.
>
> The verification below is retained unedited. It is the evidence the corrections
> were made against, and re-deriving it is exactly the cost this section exists to
> avoid paying twice.

The thirty false claims were corrected across `docs/`, the service trees, and the
three root files named above. **Two were held back, in two skill files**, because
nothing in `docs/` may rewrite `.claude/skills/**` — the owning agent does. They
are recorded here with the verification, so the correction is a small edit against
a stated finding rather than a fresh audit.

This matters more than its size. **Skills are the source documents the rest of the
repo cites**, so a false enforcement claim in one does not stay in one: it is
restated in a `docs/` section, in a code comment, and in the next agent's
reasoning, and by then the original is three citations away from whoever notices.

> **Superseded as a statement of fact on 2026-08-10; retained as evidence.** The verification
> below was true when it was made and is false now: `ci.yml` was authored, so
> `.github/workflows/` holds **two** files, and five of the nine gates the E4a table marks
> **no** have since been wired (`size-limit`, the form-rules diff, the RLS tripwire's first half,
> and the install-dependent test jobs generally). Do not read the table below as current
> enforcement — read `.github/workflows/ci.yml`'s `DELIBERATELY NOT HERE` header, which is
> maintained against the tree. The table stays because E4's whole point was that a false
> enforcement claim propagates through three citations before anyone notices, and deleting the
> evidence for a correction is how that starts again. The re-baseline is recorded in
> § *The scope re-baseline — 2026-08-10*.

**Verified against `.github/workflows/gates.yml` as it stood on the day E4 was written** —
`.github/workflows/` contained **exactly one file**, `gates.yml`, with six jobs:
`enforcement-greps`, `observability-rules`, `compose-ci-tag-drift`,
`compose-invariants`, `repo-artifact-consistency`, `boundary-greps`. There was no
`ci.yml` and no `release.yml`.

**E4a — `github-actions-pipeline`'s description asserts nine gates as "the gates CI
owns rather than review". Four are wired; five are not.**

| Claimed gate | Wired? | Evidence |
|---|---|---|
| Laravel tenancy grep | **yes** | `enforcement-greps` → *Tenancy escape hatches must be annotated* |
| Deletion-key grep | **yes** | `enforcement-greps` → *Deletion targets identifiers, never text* |
| Table allow-list grep | **yes** | `enforcement-greps` → *The data plane writes only its four allow-listed tables* |
| Telemetry checks (all three) | **yes** | `observability-rules` → alert metadata, Alertmanager route coverage, `promtool check`/`test rules` |
| ADR-010 Qdrant rebuild proof | **no** | `gates.yml` header names it among the jobs that "land in `ci.yml` later and are out of scope here"; no job runs `pytest`; already stated one subsection above |
| `/metrics` catalog diff | **no** | no scrape, no diff step anywhere in the file; needs a running Collector — this is E1 |
| form-rules diff | **no** | no `artisan` invocation in the file; needs `composer install`, which the header excludes by scope — also E1 |
| `size-limit` | **no** | named in the header's own list of excluded dependency-installing jobs; needs `pnpm install` |
| RLS tripwire | **no** | no occurrence of `rls`, `row level`, or a non-owner role anywhere in the file; needs a live PostgreSQL |

The skill's *Definition of done* restates four of the five unwired ones as
completed checkboxes, and its gate table gives each a `Tier` and a command as
though it runs. **The fix is not deletion.** Every one of the five is a gate that
should exist and whose reasoning in that skill is sound; four of them are blocked
only on lockfiles. The correction is a tense change plus an explicit
*owed, not wired* marking — a claim marked as owed is useful, a claim stated as
done is a lie, and deleting the ambition loses the design along with the error.

**E4b — `security-scanning-toolchain`'s licence row says the gate "blocks both
times". It blocks neither time.** `scripts/security/license_gate.py` is invoked by
nothing: the only mention of it in any workflow is the `gates.yml` header comment
listing it under *DELIBERATELY NOT HERE*, and no `Makefile` target calls it either.
Two independent blockers, both already recorded as **E2**: no lockfile exists in
any ecosystem, so no SBOM exists and the script exits 2 on empty inputs; and
`models.manifest.toml` carries four unresolved revisions (finding **O4**), which is
what the model arm reads. The two-run design — lockfile SBOMs pre-merge, image
SBOMs at release — is right and should be built as written; it simply has not been.

The neighbouring row, **model weights → "blocks"**, is false in exactly the same
way and for the same reasons: `picklescan` appears in no workflow, and E2 already
records that `check_models()` is unreachable from `main()` on the no-SBOM path.
Recorded together because correcting one row and leaving its neighbour is how a
half-corrected table reads as verified.

### Still open

- **E1 — the metric-catalog and form-rules diffs have no gate.** Both are
  specified against generated manifests; neither manifest is generated by anything
  today. Closes when `ci.yml` runs the generator and diffs. Revisit condition: the
  first ad-hoc metric label that reaches a dashboard without a catalog entry.
- **E2 — the licence and vulnerability gates are wired to nothing.** Blocked on
  lockfiles (`license_gate.py` returns 2 with no SBOM inputs, and its
  `check_models()` arm is unreachable from `main()` on that path) and on a missing
  test (`vuln-ignores.toml`'s sole entry names
  `services/ai-service/tests/security/test_docling_no_remote_render.py`, which does
  not exist). Dated, not open-ended: the EU CRA's vulnerability-reporting
  obligations apply from **11 September 2026**. Recorded in `SECURITY.md`.
- **E3 — the error-taxonomy-to-alert-name diff is red by design.** Eight error
  classes are uncovered and the `TODO(ADR)` block at the top of
  `infrastructure/observability/prometheus/rules/kb-alerts.yml` enumerates them.
  Closes when each uncovered class has a rule or a recorded decision — the gate
  must not land before then, because a required check that cannot pass is disabled
  within a week and never comes back.

- **E4 — two false enforcement claims in `.claude/skills/`. *Closed*,** by the two
  skill edits described one subsection above. Left in this list rather than removed
  because it is the only E-item that closed, and a list of open items with no
  record of what left it does not show whether the process works.

None of E1–E3 is a decision. Each is work that has not been done, and none of them
should be closed by relaxing the claim instead of building the check. **E4 was the
mirror image**: nothing to build, and the only wrong move would have been to close
it by deleting the claim rather than correcting its tense. It closed the right way.

**Still owed, and distinct from E1–E4:** the four ADR-029 skill corrections listed
under § *Corrections this ADR owes to the skill library*. Those are not enforcement
claims — they are skills describing a **rendering the landed code no longer
produces**, `kb-error-taxonomy`'s `internal_dependency` row (still `503`, still
unqualified) foremost among them. E4's closure does not touch them.

## The scope re-baseline — 2026-08-10

Numbered **R1…R8**, a namespace of its own, appended and never inserted. It exists for one reason
that none of the namespaces above covers: every finding above records something *found wrong in the
specification or in the code*. These record documents that went **false about the repository**,
which is a different detector and a different owner.

**What happened.** The scaffolding phase declared six Non-goals — *no business logic, no migrations,
no controllers, no retrieval stages, no provider adapters, no CI workflows*. An inventory on
2026-08-10 established that **all six have been crossed**, and put roughly 40% of the tree past that
line. *(The percentage is the inventory's; what was re-measured here is the six categories
themselves — six migrations under `services/core-api/database/migrations/`, three controllers, six
route files, ~20,000 lines under `services/ai-service/app/`, five provider adapter modules, and two
workflow files.)* Each addition was individually justified and most were forced by ADR-030 and its
consequences ADR-031…035 — **but nobody redrew the line**, so several documents kept asserting a
skeleton that no longer existed.

**The ruling was: keep every line of code, correct the documents, delete nothing.** The line as
redrawn: *build what is self-contained; do not build the five provider wire adapters, the chat
router, or the `rt/v1` / `sdk/v1` control plane.*

**The pattern behind six of the eight.** Every one of R1–R4 and R7–R8 is a **count** stated in prose
— one workflow file, four lockfiles, four tables, three unresolved model shas, zero migrations, zero
FormRequests. A count is a claim that decays on the next commit, in a file nobody re-reads, and it
decays *silently* because prose is not executed. ADR-033 already said this for the write allow-list
("admission is three properties, not a count"); R1–R8 are that argument arriving for everything else.
**Where a rule was available it replaced the count**; where only a fact was available the fact was
re-measured and the measuring command written beside it.

**~~Whether that becomes a convention is not decided~~ — it is decided.**
[**ADR-036** was accepted 2026-08-12](19-repo-structure-adrs.md#adr-036-a-documented-invariant-states-a-rule-or-a-measuring-command-never-a-count)
as **option (a), narrowed**: ban a count only where it is an *invariant* — where a legitimate change
could make it wrong and a reader would act on the number as the rule — and let orientation counts
stand provided the sentence names where the authoritative list lives. The operative test is
*"would a legitimate change make this number wrong?"*, calibrated by the ADR's own examples
(*"seven jobs"* stays; *"`ALLOWED_TABLES` has four names"* does not). The paragraph above is kept in
struck form because the corrections in the table below were applied on the facts alone, before any
rule existed, and they stand regardless — a false number is worth fixing whether or not a rule ever
forbids it. What the acceptance cost on its first day is **G1**.

| # | Claim that went false | Verified with | Corrected to |
|---|---|---|---|
| **R1** | `.github/workflows/` holds `gates.yml` **only**, and no workflow builds an image (`CLAUDE.md`, `docs/22` § O22, `docs/22` § E4, `CONTRIBUTING.md` § *Specified, not yet wired*) | `ls .github/workflows/` → two files; `grep -c 'docker/build-push-action@v7' ci.yml` → **6** | Two files. `ci.yml` has seven jobs and builds six images. **O22 is closed** with its revisit condition stated |
| **R2** | Four `pnpm-lock.yaml` files; a root dependency would write "a fifth" (`CONTRIBUTING.md`) — **already recorded as [O26](#o26--six-pnpm-lockfiles-exist-where-the-contract-documents-four-and-the-gate-that-guards-the-count-fails-on-the-current-tree-gate-half-closed); see there for the measurement, and do not re-derive it here** | `find . -name pnpm-lock.yaml -not -path '*/node_modules/*'` → **6** | *One per workspace importer.* A dependency-free importer produces an **empty** lockfile, not **no** lockfile — the root and `packages/design-tokens` each carry 13 lines with `.: {}`. The invariant is *the root importer resolves nothing*, which `gates.yml` now asserts correctly. **Four scan targets, six files: both numbers are right about different things** |
| **R3** | The data plane writes **exactly four** tables (`CONTRIBUTING.md`, plus four skill files — see *Not corrected here*) | `app/db/writes.py` → six names | ADR-033's three admission properties, restated nowhere as a number. **DISCHARGED 2026-08-11** — every site named by this row and by *Not corrected here* is corrected; the remaining occurrences of the phrase are the record of the correction, and the measurement is below |
| **R4** | Model revision shas are `TODO-RESOLVE-SHA` and block the first build (root `README.md`) | `grep -c TODO-RESOLVE-SHA services/ai-service/models.manifest.toml` → **0** | All three resolved 2026-08-07. The remaining licence blocker is a *licence* question (CDLA-Permissive-2.0), not a sha |
| **R5** | `services/ai-service` is a skeleton where "everything else is a typed placeholder with a `NotImplementedError` body" (`services/ai-service/README.md`) | `grep -rc 'raise NotImplementedError' app/<subtree>` — **note `raise`; see the correction below** | A **rule**, not a list: `providers/` `crawl/` `deletion/` are the deliberate stubs, `evaluation/`'s orchestration is a stub while its arithmetic is not, `core/` `db/` `contracts/` `observability/` `worker/` `maintenance/` are written, and `ingestion/` `retrieval/` `rag/` `api/` are mixed **file by file** |

**R5's numbers were re-measured on 2026-08-11 and two of them were wrong — including one that was
wrong when it was written.** The row's corrected-to column originally read *"0 in `core/`
`observability/` `evaluation/` `worker/` `maintenance/`; 47 in `providers/`, 35 in `crawl/`, 30 in
`deletion/`"*, and the 2026-08-11 re-measurement replaced it with a second list of seven figures.
**Both lists are history. Neither is reproduced as current here, and no third list is written** —
that is ADR-036 applied to the row that is its own evidence, and the reason is **G1**: within one
day, two of the seven 2026-08-11 figures were false again. What stands is the rule in the row above
plus the command:

```bash
for d in services/ai-service/app/*/; do \
  printf '%-14s %s\n' "$(basename "$d")" \
    "$(grep -rc 'raise NotImplementedError' "$d" | awk -F: '{n+=$2} END{print n+0}')"; done
```

`raise` is load-bearing and stays: see the second bullet below. Run it; do not trust a sentence,
including this one.

Two distinct defects were found on 2026-08-11, and the second is the interesting one:

- **`providers/` moved 47 → 44** because task 2D implemented `retry_after_seconds`. Ordinary decay,
  and exactly what R1–R8 predict about a number in prose.
- **`evaluation/` was never 0.** `judge.py:230` is a *deliberate permanent* raise guarding Ragas'
  sync path (§9 of the effort's plan says so), and `tasks.py:80,117,149` are `start_run` /
  `score_case`, both explicitly out of scope. The row said 0 because the **command was wrong**, not
  because the tree changed: a bare `grep -c NotImplementedError` also counts prose — the word appears
  in comments in `app/observability/logging.py:381`, `app/worker/process.py:168`,
  `app/retrieval/collection.py:443` and `app/api/internal/v1/embedding.py:121` — and `grep -rc` prints one
  line *per file*, so a subtree with a mix reads as a column of numbers rather than a total. The
  correction is therefore to the **command**, not just to the figure, which is the difference between
  a number that decayed and a measurement that never measured what it claimed. This is ADR-036's
  argument arriving one level deeper: a measuring command is only better than a count if the command
  measures the right thing.
| **R6** | `C2` "decides whether retrieval keeps a sparse arm at all" (root `README.md`) | ADR-032 | ADR-032 restored the sparse arm. What remains open under C2 is the **tokenizer**, which ADR-034 makes a reindex to change |
| **R7** | "There is no toolchain in this repository yet… nothing here has been executed"; `php artisan migrate` runs "zero migrations"; the form-rules gate compares "zero FormRequests" (`services/core-api/README.md`) | `composer.lock` exists; `ls database/migrations` → **6**; `ls app/Http/Requests` → **2**, both with a committed `packages/contracts/rules/*.json` | Six migrations, two FormRequests, and a form-rules gate that is no longer vacuous |
| **R8** | `CONTRIBUTING.md` gives the FastAPI type-check as `uv run mypy .` while `services/ai-service/README.md` gives `uv run mypy` | `[tool.mypy] files = ["app"]` + `strict = true`; `ci.yml` runs `uv run --frozen mypy` | `uv run mypy`, no target. **These are not equivalent**: an explicit `.` overrides `files` and drags `tests/` and `scripts/` into strict checking |

### Not corrected here, and why

**~~Skill files still say the data plane writes "exactly four" tables.~~ CLOSED 2026-08-11, by the
owning agent, which is the whole reason it was recorded here rather than fixed here.**
`.claude/skills/kb-architecture-map/SKILL.md:60` and `.claude/skills/fastapi-service/SKILL.md:21`
stated it outright; both now name `ALLOWED_TABLES` in `app/db/writes.py` and ADR-033's three
properties instead — *"Read the tuple — do not restate its length here"* and *"the test is ADR-033's
three properties, not a count"*. `grep -rn 'exactly four' .claude/skills/` returns nothing, and no
file restates the four table names as a list. Nothing in `docs/` may rewrite `.claude/skills/**`;
this entry existed so the correction would be a small edit against a stated finding rather than a
fresh audit, and that is exactly how it closed. The replacement text is ADR-033's, verbatim: the row
is **derived and rebuildable** in the ADR-010 sense, **no public API path reads or writes it**, and
**Laravel owns its migration**.

*Two counts in this paragraph were wrong before it closed, and both are worth keeping visible.* It
originally read *"four skill files … and two more restate it downstream"*, which does not add up and
was never measured; the grep found **two**. Then, within the same working session in which that was
corrected to two, the owning agent's pass took it to **zero** — so a number written, measured, and
corrected went stale twice in one day. Per ADR-036's spirit the grep is now the claim. Run it.

**Re-run 2026-08-11, and the grep needs its scope stated or it reads as a regression.** This entry's
claim is about `.claude/`, and that is the measurement that matters:

```
$ /usr/bin/grep -rn 'exactly four' .claude/ ; echo "exit=$?"
exit=1
```

Widening the same grep to `.claude/ CLAUDE.md docs/` returns **six lines in three files** —
`CLAUDE.md:18`, `docs/22:2814`, `:2846`, `:2851`, `docs/19:504`, `:587` — and **not one of them
restates the invariant**. Every one is either this finding, ADR-033's reasoning, or ADR-036 quoting
the phrase as the defect it is named after. That distinction is the whole point and is easy to lose in
a handover: *a document that records a false claim contains the false claim*, so a grep aimed at the
claim will always find its own obituary. **The check is `/usr/bin/grep -rn 'exactly four' .claude/`,
scoped to `.claude/`, and it returns nothing.** A wider grep is not a stricter check — it is a
different one, and it fails on a correct tree. This is ADR-036's recommendation arriving one level
further in: a measuring command is only better than a count if its *scope* is the claim's scope.

**~~`gates.yml` still asserts the allow-list cardinality with `[ "$t" = "4" ]`.~~ CLOSED 2026-08-11,
and ADR-033's prediction was right down to the mechanism.** Task 2E replaced the cardinality
assertion with a **membership** check — `KB_TABLE_REVIEW_PIN` at `gates.yml:423`, which names all six
tables, is compared set-wise against the tuple imported from `app/db/writes.py`, and is **green**.
Its own comment states the honest limit: the pin stops a table arriving *unreviewed*; it cannot stop
a reviewer approving the pin without checking the three properties. ADR-033 said the fix was a
membership check and not `= "6"`, and that is what landed. Kept rather than deleted, because
`CLAUDE.md` cited the old assertion as a known-red gate for a further day after it stopped existing,
and that is the failure mode this whole section documents — a correction is only complete when
everything that cited the old claim has been re-measured too.

### A divergence between a workflow and the tree, documented rather than resolved *(CLOSED — the workflow was corrected)*

> **Closed 2026-08-11 by the workflow's owner, which is how it was supposed to close.**
> `ci.yml:33-42` now records that the directory exists and has grown — `README.md` plus **four**
> spec files, `test_collection_bootstrap.py`, `test_coordination_store.py`, `test_db_pool.py`,
> `test_sparse_statistics_purge.py` — and that the whole `-m integration` selection is what the
> `ai-service` job's second pytest step runs, so *"add the directory"* is no longer part of the owed
> work. Re-measured: `ls services/ai-service/tests/integration/` returns exactly those five names.
> The rebuild proof is **still correctly held back**, now for the two reasons that survive:
> `test_adr010_rebuild.py` does not exist and there is no `app.maintenance.rebuild_index` to invoke.
> The original text is kept below because the *shape* of the finding — a justification that is right
> for a reason that has silently stopped being true — recurred twice more in this effort, as F2 and
> as the `[ "$t" = "4" ]` correction one subsection above.

`.github/workflows/ci.yml`'s `DELIBERATELY NOT HERE` header justifies holding back the ADR-010
rebuild proof on the grounds that *"neither that file nor the `tests/integration/` directory
exists."* **The directory exists** — `services/ai-service/tests/integration/` holds `README.md` and
`test_sparse_statistics_purge.py`. The *rest* of that justification still stands
(`test_adr010_rebuild.py` is absent and there is no `app.maintenance.rebuild_index` to invoke), so
the gate is correctly held back for a reason that is now half-stale. Recorded rather than edited:
the workflow is owned elsewhere, and a justification that is right for the wrong reason is exactly
the thing that gets cited later as though it were checked.

## Found while completing the stubs — 2026-08-11

Numbered **F1…F13**, a namespace of its own, appended and never inserted. R1–R8 record *documents that
went false about the repository*. These record something else: facts the stub-completion batches
surfaced that no document held at all, three of which are invariants nobody would have guessed and
one of which corrects an instruction a source file was giving about itself.

**F7–F12 were appended on 2026-08-11 by the batch that recorded Batches 8 and 9.** They keep the
detector but widen the source: F7–F10 are what two infrastructure batches found while fixing something
else, and **not one of the four was the finding its agent was sent to fix**. F9 is a *refutation* — a
finding withdrawn on measurement — and F10 is a correction to a figure this effort's own planning
documents repeated three times. Both are kept at full length deliberately: a findings log that records
only confirmations cannot be used to judge how much the next finding is worth. F11 and F12 are the two
contract gaps ADR-030's degraded path opened, which is a third detector again — nothing here went
false, and nothing here is a bug; they are *requirements written into skills and ADRs that no artifact
in the tree can satisfy*.

**F13 was appended on 2026-08-11 by the batch that closed F8's remainder.** It is a fourth detector:
not a document that went false and not an unsatisfiable requirement, but *a setting that reads as
configuration and configures nothing*. It is filed here rather than in the enforcement-claim audit
(E1–E4) because an inert knob is not a false claim about a check — it is a false affordance, and it
fails in the opposite direction: nobody asserted it worked, and everybody assumed it did.

### F1 — the `ai-service` image was nondeterministic, not merely broken

The symptom was an unbuildable image: any edit under `services/ai-service/` invalidated the layer
above `docling-tools models download`, which then died on `ImportError: libxcb.so.1` out of
`rapidocr → cv2`. The obvious reading — *a server image is missing OpenCV's X11 libraries* — is
wrong, and acting on it would have added X11 to a headless image and hidden the real defect.

**Two OpenCV distributions were installed at once.** `pyproject.toml` correctly pins
`opencv-python-headless`, and `rapidocr` independently requires plain `opencv-python`. Both
distributions' `RECORD` files claim **the same path**, `cv2/cv2.abi3.so`. A wheel installer does not
arbitrate that; the file that lands is the one written **last**. So which OpenCV the image contained
was decided by **unpack order**, not by the manifest — the pre-existing `knowledgebot/ai-service:dev`
happened to land on the headless `.so` and therefore happened to work, and a minimal reproduction
flips the outcome on install order alone with the same two distributions. *An image that builds is
not evidence that the next identical build will.*

**Fixed with a `uv` override** (`pyproject.toml:251-253`) declaring `opencv-python` under the marker
`python_version < '3'`, which can never apply — which is how `uv` drops the edge entirely rather than
resolving it. `uv.lock:16` records the override in its `[manifest]` block, so `uv sync --frozen`
fails if the two ever disagree.

**The invariant worth recording, because it is not discoverable from the tool's output:** `uv`
**silently ignores an override naming a package nothing requires** — no warning, no error, exit 0. A
future `rapidocr` bump that drops or renames its OpenCV requirement therefore leaves the override
line looking correct and doing nothing, and the failure resurfaces as an unpack-order coin flip. That
is why the fix is not only the override but a gate: `gates.yml`'s `repo-artifact-consistency` asserts
exactly one `opencv*` in `requirements.lock` and that it is the headless one, the override present in
`pyproject.toml`, and the `[manifest]` line present in `uv.lock`, with a non-vacuity floor.

**Revisit condition:** any `rapidocr` version bump. The bump must re-run the **OCR proof** — a full
detect/classify/recognise pass over a scanned fixture inside the built image — and not merely the
build, because `import cv2` succeeds under both distributions. (`hasattr(cv2, 'imshow')` proves
nothing either: headless OpenCV 5 exposes the symbol and raises `cv2.error … not implemented` only
when it is called.)

**Owed to a skill, not fixed here:** none of `ocr-pipeline`, `docling-parsing`,
`docker-compose-stack` or `security-scanning-toolchain` records the dual-OpenCV hazard.
`.claude/skills/**` is not writable from `docs/`; recorded in E4's form so the correction is a small
edit against a stated finding.

### F2 — `FastAPIInstrumentor.instrument_app` does not add middleware, and the difference is silence

`app/observability/otel.py:5` used to instruct the caller to run `configure(app=app)` from the
FastAPI lifespan. The Batch 3 brief challenged that on the grounds that Starlette raises
`Cannot add middleware after an application has started` once the stack is built, and the lifespan
scope is a `__call__`. **Both the instruction and the challenge were wrong, and the challenge was
wrong in the direction that matters.**

Measured against the pinned `opentelemetry-instrumentation-fastapi==0.65b0`: `instrument_app` does
not call `add_middleware` at all. It **replaces `app.build_middleware_stack`** — a method
`Starlette.__call__` invokes exactly once, lazily, and caches. The lifespan scope *is* that first
`__call__`, so instrumenting from the lifespan installs a builder that is never called again. There
is **no exception and no warning; there are simply zero spans.** Measured both ways:
`lifespan → spans=[]`, `create_app → spans=['GET /x http send', 'GET /x http send', 'GET /x']`.

Recorded here rather than only in the code because the failure has no signal, and because a
plausible-sounding mechanism ("it will raise") would have led to the right placement for a reason
that does not hold — leaving the next person free to move the call back the moment they observe no
exception. `configure_telemetry(app=app)` is now the **last** statement of `create_app()`, the
docstring is corrected, and the measurement is a test
(`test_instrumenting_from_a_lifespan_produces_no_spans_at_all`), run against a private
`TracerProvider` so it never touches the one-shot global.

**Revisit condition:** any bump of `opentelemetry-instrumentation-fastapi` or `starlette`. This rests
on an implementation detail of a `0.x`-versioned package, not on a documented contract.

### F3 — `repo-artifact-consistency` cannot go green before the first commit, and the gate is right

Its design-tokens step fails with *"the token build emitted untracked file(s)"* and *"only 0
generated token file(s) tracked"*. The cause is not the token build, which is idempotent: it is that
**nothing in this repository is committed yet.** `git ls-files packages/` returns **0**, and
`git ls-files | wc -l` returns 128 — every one of them under `.claude/`, `docs/`, `CLAUDE.md`,
`KnowledgeBot-AI.md` or `.gitignore`.

Recorded because the failure text names the wrong thing to fix, and the next person to read it will
go looking at the token build. It is a **precondition, not a defect**, and it clears the moment the
tree lands — which is blocked on #66, the two live-credential files that are not gitignored. Nothing
should be changed in the gate to make it pass sooner; a gate that tolerates zero tracked generated
files is a gate that cannot detect a generated file nobody committed.

### F4 — both read-only reviews returned a negative verdict, and every finding is closed

The two reviewers run after the implementation batches returned **`needs-changes`** (security) and
**`drift-found`** (contracts). Recorded as a result rather than a status line, because a read-only
pass that returns "looks fine" is indistinguishable from one that did not look — and because two of
their findings were closed by *deferral with a tripwire* rather than by a fix, which is a different
outcome and needs to be legible later:

- **A 409 the taxonomy cannot render.** `kb-internal-api-contracts` specifies a `409` for an
  `X-KB-Contract-Version` mismatch. The 18-class taxonomy has **no row that renders 409**, and adding
  one is a change to a table Python, PHP *and* TypeScript each transcribe, now guarded by two parity
  tests. Implementing it as `VALIDATION` would render 422 and put a third spelling of one rule in the
  tree. Deferred deliberately, with `test_the_taxonomy_still_has_no_row_that_renders_409` left behind
  so the day a 409 row exists, the deferral fails loudly instead of being forgotten.
- **`X-KB-Config-Version` has two contradicting definitions.** One skill calls it a *monotonic
  integer*; another skill and **ADR-011** describe a *hash of the configuration snapshot*, and a hash
  satisfies the second and cannot satisfy the first. It is inert today only because nothing reads the
  value — which is also why the missing consumer cannot simply be written: whoever writes it must
  first decide which definition is real. **Deliberately not resolved in this file.** The conflict
  spans ADR-011 and two skill files, and it is being resolved end to end by the agent that owns
  `.claude/` so the statements cannot diverge again. If ADR-011 needs an edit it will arrive as a
  supersession with its own number, not as an edit in place.

  > **CLOSED 2026-08-11, in favour of the hash, and ADR-011 needed no supersession — which is the
  > part worth recording.** Verified against the files rather than reported: `/usr/bin/grep -rn
  > monotonic .claude/skills/ docs/` returns **19 lines and not one of them is about
  > `X-KB-Config-Version`** — every hit is `time.monotonic()` in a latency snippet or a deadline
  > rule. `kb-internal-api-contracts/SKILL.md:68` now defines the header as *"a hash of the resolved
  > configuration snapshot sent in the body, rendered as a decimal integer. **Not a counter and not
  > ordered** — comparable for equality only"*, and `:81` gives the shipped derivation
  > (`InternalAiClient::snapshotVersion()`, a 56-bit slice of `sha256(body)`) together with the
  > reason it must be a hash, which is **ADR-011 property 1**: rotating a credential must not move
  > the version. `pydantic-contracts/references/model-versioning.md:5` agrees, and
  > `internal-chat-request.md:53`'s `config_version: int` is the decimal rendering rather than a
  > third definition. So the two statements were reconciled *onto* ADR-011 rather than against it,
  > and the deferral resolved the way it was set up to: the missing consumer can now be written,
  > because the question it had to answer first has an answer. The 409 bullet above is untouched and
  > is still deferred behind `test_the_taxonomy_still_has_no_row_that_renders_409`.

### F5 — two `apps/web` test commands run zero tests, and one of them is documented as if it were coverage

Measured: `pnpm exec playwright test --list` in `apps/web` returns `Total: 0 tests in 0 files`
(`apps/web/tests/e2e/` holds only `.gitkeep`), and the `components` vitest project returns
`No test files found` (`apps/web/tests/components/` is empty, shielded by
`passWithNoTests: true` at `apps/web/vitest.config.ts:16` — at the config root rather than inside a
project block, and its own comment says to delete it with the first component spec).

Neither is a defect on its own — the specs have not been written. The finding is that
`CONTRIBUTING.md:36` lists `pnpm web:e2e` in its runtime-command table with nothing marking it empty,
so the entry **reads as coverage**, and `apps/widget`'s Playwright suite — `pnpm exec playwright test
--list` there returns `Total: 22 tests in 5 files`, real cross-origin, real sockets — sits one
directory away to make the reading plausible. `CONTRIBUTING.md` is outside this file's ownership;
the honest statement has been written into the new `apps/web/README.md` instead, and the
`CONTRIBUTING.md` row is owed to whoever owns it.

**Revisit condition:** the first spec in `apps/web/tests/e2e/` or `apps/web/tests/components/`. Both
of these entries should be deleted the day they stop being true, along with `passWithNoTests`.

### F6 — a seventeenth false enforcement claim, in `apps/mobile/jest.config.js`

`apps/mobile/jest.config.js:35` explains why the production streaming module imports `fetch` from
`'expo/fetch'` by name rather than through an indirection: *"which is what CI greps for"*.

**No such grep exists.** Verified 2026-08-11: `grep -rn 'expo/fetch' .github/workflows/` returns
exactly one hit, a **comment** at `ci.yml:691` explaining the Node-side transport substitution, and
`gates.yml` does not mention Expo at all. Nothing in either workflow, and nothing in
`apps/mobile/eslint.config.mjs` or `eslint.base.mjs`, would notice the import being rewritten as
`const { fetch } = require('expo/fetch')`, aliased through a local module, or replaced with
`globalThis.fetch` outright.

This is E-series shape, not F-series — the enforcement-claim audit found **sixteen** cases where a
document or a comment described a check that does not run, then created a seventeenth while
cataloguing them. It is filed here rather than as E5 because it was found by a different pass, and
because its consequence is narrower than E1–E3: the claim is *load-bearing on a device only*. Hermes'
XHR-backed `fetch` fails by succeeding — the whole answer arrives at once and every assertion about
the final text still passes — so the import is the one line standing between a working stream and a
regression that no suite in this repository can see, and the comment tells the next reader it is
guarded when it is not.

**Two honest fixes, and the choice is the owner's:** add the grep (a line-based check that
`stream-answer.ts` contains `from 'expo/fetch'`, with a non-vacuity floor so a renamed file cannot
silently pass), or correct the comment to say the import is a **convention** enforced by review. The
wrong fix is deleting the sentence — the reason for the by-name import is real and worth keeping.

**Owner:** `mobile-engineer` for the comment; `platform-devops-engineer` if it becomes a gate.
**Revisit condition:** it recurs the moment any other source comment in this tree says "CI checks
this" without a named job and step.

### F7 — two more enforcement claims that name no runner: the vendored SAST rules, and a prune command with no table

Two claims of the same shape as F6, found by two different batches, and each is worse than a stale
sentence because the artifact it describes **exists, looks maintained, and is counted**.

**(a) The vendored semgrep ruleset is never executed by any workflow.** `scripts/security/rules/`
holds five rule files — `kb-php-tenancy.yaml`, `kb-python-fetch-safety.yaml`, `kb-python-secrets.yaml`,
`kb-python-tenancy.yaml`, `kb-ts-boundary.yaml` — carrying 31 rules and a `LICENCES.md`. Measured
2026-08-11: `/usr/bin/grep -rn 'semgrep\|opengrep' .github/workflows/` returns **two lines, both
comments**, at `ci.yml:147-148`, and both are the honest record of why the scanner is not wired
(`ghcr.io/opengrep/opengrep:v1.26.0` and `:latest` both answer `denied` to an anonymous pull, and
`opengrep/opengrep` does not exist on Docker Hub). What `gates.yml` runs, at `:1705`, is
`scripts/security/rule_count_check.sh` — a **non-vacuity floor on the number of rules**, not a scan.
The distinction is invisible in a job log: the step is named for the rules, it passes, and the rules
are real. **A rule count is a claim about the ruleset; it is not a claim about the code.** Nothing in
this repository has ever been matched against any of the 31 patterns, several of which encode
non-negotiables (`kb-python-tenancy.yaml`, `kb-ts-boundary.yaml`).

`ci.yml`'s comment is not the false claim — it is the model answer, and it is why this is (a) rather
than an E-item against the workflow. The false part is everywhere the ruleset is cited as a control.
**Owner:** `platform-devops-engineer`. Two honest fixes and the choice is the owner's: authenticate
the pull and run the scan, or state in `scripts/security/rules/README`-adjacent prose and in
`SECURITY.md` that the ruleset is **authored and unexecuted**, with the floor named as a floor.

**(b) `sanctum:prune-expired` is scheduled against a table nothing creates.**
`services/core-api/routes/console.php:80-81` schedules `sanctum:prune-expired --hours=24`. Measured:
`ls services/core-api/database/migrations/` → six files, and
`/usr/bin/grep -rln personal_access_tokens services/core-api/database/migrations/ | wc -l` → **0**;
`/usr/bin/grep -rn HasApiTokens services/core-api/app/ | wc -l` → **0**. So the command is scheduled
against a table no migration creates, on a `User` model that mixes in no token trait. It is not a
false *enforcement* claim in F6's exact sense — nothing claims CI checks it — but it is the same
defect one layer down: **a scheduled command is a claim that something is being maintained**, and an
operator reading `console.php` concludes token hygiene is handled. `console.php`'s own convention is
to comment out commands that cannot run yet, which is the fix. **Owner:** `control-plane-engineer`.

**(b) CLOSED 2026-08-13, and the framing above was wrong in a way that changes the fix.** The entry is
commented out, so the false claim is gone. But *"the command class ships, the table does not"* filed it
beside `queue:prune-failed` as though both were waiting for a migration, and under
[ADR-038](19-repo-structure-adrs.md#adr-038-one-authentication-mechanism-on-the-admin-surface-enforced-by-three-negatives)
it is **permanently** moot on this surface rather than pending: nothing mints, so a pruner is not an
entry waiting for a table but a no-op with a cron slot, and scheduling it would suggest a second
credential exists where the whole point is that one does not. A migration would therefore **not** make
it legitimate; a future mobile personal-access-token surface landing four things at once would. The
banner in `routes/console.php` now says exactly that, and it records the measurement the original
finding did not have — against the applied migrations the command **exits 1** with `SQLSTATE[42P01] …
relation "personal_access_tokens" does not exist`, so it would not prune nothing, it would fail once an
hour forever, dispatching `ScheduledTaskFailed` while `schedule:list` displayed the entry as handled.
Full entry at § **H4**, which also records what the owning skill still owes.

**One count in this entry is inherited and is worth flagging rather than repeating.** These are
referred to elsewhere in this effort as the *eighteenth and nineteenth* false enforcement claims,
continuing from F6's *seventeenth*. That sequence is built on a conflation: the enforcement-claim
audit's **False** bucket is **30** (§ *The figures*), while **sixteen** is the count of *self-tripping*
claims (§ *Self-tripping enforcement*) — a different bucket entirely, and F6 borrowed it. The ordinals
are kept because three planning documents already use them and renumbering breaks more than it fixes;
they are **labels, not measurements**, and the measurement is the two greps above. **Revisit
condition:** the next document that states an ordinal in this sequence without naming which bucket it
counts.

### F8 — the collector was misconfigured in two ways that both fail quietly, and one had switched a whole plane's exemplars off

Both found by the batch sent to fix collector routing, and neither was that.

**Three deprecated aliases, not one.** The canonical component names were read from
`otelcol-contrib components` inside the pinned image rather than from documentation, and the file
carried three old spellings: `filelog` → `file_log` (`infrastructure/docker/otel/collector.yaml:48`),
and `otlphttp` → `otlp_http` twice (`:213` `otlp_http/tempo`, `:218` `otlp_http/loki`). On 0.158.0 a
deprecated alias still resolves and only warns — `warn builders/builders.go:40 "filelog" alias is
deprecated` — so the cost was three warn lines per boot and a file that would stop parsing on the
release that removes them. The file now boots with **0** warn/error lines, which is the assertion
worth keeping: *zero warnings* is checkable, *"we fixed the alias"* is not.

**`OTEL_METRICS_EXEMPLAR_FILTER=trace_based` is not merely noisy on the PHP SDK — it is rejected, and
the fallback is silence.** The variable is one the SDK knows; the *value* is one it does not accept,
so it installs `NoneExemplarFilter`. **Exemplars were off for the entire control plane**, while
`infrastructure/observability/grafana/provisioning/datasources/prometheus.yml:32` configured
`exemplarTraceIdDestinations` and therefore asserted they were on. The Grafana panel does not error
when no exemplar arrives; it renders the same graph without the little diamonds, which is
indistinguishable from a quiet period. Fixed per runtime rather than globally, because the two SDKs
genuinely differ: `infrastructure/docker/env/core-api.env:217` is now `with_sampled_trace` and
`ai-service.env:278` stays `trace_based` (both mirrored in the `.example` twins).

**The invariant, which is not discoverable from either tool's output:** a telemetry SDK that
*recognises* a variable and *rejects* its value degrades to the safe default, and the safe default for
an exemplar filter is *none*. So a typo in an observability setting removes observability, and the
surface you would use to notice is the one that was removed. **Revisit condition:** any OTel PHP or
Python SDK bump — the accepted value set is an SDK property, not a specification one.

**The fix reached the container environment and stopped there — closed 2026-08-11.** Laravel's own
`services/core-api/.env` and `.env.example` carried `trace_based` for another day, so a developer stack
silently reproduced the defect while the containerized one was fixed; the Grafana datasource still
asserted *"the SDKs run with `OTEL_METRICS_EXEMPLAR_FILTER=trace_based`"*, which had become false for
one of the two runtimes; and `opentelemetry-instrumentation`'s environment table listed the value under
a heading reading *"identical keys in all four runtimes"*. All four are corrected, and the count is now
a **command** rather than a number:
`grep -rn OTEL_METRICS_EXEMPLAR_FILTER infrastructure/docker/env services/*/.env*` returns seven lines —
four `core-api` reading `with_sampled_trace`, three `ai-service` reading `trace_based`. Re-measured
against the vendored SDK rather than trusted: `KnownValues.php:171-175` lists exactly three accepted
values, and calling `MeterProviderFactory::createExemplarFilter` through reflection inside
`knowledgebot/core-api:dev` returns `NoneExemplarFilter` for `trace_based` (with the warning at
`MeterProviderFactory.php(74)`) and `WithSampledTraceExemplarFilter` for `with_sampled_trace`.
**The general shape of the miss is worth more than the fix:** a per-runtime correction has one site per
runtime *per deployment shape*, and "the env file" is two files when a service can also be run outside
its container.

### F9 — REFUTED: the `attributes.level` compatibility arm matches, and deleting it would blind Loki for the edge proxy and for the collector itself

**The most valuable output of the collector batch is a finding it withdrew.** The arm at
`infrastructure/docker/otel/collector.yaml:91-102` — a second `severity_parser` reading
`attributes.level`, guarded by `type(attributes.level) == "string"` — was reported (in the brief that
commissioned the work) as matching nothing, on the reasoning that its stated rationale had died: the
Monolog integer-level defect it was written for was closed by the Laravel log-contract task, so every
first-party service now emits `severity`.

**It matches.** The receiver's `include:` is `/var/lib/docker/containers/*/*-json.log` — **every
container on the host**, not the four that speak our log contract — and **traefik** and
**`otel-collector`'s own log** both emit a string `level` with no `severity`. Deleting the arm would
have blinded Loki's level label for the edge proxy and for the telemetry pipeline's own health, which
is the pair you reach for first when the telemetry pipeline is the thing that is wrong.

**And the type guard is load-bearing for a different reason than the file claimed.** 0.158.0 does not
error on an integer `level`: it writes `SeverityText: "400"` with `SeverityNumber: Unspecified(0)` — a
**garbage label instead of an absent one**, silently. An absent label is visible in a query; a label
reading `"400"` looks like data.

Recorded as a finding rather than as "no change made", because the two are indistinguishable in a diff
and only one of them tells the next person not to try it again. **The general shape:** a compatibility
arm whose *stated* rationale has expired is not thereby dead — its rationale and its corpus are
different questions, and the corpus here was wider than the sentence describing it. **Revisit
condition:** the `include:` glob narrowing to first-party containers only. That, and not the Monolog
fix, is what would make this arm genuinely dead.

### F10 — a magnitude invented upstream, repeated three times, and the structural argument that survives without it

The collector-routing defect (`laravel-migrate` on a network with no route to `otel-collector`) was
briefed with a cost of **95 seconds** of OTLP retry backoff per run, and that figure was repeated in
three planning documents. **It is not supported.** All **8** preserved runs were read: every one ends
`cURL error 6: Could not resolve host: otel-collector … Export retry limit exceeded`, and every one
costs **0.54–1.11 s**.

The mechanism was right; the magnitude was invented. Both halves matter, and they fail differently — a
wrong mechanism sends the fix to the wrong place, while a wrong magnitude sends the *priority* to the
wrong place and is far harder to notice, because nobody re-measures a number that is only used to
justify work already agreed.

**The structural argument survives the correction intact**, which is why the fix landed anyway:
`OTEL_EXPORTER_OTLP_TIMEOUT=3000` bounds **one HTTP attempt, not the retry loop**, so the observed
cost is a property of how fast DNS fails on this host and not a bound anything configures. On a host
where the name resolves slowly, or resolves to something that accepts a connection and then hangs, the
same defect costs the timeout times the retry count. The fix is a **route** (`compose.yaml:755`,
`[data, observability]`) rather than `OTEL_SDK_DISABLED`, because muting the exporter would make ten
services that gate on `service_completed_successfully` wait on the one container emitting no span at
all.

Recorded here because the correction is about *this effort's own documents*, not about the repository:
an agent measured its own brief and refused the number in it. **Revisit condition:** the next figure
in a planning document that no report attributes to a run.

### F11 — three ADR-030-family decision records describe a state the code now refuses to construct

Not drift in the usual direction. Each sentence below was **true when written**, was superseded by a
decision recorded *elsewhere in this file*, and now describes a configuration that
`app/providers/capabilities.py` makes **unconstructible** rather than merely absent — which is the
part that makes them worth listing rather than leaving to decay.

| Where | The claim | Measured 2026-08-11 |
|---|---|---|
| [`docs/19` ADR-031](19-repo-structure-adrs.md#adr-031-the-organization-designates-the-embedding-connection-disagreement-is-a-refusal) § *Trade-off* | "four of the five embedding cells are `UNVERIFIED` and fail closed, so an organization on a vendor that does publish an endpoint can be refused for a missing fixture" | Zero cells are `UNVERIFIED`. The 10 occurrences of the token in `capabilities.py` are prose, the enum member's docstring, and the `_UNKNOWN` fallback for a provider that is not one of the five |
| This file, § ADR-031 *Consequences*, 2nd bullet | the same claim | Same. Annotated in place with a dated correction block rather than rewritten |
| `docs/23` row *"Four of the five embedding cells…"* | the same claim | Already struck through and marked **INVERTED — CLOSED 2026-08-10**; kept because its prediction was right |

**Why the ADR text is not edited.** ADR-031 is `Accepted`, its **decision** is unaffected, and a
trade-off is a record of what was believed to be true at the moment of deciding — editing it in place
would make the register agree with itself and destroy the evidence that a refusal *was* rested on a
matrix that had four blanks in it. The register's convention for exactly this is a supersession
(ADR-033 replaced ADR-012's table list, and ADR-037 replaces ADR-026's fail-open reasoning). This one
does **not** get a supersession, and the distinction is the finding: ADR-033 and ADR-037 each corrected
something a *reader would act on* — a table list, a mitigation — whereas here the decision, the rule
and the revisit condition are all still exactly right and only an illustrative cost figure has moved.
**A supersession per stale illustration would make the register unreadable and would devalue the two
real ones.** So it is recorded, and the pointer sits at the argument.

**Revisit condition:** a **fourth** ADR trade-off found describing a superseded state. Three is a
list; four is a pattern, and the answer at four is a convention — most likely a dated
`As measured YYYY-MM-DD` marker on any trade-off that quotes a figure — rather than a fourth entry
here.

### F12 — the internal chat body carries one credential, and one turn can need three

> **CLOSED 2026-08-12 by ruling — option (a), the keyed map.** Ankur ruled
> `provider_credentials: {connection_id: SecretStr}`. The record of the ruling, its reasoning and its
> consequences is [**G7**](#g7--27--f12-the-internal-body-carries-a-keyed-map-of-credentials-not-one-credential);
> the text below is kept unedited as the statement of the problem, because the options and the
> already-done work it lists are what made the ruling cheap and are the reason (a) was reachable at
> all. **Read the "Options, none taken here" paragraph as history:** (a) is now taken.

**~~Open.~~ A ruling, not a defect to fix — and it is Ankur's.** Recorded here because the code that
raised it cites a finding name that leads somewhere else (see the pointer added to the **S12** row
above), so until this entry existed the question had no address.

`kb-internal-api-contracts` pins the Laravel → FastAPI body as
`{"config": {…}, "provider_credential": "sk-…"}` — **singular**, and ADR-011 is the reason it is a
top-level sibling of `config` rather than a member of it. That was written when one turn meant one
vendor. Post-ADR-030 a single chat turn can need **three** credentials: the **chat** credential, the
**embedding** credential to embed the question at stage 5, and the **rerank** credential. Under
ADR-031 the embedding connection is explicitly allowed to be a *different* connection from the chat
one — that is the whole content of the designation — and `EmbeddingRequest` / `RerankRequest` each
already carry their own `provider_connection_id`, which is the contract saying so in its own types.

**One resolution is already ruled out, and it is the one that looks cheapest:** FastAPI reading
`provider_connections` out of PostgreSQL to fill the gap. `kb-provider-adapter-contract` ("resolves
nothing from storage"), `kb-security-baseline`, and ADR-012/ADR-033's ownership rule all forbid it,
and ADR-011's rejected option (b) is that same idea under another name.

**What has already been done so the ruling is cheap when it comes**, and it is worth reading before
ruling: `app/providers/embedding_selection.py` resolves to an `EmbeddingConnection` — a
`connection_id` the control plane already knows how to decrypt, plus the `(provider, model)` pair that
names the space — and holds **no credential**, with an import-time check that refuses any field whose
name looks like a secret. `services/core-api/app/Services/Embedding/EmbeddingCandidate.php` holds the
same line on the Laravel side. So whatever keyed shape replaces the singular field, **its key is a
`connection_id`**, and both sides already produce one. Nothing in either module has to change when the
wire changes; if it did, that would be the signal the selection had been designed as a FastAPI-internal
value rather than a transmissible one.

**Options, none taken here.** (a) A keyed map — `provider_credentials: {connection_id: SecretStr}` —
which is a wire change on both planes and a change to what the never-forward rule has to cover, from
one field name to a map's values. (b) Per-surface siblings: `provider_credential`,
`embedding_credential`, `rerank_credential`, which is smaller and does not generalize to a fourth
surface. (c) Leave it singular and require every surface of a turn to resolve to one connection, which
is a **product** restriction — it would forbid exactly the configuration ADR-031 was written to
support. **Recommendation: (a)**, on the grounds that the key is already the identity both sides
compute, and (c) contradicts an accepted ADR.

**Nothing is blocked on it today**, which is why it is a ruling rather than a task: the five provider
wire adapters and the chat router are both explicitly out of the current scope, so no code exists that
would carry a second credential. It becomes blocking the day the chat router is written. **Owner:**
`contract-steward` to specify, Ankur to rule. **Revisit condition:** it stops being deferrable the
first time a query path calls `embed()` against a connection that is not the chat connection.

### F13 — five Collector settings that read as configuration and configured nothing, and an "agreement" between two files that nothing compared

`infrastructure/docker/env/observability.env` declared **nine** `KB_*` variables. `collector.yaml`
referenced **four**. The difference is a one-line measurement, and it is now written into the env file
itself so the next person runs it instead of trusting a paragraph:

```
comm -23 \
  <(grep -oE '^KB_[A-Z0-9_]+' infrastructure/docker/env/observability.env | sort -u) \
  <(grep -oE 'env:KB_[A-Z0-9_]+' infrastructure/docker/otel/collector.yaml | cut -d: -f2 | sort -u)
```

It printed `KB_OTEL_MEMORY_LIMIT_MIB`, `KB_OTEL_MEMORY_SPIKE_MIB`, `KB_TAIL_DECISION_WAIT`,
`KB_TAIL_NUM_TRACES`, `KB_OTEL_REPLICAS`, each under an explanatory comment describing the behaviour it
would have controlled. All five were **deleted rather than wired**, and the reasoning per knob is the
finding:

- **The two memory knobs sat under a false invariant, not merely an inert one.** The comment read
  *"MUST agree with the container limit in compose.yaml (512M)"* while `compose.yaml:1248` read
  *"must agree with the memory_limiter processor in collector.yaml"* — two files pointing at each other,
  a third literal (`limit_mib: 384`) doing the actual work, and nothing comparing any of them. An
  operator who edited this value to satisfy the stated agreement changed nothing at all. The repair is
  to make the agreement **computed**: `memory_limiter` now uses `limit_percentage: 75` /
  `spike_limit_percentage: 20`, which read the container's cgroup limit at startup. Measured through
  compose, not asserted — `deploy.resources.limits.memory: 512M` produces `HostConfig.Memory=536870912`
  under a plain `docker compose up`, and the Collector logs
  `Using percentage memory limiter {"total_memory_mib":512}` → `limit_mib 384`, i.e. **the same number
  the file used to hard-code**. Mutating compose to `256M` and recreating gives `total_memory_mib 256`
  → `limit_mib 192`; restored byte-identical. **Known fallback, stated because it is silent:** with no
  cgroup limit at all the percentage is taken against host RAM (measured: 7912 MiB → 5934 MiB), so the
  boot line reporting a total that is not the container limit *is* the signal that a deployment lost
  its limit.
- **Wiring the two tail-sampling knobs was tried and rejected on a measurement.** `${env:X}` on an
  **unset** variable does not fail: the container boots clean, prints *"Everything is ready"*, and the
  field takes the component default — while a *malformed* value fails loudly
  (`'num_traces' expected type 'uint64', got unconvertible type 'string'`, exit 1). So the failure mode
  is exactly the dangerous half: absent is silent, wrong is loud. The sizing rule is arithmetic over
  three values (`num_traces`, `decision_wait`, the policy list) that all live in `collector.yaml`, and
  splitting it across two files makes it uncheckable from either.
- **`KB_OTEL_REPLICAS` was unconsumable, not merely unread.** No compose file gives `otel-collector` a
  `deploy.replicas`, and the second replica it purported to configure is unsafe until the
  `load_balancing` exporter exists — the very warning its own comment carried. A dial offered for an
  unsupported topology is worse than no dial.

**The general shape:** an inert knob is not neutral. It reads as the supported way to change a
behaviour, so it absorbs the edit that would otherwise have gone to the file that matters, and the
change is then reported as made. **Revisit condition:** the `comm` command above printing anything —
it is in the env file's own header for that reason, and the reverse direction (a `${env:}` reference
with no declaration) is the same command with `comm -13`.

## The decision the credential-file audit forced — ADR-037

ADR-030 came from the product owner. ADR-031…035 came from code that could not be written without
choosing. **ADR-037 came from a container's exit code**, and it is the first entry in this register
whose whole content is *a fact every file that mentioned it stated confidently and backwards*.

### ADR-037 — the fail-open pair is the other way round, and the probe that would have caught it could not

**Status: `Accepted`. Supersedes [ADR-026](#adr-026--one-seaweedfs-container-with--s3config-two-valkey-services)'s
fail-open reasoning** and nothing else in ADR-026. Summary and the pointer entry are in
[`docs/19`](19-repo-structure-adrs.md) §28.

#### The measurement

Run against the pinned tags on 2026-08-11 as part of the credential-file audit (#66), four states per
image — mount absent (which makes Docker create a **directory** at the bind source), zero-byte file,
well-formed file, and for SeaweedFS the flag dropped entirely:

| | mount absent (Docker creates a directory) | zero bytes | what actually produces Allow-All |
|---|---|---|---|
| `seaweedfs:4.40` | **fatal, exit 255** | **fatal, exit 255** | omitting `-s3.config`, or a config parsing to `"identities": []` → anonymous `ListBuckets` **200** |
| `valkey:9.1.1` | **starts, WIDE OPEN** | **starts, WIDE OPEN** | — |

ADR-026 states both rows backwards. It says an absent identities file yields Allow-All (it is fatal),
and the mirror sentence — that Valkey refuses to start without its ACL file — was the reason
`users.acl` was treated as the *safe* one of the two committed credential files. A wide-open
`valkey-core` is the queue broker, the lock store, the rate limiter and the idempotency record for
both planes, and an unauthenticated `SET pwned 1` against it returns `OK`.

#### The half of ADR-026 that is right, and must not be lost in the correction

**`-s3.config` really is the fail-open path.** Dropping the flag from the compose command produces a
gateway that starts cleanly, logs nothing, and authenticates nothing — and so does a syntactically
valid config that parses to zero identities. That is why `bootstrap.sh` asserts an anonymous
`ListBuckets` returns 403 and why that assertion is the reason bootstrap is a script rather than a
README section. Both survive verbatim. What is wrong is only the *trigger* ADR-026 named: a mistyped
path, an unreadable file or a forgotten mount is a crash, not a silent opening. **Never "fix" a
SeaweedFS crash-loop by writing `{}` into the config** — that converts the safe failure into the unsafe
one, and it is the single most likely wrong move a reader of the old text would make.

#### Why the healthcheck could not have caught it

This is the part worth keeping, because it generalizes past both images. The probe was
`valkey-cli … ping | grep -q PONG`, and it returned **exit 0 against a wide-open server**:

```
$ valkey-cli --user kb-observer --pass x --no-auth-warning ping 2>/dev/null
PONG
$ valkey-cli --user kb-observer --pass x --no-auth-warning ping 2>&1 >/dev/null
AUTH failed: WRONGPASS invalid username-password pair or user is disabled.
```

The failed `AUTH` goes to **stderr**; the `nopass` default user answers `PING` on **stdout**. `grep`
reads stdout, matches, exits 0 — Docker reports **healthy**. The probe had already been hardened once,
against a server with `default off` that rejects everything, and that hardening is what made it blind:
it was written to distinguish *"answering"* from *"refusing"*, and a wide-open server answers. **A
liveness check written against the loud failure cannot see the silent one**, and the two are not
opposites — they are on different axes.

The SeaweedFS side has the same shape one layer up: `/cluster/healthz` asks the **master** whether it
is alive, and a gateway running full Allow-All answers it perfectly happily. Measured the same day.

#### What landed

Owned by `platform-devops-engineer`; recorded here because the *decision* is a documentation one.
Both Valkey probes now merge stderr and require the whole output to be exactly `PONG`, so any
diagnostic line at all fails the probe; the SeaweedFS healthcheck gained the anonymous-`ListBuckets`
assertion on a 15-second interval instead of only at bootstrap, with busybox `wget`'s non-zero exit on
403 inverted so **200 is unhealthy**; `bootstrap.sh` renders `users.acl` **before** `docker compose up`
and carries the corrected measurement in its own comments; `preflight.sh` treats a missing
`identities.json` as a crash-loop rather than an opening, and a missing `aclfile` as the fail-open.
Per-file modes differ and the difference is load-bearing: `users.acl` stays `0644` because the valkey
entrypoint drops to uid 999 before exec'ing the server, while `identities.json` takes `0600` because
`weed` runs as root and the file holds a plaintext S3 secret key.

**One bug the audit introduced and caught itself**, kept because it is the exact self-tripping shape
§ *Self-tripping enforcement* catalogues: the identities template's `__README` explains the
`CHANGE-ME` placeholder marker and therefore **contains** it, so preflight's file-wide `grep -q
CHANGE-ME` would have failed every correctly-bootstrapped deploy. It is now anchored to the
`"secretKey"` field — match the value, not the document.

#### Rejected framings

**"Correct ADR-026 in place."** Refused on the register's own rule, and this is the case that shows why
the rule is not ceremony: the corrected sentence would read as though somebody had measured it in 2026-08,
and the thing actually worth knowing is that a plausible reputation about two very common containers
survived fifty-odd research passes, every file that had reason to state it, and a hardening pass on the
very probe that was supposed to detect it. **"Supersede ADR-026 entirely."** Refused because the decision — one SeaweedFS container,
two Valkey services — is correct, is unaffected, and is cited elsewhere; superseding a live decision to
fix a sentence inside it makes every future reader check whether the topology changed too. ADR-033's
narrow form (*"supersedes ADR-012's table list and nothing else in ADR-012"*) is the precedent and is
followed exactly. **"Record it as a `docs/23` unverified claim."** Refused because it is not unverified —
it is measured, and it changed a mitigation.

#### Sites still repeating the superseded claim, owed to their owners

Recorded in E4's and F1's form, so the correction is a small edit against a stated finding rather than a
fresh audit. `docs/` does not write `infrastructure/`, `scripts/`, `Makefile` or `README.md`. Measured
2026-08-11 with `/usr/bin/grep`; the two sites inside `docs/` are corrected above and are not listed.

**⚠ THIS LIST WAS A LIST OF LINE NUMBERS, AND IT WENT STALE IN ONE DAY. Re-measured 2026-08-12 —
six of its seven rows were already fixed. Do not read the rows below as a to-do; run the command.**

```bash
/usr/bin/grep -rn -i 'allow-all\|starts cleanly\|serves every request' \
  README.md Makefile scripts/ infrastructure/docker/compose*.yaml
```

Every hit needs reading in context — most are now *correct* prose describing the Allow-All state and
its two real triggers, which is exactly why a bare hit count cannot stand in for the check. This is
[ADR-036](19-repo-structure-adrs.md) applied to a table that was itself the argument for recording
whole site lists: the list was right, and the *line numbers* were what rotted. See **G16**.

| Site | Status, measured 2026-08-12 | Owner |
|---|---|---|
| `infrastructure/docker/compose.yaml` | **corrected** — `:1503-1512` now names the two other triggers and says `{}` / `{"identities": []}` are the Allow-All state | `platform-devops-engineer` |
| `scripts/ops/preflight.sh` | **corrected, and the self-contradiction is gone** — the header's numbered list at `:14-31` now describes the fatal config load, and it agrees with the check | `platform-devops-engineer` |
| `scripts/dev/bootstrap.sh` | **corrected** — `:5-11` carries ADR-037 and `:430-442` states what a 200 does *not* mean | `platform-devops-engineer` |
| `scripts/ops/restore-drill.md` | **corrected** — `:127-132` adds the ADR-037 caveat and the "crash-looping container would fail this `exec`" reasoning | `platform-devops-engineer` |
| `Makefile:86` | **corrected** — names the valkey/seaweedfs split in one sentence | `platform-devops-engineer` |
| `README.md:69-72` | **was the last one wrong, fixed 2026-08-12.** It said the bootstrap check exists because a gateway with no identities "starts cleanly … and serves every request" — contradicting its own next paragraph, which says the container exits 255. Now justified by the state that *is* reachable: a config that parses but declares nobody | `docs-adr-writer` |
| `infrastructure/docker/seaweedfs/identities.json:6-9` and `:15` | **still wrong, and deliberately not fixed** — both halves at once. **Do not hand-edit:** gitignored, holds live generated credentials, rendered from `identities.json.example`, whose `__README` is already correct (verified `:18-31`). It re-renders when the live file is next regenerated | `platform-devops-engineer` |

**What the re-measurement cost, and why it is recorded rather than quietly corrected.** The table said
seven files were wrong. A reader — including the agent sent to fix them — would have opened six files
that had already been repaired, and the natural failure mode is not wasted time but a *re-break*:
reading a correct paragraph while holding a finding that says it is wrong invites an edit that inverts
it back. `preflight.sh` is the sharpest case. It used to contradict itself 655 lines apart, this table
recorded that as its most useful single fact, and the contradiction has since been resolved in the
direction the check measured — so the row that was most worth having is now the row most likely to
cause harm.

**Revisit condition:** any bump of `chrislusf/seaweedfs` or `valkey`. This entire ADR rests on measured
behaviour that neither vendor documents, so a tag bump re-opens both halves — and the check is to re-run
the four states against each image, not to re-read this section.

## The rulings of 2026-08-12

Numbered **G1…G15**, a namespace of its own, appended and never inserted. R1–R8 record documents that
went false about the repository; F1–F13 record facts the stub-completion batches surfaced that no
document held. These record something narrower and rarer: **nine questions that had been deliberately
left open, ruled on in one day by the person entitled to rule on them**, plus six things found while
writing that record down. *"Ruled on" is not "closed"* — two of the nine (**G5**, `RERANK_SCALE`; and
**G6**, the BM25 tokenizer) were ruled **held**, which closes the question of what to do without
closing the item, and each carries the date and the condition that re-opens it. A deferral with a date
and a trigger is a decision; a deferral without one is an item nobody owns.

**Why the record is bigger than the rulings.** Four of the nine went a different way from the brief
that requested them, and in three of those cases the *investigation refuted the premise* rather than
answering the question. A ruling recorded only as its outcome loses that, and the refutation is the
part that stops the same question being asked again next quarter. Where a brief was wrong, this
section says so and says what the measurement was.

**G1–G9 are the rulings. G10–G15 are what recording them turned up**, and two of those six are open
questions the rulings themselves created — filed here deliberately unresolved, because an ADR that
picks a side on an undecided question is worse than no ADR.

### G1 — ADR-036 is `Accepted`, and its first application found its own evidence row false again

**Ruling:** option **(a), narrowed** — the ADR's own recommendation, verbatim. A count that a
legitimate change could make wrong, and that a reader would act on *as the rule*, is an invariant and
must be written as a rule or as the command that re-measures it. Orientation counts stay, provided the
sentence names where the authoritative list lives. The operative test is **"would a legitimate change
make this number wrong?"**, calibrated by the ADR's own examples: *"seven jobs"* stays,
*"`ALLOWED_TABLES` has four names"* does not. Status, decision text, consequences and revisit condition
are in [`docs/19` ADR-036](19-repo-structure-adrs.md#adr-036-a-documented-invariant-states-a-rule-or-a-measuring-command-never-a-count).

**Options rejected, and why they were not close.** (b) *a CI gate per count* — a bespoke parser each,
and it cannot see a count in a sentence nobody told it about, which is how every instance in R1–R8
survived. (c) *keep counts and correct on audit* — this is the status quo, and R1–R8 is the bill for
it.

**What the acceptance cost within hours of being made, which is the finding.** The ADR's own evidence
row, **R5**, publishes per-subtree `NotImplementedError` counts, and `CLAUDE.md`'s orientation
paragraph republished them. Re-measured 2026-08-12 with the command `CLAUDE.md` itself prescribes:
`deletion` was **26**, not the published 30; `ingestion` was **5**, not the published 17; `rag` was
**0**, not 1. So a list of seven figures — itself a *correction* of an earlier list of eight,
published under a heading explaining that counts in prose decay — was wrong in three places one day
later.

**And it may never have been right.** Batch 12 wrote those bodies *days* before the list was
published, and no prose followed; whether the 2026-08-11 figures were correct at the moment they were
written or were already stale is not recoverable from here, and that unrecoverability is itself the
argument. A count in prose carries no timestamp that binds it to a tree state, so "wrong now" and
"never right" are indistinguishable after the fact — which is exactly the ambiguity the `evaluation/`
correction in R5 had to resolve the hard way, by re-deriving the command.

The correction applied is deliberately **not a third list**. R5 now carries the rule and the measuring
command and no current figures at all; the two historical lists are kept, marked as history, because
deleting them would delete the evidence that the defect recurred twice under its own documentation —
three of the seven figures in the second list were wrong within days.
`CLAUDE.md` got the same treatment. **This decay does not trip ADR-036's revisit condition** — it
predates the acceptance by hours, and the clock starts at acceptance; that is stated in the ADR so the
next reader does not close a convention on evidence from before it existed.

**Counts judged orientation and left alone** (each names its authoritative source in the same
sentence, which is the condition): `.github/workflows/` holds two files; `gates.yml` has six jobs and
`ci.yml` seven; six `pnpm-lock.yaml` files; `git ls-files | wc -l` → 128 with `packages/` → 0; six
migrations, three controllers, six route files. Each was re-measured 2026-08-12 and each is true.

**Counts judged invariant and rewritten as rules**, all in the same pass: `CLAUDE.md`'s per-subtree
`NotImplementedError` figures and R5's; `CLAUDE.md`'s *"`ALLOWED_TABLES` holds **six** names"*; this
file's *"`ALLOWED_TABLES`, now six names"* in the C2 closure note and *"the list is six names as of
ADR-033"* in the eight-decisions table. All four now name the module and state the rule.

**One count is knowingly left in place, and the exception is worth stating rather than hiding.**
[ADR-033's own heading](19-repo-structure-adrs.md#adr-033-the-write-allow-list-is-allowed_tables-and-it-now-holds-six-names)
reads *"…and It Now Holds Six Names"* — precisely the defect ADR-036 forbids, inside the ADR that
first argued against it. It stays for two reasons that both outrank the inconsistency: **an accepted
ADR is never edited in place** (that is the register's oldest rule, and ADR-037 refused a much more
tempting in-place correction on it), and the heading is an **anchor** cited from `docs/22` § C2 and
elsewhere, so rewriting it silently breaks links. If it ever needs to go, the mechanism is a
supersession with a new number — not an edit. Occurrences of the old *"exactly four"* phrasing in
R3, in the re-baseline preamble and in ADR-036's own Context are likewise left alone: those are the
record of the defect, and § *Not corrected here* already establishes that a document recording a
false claim necessarily contains it.

### G2 — #79: `enforcement-greps` is pinned known-red, not red, and the pin cannot outlive its cause

**Ruling:** the property-3 violation stands and is **pinned**, not fixed. `app/deletion/relational.py`
issues four DELETEs against `chunks` and `document_elements` (`:200,204,210,217`) while no Laravel
migration creates either — a real violation of ADR-033's third admission property. Both available
repairs are worse than the finding: writing the two migrations drags in the
`source_versions → source_items → knowledge_sources` cascade that is explicitly out of scope, and
narrowing the purge plan would shrink deletion coverage before the tables it purges exist. The pin
comes out when the source lifecycle lands.

**The mechanism, because "we will take it out later" is otherwise a lie.** `gates.yml` gained
`KB_MIGRATION_PIN_79='chunks document_elements'` with `KB_MIGRATION_PIN_79_DATE='2026-08-12'` inside
check `(b2)`, now at `gates.yml:473-603`. It is **self-expiring by three independent paths**, each
fatal:

- a pinned name that **gains a migration** fails the build (`gates.yml:559-561`) — the fix cannot
  merge while its own suppression is still in the tree;
- a pinned name the data plane **stops writing** fails the build (`:575-577`) — a pin with no cause
  would silently swallow the next write under the same name;
- a pinned name that **leaves `ALLOWED_TABLES` entirely** is never visited by the loop, so neither
  branch above can fire; the closing set comparison at `:599-603` catches it. That comparison is
  deliberately **not** delegated to check `(b1)`, which would also fail — *a pin whose liveness depends
  on a different check staying alive is not pinned, it is hoped for.*

It is also never silent: every pinned name prints a `::warning::` naming #79 and its pin date, and the
ledger line at `:601` prints on every run including when the pin is empty.

**Removal condition, stated because it is a trap for whoever lands the migrations:** adding the
`chunks` or `document_elements` migration turns `enforcement-greps` **red** until the name is deleted
from `KB_MIGRATION_PIN_79` in the same pull request, and the whole `(b2·#79)` block is deleted once the
pin is empty. That failure is a success and the flag text says so.

**Two claims are now false and were corrected in `CLAUDE.md`:** *"#79 is the only failing arm"* and
*"`enforcement-greps` fails inside its first step, which then exits before exporting the two variables
the later steps read."* Measured 2026-08-12 by executing all seven of the job's `run:` bodies on this
host: **all seven exit 0.** The second claim is doubly worth retiring — steps 2–7 had never been
reachable, so nothing had ever executed them; see **G14** for the separate diagnosis error that
accompanied it, and `docs/23` for the caveat that this is still a local emulation.

**CLOSED 2026-08-20 by Phase C1, on the removal condition this ruling stated — and closed with no
gate, because the gate predeceased the event.** Phase C1 landed the
`knowledge_sources -> source_items -> source_versions` cascade and, with it, `chunks` and
`document_elements`. That is *"the pin comes out when the source lifecycle lands"*, verbatim, so the
close required no re-litigation: nothing about the 2026-08-12 reasoning was revisited, and neither of
the two repairs it rejected was adopted. Property 3 is satisfied the way the ruling always said it
would be — by Laravel owning the migration, not by narrowing the purge plan.

**Three things about this close are worth more than the close itself.**

**One: the trap fired at nobody.** The *"Removal condition"* paragraph above is written as a warning
to whoever lands the migrations — the build goes red until the name leaves `KB_MIGRATION_PIN_79`, and
*"that failure is a success and the flag text says so"*. `.github/` was deleted on **2026-08-17**
(§ *Removing CI/CD*), three days before the landing. So the one enforcement in this repository that
was designed to expire on exactly this event was removed three days early, and the event passed in
silence. The pin was unpinned because a person read a paragraph. **A self-expiring gate is only as
durable as the workflow it lives in**, and that dependency is invisible from the gate's own design:
each of its three paths was independently fatal, and all three shared a single point of deletion.

**Two: the close is stated as a command rather than as an outcome** (ADR-036). This one imports the
tuple instead of restating it, which is what the deleted `(b1)` check did and the reason it could not
go stale:

```bash
# ADR-033 property 3, per allow-listed name: does a Laravel migration create it?
python3 - <<'EOPY'
import re, pathlib
ns = {}
exec(compile(pathlib.Path('services/ai-service/app/db/writes.py').read_text(), 'writes.py', 'exec'), ns)
mig = list(pathlib.Path('services/core-api/database/migrations').iterdir())
for t in ns['ALLOWED_TABLES']:
    hit = any(re.search(rf"CREATE TABLE (IF NOT EXISTS )?{t}\b|Schema::create\('{t}'", p.read_text()) for p in mig)
    print(f"{'ok      ' if hit else 'MISSING '}{t}")
EOPY
```

**The close is asserted against the step and the step is measurable, which is deliberate.** This note
was written *alongside* the migrations rather than after them — the record and the DDL are two halves
of one change — so if the command above reports `chunks` or `document_elements` as `MISSING`, this
close is premature, the pin is live again, and the ruling above is the one in force. That is stated
rather than assumed because the mechanism that used to make the question unnecessary is the one thing
this finding no longer has.

**Three: that command is wider than #79, and the widening is a trap of its own.** It reports every
allow-listed name with no migration, and on the day of the close two such names were **not**
violations — their writers are stubs, so no statement exists to violate anything. Property 3 bites
where a write exists, which is why the original finding cited four line numbers in
`app/deletion/relational.py` rather than a membership list. The second half of the check is therefore
`grep -rn 'DELETE FROM\|INSERT INTO\|UPDATE ' services/ai-service/app --include=*.py`; a name that
is `MISSING` in the first command *and* present in the second is this finding recurring under a new
name. Recorded here because a reader running only the first command will otherwise open two findings
that do not exist, or — worse — conclude from two false alarms that the command is noise.

**A code comment goes false with this close, in a tree `docs/` does not own.**
`services/ai-service/app/deletion/relational.py:193-196` states that **no migration in this
repository creates either table** and marks those two entries' column names as unverified against a
schema *"in a way the two sparse entries are not"*. Both halves are now wrong, and the second half is
the useful one: those column names can be checked against a real migration for the first time. It is
`deletion-engineer`'s to correct; see § P2.

**Cross-reference defect, fixed in the same pass and recorded rather than quietly corrected.** This
ruling is **G2**. Three documents cited it as **G1** — `CLAUDE.md`'s #79 paragraph and two
*"What this effort did not touch"* notes in this file (§ ADR-047…052 and § ADR-055…059) — until
2026-08-20. **G1 is the ADR-036 ruling**, one heading above, and it is about counts in prose, so each
of those three citations landed a reader on a page about a different subject that reads as plausibly
related. All three are corrected. The shape is already known here — § L1's preamble records an ADR
amendment citing *"finding S2"* that had landed on the wrong one, and § C1 and § F12/S12 carry
head-of-finding pointers for the same reason — and the lesson is the same: a label one character from
another label has to be checked **at the target**, never at the source. What let this instance survive
is that the wrong target was *adjacent*, so nothing about the reading experience said "wrong page".


### G3 — #80: the near-duplicate penalty is dropped by ruling, so a verbatim spec line now has no code behind it

**Ruling:** `penalize_near_duplicates` and the `near_duplicate_penalized` exclusion reason are both
**deleted**, not deferred. The grounds are **vacuity**: the only near-duplicate detector this tree can
offer is the chunker's `overlap_of`, and its firing set is a strict **subset** of the adjacency
exemption that already spares those candidates, so any implementation resting on it would provably
never fire once.

**"Subset", not "coincides exactly", and the distinction is the record.** The brief that opened this
claimed the two sets coincide; the implementing batch refuted that and was right. `chunker.py:844`
sets `overlap_of` to the immediately preceding chunk and `seq` is strictly sequential within a source
version (`chunker.py:833,856`), so every `overlap_of` link *is* an adjacency by
`(source_version_id, seq)` — but the exemption is wider than that: it also covers `seq+1` pairs and
pairs where no overlap was carried at all. Vacuity follows from the subset direction alone (everything
the detector could fire on is already exempt) and does **not** require the sets to be equal. The
reasoning is written beside the code at `app/rag/evidence.py:284-322`, and
`tests/unit/test_dedup_diversity.py:311-321` fails if either name returns.

Two supporting reasons that stand on their own: a penalty **has no scale to live on** — it would
adjust `fused_score`, and RRF is magnitude-free by construction, so subtracting a constant re-orders
candidates by an amount unrelated to how similar they are; and the reason was **misfiled** —
`near_duplicate_penalized` described a *retained* candidate, so recording it through `run.exclude`
would have broken the identity `tests/unit/test_evidence_threshold.py` asserts (scored minus packed
equals excluded) by putting one candidate on both sides.

**The deviation this creates, which is the reason it is a finding and not just a ruling.**
[`docs/07-rag-query-pipeline.md:169`](07-rag-query-pipeline.md) §12.10 and `KnowledgeBot-AI.md:1681`
both read *"Penalize near duplicates."* Neither file may be edited — they are verbatim spec extracts —
so **a specification line now has no code behind it by ruling rather than by omission**, and that is a
different thing from a gap. A gap gets filled by the next implementer; a ruling has to be re-opened.
Anyone reading §12.10 and finding stage 10 short is reading correctly: the stage does exact duplicates
by `content_hash`, the per-document cap by `source_id`, and the adjacency exemption that makes the cap
safe to apply, and nothing else.

**Reopening condition, all three required:** a *real* detector (a shingle/simhash pass over chunk
text, or a content-hash equivalence class that survives the exact-duplicate drop), a penalty expressed
in **ranks** rather than fused score, and a second trace verb meaning "adjusted, not dropped". Until
all three exist, implementing §12.10 ships a stage that never fires — a trace with a stage name, a
playground with a column, and never a row in either.

### G4 — #103: the `provider_models` index drop stands

**Ruling:** keep the drop. The 8D measurements stand and were not re-litigated: the child lookup plans
as `Index Scan using provider_models_org_connection_model`
(`services/core-api/database/migrations/2026_08_07_000400_create_provider_models_table.php:98`),
`ON DELETE RESTRICT` still fires, and `forOrg`'s `ORDER BY` is satisfied from the index with **no
`Sort` node** in the plan.

**The cost, stated because a ruling that lists only benefits is not a record:** `enabled` is in no
index. A future filter on `enabled` that is not also scoped by `(org, connection, model)` gets no
index support from this schema. **Revisit when** a query plan on `provider_models` shows a `Seq Scan`
or a `Filter` on `enabled` at a row count that matters — that is the observable, and it is a plan, not
an opinion.

### G5 — #108: `RERANK_SCALE` stays keyed by provider, and OpenRouter reranking stays refused

**Ruling: deferred, deliberately.** `RERANK_SCALE` remains keyed by *provider*, and the platform keeps
honestly refusing OpenRouter reranking rather than guessing a scale.

**Why refusal is the honest answer and not a missing table row.** On a gateway the score scale is not
a property of the provider: OpenRouter's `/api/v1/rerank` fronts several upstream cross-encoders on
one credential and documents no normalization between them, so `relevance_score` means whatever the
upstream that served *this request* meant by it. No evaluation run over the golden corpus can fill a
per-provider cell for a quantity that is per-upstream. The reasoning is at
`app/providers/capabilities.py:117-125`, and NVIDIA NIM is the contrast that makes the line worth
drawing — its `LOGIT` is thresholdable, its calibration is one evaluation run away, and nothing
refuses it.

**Revisit condition, and it is a contract change rather than a table edit:** re-key `RERANK_SCALE` on
`(provider, model)` — or on the resolved upstream — **and** pin the upstream hard enough that the
measurement stays true, which means `provider.order` plus `allow_fallbacks: false`. Both halves are
required. Re-keying without the pin produces a calibrated number for a model that may not be the one
that answers.

### G6 — D7: the BM25 tokenizer stays unimplemented, and the ruling is re-dated

**Ruling: held, dated 2026-08-12.** `app/retrieval/sparse.py:tokenize` keeps raising
`NotImplementedError` at `sparse.py:246`. Nothing changed about the reasoning; what changed is that
the date on the hold moved, so a reader can tell a live deferral from an abandoned one.

The reason is priced, not vague: [ADR-034](19-repo-structure-adrs.md#adr-034-sparse_analyzer-is-part-of-the-embedding-space-so-an-analyzer-change-is-a-reindex)
makes `SPARSE_ANALYZER_VERSION` part of the collection name, so changing the tokenizer after the first
large ingest re-embeds the **dense** vectors too, at a provider's per-token price (ADR-030). The
tokenizer must be settled before the first large ingest, not after — which is exactly why it is not
being settled in a batch whose scope is documentation and stubs. `tests/unit/test_sparse_arm.py`
asserts the `NotImplementedError` and matches on `"C2"`, so the hold is enforced rather than merely
recorded.

**Consequence that hurts, unchanged:** the sparse arm has arithmetic and no producer. Hybrid retrieval
is dense-only in practice until this lands.

### G7 — #27 / F12: the internal body carries a keyed map of credentials, not one credential

**Ruling: option (a)** — `provider_credentials: {connection_id: SecretStr}`. This closes
[F12](#f12--the-internal-chat-body-carries-one-credential-and-one-turn-can-need-three) and the
credential half of the question the code files under *"finding S12"*.

**Reasoning:** the key is already the identity both planes compute.
`app/providers/embedding_selection.py` resolves to an `EmbeddingConnection` — a `connection_id` the
control plane knows how to decrypt, plus the `(provider, model)` pair that names the space — and holds
**no credential**, with an import-time check refusing any field whose name looks like a secret.
`services/core-api/app/Services/Embedding/EmbeddingCandidate.php` holds the same line on the Laravel
side. So **neither module changes when the wire changes**, which is the property that made (a) cheap;
had either needed editing, that would have been the signal the selection had been designed as a
FastAPI-internal value rather than a transmissible one.

**Options rejected.** **(b) per-surface siblings** (`provider_credential`, `embedding_credential`,
`rerank_credential`) — smaller, but it does not generalize to a fourth surface, and a fourth surface
is a routine product change rather than a hypothetical. **(c) leave it singular** and require every
surface of a turn to resolve to one connection — rejected because it **contradicts accepted
[ADR-031](19-repo-structure-adrs.md#adr-031-the-organization-designates-the-embedding-connection-disagreement-is-a-refusal)**:
ADR-031 exists precisely to allow the embedding connection to differ from the chat connection, so (c)
would forbid the configuration an accepted ADR was written to support. **Reading
`provider_connections` from PostgreSQL in FastAPI** was already ruled out before this batch, by
`kb-provider-adapter-contract` ("resolves nothing from storage"), `kb-security-baseline`, and
ADR-012/ADR-033's ownership rule — it is ADR-011's rejected option (b) under another name.

**Consequences, including the ones that hurt.** The never-forward rule now has to cover **a map's
values** rather than one field name, which is a wider target for a redaction bug and a wider target
for a log formatter that walks a dict. Every redactor, audit path and log filter that special-cased
the literal key `provider_credential` has to learn the map. And the wire change lands on both planes
at once.

**Nothing is blocked on it today** — the five provider wire adapters and the chat router are both out
of the current scope, so no code carries a second credential yet. **It becomes blocking the first time
a query path calls `embed()` against a connection that is not the chat connection**, which is F12's
own revisit condition and is unchanged by the ruling. **Owner:** `contract-steward` to specify the
wire; `control-plane-engineer` and the FastAPI side to implement when the chat router is built.

### G8 — #137: the secret-scan finding was closed by lowering entropy, with no waiver — and the brief for it was wrong

**Ruling: no waiver file.** The finding was closed by lowering the entropy of the offending fixture,
which is the repair a waiver would have papered over. Result: **38 → 36 gitleaks findings, zero of
them in a committable file.**

**The brief was wrong about which files were firing, and the measurement is the useful part.** It
named three Python fixtures. **All three produce zero gitleaks findings**, for three separate reasons,
each measured rather than reasoned:

- repeated `CANARY` markers fall **under** the 3.5 Shannon-entropy bar the generic high-entropy rule
  applies, so a long repetitive string is not a secret to gitleaks no matter how long it is;
- gitleaks' OpenAI rule requires the literal infix `T3BlbkFJ`, so an `sk-`-prefixed placeholder
  without it never matches;
- `AKIAIOSFODNN7EXAMPLE` — AWS's own published documentation key — **does not fire**, proven with a
  positive control (a different, non-example `AKIA…` string in the same file *did* fire, so the rule
  was live and the negative was real).

The one committable file that actually fired was
`services/core-api/tests/Unit/KbJsonFormatterTest.php`. **Recorded because a triage that names the
wrong files is worse than no triage:** it sends the next person to read three innocent fixtures and
teaches them that the scanner is noisy, when in fact it was precise and the report was not.

**The positive-control habit is the transferable part.** Every one of those three negatives is
indistinguishable, in a report, from "the scanner did not run". The only thing that separates them is
a control that is expected to fire — the same argument `gates.yml`'s negative control on the #79 pin
matcher makes (**G2**), arriving in a different tool.

### G9 — #135: `COVERAGE_WARN` was **not** moved, and the investigation inverted the finding

This is the most important record in the batch, because the requested change was refused on
measurement and a **third, worse defect** was found while measuring.

#### The threshold was not moved, and the reason is a distribution with no gap in it

14D built the distribution rather than arguing about the constant: **74 page-parses through the real
`parse_document`** — three clean pages of `samples/corpus/documents/scanned/scanned-po-88214.pdf`
rasterized at 300 dpi, put through 24 degradation variants each across five axes (ink density,
Gaussian blur, JPEG quantisation, additive noise, skew), plus the four pages as shipped — each page
labelled by **word recall against the same page undegraded**, so the threshold is calibrated against
*"did we lose the text"* rather than against itself.

```
pages that lost nothing   (recall >= 0.90):  n=59   coverage 0.4638 – 1.0000
pages that lost half+     (recall <= 0.50):  n=15   coverage 0.2053 – 0.4811
```

**The two populations overlap.** There is no gap of the kind `CELL_TRUST_FLOOR` and
`LOW_CONF_MASS_WARN` sit in, so no threshold separates them. The best that exists is 0.44–0.46 — 12 of
15 degraded pages caught with no false warning — chosen 3% below a page that lost nothing, which is a
coincidence and not a margin.

#### The axis that settles it: skew, with the engine reading perfectly

Rotation alone, cell recall **1.000 at every angle** — the engine lost not one character:

```
skew     0.5°     1°      2°      3°      5°      7°
dense  1.0000  0.9825  0.7636  0.6075  0.3909  0.4849
prose  0.9949  0.9857  0.7920  0.5705  0.4969  0.5425
sparse 1.0000  1.0000  0.9375  0.7696  0.5613  0.4460
```

A **5° crooked scan that lost nothing reads 0.3909**. So every threshold at 0.40 or above warns on an
ordinary crooked page, and the 0.44–0.46 "optimum" is unusable. **The mechanism was measured, not
inferred:** every `TextCell.rect` docling returns is axis-aligned and tight to a *deskewed* line crop,
so the boxes cannot follow rotated ink and the ink-in-box ratio falls with angle regardless of how
well the page was read.

**Therefore `COVERAGE_WARN = 0.30` is a catastrophe floor, not a quality gate** — its ceiling is set
by page skew, not by degradation. It sits 1.30× below the worst clean-but-crooked page and catches the
pages where the detector has essentially stopped proposing regions. Its provenance is now written
beside the constant at `app/ingestion/ocr/guarded.py:211-266`, including the correction that the *old*
note's 4.4× gap was measured somewhere else entirely — offline against a standalone engine call on the
raster, where the same shipped page reads 0.5054 through the pipeline because docling's own invocation
recovers 11 OCR cells where the offline measurement recovered 5. **Nothing about the threshold was
wrong; the sentence describing it named a measurement the code never sees.**

**And moving it is not a configuration edit.** `COVERAGE_WARN` is in `OCR_CFG_INPUTS`, so changing it
changes `ocr_cfg_version` and forces **every scanned source in the platform to re-OCR and re-version**.
A 3% gain for a platform-wide reindex.

#### The third defect, found while measuring, and worse than the one 14D was sent for

**Every born-digital page reported `ocr_coverage_unmeasurable`** — 14 of 14 handbook pages, 2 of 2
datasheet pages. A page with no OCR cells has no polygons, and *"no polygons"* was being reported as a
**failed ink measurement**. It would have been the most common warning string in the first real
ingest, attached to documents with nothing whatsoever wrong with them — and a warning that fires on
every healthy document is how a warning channel stops being read.

Fixed by distinguishing the two states that had been collapsed: **"no OCR ran"** → `None`, silent;
**"OCR ran and the mask is untrustworthy"** → NaN, warned. `app/ingestion/parsing/converter.py:877`
and `app/ingestion/ocr/guarded.py:844,874` carry the reasoning.

**The shape worth keeping:** a signal that cannot be computed and a signal that computed badly are not
the same signal, and collapsing them puts the loud value on the common case.

#### The `-W error::DeprecationWarning` half was fixed, and its framing corrected

The exemption landed, but **not** for the reason first stated. It is *not* "any document with a bare
list item". Every backend that builds lists directly — DOCX, PPTX, HTML, Markdown — creates the
`ListGroup` first, so none of them reaches the deprecated path. The live path is reached only when the
**layout model** nests a list item inside a form, a key-value region, a picture, or a rich table cell.
In practice that means **forms, invoices and purchase orders** — which is a much smaller and much more
specific class than "any document with a list", and the difference matters because the wrong framing
makes the exemption look like a blanket relaxation.

**The pytest trap that makes this non-obvious, and it would have produced a convincing non-fix.**
pytest applies ini `filterwarnings` **first** and command-line `-W` **second**
(`_pytest/config.apply_warning_filters`), each *prepended* to the filter list, so **the last applied
wins**. An exemption written as a `filterwarnings` list entry would therefore have silently lost to
`-W error::DeprecationWarning` and changed nothing at all — while reading, in a diff, exactly like a
fix. Both exemptions are consequently `-W` entries in `addopts`
(`services/ai-service/pyproject.toml:330-345`), and the precedence rule is written there.

### G10 — CLOSED 2026-08-12: `overlap_of` left the payload projection

**Created by G3, not resolved here.** `app/retrieval/search.py:137` documents `overlap_of` as
projected *"for dedup"* and `:153` lists it in `PAYLOAD_PROJECTION`. After the #80 ruling **nothing in
the query path reads it.** Either the comment is corrected — the field is projected for some other
reason, or for a future one that should be named — or the field leaves the projection.

**Why it was deliberately not fixed in passing**, which is the part that makes this worth a finding
rather than a chore: `search.py` is the file the Qdrant enforcement gate exempts **by literal path** —
`grep -vF 'app/retrieval/search.py'` at `gates.yml:766`, with the vacuity ledger counting the exempted
matches separately at `:1302-1303` and the reason stated at `:133` (that file carries docstring prose
naming Qdrant verbs, which a comment filter cannot drop). The smallness of that exemption is
load-bearing. A drive-by edit to an exempt file by an agent
who is not its owner is the way an exemption quietly grows, and the exemption growing is a worse
outcome than a stale comment.

**Ruled 2026-08-12: drop it from the projection.** The alternative — keep the field and correct the
comment — was rejected because the corrected comment would have had to say "carried for a future
reader", and a field whose only justification is a promise is the vacuity pattern this whole effort
exists to remove. Ingestion still writes `overlap_of`, it is still in the Qdrant payload and still in
`kb-chunking-rules`' metadata schema; the *query* path stops asking for it.

**The exemption did not grow, which was the reason for deferring in the first place.** The edit removes
a tuple entry and a clause and introduces no Qdrant verb, and the vacuity ledger's counts
(`gates.yml:1300-1303`) are all derived from `grep -c` rather than pinned, so nothing had to be adjusted
to keep the gate green.

**A second defect surfaced while closing it, and it is the more transferable one.**
`tests/unit/test_branch_query.py:401` asserts `tuple(projected) == PAYLOAD_PROJECTION` — a comparison
against the constant, which **cannot fail for any edit to the tuple.** It proves the call site uses the
constant; it proves nothing about what the constant contains, and it followed this change silently. The
two assertions beside it (`"text" not in projected`, `"content" not in projected`) are the ones with
teeth, and `assert "overlap_of" not in projected` was added in that form. Same class as **G12**: an
assertion that is true of every possible implementation, green in every run.

**Owner:** `retrieval-engineer`. Sites: `app/retrieval/search.py:137,146`,
`tests/unit/test_branch_query.py:404`.

### G11 — CLOSED 2026-08-12: the three rows now measure the exclusion

`services/core-api/tests/Unit/KbJsonFormatterTest.php` carries an allow-list dataset in which **three
rows have credential-shaped values**. Every one of them is therefore rescued by `redact()` — the
backstop — while the row's own comment claims it proves the *field is dropped*. The test passes for
the wrong reason, and would keep passing if the exclusion were deleted.

14C added **one** row that makes the dataset's claim true rather than rewriting another agent's
fixtures, which is the correct boundary: a test's owner is the agent that owns the code under test.
**The other three want `control-plane-engineer`.**

**Fixed 2026-08-12.** Each of the three keeps its key — the key *is* the excluded field under test —
and takes an **unshaped** value carrying a `MUSTNOTAPPEAR` needle, so nothing but the exclusion stands
between it and the log store. The backstop is not left unmeasured: the three rows in the second group
(`reason`, `reason`, `model`) test exactly that, with real credential shapes, on fields the allow-list
*permits* — which is what made it safe to stop measuring it twice.

**The mechanism, because it is the part that generalises.** `partition()` drops a disallowed field by
name and never renders its value; `normalize()` hands an allowed field's **bare value** to `redact()`.
So the key/value rule in `CREDENTIAL_KEY_VALUE` — which would have matched `"provider_credential":…`
in the rendered line — never sees the key at all, and the only rule that could rescue these rows was
the vendor-key/bearer shape match on the value itself. Remove the shape and the row has exactly one
defence left, which is the one it claims to test.

**A rule now sits in the file above the dataset**, because the next person to add a row will reach for
a realistic-looking credential by reflex: the value must carry no `sk-`/`nvapi-`/`ghp_`/`AKIA` prefix,
no `bearer `/`basic ` scheme, no `KB1 `, no `http…?query`, and none of `CREDENTIAL_KEY_VALUE`'s
keywords followed by `:` or `=`.

### G12 — two test-quality defects found by mutation, both fixed, both invisible until the redactor was broken

Both were in `services/core-api/tests/Unit/KbJsonFormatterTest.php`, both found by mutation testing,
and both share a property worth naming: **the suite was green, and stayed green under mutation, which
is the only signal either would ever have produced.**

- A test named *"renders an exception without PHP argument values"* **could never fail.** PHP
  truncates a scalar trace argument to **15 characters**, so the 29-character needle the assertion
  searched for was unfindable in the rendered trace regardless of what the formatter did. The
  assertion was true of every possible implementation.
- The allow-list dataset rows of **G11**, of which one is now correct and three are not.

**The transferable finding:** a test whose needle is longer than the runtime's own truncation limit is
a tautology, and nothing in a green run distinguishes it from a passing test. This is the same class
as `gates.yml`'s self-tripping enforcement (§ *Self-tripping enforcement — the systemic pattern*) and
as **G8**'s missing positive control, arriving in a third tool.

### G13 — `yq` and `jq` are not on `PATH`, and the containerised shim has two silent failure modes

**Correction to a durable fact other documents assert.** `which yq` and `which jq` both return
nothing on this host, measured 2026-08-12. Any instruction that says containerised shims for them are
on `PATH` is false, and a script that assumes it fails at the first invocation. Nothing under `docs/`
made that claim — the only `yq` mention in this file is § *Doctrine stated but unenforceable*
describing what the CI job uses, which is correct because CI installs it — so this is recorded as a
fact to preserve rather than a correction to `docs/`.

**Two gotchas that make a hand-rolled shim produce a convincing false negative**, both worth more than
the absence itself:

- **The container must mount the repository at its _host_ path.** Mounting it at `/repo` makes every
  absolute host path in an argument unresolvable inside the container — and the tool then reports the
  file as missing or empty rather than erroring in a way that names the cause.
- **The shim must not use `docker run -i`.** Inside a `while read … done < file` loop, `-i` attaches
  the container's stdin to the loop's stdin and **swallows the rest of the file**, so the loop runs
  once and stops. The import completes, exits 0, and is short — a false negative that looks exactly
  like a small input.

The second is the dangerous one, and it generalizes past `yq`: any command inside a `while read` loop
that consumes stdin truncates the loop silently.

### G14 — `enforcement-greps` has no step-level `env:` at all, and an earlier diagnosis blamed the missing block

**Correction to a recorded diagnosis.** An earlier record attributed a false red in `enforcement-greps`
to a *missing step-level `env:` block*. There is no step-level `env:` in that job and there never was:
**all six variables are job-level, at `gates.yml:115`**, deliberately, so that the Qdrant detectors are
defined once and consumed by three steps rather than spelled three times. The cause of the false red
was the **job-level** block, not the absence of a step-level one.

Worth keeping because the wrong diagnosis is actionable in the wrong direction: it invites someone to
*add* a step-level `env:`, which would reintroduce exactly the three-copies drift the job-level block
exists to remove — and the copy that drifts is always the one in the gate, so the self-test keeps
proving a pattern nothing enforces.

### G15 — a test count means nothing without its suite selection, and two true figures read as a contradiction

**Measured 2026-08-12 on this host, after Batch 14:**

| Selection | Result |
|---|---|
| `tests/unit` | **1349 passed, 1 skipped** |
| the whole `tests/` tree | **1538 passed, 7 skipped** (collected: unit 1350 + contract 95 + integration 80 + security 20) |
| `ruff` | clean |
| `ruff format --check` | **170 files** |
| `mypy` | clean on **87** source files |

The widely-quoted **"1455 / 1"** is **not a stale number**. It is `unit + contract + security` — a
*different suite selection* — and it was correct for that selection. Two true figures that disagree
read as one false figure, and the reader's conclusion is that somebody did not re-run the tests.

**The convention this establishes, and it is ADR-036 in a second currency:** every test count states
its selection, or it is not a measurement. `1538` and `1455` are both right; `"the suite passes 1455"`
is not a claim at all. The same argument the *Not corrected here* subsection makes about a grep's
**scope** — a measuring command is only better than a count if its scope is the claim's scope —
applies unchanged to a test selector.

Note that `mypy`'s **87** and `ruff format`'s **170** are counts of *files the tool visited*, which is
a property of `[tool.mypy] files = ["app"]` and of the formatter's discovery, not of the tree. They
move when either config moves, which is why the config is named beside them (see **R8**: `uv run mypy`
and `uv run mypy .` are **not** equivalent, and the explicit `.` drags `tests/` and `scripts/` into
strict checking).

### G16 — a site table went stale in one day, and the stale rows were more dangerous than the finding

**Measured 2026-08-12.** ADR-037's § *Sites still repeating the superseded claim* named **seven** files
still stating the SeaweedFS/Valkey fail-open direction backwards. Re-running its own kind of check —
`/usr/bin/grep -rn -i 'allow-all\|starts cleanly\|serves every request'` over `README.md`, `Makefile`,
`scripts/` and `infrastructure/docker/compose*.yaml` — found **six of the seven already corrected**.
Only `README.md:69-72` survived, and it has now been fixed. The live `identities.json` is the seventh
and is deliberately untouched: gitignored, holding generated credentials, and rendered from a template
whose `__README` is already correct.

**The failure mode is not wasted time, it is a re-break.** An agent handed "these seven files are
wrong" opens a file that reads correctly and has to choose between the finding and the text. The
natural resolution is to trust the finding and edit the text back to the claim — which would invert a
correct paragraph using a document that exists to prevent exactly that. `scripts/ops/preflight.sh` is
the sharpest case: the table recorded its 655-line self-contradiction as *"the most useful single fact
in this table"*, and that contradiction has since been resolved in the direction its own check
measured. The most valuable row became the most hazardous one, by being fixed.

**This is [ADR-036](19-repo-structure-adrs.md) landing on a table that was itself the argument for
recording whole site lists**, and the resolution is the same as R5's: the table keeps its rows as a
record of what was found, and gains **the measuring command at its head** so the next reader runs it
instead of trusting line numbers. The rows now carry status, not instructions.

**The transferable rule: a site list is a measurement with a timestamp, not a work queue.** Anything
that names `file:line` in a repository under active repair is stale from the moment it is written, and
the older it is the more confidently wrong it reads.

### G17 — `blur >= 3.0` reaches the index as silence, and now it warns

**The defect, measured by 14D:** at Gaussian blur ≥ 3.0 Docling's layout model classifies the whole
page as a picture. OCR runs and reads it *perfectly* — cell recall **1.000** — and not one cell becomes
a text element, so element recall is **0.000**. The page contributes nothing to the index and **nothing
warned**: `ocr_low_confidence` was silent because the cells really were read confidently, and
`ocr_low_coverage` was silent because the ink really is inside the cell rectangles. Both were correct.
An admin reading `ocr_cells: 41, ocr_confidence: 0.94, warnings: []` on a page that indexed nothing had
been told the opposite of what happened.

**Fixed 2026-08-12 as a third arm on `assess`**, `ocr_text_unplaced`, fired when OCR produced cells on
a page and the layout model placed zero text elements there. The count comes from the caller —
`_placed_text_per_page` in `parsing/converter.py`, over `document.texts[].prov[].page_no` — because it
is a fact about the document tree and `app/ingestion/ocr/` must never learn to read the layout model's
output.

**Three design points, each of which had a wrong version that looks identical in a diff:**

- **No tunable, and that is what made it landable.** The signal is zero-versus-nonzero, so nothing
  entered `OCR_CFG_INPUTS` and `ocr_cfg_version()` did not move. A version of this change that reached
  for a threshold would have **re-OCR'd every document in the platform** to add a warning, at the
  admin's per-page engine cost. `test_the_current_identity_is_pinned_so_a_reingest_is_never_accidental`
  now pins the digest (`ocr/v1:rapidocr:9a5e07d644c6`) so the expensive direction fails a test: every
  other test in that file proves the string moves when it *should*, and none could fail when it moves
  and should not have.
- **`None` is not zero.** An unmeasurable count returns `None` and is silent. Returning an empty dict
  instead would say "every page has zero text elements" — the warning condition — so any result shape
  the walker did not understand would have warned on **every page of every document**. That is exactly
  the `ocr_coverage_unmeasurable` false positive (G9) reproduced in a new arm, one release later.
- **The arm filters `from_ocr` itself rather than trusting the caller**, and this was caught by a test
  rather than by review: the first implementation gated on the raw cell list, so a born-digital page
  carrying one digital cell and no text element warned. Every other arm of `assess` filters its own
  input — `low_confidence_mass` does it through `_ocr_weights` — and the one caller that happens to
  pre-filter is not the contract.

**Verified at the unit level; the end-to-end reproduction is owed.** `parse_document` cannot run on
this host — `ARTIFACTS_PATH` is `/models`, which exists only inside the `ai-service` image — so the
blur-3.0 page has not been re-parsed since the arm was added. The reproduction script is written and
ready. See `docs/23`.

### G18 — the `logit` metric gap is ruled, not deferred

`kb_retrieval_evidence_score` carries a mandatory `scale` label, and on `scale="logit"` it can record
**nothing**: `opentelemetry-sdk` 1.44.0's `Histogram.record()` silently discards negative amounts, and a
logit distribution is roughly half negative. Recording only the positive half is *worse* than recording
none — a truncated distribution is indistinguishable from a healthy one and moves the p10 "weakest
accepted evidence" panel in the reassuring direction. NVIDIA NIM is today the only rerank-eligible
provider and it returns `LOGIT`, so **the histogram records nothing in the one working configuration.**

**Ruled 2026-08-12: record nothing, and read logit evidence quality from the evaluation suite.** The
instrument carried a `TODO(retrieval-engineer + rag-eval-engineer)`; it is now a stated constraint with
two revisit conditions.

**Why the alternatives were rejected**, which is the part worth keeping:

- **σ(score)** is monotone and preserves quantiles exactly, but it is arithmetic on a provider's score
  (`bge-reranker` restricts that), it saturates precisely where the accepted evidence lives, and it
  cannot be relabelled `scale="sigmoid"` — that value is the *provider's own claim*, and overwriting it
  turns stage 12's scale assertion into a comparison of a value with itself. A fifth `RerankScale`
  member would mean a closed enum growing a member describing our arithmetic rather than a vendor's
  contract, which is what the enum exists to prevent.
- **A second metric family** for threshold-relative distance would work and is a new family — outside
  the skeleton line of 2026-08-11, and a decision to make alongside the panels it is for.

**Two revisit conditions, both mechanical**, so this does not become permanent by inattention: a
bounded-scale provider becoming rerank-eligible, or an SDK release whose `Histogram.record()` accepts
negative amounts — a one-line check at every SDK bump. **The gap is in a quality panel, not in a
correctness signal:** stage 12 still asserts the scale, still thresholds, and still refuses an
uncalibrated one, none of which depends on an exported metric.

### G19 — hadolint was reading a nameless Dockerfile

`ci.yml` piped each Dockerfile on stdin (`hadolint - < "$df"`), so the linter had no filename. Two
consequences, one cosmetic and one latent: findings from four images arrived in one undifferentiated
list attributed to `-`, and hadolint had no file location to resolve configuration against. **The day
somebody adds a `.hadolint.yaml` to silence one rule for one image, the pipe form ignores it and the
rule stays loud — a suppression that reads as applied and is not.**

Fixed by mounting the repository read-only and passing the path
(`-v "$PWD:/repo:ro" -w /repo … hadolint "$df"`). Nothing is broken today because no such file exists,
which is precisely what made it cheap: the fix costs one line now and an afternoon of confusion later.

**Same shape as F7 and as `apps/mobile/jest.config.js:35`** — a control that cannot do the thing its
surroundings assume it does, sitting green. It differs in one way worth noting: this one was not making
a false claim, it was *foreclosing a true one*. Nobody had written the config it would have ignored.

## Found while starting the stack (2026-08-12)

Docker Desktop returned and the development stack was brought up for the first time since Batch 1B —
`docker ps -a` and `docker volume ls` had both been empty, so this was a cold start against surviving
images, not a restart. The topology needed no change: **the only host listeners the stack creates are
`:80`, `:443` and `127.0.0.1:6379`**, `laravel-migrate` exited 0 with all six migrations applied, and
every service reached its steady state on the first attempt. Three findings came out of the edge round
trip that followed. Measure the current state rather than trusting the counts in this paragraph
(ADR-036): `docker compose ps -a` and `ss -ltn`.

### G20 — the widget origin was dead at the edge, and the probe said healthy

`apps/widget/vite.config.ts` set no `server.allowedHosts`. Vite refuses any request whose `Host` header
is neither an IP literal nor listed there, answering a **plain-text 403** — so
`https://kb-widget.example/` through Traefik returned 403 for the entire life of the dev stack while
`docker compose ps` reported `sdk` **healthy**.

**The two halves are one defect.** The healthcheck probed `http://127.0.0.1:8080/`, and an IP host
bypasses Vite's check entirely, so the probe was structurally incapable of observing the failure it was
positioned to catch. That is the shape this repository keeps finding — a control that validates its own
copy of the thing rather than the thing (`gates.yml`'s vacuity ledger, F7, G19) — and it is why the
fix is in both files:

- `apps/widget/vite.config.ts` — `allowedHosts` on both `server` and `preview`, derived from
  `widgetOrigin` so it cannot drift from the Traefik rule `` Host(`${WIDGET_DOMAIN}`) ``. Vite's CLI
  has **no** `--allowedHosts` flag (checked against 8.2.0's `cli.js`), which is why Compose cannot fix
  this on its own.
- `infrastructure/docker/compose.override.yaml` — the `sdk` healthcheck now sends
  `Host: ${WIDGET_DOMAIN}`, interpolated from the same `.env` value that builds the router rule. The
  probe now fails in exactly the case the edge fails.

**Proved by mutation**, not by a green run: with `allowedHosts` removed, the edge returns 403 **and**
the probe exits 1; restored, both are 200/0, and the file is `sha256sum` byte-identical. Note that Vite
hot-restarts on a `vite.config.ts` change under the dev bind mount, so the fix took effect without a
container recreate — which is also how the first attempt at this proof lost its control.

### G21 — one root file, and `pnpm lint` could not run in either node container

Both node images copy `package.json`, `pnpm-workspace.yaml`, `.npmrc`, `pnpm-lock.yaml` and
`tsconfig.base.json` into the workspace root and stop there. `apps/web/eslint.config.mjs` and
`apps/widget/eslint.config.mjs` both import `../../eslint.base.mjs`, which is not among them, so lint
died with `ERR_MODULE_NOT_FOUND: Cannot find module '/app/eslint.base.mjs'` in both containers.

The measurement is what makes this small: in the same containers `typecheck` passes and the web Vitest
suite runs green, so the gap was **one file**, not the toolchain. Fixed with a read-only bind mount of
that single file in the dev overlay — safe to mount alone for the reason the file states about itself,
that it has **zero imports** and is plain data.

### G22 — the documented development workflow runs nowhere, and the images cannot host it

`CONTRIBUTING.md`'s test table is host commands. On this host **not one of them executes**: PHP is 7.4
against a required 8.4, `make` is not installed, node and pnpm are under nvm and off the
non-interactive `PATH`, and `uv run` fails on container shebangs. The images cannot absorb it either —
`services/core-api` and `services/ai-service` are both built `--no-dev` **on purpose**
(`Dockerfile:256` says why: PHPUnit, Pest, Faker and the debug bar do not belong in a shipped
artifact), so Pest, pytest, ruff and mypy are absent by design rather than by omission.

So the honest statement of the gap is not "the toolchain is broken" — it is that **there is no
artifact anywhere in this repository whose job is running the tests**, and the workflow that has been
used all along is host binaries that happen to be present. The node half is the exception and is now
whole (G21): `docker compose exec web|sdk` runs typecheck, lint and Vitest.

**Ruled 2026-08-12 as recorded-not-fixed.** Closing it means new dev-dependency stages in two
Dockerfiles plus a `tools` Compose profile, which is past the skeleton line of 2026-08-11 and is a
decision about how this repository is developed rather than a defect in what it ships. The revisit
condition is mechanical: the first time CI's behaviour and a developer's cannot be reconciled by
reading `ci.yml`.

### G23 — the blur reproduction ran, and did not reproduce

The three verifications Batch 16 recorded as *owed* were run on 2026-08-12 once Docker returned. Two
confirmed their findings. The third refuted its own premise, which is why it gets an entry.

**G19 (hadolint) — confirmed, and the config claim is now measured rather than argued.** Both
invocation forms were run against all four Dockerfiles: identical exit codes and identical finding
counts, with the filename the only difference (`-:53` became `services/core-api/Dockerfile:53`). The
part worth having is the second experiment — with a temporary `.hadolint.yaml` ignoring `DL3008`, the
path form reported **0** such findings and the stdin form still reported **1**. The suppression that
"reads as applied and is not" is no longer a prediction.

**G11 (the Pest allow-list rows) — confirmed by mutation.** With
`KbJsonFormatter.php`'s `! in_array($key, self::ALLOWED_EXTRA_FIELDS, true)` replaced by `false`,
**exactly four** dataset rows of *"it never writes a secret that arrives…"* fail, plus the separate
`dropped_fields` test. Before Batch 16 reshaped them, three of those four stayed green under the same
mutation because `redact()` was rescuing credential-shaped values. Restored `sha256sum`
byte-identical; the suite is green either side.

**G17 (the `blur >= 3.0` degradation) — NOT reproduced, and the recorded threshold is now in doubt.**
The reproduction ran inside `knowledgebot/ai-service:dev` against the real `/models` weights — Docling
layout plus RapidOCR, no stubs. Sweeping the radius on a 300 dpi rasterised text page:

| radius | ocr_cells | placed_text | coverage | warnings |
|---|---|---|---|---|
| 0.0 | 11 | 8 | 0.9993 | — |
| 1.7 | 11 | 8 | 0.9993 | — |
| 3.0 | 8 | 8 | 0.9994 | — |
| 4.5 | 4 | 4 | 0.5737 | — |
| 6.0 | 3 | 3 | 0.4451 | — |
| 8.0 | 3 | 3 | 0.2593 | `ocr_low_coverage:0.26` |
| 12.0 | 0 | 0 | `None` | — |

**`placed_text` tracks `ocr_cells` at every radius.** The state `ocr_text_unplaced` detects — cells
read, nothing placed — never occurred, so the arm never fired, and the "whole page classified as a
picture" behaviour did not appear at 3.0 or anywhere above it. Two rows are worth reading as controls
rather than as results: at 8.0 the pre-existing `ocr_low_coverage` arm fires exactly as designed, and
at 12.0 OCR reads nothing at all, so both counts are zero and silence is **correct** — that is the
false-positive case the detector was fixed for (`docs/22` § G17), passing on a real page.

**What this does and does not establish.** It does not show the defect is absent: 14D measured it on
different input, and this page is clean typography rasterised and blurred, with none of the noise,
skew or JPEG artefacts a real scan carries — any of which could move a layout model that Gaussian blur
alone does not. It does establish that **the reproduction we wrote cannot reach the state**, so the
end-to-end claim is still owed and the `blur >= 3.0` figure should not be repeated as though measured
through this path. `docs/23`'s row is updated to say so. Closing it needs 14D's actual input, not a
larger radius — the sweep already went to 12.0 and found the other failure mode instead.

**The signal itself is unaffected and stays.** It is five unit tests, no tunable, `ocr_cfg_version()`
unmoved, and it costs nothing when the state never arises — which the sweep also just demonstrated
across seven parses.

## The auth-and-session decisions — ADR-038…043

One effort, six batches, `services/core-api` and `apps/web`: login, logout, `GET /me`, org switch,
password reset, email verification, invitation-gated registration, org invitations, and a real
`audit_logs` table. It made **forty-four numbered decisions** and produced **six** ADRs, and the ratio is
the first thing worth recording. Most of the forty-four record no architectural choice — which file a
helper lives in, which inherited parameter must stay untyped to avoid an LSP fatal, which Vitest project
needs a `define` for a `process.env` read. The six below are the ones a future change can *violate*, and
each one names alternatives that were genuinely on the table.

**Numbering, stated once.** The effort's decisions are cited as **plan D1…D44** throughout, because
§ *The rulings of 2026-08-12* § G6 already uses the bare label `D7` for an unrelated tracker item and the
two would otherwise collide on the page. The plan file is
`~/.claude/plans/let-s-build-user-auth-refactored-avalanche.md`; it is the authoritative record of the
effort and is not in this repository.

**What is deliberately *not* an ADR, and why.** Plan D1 (the route table, and the fact that `/me` and the
session routes cannot carry `org.member` because `TenantContext::handle()` throws when the
`{organization}` segment is absent) is a *consequence* of ADR-038's placement, not a separate decision —
it is recorded inside it. Plan D4 (`remember` dropped), D12 (FormRequests flat rather than under an
`Auth\` subnamespace), D14…D19, D29…D31, D35, D37, D38, D41, D43 and D44 are implementation rulings, and
those of them that a future reader could re-open are recorded as findings below or in the code comment
that carries the measurement. Two are recorded as **reversals** rather than as decisions, because their
value is the refutation and not the outcome — see § *The six reversals* at the end of this section.

### ADR-038 — one mechanism on the admin surface, and the option that made a test unwritable

Full decision and consequences: [ADR-038](19-repo-structure-adrs.md#adr-038-one-authentication-mechanism-on-the-admin-surface-enforced-by-three-negatives).
The option set, with what each costs:

**(a) Reject the bearer credential at the group boundary.** `RejectBearerToken` prepended to the `api`
group; no trait, no table, no prune schedule. *Costs:* the `laravel-sanctum-auth` Definition-of-done test
becomes unwritable, because `tokenCan()` will not exist to call; and a future PAT surface starts from
nothing rather than from a half-built mechanism.

**(b) Create `personal_access_tokens` in the framework's shape** so `PersonalAccessToken::findToken()`
returns `null` and the bearer branch answers 401 honestly. *Costs:* it makes the wrong credential merely
*fail* rather than stating the invariant, and it plants a table — with its own index growth, its own
prune-schedule question and its own `sanctum.expiration` interaction — for a credential nothing mints.
It also leaves the second authentication path live in code, so the drift the rule exists to prevent
remains one `createToken()` call away.

**(c) Add `HasApiTokens` to `User` for the test.** *Costs:* this was the original plan and it is the one
that was overturned. It grants the admin surface a second credential type in order to make one assertion
writable. The measurement that killed it is in vendor: `Guard::__invoke()` checks
`supportsTokens($user)` and returns the session user **unchanged** when the trait is absent — so the
trait buys authentication nothing, and its only effect is that `tokenCan()`, `createToken()` and
`currentAccessToken()` begin to exist.

**Ruled (a).** The property the lost DoD test protected — *abilities are never the gate* — is now three
negatives in `tests/Security/SingleCredentialMechanismTest.php`. **The honest limitation:** that is a
stronger property and a *different claim*. The original test would have demonstrated that a
`TransientToken`'s `can()` returns `true` for every string; nothing here demonstrates it, because no code
path can construct one. The gotcha survives as doctrine, and the next person to add ability middleware to
this codebase will not be stopped by a measurement — they will be stopped by a grep.

**One consequence found while writing it.** The pre-existing unauthenticated **500** that (b) was
partly proposed to fix is fixed by (a) as a side effect: any request to an `auth:sanctum` route carrying
a non-empty `Authorization: Bearer` and no valid session reached `findToken()` against a table no
migration creates → `42P01` → 500 `internal_dependency`, from anyone, with no credential at all. It
predates this work and is not caused by it.

### ADR-039 — opaque hashed rows, and the signed-URL option that cannot be validated at all

Full decision: [ADR-039](19-repo-structure-adrs.md#adr-039-emailed-capabilities-are-opaque-hashed-database-rows-never-signed-urls-and-never-a-path-segment).

**(a) `URL::temporarySignedRoute()` for all three flows.** *Costs:* it does not work here, and that is a
mechanism rather than a preference. `URL::hasValidSignature($request)` validates against
`$request->url()`, which is the **API** URL, while the URL the recipient clicked is the **SPA** URL — so
validating after the SPA echoes the parameters back means reconstructing and re-verifying the signed SPA
URL by hand, and any difference in query-parameter order or percent-encoding between what Laravel signed
and what the SPA echoes fails `hash_equals` silently. That is a bug that reproduces on some mail clients
and not others. Three further costs, each of which would decide it alone: a signed URL additionally
carries the user id and `sha1(email)`; it is replayable until `expires`, so single use is inexpressible;
and it is not revocable without a denylist, which is a table anyway.

**(b) One opaque 32-byte token per flow, stored as a digest.** *Costs:* one extra table and one prune
entry. `password_reset_tokens` keeps the framework's bcrypt `token text` because
`DatabaseTokenRepository` writes it, so the *shape* is not uniform even though the *doctrine* is —
`bytea` digests for the two new tables, a bcrypt string for the framework's.

**(c) (b), but with the token as a path segment** so the routes read as REST. *Costs:* a capability in a
path lands in Traefik access logs, in `Referer` and in browser history, and it re-opens a prefix hazard
where `/invitations-<anything>` can be mistaken for a route in the group.

**Ruled (b), with plan D2's no-path-segment rule.** Two things worth carrying forward. First, `email`
appears in the reset URL and only there, because `PasswordBroker::reset()` needs it to find the user and
the token row is keyed by it — it is the framework's own shape, and the address is in the recipient's own
mailbox. The verification and invitation URLs carry no id, no email and no hash. Second, plan D13 made
the field name `token` in **every** FormRequest that accepts one; the design had used
`invitation_token` in one of five, and `form-drift.test.ts` asserts path-set equality against the Zod
schema, so one name is not tidiness — two names is a red suite or a silently unmirrored field.

**The accepted residual is the interesting one.** A queued notification puts the **plaintext** token in
the Valkey job body for the life of the job. Every alternative is worse: not queueing re-opens the
timing oracle ADR-040 closes, and re-minting inside the job changes the token after the row was written.
So the Security suite asserts the plaintext is absent from `storage/logs`, from every log line and from
the response body, and **deliberately not** from Valkey — an assertion that would fail on correct code.

### ADR-040 — account non-enumeration, and why it is not the deny-oracle property

Full decision: [ADR-040](19-repo-structure-adrs.md#adr-040-account-non-enumeration-is-a-response-shape-rule-and-it-is-not-the-deny-oracle-property).

The two properties are distinct and the documents must not let them be confused, because the *tests* for
them are different in kind:

| | Deny oracle (`DenyOracleTest`, `toDenyAsNotFound()`) | Account non-enumeration (`AccountEnumerationTest`) |
|---|---|---|
| Claim | a **denial** is byte-identical to a **record that never existed** | the **N ways of failing one endpoint** are byte-identical to **each other** |
| Surface | `rt/*` and `sdk/*` — the public ones | `api/*` — the admin one, which *deliberately* admits record existence with a 403 |
| Reference | a **live control**: a real request to a path on the same surface with certainly no route | the sibling failures themselves; **never** a literal and never a missing route |
| Wrong test it invites | using it on an admin route, where it **passes while asserting the wrong property** | turning an admin denial into a 404 "to be safe", which fails the deny test's admin arm and makes its public arm vacuous |

**The options that lost.** **401 for bad credentials** — rejected because a 401 *from* `/login` is a
redirect loop in the SPA's global handler, and because the taxonomy argument cuts the other way anyway:
the login request carries no session or token to be invalid, it carries a form, and the form failed
business validation. **A message on `password`** — that is the oracle in one sentence. **429 on a
broker-throttled forgot-password** — probing twice turns the throttle itself into the oracle, because a
throttle proves the first probe created a token, which proves the account exists; all three broker
outcomes collapse to one 200. Our own `password-request` limiter still returns a real 429, and discloses
nothing because it keys on `acct:` **and** `ip:` and fires identically for existing and non-existing
addresses. **`unique:users,email` on register** — that makes `/register` a live account-existence oracle
for anyone who can POST. **Distinguishing unknown-address from wrong-password in the audit `reason`**
(plan D27) — it costs a second `retrieveByCredentials()` probe *outside* `SessionGuard::attempt()`'s
200 ms timebox, which reintroduces in our own code the differential the framework spends a timebox
closing; an investigator can ask the same question of the same database later.

**The fifth invalid-invitation case was found during implementation, not design** (plan D33): a pending
invitation into a **suspended organization**. The design enumerated four. A 409 there would prove both
that the token was real *and* a fact about a tenant the caller is not in, so it folds into the same
byte-identical refusal.

**The cost nobody likes.** A recipient whose invitation genuinely expired is told only that it is no
longer valid, and support cannot distinguish that from a typo without reading the database. That is the
price of the property, it is paid every time, and the answer when it becomes intolerable is an
authenticated read surface — not a richer public error body.

### ADR-041 — the audit table, and the two-skill contradiction it resolved

Full decision: [ADR-041](19-repo-structure-adrs.md#adr-041-audit_logs-is-append-only-and-monthly-range-partitioned-and-the-write-failure-policy-is-per-operation).

**The contradiction was real, and both sides were right.**
`.claude/skills/kb-observability-conventions/SKILL.md` and its Definition of done state that *an audit
write failure aborts the operation*. The brief for this work stated that an audit write failure *must not
turn a successful login into a 500*. Neither is wrong; they describe different operations. The options:

**The line between the two, stated as a rule rather than as a list** — see **H14** for why that
matters: `ON_FAILURE_ABORT` where the audited change can still be rolled back, `ON_FAILURE_LOG` where it
cannot. For a session act the cookie has already been issued or destroyed and there is no transaction
left; for the forgot-password request the mail is already dispatched and the row is written *inside* the
broker's 200 ms timebox, where an extra failure branch would be a timing differential. The options:

**(a) Abort always.** *Costs:* a failed audit INSERT on the login path returns 500 to a caller whose
session cookie has already been issued — the caller **is** logged in, the response says otherwise, and
the row is lost as well. There is no transaction left to roll back at that point, so "abort" does not
even restore consistency.

**(b) Log always.** *Costs:* a role change can succeed with no record of who made it. That is the exact
failure the audit table exists to prevent, and it fails silently.

**(c) Per operation, in one constant.** *Costs:* two behaviours to understand instead of one, and a new
operation has to choose. Chosen, because the choice is *forced* — the constant is the only place a
policy can be set, so a caller cannot accidentally pick the lenient policy for a state change, which a
per-call-site argument would invite.

**(d) Per operation, chosen by the caller.** Rejected outright for the reason (c) is chosen.

**Two constraints (c) puts on every caller, and they are easy to violate by accident.** Every
ABORT-policy audit call must sit in **one transaction with the state change it records** — `AuditLogger`
deliberately opens none, because a service that transacts around a single INSERT gives the appearance of
atomicity without the fact. And `kb:create-audit-partitions` must **stay scheduled**: at 00:00 on the
first of a month past the partition runway, every audit insert fails with `23514` and every ABORT-policy
action returns 500 — total, instant, on a clock. Its sibling `kb:prune-audit-partitions` is deliberately
**not** scheduled, because an unattended DROP of whole months of the compliance record is a retention
policy rather than a maintenance task.

**What the append-only guarantee actually rests on, measured rather than assumed.** On live PostgreSQL
18.4 the ACL is correct — `pg_class.relacl` shows no UPDATE and no DELETE on the parent or any partition
— and `UPDATE audit_logs SET operation='tampered'` still returned **`UPDATE 1`**, because `rolsuper` is
true for the only login role. The test asserts the ACL through `pg_class.relacl` and **never** through
`has_table_privilege()`, which answers "yes" for a superuser and would therefore pass for the wrong
reason; it `markTestSkipped`s the enforcement half with the reason stated. Open as **H3**.

**And the column spelling was a two-writer bug in waiting** (plan D32): the migration documented
`'user'`-style `subject_type` values while every writer in the tree passed `::class`. Two spellings of
one fact in a free-text column means a query for a subject finds half its rows. FQCN wins on three
counts — it is what the code already produces, `::class` is compiler-checked where a literal is not, and
it matches Laravel's morph convention so a future `morphTo()` needs no map.

### ADR-042 — invitation-gated registration, and the four decisions tooling made for us

Full decision: [ADR-042](19-repo-structure-adrs.md#adr-042-registration-is-invitation-gated-and-the-session-wire-is-flat-enveloped-and-total).

**Onboarding options.** **(a) HTTP self-service signup** — rejected: no invitation, no tenant, and no
bound on abuse, and it would need an organization-creating route, which is the one thing this scope
refuses. **(b) A seeder** — rejected: `DatabaseSeeder` stays empty per its own docblock, and a seeder is
not an operator-facing artifact. **(c) An artisan command that refuses to run when any organization
exists.** Chosen. Its four safety properties are each independently sufficient, and the one worth
re-reading is *idempotent by refusal, not by upsert*: an upsert would let the command silently **change**
an existing owner, which is precisely the backdoor a bootstrap command is suspected of being.
`--print-link` exists because bootstrapping an environment with no working mailbox is a real situation
and gating it produces a lockout; it is off by default and its help text says it writes a credential to
the terminal.

**Four wire decisions were forced by tooling rather than chosen, and the distinction matters** because
each has an aesthetic reading that is wrong:

- **Flat and total** (plan D5): `tests/Contract/OpenApiDocumentTest.php` requires every resource
  component be **closed** (`additionalProperties: false`) *and* **total** (declared ⊆ required). There
  are no optional fields available, so "make `current_organization_id` optional" is not a choice.
  `organizations` lists **all** memberships with `status`, not only active ones, so a suspended user can
  see why their list is greyed out; `current_organization_id` is only ever an active membership or
  `null`.
- **The `data` envelope** (plan D23): `ResponseShape::$properties` maps a response *key* to a
  `ProvidesOpenApiSchema` class, so an unwrapped body is literally unpublishable by `kb:dump-openapi` —
  and both endpoints that predate all auth work already wrap. This is how a **real cross-plane break**
  was caught: the MSW fixtures had been written unwrapped against an assumption rather than against the
  controllers. It was fixed on the **fixture** side, because a fixture that disagrees with the server is
  exactly the bug the MSW layer exists to catch.
- **`{"data":{"invitations":[…]}}` rather than `{"data":[…]}`** (plan D26): `DumpOpenApiCommand` cannot
  express "an array of" for a response key, while the contract test requires `additionalProperties:
  false`, which an array schema cannot carry. Recorded as a dumper gap (**H2**), not as a style.
- **`resend` as a single-action controller** (plan D26): `arch()->preset()->laravel()` restricts a
  controller's public methods to the seven resource verbs plus `__construct`/`__invoke`/`middleware`, so
  `InvitationController@resend` failed the Arch suite — which was the preset working.

**`RegisterRequest` validates no `email`** (plan D3) for two independent reasons: it removes a disclosure
branch, and the drift suite's path-set equality would otherwise force `email` into the Zod schema for a
field the server does not read. **The one deliberate disclosure in the whole surface** is register's
already-registered case, and it is acceptable precisely because the caller has already proven possession
of an invitation token bound to that exact address, which an org admin deliberately sent there.

### ADR-043 — the `#[ScopedBy]` omission, and why the obvious escape hatch is the worst option

Full decision: [ADR-043](19-repo-structure-adrs.md#adr-043-two-org-owned-models-deliberately-carry-no-scopedby-because-organizationscope-fails-closed).
**This is the ADR that is not in the effort's own suggested grouping**, and it is here because it is a
decision a future change can violate with a one-line edit that looks like a correction. It also has the
sharpest option set of the six.

**(a) Attach `#[ScopedBy(OrganizationScope::class)]` like every other org-owned model.** *Costs:* the
guest read paths — invitation preview, register, accept, email verify — run **before** any organization
is known, `OrganizationScope::apply()` fails closed with `whereRaw('1 = 0')`, and every lookup returns
nothing **always**. The failure renders as *"this invitation is no longer valid."* — a plausible,
correct-looking message. Registration would be totally broken with green-looking code, no exception and
no log line. Not viable.

**(b) Attach it, and call `withoutGlobalScope(OrganizationScope::class)` on the guest reads.** *Costs:*
this is the option someone will reach for, and it is **worse than (c)**, which is the part worth
recording. The tenancy gate greps `withoutGlobalScopes\(` — **plural** — so the singular call is
invisible to CI while the model reads as correctly scoped in review. The result is a real bypass with a
green gate, which is strictly worse than a stated exemption with no gate. Both models name this rejection
in their docblocks so the next reader finds the reasoning at the call site.

**(c) Omit the attribute, and carry isolation in the read path instead** — guest reads by `token_hash`
(256-bit random, unique index), admin reads through a repository method taking `organization_id` as a
required positional argument. **Chosen**, matching the pre-existing decision on `OrganizationUser`.
*Costs:* it is a convention rather than a mechanism, and it does not fail closed. A repository method
that later grows an `organization_id`-optional overload silently loses the entire guard, and no test
would notice.

**The consequence that hurts is about the models that come next, not these two.** The reflection arch rule
*"every org-owned model carries `#[ScopedBy]`"* stays disabled, because three models now claim the
exemption and enabling it needs an annotated exception list carrying each model's reason (the
`// tenancy-exempt: <reason>` form the tenancy skill's Definition of done already establishes). Until that
list exists a **new** org-owned model can ship unscoped with nothing complaining. These two are exempt for
a stated reason; the fourth might be exempt by accident, and that is the direction the missing rule fails
in. Recorded as the ADR's revisit condition, and as **H1** for the grep half.

### The six reversals

Recorded because a ruling kept only as its outcome invites the same question next quarter — ADR-036's
spirit applied to this effort's own history. Four of these overturned an earlier decision *by the same
author*, and two refuted a premise that had been published as measured.

**1. Plan D16 → D25 — the framework's `verified` middleware would have 500ed a `curl`.** D16 created
`EmailVerificationService` as a throwing placeholder so PHPStan would not be red for a known reason
across two batches. D25 then replaced Illuminate's `EnsureEmailIsVerified` with our own, because
Illuminate's redirects a non-JSON caller to a route named `verification.notice` — which does not exist
here and never will, since no HTML is served and the notice is a Next.js page on another host. That
branch is reachable with a plain `curl` carrying a valid session cookie and no `Accept` header, and
renders as an unauthenticated-adjacent **500**. Ours throws `AuthorizationException` and has no redirect
branch, so the render closure applies the surface-aware 403-admin / 404-public split.

**2. Plan D24 — the design's own rate limiter was a global choke.** As written, `throttle:verification`
keyed on the literal `'anon'` when there was no authenticated user, so **every anonymous caller on the
internet shared one bucket of six verification attempts per hour**. `POST /auth/email/verify` is
necessarily a guest route, because mail links open in another browser. The implementing agent shipped
the design verbatim and documented the hazard rather than silently diverging; the repair keys on the
token digest instead. Worth preserving because the defect is invisible in review — the limiter *looks*
per-caller.

**3. Plan D28 — an audit gap the implementer refused to paper over.** Resend **mints a new bearer
capability** and kills the old one, and wrote no audit row: an admin could mail an unbounded number of
live invitation links to a third party's mailbox and the trail would show one `created` from months
earlier. The implementer declined to reuse the `created` operation, correctly — that would make the
table assert one invitation was created twice and break the "count creations to count invitees" reading
— and added `organization.invitation.resent` as a new ABORT-policy operation inside `rotateToken()`'s
transaction, plus a new `invitation-resend` limiter keyed on the **invitation** rather than the actor.
The existing `throttle:admin` keys on the actor at 120/min and therefore cannot bound per-recipient
volume at all.

**4. Plan D34 and D36 — the implementation forced two copy-and-status decisions the design got wrong.**
D34: the shared error handler produced *"You do not have access to this."* for an unusable
verification link — taxonomy-correct (`authorization`/404, since the capability addresses nothing the
caller may act on) and **wrong user-facing copy**, telling someone who clicked a link in their own inbox
that they lack a permission, and contradicting the explanation rendered beneath it. The fix is a
per-screen `copyFor` override used in exactly one place, asserted by what it **replaces** so deleting it
goes red. D36: a suspended organization answered 409, which the client rendered as *"Something on our
side is unavailable. Try again shortly."* — false twice over, and unrepairable client-side because the
envelope carries the **class**, not the status, so a suspended org is indistinguishable from a real 503.
The 409 → `internal_dependency` mapping is deliberate and has a live tripwire test, so the fix was to
stop emitting 409.

**5. Plan D39 → D40 — a schema that was correctly refused, then correctly shipped.** D39 shipped the
invite form with **no Zod resolver**, on the rule that a schema ships *with* its manifest and a drift
test or it does not ship. D40 closed it in the order the rule requires, once the manifest existed. What
the move bought is a check neither copy could have had: the drift suite probes `in:` **member by
member** against `Rule::in(OrgRole::values())`, where a local `readonly Role[]` annotation caught only a
role the server **dropped** and was blind to one the server **added** — the direction that actually
happens, whose symptom is a select silently missing an option with nothing red. Mutation-verified both
ways.

**6. Plan D42 — an agent measured its predecessor's formula and found it wrong.** The drift harness was
predicted to need blanket suppression of email size probes, with `'a'.repeat(242) + '@example.com'` given
as the sized generator. Measured against `egulias/email-validator 4.0.4` under
`RFCValidation + NoRFCWarningsValidation` — what `ValidatesAttributes.php` builds for `rfc,strict` — that
address is **invalid** (`LocalTooLong`, 242 > 64), so the harness would have asserted a server
acceptance that does not happen. The replacement synthesizer (local ≤ 64 plus a dot-atom domain) is
measured valid at **every** length 6…254 and invalid at 255, pointing the same way `max:254` does.
Suppression was made the last resort deliberately, because `min:12` on a password and `max:254` on an
address are precisely the numbers most likely to drift and the client-side spec asserts them against
hard-coded constants that cannot notice the **server** moving. This is the entry to read first: the
predecessor's figure was stated with a specific number and a specific rule, which is what made it
checkable — and it was wrong.

**A seventh, adjacent, worth one line because it is an ordering lesson rather than a reversal:** plan
D44 found `pnpm contracts:typecheck` red while Vitest was green, on a `test/**` file fixed in an earlier
batch. Vitest does not typecheck, and CI runs both. **A `test/**` change is not verified by running the
tests.**

## Found while building auth and session — 2026-08-13

Numbered **H1…H14**, a namespace of its own, appended and never inserted. The detector is a fifth one
again: not a document that went false (R1–R8), not an unsatisfiable requirement (F11–F12), not an inert
knob (F13) — these are **things the effort chose not to fix, each with a named owner**, plus the traps
in the test harness that make a correct assertion report the opposite of the truth. That last group is
the one to read: a harness fact that inverts an assertion is worse than a missing test, because the
missing test is visible and the inverted one is green.

**H14 is the odd one out and was found by writing this section rather than by the effort**, which is
recorded rather than smoothed over: the ADR-041 entry could not be written without stating the
ABORT-versus-LOG rule, and stating it surfaced a count in the implementation's own docblock that the
implementation had already made wrong.

Every claim below was verified against the tree on 2026-08-13, and per ADR-036 each one leads with the
command that re-measures it rather than with a line number.

### H1 — the tenancy gate greps the plural `withoutGlobalScopes(` only

```bash
grep -n 'withoutGlobalScopes\?\\(' .github/workflows/gates.yml
```

The pattern in the *Tenant scoping* job is `withoutGlobalScopes\(` — **plural**. The singular
`withoutGlobalScope(` is a per-scope bypass the gate cannot see, and it is exactly the escape hatch
someone reaches for when `OrganizationScope`'s fail-closed branch breaks a guest read. That is not
hypothetical: it is the **rejected alternative in [ADR-043](19-repo-structure-adrs.md#adr-043-two-org-owned-models-deliberately-carry-no-scopedby-because-organizationscope-fails-closed)**,
and the reason the ADR rejects it is that the bypass would be invisible while the model looked correctly
scoped in review. Both `OrganizationInvitation` and `EmailVerificationToken` name the rejection in their
docblocks, so a grep for `withoutGlobalScope(` in `app/Models/` currently returns those two comments and
no call.

**The fix is one character:** widen to `withoutGlobalScopes?\(`. **Owner:** `platform-devops-engineer`
(the file is `scripts/`-adjacent CI and not `docs/`'s to edit). Note the gate already has the
`tenancy-exempt:` escape and the `$KB_PHP_COMMENT_LINE` comment filter, so widening it does not make the
two docblocks red.

### H2 — three `DumpOpenApiCommand` gaps, one of which shaped a published wire format

> **Status, 2026-08-13:** (a) and (c) are **closed**, each by a different fix from the one recorded below;
> both disagreements are preserved in place. (b) remains open and is the one that matters, because it is
> already visible on the public wire.

```bash
grep -n 'getActionMethod\|properties. => .properties' services/core-api/app/Console/Commands/DumpOpenApiCommand.php
```

**(a) An empty `#[ResponseShape]` publishes invalid JSON Schema.** `operation()` emitted
`'required' => array_keys($properties)` and `'properties' => $properties` unconditionally, so a bodyless
success (`properties: []`) json-encoded `properties` as `[]` — a JSON array where the schema requires an
object.

**CLOSED, and NOT by the fix this finding recommended.** The paragraph above proposed `(object) $properties`;
`responseShapeFor()` now **refuses at dump time** instead, with a message naming the action
(`Controller::method`) and pointing at `AcknowledgementResource::ok()`. The disagreement is recorded rather
than overwritten, because the reasoning is the interesting part: casting makes the dumper *accept* the shape
the convention forbids, and the convention is not stylistic. Under plan D23 the SPA unwraps `data` once at
the fetch boundary (`browserFetch<{data: T}>` then `.data`), so a 204 hands that boundary `undefined` at a
call site with no branch for it — an emitted-but-valid empty schema would have published that as supported.
Refusing keeps D8 enforced rather than merely observed, and if a bodyless success is ever wanted, this guard
is what must be deleted **deliberately**, together with the `content` omission and the client's unwrap.

**(b) It cannot express "an array of" for a response key.** This is the gap that **forced a wire
format**: `{"data":{"invitations":[…]}}` rather than `{"data":[…]}` (plan D26), because
`tests/Contract/OpenApiDocumentTest.php` also requires every component be `additionalProperties: false`,
which an array schema cannot carry. So a tooling limitation is now visible on the public wire, and
closing it later would be a breaking change to two endpoints rather than a dumper fix.

**(c) `getActionMethod()` returns the class name for a single-action controller.** Register a route as
`Route::post($uri, SomeController::class)` and `getActionMethod()` yields the **class name**,
`method_exists()` fails, and the dump aborts with *"is not a controller action"* — taking the whole
generated OpenAPI document with it.

**CLOSED**, by an `actionMethod()` helper that reads `$route->getAction('uses')` — what the router actually
dispatches — through `Str::parseCallback($uses, '__invoke')`. Closure routes still fall through to the
existing "is not a controller action" refusal. No document change: `routes/api_admin.php` already spelled
`'__invoke'` out, and that explicit form is kept as belt-and-braces with its comment rewritten to say it is
no longer load-bearing.

**AND THE MECHANISM ABOVE WAS STATED SLIGHTLY WRONG HERE**, which is worth correcting rather than deleting,
because the wrong version is the one somebody would reason from. This finding said `RouteAction::parse()`
"only appends `@__invoke` when the action is a bare string". It appends to `uses` whenever there is no `@`.
The actual failure is an ORDERING one: `Router::convertToControllerAction()` writes the `controller` key
**first**, `RouteAction::makeInvokable()` appends `@__invoke` to `uses` afterwards, and
`getActionName()` reads `controller` — so the two keys disagree and the older one wins.

**Owner:** `control-plane-engineer`. (a) and (c) are closed. **(b) is the one that remains**, it is a design
question rather than a small fix, and the honest statement is unchanged: the public wire now depends on the
answer, so closing it later is a breaking change to two endpoints rather than a dumper fix.

### H3 — the `audit_logs` REVOKE is an audit artifact, not an enforced control

```bash
grep -rn 'POSTGRES_USER' infrastructure/docker/
```

Plan D21, and it is written into the migration rather than papered over. Measured on live PostgreSQL
18.4: `pg_class.relacl` shows no UPDATE and no DELETE on the parent or on any partition — the ACL is
exactly right — and `UPDATE audit_logs SET operation='tampered'` still returned **`UPDATE 1`**, because
`rolsuper` is **true** for the only login role. Compose's `POSTGRES_USER` creates a superuser, and a
`REVOKE` from a superuser is decoration. Two further limits, both stated in the migration because a
security control *believed* to be stronger than it is, is worse than a missing one: **`TRUNCATE` is not
revoked**, deliberately, because the Integration suite's `DatabaseTruncation` needs it on every table and
it erases evidence as thoroughly as a `DELETE`; and **a table owner can `GRANT` the privilege back to
itself** — PostgreSQL owners hold no *implicit* privileges, so the REVOKE does bite a non-superuser
owner, but they retain the right to re-grant. Real append-only therefore needs the application role **not
to own the table**, which is the same role split.

**One non-obvious thing the migration gets right and a naive version would not:** privileges on a
partition are **not** inherited from the parent. Access routed *through* the parent checks only the
parent, but `UPDATE audit_logs_2026_08 SET …` checks the partition, and a partition created by this role
is owned by it with the owner's default privileges — so revoking only on the parent leaves every
partition writable by name. The REVOKE covers the parent and every existing partition, and
`kb:create-audit-partitions` re-issues it for each new one
(`grep -n REVOKE services/core-api/app/Repositories/Eloquent/EloquentAuditLogPartitionRepository.php`) —
which is the line to check if a future partition creator is written anywhere else.

Append-only therefore currently rests on three things and not on the database: the ACL as a record of
intent, `AuditLog`'s PHP-level refusals, and no code path issuing the statement. The test asserts the ACL
via `pg_class.relacl` and **never** via `has_table_privilege()`, which answers "yes" for a superuser and
would pass for the wrong reason; the enforcement half `markTestSkipped`s with the reason stated.

**Closing it needs a non-superuser application role in `infrastructure/docker/`**, which is also the
moment to revoke `TRUNCATE` and to turn the skip into an assertion. **Owner:**
`platform-devops-engineer`. Note the ordering trap the migration itself records: if the role is
introduced later, a `REVOKE … FROM PUBLIC, CURRENT_USER` written today hits the **owner** and the new
app role keeps its UPDATE/DELETE, so the migration and the role have to be reasoned about together.

### H4 — `sanctum:prune-expired` is now *permanently* moot rather than pending — F7(b) updated in place

F7(b) recorded a scheduled command against a table no migration creates. Its framing — *"the command
class ships, the table does not"*, filed beside `queue:prune-failed` as though both were waiting for a
migration — is now **wrong in a way that changes what a reader should do about it**, and F7(b) has been
corrected rather than duplicated here. Under
[ADR-038](19-repo-structure-adrs.md#adr-038-one-authentication-mechanism-on-the-admin-surface-enforced-by-three-negatives)
the omission is **permanent on this surface**: nothing mints, so a pruner is not a pending entry but a
no-op with a cron slot, and scheduling it would suggest a second credential exists.

```bash
grep -n 'sanctum:prune-expired' services/core-api/routes/console.php
```

The entry is commented out under a `PERMANENTLY OMITTED UNDER DECISION D11` banner that states the
measurement (against the applied migrations, `php artisan sanctum:prune-expired --hours=24` exits 1 with
`SQLSTATE[42P01] … relation "personal_access_tokens" does not exist` — so it would not prune nothing, it
would **fail once an hour forever**, dispatching `ScheduledTaskFailed` while `schedule:list` displayed
the entry as handled) and names the four things a future PAT surface must land together. **Owner:**
`control-plane-engineer`; the console-file half is **done**.

**Still owed to a skill, in E4's form:** `.claude/skills/laravel-sanctum-auth/SKILL.md`'s Definition of
done asks that `sanctum:prune-expired` **be scheduled**, which is now false for this surface, and asks
for a test that `tokenCan()` returns `true` under a session, which is **unwritable** under ADR-038.
`.claude/skills/**` is not `docs/`'s to edit; recorded so the correction is a small edit against a stated
finding. **Owner:** the skill's owning agent.

### H5 — two stale CI comments, both describing a mechanism that no longer exists

```bash
grep -n 'src/resources/ is generated\|five data services' .github/workflows/ci.yml .github/workflows/gates.yml
```

**(a) `ci.yml`'s *OpenAPI document is current* step** says `packages/contracts/src/resources/` is
**generated from** the OpenAPI document. It is hand-written, and it is *mechanically checked* against the
document by `packages/contracts/test/resource-drift.test.ts` — which is what replaces a generator, and
which earned its place immediately: it caught both a wire-component rename (`SessionOrganization` in TS
against `SessionMembership` in PHP) and its own wrong assumption that enums are published as named
components, when `DumpOpenApiCommand` **inlines** them into the property that carries them (plan D37).
The comment's *failure mode* is still right; its *mechanism* is wrong, and a reader who believes it will
go looking for the generator.

**(b) `gates.yml`'s port-publishing gate** explains why it is not asserted over
`compose.override.yaml` by naming five data services that file publishes — postgres, qdrant, both
valkeys, seaweedfs. Measured: `compose.override.yaml` now publishes **two**, and neither list is what the
comment says. The *exclusion* is still correct and the assertion is unaffected; the justification names a
list that was deleted.

**Owners:** `platform-devops-engineer` for both files. (a) is also worth a cross-check by
`admin-web-engineer`, since `src/resources/` is theirs and the comment is a claim about their directory.

### H6 — the sanctum skill's own Definition-of-done grep is red on correct code

```bash
grep -rn 'createToken(' services/core-api/app
```

`.claude/skills/laravel-sanctum-auth/SKILL.md`'s Definition of done asks for
`rg -n "createToken\(" services/core-api/app`. That pattern matches
`Password::broker()->createToken($owner)` in `BootstrapOrganizationCommand` — the framework's
**password-reset** token, minted on purpose so the first operator sets their own password through the
ordinary flow rather than having one handed to them on a terminal. It is single-use, 60 minutes, hashed
in the database, and it is not a bearer credential for this API.

**So if that grep is added to `gates.yml` verbatim, the gate is red on correct code**, and the fix
someone reaches for under time pressure is an exclusion by *file* — which would then let a genuine
`$user->createToken(...)` through in the same file. The replacement in
`tests/Security/SingleCredentialMechanismTest.php` narrows by **receiver** instead
(`/(Password::|->broker\(\)|PasswordBroker)/`), so a real mint in that same file still fails. **Owner:**
whoever lands the gate — the test is the specification for the pattern, not the skill line.

### H7 — two Pest-harness facts that silently invert assertions

Both are documented at length in `services/core-api/tests/Support/SpaSession.php`'s class docblock, and
both are recorded here because the next person writing a multi-request session test will hit them and
the symptom is a **passing test that asserts the opposite of the truth**.

**(a) Guards are cached per process, so logout and password-change tests report the reassuring
direction.** `AuthManager` caches every resolved guard for the life of the process, and Sanctum's
`RequestGuard` caches the resolved user in `$this->user` and never clears it on a new request —
`app()->refresh('request', $guard, 'setRequest')` swaps the request and leaves the user alone. In
production one process serves one request, so this is invisible. In the suite one process serves every
request of a test. Measured 2026-08-13, both directions wrong the same way: after `POST /auth/logout` a
re-sent cookie gets **200** from `GET /me` with the cache and **401** without it; and after a password
change, a sibling session gets **200** with the cache and **401** without — so a *"reset logs the
siblings out"* test written the obvious way reports **the opposite of the truth**, because
`AuthenticateSession` compares `$request->user()->getAuthPassword()` and the cached model still holds the
old hash. `Auth::forgetGuards()` is what a new PHP-FPM request does for free; `SpaSession::freshProcess()`
is that call, and it must follow any request that changes authentication state. It is not a bypass — it
removes a test-only cache and weakens no check.

**(b) `RefreshDatabase` rolls back the database and nothing else, so rate-limiter buckets outlive the
run.** `phpunit.xml` pins `CACHE_STORE=valkey`, so every bucket survives the test that filled it, the
next test in the file, **and the next run of the whole suite**. The `login` limiter allows 20/min per IP
and every request in the suite arrives from `127.0.0.1`. **Reported by the batch that built the harness**
(and not re-measured here, because the stack is down): consecutive runs against the persistent store
produced **28 then 48** failures, and `CACHE_STORE=array` produced **0** — the rising figure is the
tell, since a bucket that survives the run makes the *second* run worse than the first. The fix is not flushing
the cache — under `--parallel` that wipes the sibling workers' locks, which `pest-testing` bars outright
— it is making the key unique, which is also the honest model, since these *are* different clients.
**Anyone adding a Feature test that logs in for real must call `SpaSession::isolateRateLimits()`**, which
assigns the test its own address in `2001:db8::/32` (the IPv6 documentation range, so it can never be
real and still passes the `FILTER_VALIDATE_IP` the audit logger applies). A test that wants to *exercise*
a limiter still can: the bucket is unique to it, not absent. **Owner:** `test-engineer`.

### H8 — `session.domain` is the empty string, not `null`, so adding a default is a no-op

```bash
grep -n 'SESSION_DOMAIN' services/core-api/.env services/core-api/.env.example services/core-api/config/session.php
```

`config/session.php` reads `env('SESSION_DOMAIN')` and `.env` ships `SESSION_DOMAIN=` — a **present**
key with an empty value, which Dotenv supplies as `''`. So `env('SESSION_DOMAIN', '.localhost')` returns
`''`, not `'.localhost'`: **the default never applies**, because a default only fires when the key is
absent.

**Behaviour today is correct** — an empty domain produces a **host-only** cookie, which is what a
cross-origin SPA using `credentials: 'include'` needs, and `tests/Security/SessionCookieScopeTest.php`
asserts that `kb_session`'s `Set-Cookie` carries no `Domain=` attribute and that `config('session.domain')`
neither starts with `.` nor contains `*`. The finding is that the *safety of a default is illusory*:
someone adding `env('SESSION_DOMAIN', '.localhost')` as a belt-and-braces measure would believe they had
set a floor and would have changed nothing, and the same reasoning applied in the other direction — "the
default protects us" — would be wrong. **A trap to know, not a bug to fix.** The hazard the wildcard
would create is live for a reason the skill's gotcha does not state for this topology: the widget is
correctly on a separate registrable domain, but hosted chat is a **public** surface on the *same*
registrable domain as the admin console, so a `.${DOMAIN}` cookie would reach it. **Owner:**
`control-plane-engineer`, as a docblock, not a change.

### H9 — `tenantPair()` still throws, and the two-org fixtures are inline with a pointer

**CLOSED IN PART on 2026-08-19 by ADR-058.** The `bots` migrations landed, the helper no longer
raises, and its signature is unchanged. What is *not* closed is the canary's position: it sits in Org
B's bot welcome message rather than in indexed source content, because the latter needs Phase C's
migrations and a real Qdrant container — so a leak through retrieval, a citation title or an export
is still not covered by this fixture. The finding text below is kept as written, because the reason a
second helper was refused is the reason the Phase C move must be a *move* and not a second canary.
The measuring command inverts on closure:

```bash
grep -n 'not implemented' services/core-api/tests/Support/tenancy.php   # was the finding; now silent
grep -n 'TODO(phase-c)' services/core-api/tests/Support/tenancy.php     # what is still owed
```

Unchanged and still blocked on the `bots`/`knowledge_sources` migrations, which this scope does not
touch. Every two-org test in this effort builds its pair inline from `OrganizationFactory` +
`UserFactory::orgRole()` with a `TODO(fixtures)` pointing at `tenantPair()`. **No second helper was
added**, deliberately: `pest-testing`'s "no single-organization helper" rule is about not making leaky
tests easy, and inline two-org fixtures honour it while a second helper would be the thing everyone
imports and then never migrates off. **Owner:** `test-engineer`, blocked.

### H10 — `ON_FAILURE_LOG` atomicity cannot be proved with a real database failure under `RefreshDatabase`

```bash
grep -n '25P02\|AuditLogRepositorySpy' services/core-api/tests/Feature/AuditAtomicityTest.php
```

The ABORT cases are proved the honest way: dropping the current month's audit partition makes every
INSERT fail at the database with `23514`, inside the real transaction, on the real connection — a mocked
repository would prove the PHP branch and nothing about whether the audit INSERT and the state change
share a transaction at all, which is the entire property.

**The LOG cases cannot use that technique, and the reason is PostgreSQL rather than Laravel.**
`RefreshDatabase` holds one transaction around each test. An unwrapped failing INSERT **aborts that
ambient transaction**, so every later statement in the request dies with `25P02
current transaction is aborted` — the login 500s, and the test reports that `ON_FAILURE_LOG` does not
work. In production there is no ambient transaction and the swallowed failure is genuinely local to the
audit write. So those cases break the write at the **repository boundary** with a spy instead, and the
spec carries a **positive control that the double was reached** (`expect($spy->writes)->toBe([])`), because
a container binding that silently did not take would otherwise produce a green test proving nothing.

Recorded because the substitution looks like a weaker test chosen for convenience, and it is not: the
stronger technique measures a *harness* property here rather than the property under test. **Owner:**
`test-engineer`. The way to close it is an Integration-suite case outside `RefreshDatabase`, not a
larger mock.

### H11 — four things the schema and the surface owe, recorded rather than built

- **No global user suspension.** §8.1 asks for user activation and suspension; the schema has only
  per-membership `MembershipStatus::Suspended`, so a user suspended in their only organization can still
  log in and see an empty list. `users.status` is an `ALTER` on a populated table needing the
  `SET lock_timeout` + `retry()` form, which is its own unit. **Owner:** `control-plane-engineer`.
- **`MembershipStatus::Invited` has no producer.** An invitation creates no membership row — the row is
  created `Active` on acceptance, so `organization_invitations` is the single owner of pending state.
  Retained with a comment naming the new owner, because removing it is a
  `DROP CONSTRAINT` / `ADD CONSTRAINT … NOT VALID` / `VALIDATE CONSTRAINT` migration on a live CHECK for
  zero functional benefit. This is the dead-enum-case smell `Permission.php` warns about, kept
  knowingly. **Owner:** `control-plane-engineer`.
- **`AuditLog::organizationId()` throws for platform-scope rows** (plan D22), so those rows are not
  authorizable through any org policy. `audit_logs.organization_id` is nullable because a failed login
  for an unknown address has no tenant, and `OrgOwned::organizationId(): string` is not — an empty
  string would be a lie the policy layer compares against a real org id and denies, reading as a
  permission bug. Harmless while no read surface exists; **must be decided when the audit-log viewer
  lands**. **Owner:** `control-plane-engineer`.
- **No `organization.bootstrapped` audit operation.** `kb:bootstrap-organization` creates the first
  organization, the first owner and the first membership, and writes one structured **log** line rather
  than an audit row. That is telemetry, which `kb-observability-conventions` explicitly separates from
  audit — different retention, different access control, no append-only guarantee. Now that `audit_logs`
  exists the gap is closable and was not closed. **Owner:** `control-plane-engineer`.

### H12 — two `apps/web` items owed, one of which can only be caught by the build

- **`features/auth/session.ts` still mixes a client-only React context with pure fetch helpers**, so any
  *server* module importing anything from it breaks RSC compilation. Found by `pnpm web:build` and **only**
  by the build — typecheck and the full Vitest suite both passed on the same tree (plan D38). The
  unblocking fix was to extract `singleToken` into a dependency-free
  `src/lib/auth/single-token.ts` imported by both server pages, matching the placement argument
  `safe-next.ts` already makes for `proxy.ts`; **the split of `session.ts` itself is still owed.** The
  general lesson is the ordering one: a green typecheck and a green suite do not prove a Next.js app
  compiles. **Owner:** `admin-web-engineer`.
- **Two `knownPaths` sets still derive from `Object.keys(…FormDefaults())`** rather than from the
  manifest — `forgot-password-form.tsx` and `reset-password-form.tsx`. **Deliberately not changed:** the
  chain (`defaults` keys ≡ `z.strictObject` paths ≡ manifest keys) is asserted end to end by the drift
  suite's set-equality, so both routes catch a server-side field addition and only the *location* of the
  red differs. Recorded so the next form author follows `known-paths.ts` rather than copying the older
  shape. **Owner:** `admin-web-engineer`.

### H13 — the org switcher's accessible role is `combobox`, not `button`

```bash
grep -rn 'getByRole' apps/web/tests/components/org-switcher.test.tsx
```

A Radix `Select.Trigger` renders with `role="combobox"`, so `getByRole('button', { name: 'Switch
organization' })` finds nothing. Recorded because `.claude/skills/vitest-playwright/SKILL.md`'s
two-organization harness sketches the future Playwright selector as `getByRole('button', …)`, and the
accessible **name** was deliberately kept as exactly `Switch organization` so that harness needs no
rename — the role is the only part that has to change. A fact for the next spec author, not a defect.
**Owner:** `test-engineer`, one word in a future spec.

### H14 — `AuditLogger`'s own docblock counts three lenient operations and the constant beside it holds four

Found on 2026-08-13 **while writing ADR-041**, which is why it is here rather than in the effort's own
report: the ADR could not be written without stating the rule, and stating the rule surfaced the
mismatch.

```bash
grep -c 'ON_FAILURE_LOG,' services/core-api/app/Services/Audit/AuditLogger.php
grep -n 'authentication-outcome events' services/core-api/app/Services/Audit/AuditLogger.php
```

The class docblock says *"for the **three** authentication-outcome events, the thing being audited has
ALREADY HAPPENED irreversibly"* and names a session cookie being issued or destroyed. The constant
assigns `ON_FAILURE_LOG` to a **fourth** operation, `auth.password_reset.requested`, which is neither an
authentication outcome nor a cookie act — and which *does* leave a durable row, since the broker creates
a `password_reset_tokens` row before the audit call. Two sentences later the same docblock says
*"changing **four** rows of one constant is the whole edit"*, so the file already disagrees with itself
by one.

**Both figures are stale in the same way and for the same reason ADR-036 exists**: plan D20 was written
about three session operations, a fourth lenient operation was added later, and the prose beside the
constant was not re-derived. This is the third instance in this repository of a count restated next to
the list it describes (§ ADR-033, § R5, § G1), and the first inside `services/core-api`.

**The rule the code actually implements is defensible and is not in doubt** — *ABORT where the audited
change can still be rolled back, LOG where it cannot* covers all four, and
`PasswordResetService`'s own docblock gives the extra reason specific to that operation: the row is
written **inside** `PasswordBroker::sendResetLink()`'s 200 ms timebox on the exists-branch only, so an
extra failure branch there is a timing differential, and the timebox absorbs the INSERT rather than the
handling of its failure. The defect is that the rule is nowhere stated and a count is stated twice
instead.

**And nothing would catch a policy flip.** `tests/Unit/AuditLoggerTest.php` pins the operation **names**
(plan D29, correctly — a count could not say *which* operation appeared) and asserts each `on_failure`
value is *one of* the two constants, which is a type check rather than a policy check. So moving a role
change from ABORT to LOG is a green suite: the audited state change would commit with no row, silently,
which is exactly the failure the ABORT policy exists to prevent. **Recommended fix, and it is small:**
replace the two counts with the rule, and pin the **policy per operation by name** in the same style D29
used for the name set. **Owner:** `control-plane-engineer`.

### H15 — every guest operation expressed "no credential" as SILENCE, and silence is unassertable *(CLOSED)*

```bash
python3 -c "import json;d=json.load(open('packages/contracts/openapi/core-api.openapi.json'));print(sorted((o.get('operationId'),o.get('security')) for p in d['paths'].values() for m,o in p.items() if m in ('get','post','put','patch','delete')))"
```

The premise this was investigated under was wrong, and finding that out was the useful part.
`DumpOpenApiCommand::securityFor()` **already existed at the skeleton commit** and already derived the
requirement from `$route->gatherMiddleware()`, so all 13 `auth:sanctum` operations already carried
`security: [{"sanctumSession": []}]` — the "derive it from the route rather than a hand-maintained
attribute argument" approach was the implementation, not a proposal.

What was genuinely missing is the other half. The six guest operations expressed "guest" only as the
**absence** of the key. Under OpenAPI that resolves to the document-level requirement, and with none
declared it means "no credential" — the right answer, reached by silence, and therefore indistinguishable
from a generator that never asked the question. It was also unassertable, which is why nothing noticed.

`security` is now emitted **unconditionally**, `[]` included, and `securityFor()` carries two refusals
guarding the one dangerous direction of this field — publishing an authenticated route as public:

* an auth-family middleware that is not `auth:sanctum` (`/^auth($|[:.])/`, catching `auth`, `auth:web`,
  `auth.basic`, `auth.session`) **throws**, citing ADR-038: a second mechanism on this surface is a
  finding, not a document change;
* a route on a surface with **no declared scheme** throws. `rt/` and `sdk/` have empty route files and
  their credentials are still TODOs, so `[]` for the first route added there would publish a public
  endpoint. Inert today, a forcing function later.

The guest set is pinned **by name** rather than by count, for plan D29's reason: *"expected 6 got 7"*
cannot tell a deliberate new guest route from `auth:sanctum` being dropped off `GET /me`. There is a
positive control on the authenticated side too, so an all-guest document cannot pass vacuously.

`securitySchemes` is untouched and `sanctumSession.name` is still `kb_session` — which matters, because
`apps/web/src/proxy.ts` and its spec pin that exact string as the regression guard for finding F-1, the
defect that made the whole admin console unreachable.

### H16 — `ErrorEnvelope`'s published description contradicted its own `required` list *(CLOSED)*

```bash
grep -n 'request_id' services/core-api/app/Console/Commands/DumpOpenApiCommand.php packages/contracts/src/envelope.ts
```

The component described itself as *"Identical in the non-streaming body and in the SSE `error` frame"*
while its `required` array included `request_id`, and `packages/contracts/src/envelope.ts` types that
field as **optional** precisely because SSE frames omit it (`src/sse/events.ts` reuses the same type).

**The description was the wrong half, and `required` was deliberately not touched.** The internal-contract
skill's client-facing frame is `{"error_class", "message", "retryable"}` with no `request_id`, so the frame
really does omit it, the TypeScript optionality is the **union of both surfaces**, and `required` describes
what this service actually sends over HTTP. Weakening `required` to match a frame this component does not
describe would have made the accurate half wrong. The description now scopes itself to the non-streaming
body and names the difference; the property description says the field is *absent entirely — not null —*
from the SSE frame; and a contract arm asserts the coupling as an invariant, with a failure message
stating both legal resolutions so the next reader does not have to re-derive which half to change.

## The shutdown-determinism decisions — ADR-044…046

Three decisions from 2026-08-14, all of them started by one report: *"despite `down` instructions and the
manual stop button, the containers keep restarting themselves."* Two of the three are not about restarting
at all — they were found while measuring the first, and each was a worse defect than the one reported.

**The reported symptom had two candidate mechanisms and only one of them survives measurement.** A crash
loop under `restart: unless-stopped` looks like the obvious culprit and is not: on Engine 29.6.2, two
containers that exit 255 immediately reached `RestartCount` 9 (`unless-stopped`) versus 0 (`no`) in 30 s,
and then `docker stop` settled **both** and they stayed settled. So the stop button is not defeated by a
loop on this engine. What survives is the daemon's own restart manager: `unless-stopped` exempts only
containers explicitly stopped **before** the daemon went down, and Docker Desktop's daemon stops and starts
constantly. Recording the refuted half matters, because "it was a crash loop" is the explanation a reader
will assume and it would send the next person to fix the wrong thing. → **ADR-044**.

**Then `make down` was reported as *"took too long and yet not down"*, which was a different defect.**
Not restart policy — signal handling. The core-api image inherits `STOPSIGNAL SIGQUIT` from `php:*-fpm*`,
PID 1 in four of its six services is `kb-serve` or `php artisan horizon`, neither handles SIGQUIT, and a
signal PID 1 has no handler for is **discarded**. Docker therefore waited out each service's full
`stop_grace_period` and SIGKILLed — 26 minutes for `laravel-worker-long`. The premise-checking that
mattered here went the other way twice: `schedule:work` **does** trap `INT TERM QUIT`, through Laravel's
`$this->trap()` helper, which a `pcntl_signal|SignalableCommandInterface` grep does not find — so
`laravel-scheduler` was already correct and is an exemption rather than an omission; and `MigrateCommand`
traps **nothing**, so its `# never SIGKILL a migration mid-DDL` comment was never a promise and is now
written as the deadline it is. → **ADR-045**.

**And the fix to the first decision exposed the third defect, because a working `down` made `up` fail.**
`down` frees the project's networks; the next `up` allocates the lowest free /16 from the daemon's built-in
pool; the pinned `edge` CIDR was **inside** that pool, so our own unpinned `data` network took it and
`edge` could not be created. Deterministic on every cycle, and it had been latent for as long as the pin
existed — nothing exercised a full `down` before. → **ADR-046**.

## Found while making the stack stop — 2026-08-14

Five findings. Two are Compose behaviours that make a documented shutdown incomplete; two are **green
suites that were hiding a red build**, in two different runtimes, by two different mechanisms; and the
last is ADR-036 catching this effort's own prose one day after it was written.

### I1 — `docker compose down` leaves profiled containers running, and `--remove-orphans` is not the fix

```bash
cd infrastructure/docker && docker compose config --profiles
```

Measured on Compose v5.3.1 with a two-service scratch project, one service behind `profiles: [test]`:

| Command | Result |
|---|---|
| `docker compose down` | removed the unprofiled service; the profiled container **kept running**, and the network then failed to remove with *"Resource is still in use"* |
| `docker compose down --remove-orphans` | left it running too |
| `docker compose --profile '*' down` | removed the container **and** the network |

**A profiled service is not an orphan.** Compose can see it in the file; it is merely outside the active
profile set, so `--remove-orphans` never looks at it — which is why `deploy` already carrying that flag
said nothing about this case. This is the whole explanation for `postgres-test` and `valkey-test` sitting
in `docker ps -a` for days after every shutdown, and for the "Resource is still in use" network error that
looks like a Docker bug. **CLOSED:** `make down` is `docker compose --profile '*' down` (needs Compose
≥ 2.24; `COMPOSE_PROFILES='*'` for an older client), with the measurement written above the target.

### I2 — the PHP suite exited **1** with 490 tests passing and zero failures *(CLOSED)*

```bash
grep -rn '^use [A-Z][A-Za-z]*;' services/core-api/tests --include=*.php | while IFS=: read -r f _ _; do
  [ "$(grep -c '^namespace ' "$f")" -eq 0 ] && echo "$f"; done | sort -u
```

`php artisan test` reported `Tests: 2 skipped, 490 passed (3167 assertions)` with **no FAILED line
anywhere** and exited 1 — a red build whose own report says it is green. Cause:
`tests/Security/SingleCredentialMechanismTest.php` declares **no namespace**, so its
`use RecursiveDirectoryIterator;` / `use RecursiveIteratorIterator;` were non-compound imports of a global
name *into* the global namespace, which PHP reports as `Warning: The use statement with non-compound name
'...' has no effect` — and `phpunit.xml` sets `failOnWarning="true"`.

**Why nothing surfaced it, which is the reusable half.** PHPUnit's exit calculator counts risky, warning
and deprecation **events**; the JUnit logger records none of them (`<testsuite tests="56" errors="0"
failures="0" skipped="0">`), and Pest's printer shows none either, even with `--display-warnings`.
`--log-events-text` is the only thing that names them, and it took bisecting suite → file to get there.
This is the same defect as the `use RuntimeException;` in a migration recorded during the auth effort, and
the discriminator is the same: `grep -c '^namespace '`, not the presence of a short import — the four files
under `tests/Support/` carry the identical shape legitimately because each declares a namespace.

**CLOSED** by deleting both lines; Pint then rewrote the call site to `\RecursiveIteratorIterator(new
\RecursiveDirectoryIterator(...))` and the file pins that shape in a comment, because "tidying" the
backslashes away is a Pint failure and re-adding the imports to justify the short form brings the warning
back. ~~**Still open, and small:** nothing prevents the third instance.~~ **The gate landed 2026-08-17**
as `repo-artifact-consistency` → *"A namespace-less PHP file may not carry a non-compound `use`"*, and it
catches the third instance in the one place no runtime can: PHP emits a *warning*, `failOnWarning="true"`
turns it into exit 1, and the Pest report still reads green — the original cost a `--log-events-text` run
to diagnose at all.

Two design points worth keeping, because both were the difference between a gate and a nuisance. **The
namespace condition is the rule, not an optimisation:** files under `tests/Support/` carry the identical
`use` shape *legitimately*, because each declares a namespace and importing a global name there genuinely
changes resolution — so the file is read for a `namespace` declaration first and skipped when it has one. A
gate that flagged those would have been deleted within a week. **The pattern is anchored at start-of-line**
so this file's own prose and the comment in `SingleCredentialMechanismTest.php` pinning the corrected
`\RecursiveIteratorIterator(...)` shape do not match themselves. Verified by planted probe: namespace-less
→ exit 1 naming `file:line`; namespaced twin → silent; corpus **76** namespace-less files under
`services/core-api`, asserted non-zero by the step so it fails rather than passes if the corpus empties.
**Known narrower than the warning:** `use Foo as Bar;` is not matched — the aliased form is a deliberate act
rather than a leftover import, and matching it needs a real parser.

### I3 — the ai-service PostgreSQL role default drifted from its own template *(CLOSED)*

```bash
grep -n 'KB_PG_USER' infrastructure/docker/env/ai-service.env.example services/ai-service/app/core/config.py
```

Two `pytest` failures, and the drift was in the auth-effort work rather than in anything the shutdown work
touched: the PostgreSQL role split set `KB_PG_USER=kb_app` in the template while
`Settings.pg_user` still defaulted to `knowledgebot`. `test_the_defaults_match_the_deployment_template`
exists for exactly this and said so (`assert 'kb_app' == 'knowledgebot'`); the stake it names is that a
default which drifts from the template still **starts** the service, pointed at credentials that may not
be the intended ones.

The second failure pointed the other way and is the more interesting one: a **stale literal** —
`postgresql://knowledgebot:…` — inside the one test whose stated purpose is that the five `KB_PG_*`
variables are not discarded. It now derives the user from the template values it already loads, which
proves that purpose directly, while host, port, database and the mounted password stay literal and the
value itself stays pinned by the sibling test. Only the **role** moved, not the database name.

**The general shape is worth keeping**, because it is the blind spot of the key-name drift checker added
during the auth effort: both files *had* the key, only the **value** differed, so a `comm`-style
template-versus-live comparison comes back empty. ~~A value-aware check … is still owed.~~ **It landed
2026-08-17** as `config-delivery` → *"Templates and code defaults agree on VALUES, not just key names"*,
in two arms.

**Arm (a) compares concrete against concrete, and that is what makes it a rule rather than an exemption
list.** It parses `Settings` in `app/core/config.py` with `ast` and compares each literal default to the
matching `KB_*` value in `ai-service.env.example`. A field defaulting to `None` is **skipped by the rule,
not by a name list** — that is the secret-pointer pattern working as designed (`KB_PG_PASSWORD_PATH` and
three siblings default to `None` so an unset pointer fails loudly rather than reading a stale file). Only
where *both* sides commit to a concrete value can they disagree. **One exemption exists and is named with
its reason:** `KB_ENVIRONMENT`, where the code default is the safe local value and the template is the
production render. Naming it rather than pattern-matching it away is the ratchet — a *second* key that
starts disagreeing turns the build red and must be justified in a PR.

**Arm (b)** resolves every `/run/secrets/x` pointer in every env template against compose's top-level
`secrets:`. It deliberately checks **declaration, not per-service mounting**: compose's `secrets:` is
narrow per service while `env_file:` is shared by all six Laravel services, so those two disagree *by
construction* and a mount-level assertion would flag that design as a defect.

**Why the two gates already in this job both missed I3, which is the point of adding a third.** The
name-set checker (check 3) saw the key on both sides. The role-split gate asserts the **template** says
`KB_PG_USER=kb_app` — which was true; the wrong value was the **code default**, i.e. exactly what applies
when the variable is unset: every `pytest` run and every `docker run` without an `env_file`. Verified by
re-introducing the original defect (`pg_user = "knowledgebot"`), which the new gate names explicitly, and
by a bogus pointer for arm (b); both restored and checksum-confirmed. Live corpora **17** concrete value
pairs and **12** pointers against **16** declared secrets, each arm failing rather than passing if its
corpus empties.

### I4 — an exact request count inside `vi.waitFor` is a one-way ratchet, in two specs *(both CLOSED; the stray read's origin unproven)*

```bash
# every exact-count assertion still sitting inside a retrying waiter — the ratchet detector.
# The third filter drops COMMENT lines: the two specs below now explain the ratchet in prose, and
# a detector its own documentation trips is a detector nobody runs twice (the D73 lesson, again).
# Verified against a planted probe, so the pattern still catches a real one: currently no hits.
grep -rn -A4 'vi\.waitFor' apps/web/tests --include=*.tsx \
  | grep -E 'toBe\([0-9]+\)' | grep -vE ':[0-9]+[:-][[:space:]]*//'
```

`apps/web/tests/components/members-screen.test.tsx` asserted `expect(invitationReads).toBe(2)` inside
`vi.waitFor` to prove the invitation list is **re-read** after an invite rather than patched optimistically.
`waitFor` retries until the assertion holds, so a count that **overshoots can never come back**: one stray
read burns the entire timeout and reports `expected 3 to be 2`. It failed once in ~28 full-suite runs and
passed 3/3 in isolation.

**Six mechanisms were tested and refuted before anything was changed**, which is why the repair is at the
assertion rather than at a guessed cause: window-focus refetch (`refetchOnWindowFocus: false`), a remount
refetch (`staleTime: 30_000` covers it), a cache shared between tests (`Providers` builds a client per
mount), a cold-Vite page reload (a wiped `node_modules/.vite` run produced **zero** reload warnings, so
`optimizeDeps.include` is complete), a late response from the previous test (**MSW matches handlers at
request interception, not at response time**, so a response arriving after the swap cannot reach the next
test's handler — verified by delaying that read 900 ms), and a third read that normally lands after the
assertion (there is none: the event list 1500 ms later is `GET members │ GET invitations │ POST │ GET
invitations` and nothing more).

**The replacement is strictly stronger than the count.** The list handler serves a second pending row on
every post-invite read — gated on a flag the POST sets, not on a read index — and the test asserts that
row reaches the screen. The client cannot invent it: the POST reply carries a *different* address, so an
optimistic update or a `setQueryData` patch would render that one and fail. The count said "a request was
made"; this says "what is on screen came from the server's answer to it". A monotonic
`toBeGreaterThan(readsBefore)` keeps the original intent in a form no extra read can invalidate. Three
teeth-checks: deleting the mutation's `onSettled` invalidation fails it by name; serving the row from the
start fails a new negative control; and injecting the exact failure trigger (an extra read, 2 → 3) now
**passes**. The stray read's origin remains **unproven** — recorded in `docs/23` rather than guessed at in
a comment.

**The detector above then found a second instance, and it is now fixed too.**
`apps/web/tests/components/org-switcher.test.tsx` held `expect(meCalls).toBe(1)` before the click and
`expect(meCalls).toBe(2)` after it, both inside `vi.waitFor` — the same shape, and the second guarded the
same property (an error path invalidates the session query, so the membership list is re-read). It had
never failed, which is exactly what the members-screen assertion did for weeks first.

**The replacement asserts what the invalidate is *for*, in the words of the hook's own comment** —
*"otherwise the user keeps picking an option that keeps failing"*. The `/me` handler drops the picked
membership to `suspended` once the 403 has been served (flag set by the POST, not by a read index), so the
refetched document no longer offers it: the spec reopens the control and asserts that option is **gone**,
with the count kept only as a monotonic `toBeGreaterThan`. The status is `suspended` rather than absent
deliberately — two active memberships must remain, because `OrgSwitcher` returns `null` below two and would
take the alert down with it, quietly converting this into a test of something else. Nothing in the 403
envelope names an organization, so no client-side state can produce this result; only the server's fresh
list can.

The pre-click `toBe(1)` became a result assertion as well — the trigger renders *Acme Research*, which is
the session having arrived, stated as what the user sees rather than as a request count.

**Three teeth-checks, matching the members-screen fix:** deleting the `invalidateQueries` call in
`use-switch-organization.ts` fails it (`expected [ <div role="option" …> ] to have a length of +0 but got
1`); serving the membership as `active` on the refetch — i.e. making the assertion independent of the
server's fresh list — fails it identically, which is what proves reopening the popover is not what
satisfies it; and injecting the **exact** failure trigger of the old ratchet, one extra `/me` read before
the refetch, now **passes**. The fixture rows are typed `SessionMembership` from `@kb/contracts` rather
than `as const`, so a role or status this test invents is a compile error instead of a green assertion
about a shape the server cannot send.

### I5 — the edge CIDR moved and four documents kept naming it, one of them dangerously *(CLOSED)*

```bash
# (a) the one authoritative default…
sed -nE 's/^x-edge-subnet.*KB_EDGE_SUBNET:-([^}"]+)\}.*/\1/p' infrastructure/docker/compose.yaml
# (b) …and every other CIDR claim in the tree, to be read against it
git grep -nE '\b(10|172|192)\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+' -- . ':!docs/'
```

ADR-046 changed one default. Four files went on naming the old value, and they divide neatly by how much
it matters:

* **`infrastructure/docker/.env.example` — the dangerous one.** It stated the anchor "carries the default
  (172.24.0.0/16)" and its commented example was `# KB_EDGE_SUBNET=172.24.0.0/16`. Uncommenting that would
  have re-pinned the exact in-pool range the move exists to escape, *while looking like the documented
  fix for the collision it causes*. The prose now points at `compose.yaml` instead of restating the CIDR,
  and the example is deliberately a **different** block with the pool rule beside it. The file's own
  warning had predicted this ("a copy that goes stale on every existing deployment the day the default
  changes") about the wrong copy — itself.
* **`services/core-api/tests/Security/TrustedProxyTest.php`** — a docblock claiming its constant *is* the
  anchor's value. The test passes either way (any bounded CIDR exercises the inside/outside decision), so
  this was a false comment rather than a failure; it now states that it is deliberately uncoupled and
  names `preflight.sh` §3b as what actually asserts the deployed range.
* **`scripts/ops/preflight.sh`** — an "Expected the form:" hint quoting the old default, i.e. a message
  that would send a reader to fix the half that is already right. Now `<cidr>`.
* **`compose.yaml` and `infrastructure/docker/README.md`** — left alone deliberately: both are **dated
  measurements of the unpinned behaviour**, which is still exactly what was observed.

**And a fifth instance of the same class, in this effort's own new prose:** the `SHUTDOWN DETERMINISM`
block asserted that `compose.yaml` gives "27 services" `restart: unless-stopped`. Measured: **26** — 27 is
the number of alias uses in the overlay (26 overrides plus mailpit, which exists only there). One day old,
already wrong, in the paragraph that introduces a gate whose job is to stop exactly this. Replaced by the
two measuring commands. ADR-036 keeps earning its place.

### I6 — the only tests that assert a real parse had never run anywhere *(CLOSED 2026-08-17, then **REOPENED** the same day when CI was deleted — see § Removing CI/CD)*

Found by building `knowledgebot/ai-service:dev` on 2026-08-17 in order to clear six skips that had been
reported, correctly, as skips in every run of this effort — and then asking where they *do* run. The answer
was nowhere.

```bash
# (a) the guard, and the constant it reads — hardcoded, not configurable
grep -n 'ARTIFACTS_PATH' services/ai-service/app/ingestion/parsing/converter.py | head -2
# (b) every place a workflow could supply /models to a test run
grep -nE 'docker run.*ai-service|models:/models|ai-service:dev' .github/workflows/*.yml
# (c) and why the image cannot run its own suite
grep -nE '^tests/' services/ai-service/.dockerignore
```

`tests/integration/test_parsing_live_document.py` is the only file in the repo that asserts what the Docling
layout model and RapidOCR actually produced: that the **OCR quality signal is fed** (finding #23, the signal
that existed and that nothing read), that the **coverage arm does not misfire** (the G9 ruling, where 74 real
page-parses showed clean and degraded populations overlapping), and that a **parse is reproducible** — which
publication depends on, because it verifies an expected chunk total before activating a version. All six
tests sit behind `_NO_WEIGHTS`, which tests `ARTIFACTS_PATH / "docling-project--docling-layout-heron"`, and
`ARTIFACTS_PATH` is hardcoded `Path("/models")`.

**Three facts compose into the defect, and each is individually correct.** The weights are baked into the
runtime image and nowhere else. `ci.yml`'s `ai-service` job runs `uv run --frozen pytest -m integration` **on
the runner**, where `/models` does not exist. And `tests/` is in `services/ai-service/.dockerignore`, so the
image that *has* the weights does not carry the suite. Nothing was misconfigured; there was simply no step
anywhere that put the suite and the weights in the same filesystem. Grep (b) returns only two unrelated
`python:3.13-slim` helpers in `gates.yml`.

**Why it read as fine for so long.** The skip *reason* is accurate and actively misleading: "they are baked
into `knowledgebot/ai-service:dev` and mounted from the `models` volume" describes how weights reach a
**production worker**, which is true, and which a reader scanning a green log parses as "covered elsewhere".
The `pytest -m integration` step reported **74 passed, 6 skipped** and exited 0. The file's own docstring says
a green tick from a skipped parser "is the failure this file exists to prevent someone shipping" — it was in
exactly that state itself, which is the sharpest available argument for `-rs` being on by default.

**CLOSED** — and then reopened. The fix was `services/ai-service/tests/harness/Dockerfile.pytest` plus a new `images`-job step, *"Live
document parse (real weights, in the image built above)"*. First execution, 2026-08-17: **6 passed in 65 s**,
a real Docling parse of `samples/corpus/documents/scanned/scanned-po-88214.pdf` and the born-digital
handbook.

**The `images`-job step was deleted the same day**, with the rest of `.github/` — see § *Removing
CI/CD*. What survives is the harness and this record, so the tests can be run and are documented;
what does not survive is anything that runs them without being asked. Read the rest of this finding as
the design of a harness rather than the closure of a gap.

Four decisions in it are worth keeping, because each was a fork where the obvious choice was wrong:

* **The harness is a file under `tests/`, not a stage in the shipped Dockerfile.** Precedent and reason both
  come from `apps/widget`, which puts its stream probe in `tests/harness/probe.vite.config.ts` rather than a
  `--mode` in the shipped `vite.config.ts` so a test-only entry "can never be built into a customer
  artifact". A `runtime-test` stage carrying pytest would live one `--target` typo from shipping. The shipped
  Dockerfile stays `uv sync --frozen --no-dev`.
* **Only the two pinned runners are added** (`pytest==9.1.1`, `pytest-asyncio==1.4.0`, copied from the dev
  group so the harness cannot drift to a different pytest). Syncing the dev group wholesale would defeat the
  point — the run must exercise the *shipped* dependency set, and would drag `ruff` and the coverage plugins
  into an image that asserts its own containment at build time. `pytest-asyncio` is required even though the
  file is synchronous: `asyncio_mode = "auto"` under `--strict-config` makes an unclaimed ini key an error, so
  its absence kills the run during *config* with a message naming the ini key rather than the plugin.
* **The repo root is mounted, not `services/ai-service`.** The corpus resolves as
  `SERVICE_ROOT.parents[1] / "samples"`. Mount the service directory at `/app` and `SERVICE_ROOT` becomes
  `/app`, whose `.parents` holds exactly one entry, so `parents[1]` raises `IndexError` **during collection**
  and all six tests error for a reason that mentions nothing about parsing.
* **`load: true` and `tags:` were added to the runtime build, as a pair.** Without them the built image stays
  in the builder cache where the daemon cannot see it, and `docker run` either fails on a missing image or
  pulls a public one by that name. The `runtime-crawl` and `runtime-evaluation` targets stay build-only,
  because nothing executes them. The cost is real and is the price of the coverage: ~3.5 GB exported to the
  daemon.

**The step cannot pass vacuously, and that is the part under test.** A skip is an `::error::` and exits 1,
because a skip here *is* this finding regressing, and `2 passed, 4 skipped` is indistinguishable from success
in the summary line. Verified by hiding the scanned fixture: the run reported **2 passed, 4 skipped** and the
step **exited 1** naming I6; fixture restored and checksum-confirmed. A second arm requires the count to be
exactly `6 passed`, so a test added without moving the floor — or silently dropped by collection — fails too.

**A side result worth more than the six ticks.** The two upstream advisories that `pyproject.toml` exempts by
message and module both fired during the real parse — `rapid_ocr_model.py:437` reading its own
pydantic-deprecated `rec_font_path`, and `docling_core`'s "ListItem parent must be a list group". Both landed
in the warnings summary and **neither failed the suite**, which is precisely what the `-W default:` narrowing
claims and what `filterwarnings` entries could not have achieved (ini filters are applied *before*
command-line `-W`, so they would lose to `-W error`). That narrowing was documented as measured but had never
been exercised by a real parse in this repo. It has now been, in both directions.

## Removing CI/CD — 2026-08-17

`.github/` was deleted, on Ankur's instruction, with the reference sweep across the tree done in the
same pass. It had held two files:

* `gates.yml` — seven install-free jobs, 33 steps: `enforcement-greps`, `observability-rules`,
  `compose-ci-tag-drift`, `compose-invariants`, `repo-artifact-consistency`, `boundary-greps`,
  `config-delivery`.
* `ci.yml` — seven jobs that installed every lockfile and ran Pest, pytest, Vitest, Playwright,
  ESLint, `next build`, `size-limit`, six image builds, and the dependency/secret scans.

**Nothing replaced them.** `CONTRIBUTING.md` § *The invariants that used to be enforced, and now are
not* is the review checklist that stands in their place, and `CLAUDE.md`'s header states the rule for
the repo. This section records what the removal actually cost, because the interesting part is not
the deletion — it is that **every claim of the form "CI asserts X" in this repository became false in
one commit**, and the tree carried roughly 85 of them across 40 code files.

### The reference sweep, and why it was not a find-and-replace

256 reference lines across 78 files. They divided into three kinds, and only the first is mechanical:

1. **Dead pointers** — `.claude/agents/{platform-devops,test}-engineer.md` listed
   `.claude/skills/github-actions-pipeline/SKILL.md` as *required reading*, and that skill was deleted
   too. An agent's must-read list pointing at a missing file is the worst failure in this set, so it
   went first. Eight more skills carried `(`github-actions-pipeline`)` as an inline cross-reference.
2. **Claims that inverted.** A comment saying "nothing enforces this — and gates.yml has no step for
   it either" is *more* true now, and needed only its second clause dropped. A comment saying "CI
   greps for this" became a lie and needed rewriting.
3. **Claims whose premise flipped back.** These are the ones worth reading, below.

**Historical records were deliberately NOT rewritten.** This document, `docs/19`'s ADRs, `docs/23` and
`CHANGELOG.md` describe what was true when written. Erasing the gates from them would make them less
accurate, not more, so present-tense claims were moved to past tense and the ADRs got dated
amendments — `docs/19` ADR-044/045 now say their `compose-invariants` checks are gone. The 74 CI
mentions still in this file are history and are meant to stay.

### Three things that regressed, not merely stopped being enforced

* **`docs/22` § I6 is REOPENED.** It was closed hours earlier by a CI step that ran the six
  live-parse tests inside `knowledgebot/ai-service:dev`, where the pinned Docling/RapidOCR weights
  live at `/models`. That step is gone. The harness survives —
  `services/ai-service/tests/harness/Dockerfile.pytest`, whose header now says so — but running it is
  a manual act, and the original finding was precisely that *nobody ran these anywhere*. **The
  difference from before is a documented harness and a named owner; the exposure is identical.**
* **The committed OpenAPI document is unchecked again.** `services/core-api/tests/Contract/`
  `OpenApiDocumentTest.php` carried a comment retiring an older test "because `ci.yml` now runs
  `kb:dump-openapi --check` against the real artifact". That premise is false again, and the file now
  names the gap: the suite proves the *generator* is deterministic and that `--check` *can* fail;
  nothing proves the *committed* artifact is current. Restoring the default-path variant of that test
  is the cheapest fix and was deliberately left as a decision rather than a drive-by edit.
* **`KB_MIGRATION_PIN_79` is gone, and finding #79 is still a live violation.** The pin self-expired
  by three independent paths, so whoever landed the `chunks` / `document_elements` migrations would
  have got a red build until they unpinned the name. Now nothing will remind them. The 2026-08-12
  ruling stands; only its enforcement left.

  **Amended 2026-08-20 — this is the one prediction in this section that came true, and it came
  true three days later.** Phase C1 landed the `knowledge_sources → source_items → source_versions`
  cascade together with `chunks` and `document_elements`, which is exactly the event the pin was
  built to catch, and **nothing objected**: no red build, no reminder, the name unpinned by hand
  because a person read this bullet. The finding is **closed** — see § *The rulings of 2026-08-12*,
  **G2** and its closing note for the measuring command, which imports `ALLOWED_TABLES` rather than
  restating it. The paragraph above stays in the present tense it was written in, because it
  describes what was true on 2026-08-17 and because the interval it left open — a self-expiring
  gate deleted three days before the change it expired on — is the whole lesson. It is also the
  reason the close is recorded in three places rather than one.

### Two smaller consequences worth naming

* **`scripts/security/rule_count_check.sh` is orphaned.** It was written *because* a gate needed a
  non-vacuous rule count, and it was the only script in `scripts/` with a live caller.
  `scripts/security/license_gate.py` never had one. Both still work.
* **`.gitleaks.toml` outlived its reason and is still worth keeping.** It was written hours before the
  removal to unblock `secret-scan`, whose `dir .` arm exited 1 on eight `generic-api-key` findings —
  all synthetic password fixtures in auth tests. With no CI the config's job changed rather than
  ended: without it, anyone running gitleaks by hand gets eight false positives and learns to ignore
  the tool. Its four positive controls are recorded in the file and still pass.

### What the removal did not touch

Every suite still passes, and the commands are unchanged: Pest **490**, pytest **1470** offline +
**74** integration (+**6** live-parse via the harness), Vitest **436** web / **160** contracts /
**31** widget, Playwright **22** widget E2E, Jest **69** mobile. The suites were never the fragile
part — the gates were, because they asserted the things a passing test cannot see.

## The provider-lifecycle decisions — ADR-047…052

Six decisions from 2026-08-19, from the effort that finished the provider-connection resource
(`index`/`show`/`update`/`destroy` beside the existing `store`), added credential rotation and a
per-connection model catalogue, and built `/settings/providers`, `/settings/providers/[connectionId]`
and `/settings/embedding`. The six are the ones a future change can *violate*; the rest of the effort
is implementation. **The numbering starts at 047 because ADR-044…046 were taken by the
shutdown-determinism effort of 2026-08-14** — the brief for this work asked for "ADR-044" and that
number is five days older than the request.

**Two of the six overturned the instruction that asked for them, and that is the half worth reading.**

* **The brief said "bump `key_version` on rotation."** It cannot: `key_version` is the **KEK's**
  version, written by `CredentialVault::seal()` from config, and `open()` will have to select a KEK by
  that number the moment a second one exists. Bumping it on a credential rotation writes a version
  naming a KEK that never wrapped the row — unwrappable ciphertext, silent at the moment of damage,
  discovered at the next KEK rotation for every credential rotated since. `kb-security-baseline` §18.2
  already said so, and its worked example passes `keyVersion: $dek->kekVersion()` under the comment
  *"rotation = rewrap, no migration"*. **The skill was right and the newer instruction was wrong**,
  which is the direction a tie-break does not naturally go, so a separate `credential_version` column
  landed instead. → **ADR-048**.
* **The same skill's §18.4 worked example refuses the rotation we permit.** Its check 5 reads
  `abort_unless($credential->status === CredentialStatus::Active, 409)`. Applied here that makes
  delete-and-recreate the only escape from a bad key, and the delete costs the connection id, its
  `provider_models` rows and its embedding designation — an ADR-031 re-index as the price of a typo.
  We check the *organization's* status and not the connection's, and the rotation returns a `revoked`
  or `invalid` connection to `active`. → **ADR-047**, and the divergence is recorded as **J7** rather
  than resolved, because this document does not edit skills.

The other four each came from a gap rather than a correction. **ADR-049** — the delete is a guarded
hard delete, and the `ON DELETE RESTRICT` that ADR-031 put on the designation is surfaced as an
actionable 409 instead of being worked around, because undesignating returns the organization to
resolve-by-rule and may move the vector space under an indexed corpus. **ADR-050** — `docs/11` §16.2
and `docs/02` §8.4 both require pricing metadata and no migration had created a column; the decision
that generalizes beyond this table is that the column's ceiling sits **above** the FormRequest's bound,
so the boundary value is a 422 with a field to key on rather than SQLSTATE `22003` rendered as a 500.
**ADR-051** — the capability vocabulary stays out of `packages/contracts`, because a closed copy there
would assert a guarantee neither plane makes; the control plane constrains **spelling, not
membership**. **ADR-052** — the internal relay was reading two fields of a four-field envelope, and one
of the two it dropped was the data plane's origin verdict.

**ADR-052 is ADR-029 re-opened one hop later, on the exact envelope ADR-029 was about**, and it is
recorded that way deliberately. O1 established that both planes render an unhandled internal exception
identically — 500, `internal_dependency`, `retryable: false`. The data plane did.
`InternalAiClient::relay()` then read `error_class` and `message` only, `KbException::relayed()`
defaulted `origin` to `DOWNSTREAM`, `bootstrap/app.php` recomputed `retryable` from class-plus-origin, and *"our bug, do not
retry"* reached the browser as `retryable: true`, where `apps/web`'s query client ran a full backoff
ladder against a guaranteed failure. The generalizable form: **a decision about how two planes agree on
a field survives only where every hop that copies the field preserves it**, and a relay that reads a
subset of an envelope is such a hop. The second dropped field, the per-field `errors` map, broke a
different invariant — `apps/web` discriminates the ADR-031 resolver refusal on `validation` **with no
map**, which is sound only while every other `validation` keeps one.

**What this effort did *not* touch:** finding **#79** stays pinned exactly as the 2026-08-12 ruling
left it (§ *The rulings of 2026-08-12*, **G2** — cited as G1 here until 2026-08-20; G1 is the
ADR-036 ruling). Nothing here goes near `chunks`, `document_elements` or
the `source_versions → source_items → knowledge_sources` cascade, and `ALLOWED_TABLES` is unchanged.

## Found while building the provider surface — 2026-08-19

Seven findings, **all now closed**. Three were defects that were fixed, two of them pre-existing and
reachable long before that effort; one was a race closed in one direction only; and three were open on
the day they were written and were closed later the same day by a follow-up pass — J1 and J2 as
**ADR-054** and **ADR-053**, and J7 by a ruling that edited the skill rather than the code.

**Two of the three closures went a different way from the shape the finding implied, and that is the
half worth reading.** J1's "owed work" was closed by *deciding not to do it* and writing down the cost;
J2 was closed by adding a field to an envelope whose smallness was itself a design property. In both
cases the alternative was the more obviously "complete" fix and was rejected for what it would have made
permanent — a synchronous dependency on a metadata edit, and a status a client could branch on.

### J1 — `assert_row_coherent` never runs on the catalogue write path, and the docblocks that said it did were in both services

**Closed by ADR-054 on 2026-08-19, by accepting the deferral rather than removing it.** Candidate (b)
below was taken. The finding text is kept in full because the *reasoning* is what the ADR points at.

Three docblocks in `services/core-api` stated that the data plane refuses an incoherent capability row
at save time. It does not. The measuring commands:

```bash
grep -rn 'assert_row_coherent\|assert_org_can_embed' services/ai-service/app/api \
  services/ai-service/app/ingestion services/ai-service/app/retrieval
grep -rn 'assert_row_coherent' services/ai-service/app/providers/embedding_selection.py
```

The first returns nothing — **no HTTP path calls either function.** The second shows the only reachable
caller: `ineligibility()` in `app/providers/embedding_selection.py`, which invokes
`assert_row_coherent` inside a `try` and converts the `KbError` into an
`EmbeddingIneligibility.ROW_INCOHERENT` rejection. That is correct where it stands — a capability
question on the request path must become a value rather than an exception — but it means the coherence
check only ever runs over the connections `embedding_readiness` walks, i.e. **embedding candidates**.

So `ProviderModelService` never crosses the seam at all, and a row registering `openrouter` with
`["rerank"]` saves cleanly with a 200, appears in **no** `rejected[]` on the readiness screen, and
simply never reranks. `capabilities.can_rerank` is the AND of three questions and OpenRouter fails one
of them permanently (§ G5) — but nothing tells the operator, at any point, ever. ADR-051's trade-off
says the refusal "arrives later, by name, with the matrix cell quoted"; for the embedding family that
is true, and for every other family it is not.

**The docblock half was larger than this finding first reported.** It said three docblocks in
`services/core-api` had been corrected; a full sweep on 2026-08-19 found the same claim alive in the
**data plane**, including in `assert_row_coherent`'s own docstring, which stated as fact that it is
"called when a provider connection or a bot's model selection is **saved**". `assert_org_can_embed`
carried a matching claim and has **no caller at all**. All of them now say what the code does. Measure
rather than trust a list (ADR-036):

```bash
grep -rn 'assert_row_coherent(' services/ai-service/app     # the definition and ONE call site
grep -rn 'assert_org_can_embed' services/ai-service/app     # the definition and its __all__ entry
```

**Two candidate closures were offered; (b) was taken and is ADR-054:**

* **(a)** call a coherence endpoint from the catalogue write path. It is the honest fix and it makes
  the save path synchronous on the data plane, which is a new dependency for an operation that has none
  today — and one that fails the save when `ai-api` is briefly down, for a metadata edit. **Rejected**
  for that reason: a rename or a price correction should not fail because the data plane is restarting.
* **(b)** accept the deferral permanently and say so in the operator-facing copy: a capability flag is a
  *claim*, honoured only where the matrix agrees, and the console shows which claims are honoured for
  the families it can ask about. Cheaper, and it leaves the rerank family with no feedback at all.
  **Taken.** `model-form.tsx` now carries a standing sentence on the rerank task saying that the
  provider's own ability to rerank is checked neither there nor on save.

A third option was considered and not offered as a closure: mirror the provider × task matrix into
`apps/web` so the console could answer the third question itself. That is a **fourth copy** of a table
ADR-051 deliberately keeps out of the client, and it would go stale in the silent direction — claiming
a vendor cannot do something it now can.

**What is still true after the closure**, and is the cost ADR-054 accepts: an operator can save
`openrouter` + `["rerank"]`, get a 200, appear in no `rejected[]`, and never rerank. The only thing
between them and that is copy.

Owner: closed by `control-plane-engineer` and `admin-web-engineer`; the matrix half was never the gap.

### J2 — a deliberate 4xx is indistinguishable from a defect on the client, and the workaround was a message sentinel

**Closed by ADR-053 on 2026-08-19.** Candidate (b) was taken: the envelope carries an `actionable`
boolean and the client stops comparing strings. The finding is kept in full because the *shape* of the
workaround — a deny-by-exclusion filter whose premise is a property of the whole tree — is the part
worth recognising again elsewhere.

`KbError` carries no HTTP status. The render closure maps an unclassified 4xx our own code
raised onto `internal_dependency` with `retryable: false` — and an unhandled exception renders as
`internal_dependency` with `retryable: false` too. **A 409 and a 500 therefore arrive at the browser as
the same `(error_class, retryable)` pair**, so ADR-049's actionable *"clear the designation first"*
message is indistinguishable, structurally, from *"the service could not complete this request."*

The console's workaround is a **sentinel**: `bootstrap/app.php` renders every status ≥ 500 with one
fixed string and `services/ai-service/app/main.py::_handle_unexpected` emits it byte for byte (ADR-029),
so a non-retryable `internal_dependency` carrying *anything else* is a deliberate 4xx. One call site
discriminates differently, on `errors === null`, which is the ADR-031 refusal shape rather than this
one. Measure the blast radius rather than counting it here:

```bash
grep -rn 'deleteConflictMessage\|SERVICE_FAILURE_MESSAGE' apps/web/src
```

Contract review verified the sentinel is safe **today** by enumerating every `abort*` in the tree and
confirming none passes a custom message on a status below 500 that this screen can reach. **It is a
deny-by-exclusion filter**, and its premise is a property of the whole tree rather than of the endpoint
it guards: the first `abort(400, $detail)` anywhere reachable from these screens breaks it, silently,
by making a new deliberate 4xx look like a defect — or worse, by making a defect whose message happens
to differ look actionable. **Two candidate fixes were offered; (b) was taken and is ADR-053:**

* **(a)** carry the HTTP status on the error envelope. Complete, and it publishes a field every client
  can then branch on — including branching on it *instead of* `error_class`, which is the coupling the
  taxonomy exists to remove. **Rejected** for exactly that: it is a door that cannot be closed again.
* **(b)** mark the deliberate-4xx case explicitly — an envelope flag or a dedicated taxonomy reading —
  so a client recognizes it without inferring anything from a string. Narrower, and it does not hand
  clients a status to branch on. **Taken**, as a fifth envelope field, `actionable`.

**The shape of what the field replaced is the transferable part.** The sentinel was not wrong; it was
*correct for a reason that lived somewhere else*. Its premise — "no `abort` below 500 anywhere in the
tree passes a custom message on a path these screens reach" — was verified once, by enumeration, and
then had to stay true forever, in files nobody editing them would connect to a delete button in the
admin console. A guard whose correctness is a property of the whole repository rather than of the value
in hand is a guard that fails silently and at a distance.

**Two things the closure turned up that the finding had not:**

* the suspended-organization `abort_unless(..., 409)` had **already gained a message**
  (`OrganizationStatus::SUSPENDED_REFUSAL`) since this finding was written, so a unit test asserting
  "this 409 carries no sentence for anyone to render" was recording a state that no longer existed and
  would have kept doing so;
* two component specs proving that a 500's message never reaches the screen used the 5xx constant *as
  the fixture message* — so the assertion could not fail whatever the client did. They now carry an
  operator-shaped message with a hostname in it, which the flag alone keeps off the screen.

Owner: closed by `control-plane-engineer`; the data plane's half of the envelope moved with it, because
a consumer cannot tell which plane produced one.

### J3 — two `BinaryCast` defects, both pre-existing, both reached by this work, both fixed

`App\Support\Crypto\BinaryCast` is the `bytea` cast every sealed credential passes through. Two
independent bugs, neither introduced here and both exposed the moment a code path read a ciphertext
column **twice**:

* **(a) the stream was drained by the first read.** `stream_get_contents($value)` reads from the
  current position, and Eloquent does not cache a class cast whose `get()` returns a non-object
  (`getClassCastableAttributeValue()` unsets the cache unless `is_object($value)`), so every access
  re-ran the cast against the same handle. First read: the bytes. Second and every later read: `''`.
  `CredentialVault::open()` reports that as **"ciphertext is truncated"** — a message that reads like
  KEK corruption and sends the reader to the key management, which is the wrong place entirely.
  **The failure mode here was misdiagnosis, not breakage**, and that is why it is written down. Fixed
  by reading from offset 0 explicitly, which makes repeated access idempotent.
* **(b) the resource branch fell through into the string branch.** It assigned back into `$value` and
  dropped into the `\x`-prefix decode, so **decoded** bytes beginning with the two ASCII bytes `\` and
  `x` reached `hex2bin()`. A sealed credential begins with a random 12-byte IV, so roughly one in
  65,536 starts that way by chance; the "and the remainder must look like hex" guard narrowed the
  window to `(16/256)^n` and left it open — a probability argument standing in for a structural one.
  The outcome was either a throw or, worse, a **silent decode to different, shorter bytes**. Fixed by
  branching on the **source**: a resource is decoded bytes and returns immediately; a string came from
  `set()` and is `\x` + hex by construction. Neither branch has to guess.

A third, smaller one was fixed in the same pass and is recorded because the shape recurs: `set()`
returned `[$key => null]` for a non-string, which on a **nullable** `bytea` column is silent data loss —
`save()` succeeds and the row reads back as "no credential stored". It throws now.

### J4 — the designate/delete race was closed in one direction only

**Closed.** `EloquentProviderModelRepository::delete()` locks the model row, then `organizations`, then
re-reads the designation, so **designate-then-delete** was always caught. The mirror image was not:
`designateEmbeddingConnection()` locked `organizations` and performed **no existence check on the
pair**, so **delete-then-designate** left the organization naming a catalogue row that no longer
existed — both requests 200, nothing raised, and the fault surfacing at the next upload as a resolution
error nobody can connect to an action taken days earlier.

The reason nothing else caught it is the part worth keeping: **`organizations.embedding_model` is a bare
`text` column with no foreign key**, unlike `embedding_connection_id`, which has a composite FK with
`ON DELETE RESTRICT`. Half the pair is protected by the database and half by the application, and only
the protected half had a test that could fail. The pair is now re-verified **inside** the designating
transaction, under the `organizations` row lock the delete path also takes — a plain `SELECT` and not a
second `lockForUpdate()`, because locking the model row here would order the two locks opposite to the
delete path (model → organizations there, organizations → model here) and turn a refusal into an ABBA
deadlock: `40P01` for both requests instead of one actionable 422 for one of them.

The pre-flight resolution in `EmbeddingDesignationService` cannot be the authority for this, and the
reason generalizes: **it makes an HTTP call, and an HTTP call must never sit between BEGIN and COMMIT**
— it pins `xmin` and stops autovacuum reclaiming dead tuples database-wide — so it runs outside both
transactions and loses the same race.

### J5 — the audit fingerprint backstop erased the field that identified the row

**Closed.** `AuditLogger`'s `details` writer redacts anything matching the credential heuristics and
records a keyed fingerprint in its place. Tenant-controlled **free text** can trip that heuristic —
`label` is exactly the kind of field that can — and the old behaviour dropped the key entirely, so a
`provider.connection.deleted` row could lose the one field that says *which connection*. An audit row
that cannot identify its subject is worse than a redacted one.

The row now degrades to `<key>_redacted` beside the fingerprint, carrying whatever survived around the
match — **unless the whole value was the match**, in which case the marker alone would say exactly what
the fingerprint's presence already says, and the key is omitted rather than written twice. The
fingerprint failure path is also deliberate: `fingerprint()` refuses to write an **unkeyed** digest when
`app.key` is empty, because an unkeyed digest is reversible by lookup and only *looks* redacted, and
`sanitize()` runs **outside** `record()`'s try/catch — so an escaping throw there would ignore the
operation's `ON_FAILURE_LOG` policy (ADR-041) and turn a failed login into a 500.

### J6 — `provider-connections.store` was unaudited, and that was a §18 gap rather than a deferred nicety

**Closed.** The endpoint that stores an organization's first provider credential wrote no audit row at
all. `docs/13` §18.11 lists *"provider credential changes"* among the events that must be audited, and
creating the credential is the one that trail most obviously exists for. The surface now writes
`provider.connection.{created,updated,deleted,credential_rotated}` and
`provider.model.{created,updated,deleted}`, all `ON_FAILURE_ABORT` and all written **inside** the
repository transaction that makes the change — `AuditLogger` opens no transaction of its own and `DB`
is arch-pinned to `App\Repositories\Eloquent`, so each mutating repository method takes the audit call
as a required closure.

One row is deliberately not `ABORT`: `provider.connection.credential_rotation_failed` is
`OUTCOME_FAILURE` under the lenient policy, because there is no state change left to roll back — the
point of the row is that nothing happened — and aborting on a failed audit write would convert a
recorded rejection into an unrecorded 500. Read `AuditLogger::OPERATIONS` for the membership of each
policy; do not restate it or its cardinality (ADR-036, ADR-041, and § H14 for what happens when it is
restated beside the constant).

### J7 — `kb-security-baseline` §18.4's worked example refuses the rotation ADR-047 permits

**Ruled on 2026-08-19: the skill names the exception; the code does not move.** Of the two readings
below, the first was taken — the example is generic and this product's credential lifecycle is the
exception — so `kb-security-baseline` §18.4 now carries two paragraphs under its code block: one
stating that **check 5 on a rotation asks about the parent entity**, because the thing being rotated is
the thing that is broken, and one reconciling the §18.3 half by noting that the `current_password`
rule form and the freshness window are both legitimate, and that only the rule form runs *before the
row is read*. `RotateProviderCredentialController` is unchanged and ADR-047 stands.

The reasoning, kept because the ruling is only as good as it: the skill's example spells check 5 as
`abort_unless($credential->status === CredentialStatus::Active, 409)`, i.e. *a disabled or pending
credential is not rotatable*. `RotateProviderCredentialController` checks the **organization's** status
and deliberately not the connection's, for the reason ADR-047 states: `invalid` and `revoked` are the
two states whose remedy **is** a new key, and refusing makes delete-and-recreate the only escape — at
the cost of the connection id, its catalogue rows and its embedding designation.

Two readings were available and they are not equivalent: either the skill's example is generic and
this product's credential lifecycle is the exception (in which case the example wants a sentence naming
the exception), or check 5 for a *rotation* is genuinely about the parent entity rather than the
credential (in which case the example is wrong in general and the fix is upstream of us). **The first
was chosen**, and the reason is worth stating: the second is a claim about every service that rotates a
credential, made from one product's experience of two states — `invalid` and `revoked` — that another
product might not have. The skill now says both things: the example stands as written, and a rotation
is the case where the status question moves up to the owner.

There was a second, smaller divergence in the same example: it satisfies §18.3 with a **freshness
window** (`requireReauthenticationWithin($actor, minutes: 15)`) where ADR-047 re-checks the password.
Both are legitimate readings of §18.3, and only the rule form runs before the row is read; the skill now
says so and recommends the rule form wherever the action has a FormRequest.

Owner: ruled by the session that owns `.claude/skills/**`; no code changed.

## The bots-schema decisions — ADR-055…059

Five decisions from 2026-08-19, from the step that landed the `bots` schema, its models, its policy,
its permission grant, its factories, and the two primitives the rest of Phase B is built on. **No
endpoint shipped in it** — routes and controllers are the next step — so everything here is schema,
authorization vocabulary, test harness or wire shape, which is exactly the set a later change can
*violate* without noticing. **The numbering starts at 055 because 044…046 are the shutdown-determinism
effort of 2026-08-14 and 047…054 the provider-lifecycle effort earlier the same day**; check
`grep -c '^### ADR-' docs/19-repo-structure-adrs.md` rather than trusting a number in prose (ADR-036).

**The one that generalizes furthest is ADR-055, and it is a rule about `jsonb` rather than about
fallback chains.** `postgresql-patterns` admits `jsonb` for three shapes, one of which is a
configuration snapshot written once and read whole — and the fallback chain genuinely *is* part of the
configuration snapshot that crosses the internal seam. That is the strongest argument for the `jsonb`
spelling and it still loses, because **a snapshot is assembled from the source of truth and is not the
source of truth.** What settles it is narrower and harder: `jsonb` cannot carry a foreign key, every
other model reference in this schema is guarded by a composite key against `(organization_id, id)`
precisely so a row cannot name another tenant's, and a `jsonb` chain would leave the *primary* model
guarded by the database and its *replacements* guarded by whichever service last wrote them. The
failure that follows is one tenant's conversations answered on another tenant's credential, with every
downstream layer agreeing because it was told whose credential answers.

**ADR-056 is the one to read if you only read one, because it is an extension of the specification
rather than an interpretation of it.** §6.3 gives an Organization Administrator "Manage bots", so
`bots.manage → Owner/Admin` is the spec as written. §6.4 and §6.5 **never mention bots in either
direction**, and `bots.view` is granted to the Knowledge Manager and the Analyst anyway, against two
named upcoming surfaces (Phase C6's source-to-bot assignment; Phase E's per-bot conversation review).
It is labelled an extension at all three sites that encode it — `Permission::BotsView`,
`OrgRole::grants()` and `RolePermissionMatrixTest`, which states the matrix independently — because a
silence somebody filled in must not read later as something the spec said. Its most surprising
consequence is one line: **the Analyst row is no longer all-false**, so "an analyst holds nothing" has
gone from a true shortcut to a false one, and any dataset resting on it now passes for the wrong
reason.

**ADR-057 is ADR-030's consequence arriving in the control-plane schema**, and its load-bearing
sentence is a negative: a `0.30` column default on `bots.evidence_threshold` **would fail no test**.
`0.30` is a valid float on every scale, applying it to a logit passes almost everything, applying a
logit threshold to a bounded score refuses almost everything, and nothing raises — only the refusal
rate moves, only in aggregate, and since ADR-030 it moves for one tenant and not the rest. So the
column is nullable with no default and stores its scale beside it, and
`App\Enums\EvidenceThresholdScale` is deliberately the data plane's `RerankScale` **minus
`uncalibrated`**: that member is not a scale, it is the statement that no characterization exists, so
a threshold carrying it would be a stored contradiction. The enum and `bots_evidence_threshold_scale_check`
are generated from one another at migration time and **must move together** — a fourth thresholdable
member is an enum case *and* an `ALTER`, in one migration, or they drift silently.

**ADR-058 closes § H9 in part and is the one whose trade-off is easiest to miss.** `tenantPair()` no
longer throws, its signature is unchanged, and all six `TenantPair` properties were narrowed from
`object` — not the two the brief named, because level 8 rejects a property read on `object` and a
partial narrowing leaves the fixture unusable. The half that stays open is the canary's *position*:
it is in Org B's bot welcome message, not in indexed source content, so the surfaces the canary was
designed to police — retrieval, citations, exports — remain uncovered until Phase C. **The fixture is
now half a fixture that looks whole**, and a green isolation suite is what makes that dangerous.

**ADR-059** is the repo's first paginated envelope and is therefore the shape both planes are now
built against: an object wrapper (because `#[ResponseShape]` cannot express "an array of" and
`additionalProperties: false` cannot apply to an array schema), one shared `meta` component, and the
**applied** query echoed back rather than the requested one, because `ListQuery::fromValidated()`
clamps `per_page` silently for callers that never ran a FormRequest.

**What this step did not touch:** finding **#79** stays pinned exactly as the 2026-08-12 ruling left
it (§ *The rulings of 2026-08-12*, **G2** — cited as G1 here until 2026-08-20; G1 is the ADR-036
ruling). Nothing here goes near `chunks`, `document_elements` or the
`source_versions → source_items → knowledge_sources` cascade, and `ALLOWED_TABLES` is unchanged.

## Found while landing the bots schema — 2026-08-19

**K1 is a pre-existing flake this work only surfaced**; K2 was a schema asymmetry that had been
invisible for as long as nothing referenced a model row; K3 is an encoding trap whose failure reads as
a constraint bug; K4 and K5 are the two halves of the theme handoff that are *not* yet enforced
anywhere, one of them named against a column that does not exist. **K6 was found by writing ADR-056
rather than by the effort** — two docblocks cite §6.4 as an explicit exclusion where §6.4 is silent —
which is a shape this document has recorded before — § **H14** (*"found by writing this section rather
than by the effort"*) and § **G10–G15** (*"what recording them turned up"*) are the same thing: a
defect surfaced by writing the record rather than by the work the record is about.

### K1 — two auth specs post fixed literals against limiters keyed on those literals, so the suite 429s on a later run

**Confirmed, not suspected**, and **not caused by this change.** The `control-plane-engineer` who
found it reproduced it and then confirmed the diagnosis by flushing the limiter store and re-running
clean. The two tests:

```bash
grep -n "malformed verification token" services/core-api/tests/Feature/AuthEmailVerificationTest.php
grep -n "malformed address"            services/core-api/tests/Feature/AuthPasswordResetTest.php
grep -n "RateLimiter::for('verification'\|RateLimiter::for('password-request'" \
     services/core-api/app/Providers/AppServiceProvider.php
```

Both post a **fixed literal** — `'too-short'` as a token, `'not-an-address'` as an email — and both
limiters key on exactly that value: `verification` on `'tok:'.hash('sha256', $token)`,
`password-request` on `'acct:'.Str::lower($email)`. The store is persistent, so the budget carries
across runs of the suite, and `SpaSession::isolateRateLimits()` randomizes **only the IP axis**
(`REMOTE_ADDR` to a fresh `2001:db8::/32` address) — which is the axis that is not binding here. Every
*other* test in `AuthPasswordResetTest` already uses `SpaSession::uniqueEmail()`; these two are the
ones that did not.

**A correction to the brief that reported this, and it makes the flake worse rather than better.** The
brief described both as "6 per 60 minutes against `hash('sha256', …)`". That is the `verification`
limiter only. `password-request`'s account axis is a *different* limiter with a *shorter* window and a
*smaller* budget, keyed on the lower-cased submitted address and not on a digest — read the two
`RateLimiter::for` blocks for the live numbers (ADR-036; `SpaSession::uniqueEmail()`'s own docblock
states the same hazard). So the reset spec turns red after **fewer** consecutive runs than the
verification one, in a **shorter** window, which is the direction that matters when somebody is
deciding whether they can reproduce it.

**Fix:** unique literals in those two tests, the same way every neighbouring test already does it. It
is a one-line change in each and it is deliberately **not** made here. **Owner:** whoever owns the
auth suite (`test-engineer` with `control-plane-engineer`); this step does not touch `tests/Feature/Auth*`.

### K2 — `provider_models` carried no `UNIQUE (organization_id, id)`, so the composite FK that stops cross-tenant model naming failed with 42830

**Closed by migration `2026_08_19_001300`.**

```bash
grep -rn 'org_scoped_key' services/core-api/database/migrations
```

PostgreSQL requires a referenced column list to be backed by a unique constraint, so
`FOREIGN KEY (organization_id, provider_model_id) REFERENCES provider_models (organization_id, id)`
fails outright with **42830 — "there is no unique constraint matching given keys"**. `bots` carries
that key, `bot_fallback_models` carries it a second time, and the index has to exist before either
table is created.

**Why the asymmetry survived is the transferable part.** `provider_connections` got its identical
index inside its own `CREATE TABLE` (`2026_08_07_000300`, which writes the reasoning out at length),
because the tables pointing at it were already planned. `provider_models` did not, because at the time
**nothing referenced a model row at all** — the catalogue was a leaf. A bot naming a model is what
promotes it to an interior node, and the missing index is the other half of that promotion. The
general shape: *a tenancy guard that is only needed by a referent is absent for exactly as long as
there is no referent, and its absence is invisible until the first one arrives — as a raw SQLSTATE
from a migration, not as a security finding.* Nothing sweeps for it; the check is to ask, whenever a
table gains its first inbound composite key, whether the target index exists.

It is its own migration rather than a line in `create_bots_table` because it is an **ALTER on a
populated table and therefore has a lock story** — `SET lock_timeout`, `CREATE INDEX` taking a `SHARE`
lock, and the deliberate refusal of `CONCURRENTLY` (which cannot run inside the transaction every
migration in that directory runs inside). Folding it into a lock-free `CREATE` would hide that
paragraph in a file whose every other statement has no lock story, which is exactly where the next
reader would stop looking for one.

### K3 — `bots.theme` cannot use Laravel's built-in `array` cast, and the failure reads as a constraint bug

**Closed by `App\Support\Casts\JsonObjectCast`. Both halves were verified against the running server,
not reasoned about.**

PHP cannot distinguish an empty array from an empty map, and Eloquent's built-in `array` cast is
`json_encode($value)`. So `$bot->theme = []` — the overwhelmingly common state, *"this bot uses the
platform theme"* — encodes as `[]`, whose `jsonb_typeof` is `'array'`, while a themed bot encodes as
`{}`. The column would then silently hold **two JSON types** depending on whether anybody had themed
the bot.

`bots_theme_vocabulary` is a **key-set subset test written without a subquery** (a CHECK may not
contain one): `theme - 'primary' - 'accent' - 'radius' = '{}'::jsonb`. `'[]'::jsonb` is not
`'{}'::jsonb`, so **with the built-in cast every unthemed bot is refused by the database** — and the
error points at a constraint, so the first diagnosis is that the constraint is wrong rather than that
the encoding is. Without the constraint it would be worse rather than better: `theme -> 'primary'`
over an array returns NULL, a client generated from the OpenAPI document would declare an object and
receive an array, and `Object.entries([])` happens to equal `[]` — so the renderer would work, on the
empty case, forever, and break the first time a migration assumed the column's type.

The cast is `(object)` on write, and that is the whole mechanism: it makes `[]` encode as `{}`, and it
makes a **list** like `['primary','accent']` encode as `{"0":"primary","1":"accent"}`, which the
key-set CHECK then refuses **by name** — the correct outcome, because a list is not a map and the
refusal says so, whereas `["primary","accent"]` would produce a type failure whose message points at
the type rather than at the keys. Three alternatives were rejected in the file's own docblock: a
column default (present, and useless, because an explicit `[]` from a FormRequest overrides it); a
mutator on the one model (which every future `jsonb` map would have to repeat, and the one somebody
forgets fails in production); and `AsArrayObject` (Laravel's own answer, which encodes `{}` correctly
and changes the PHP-side type at every read site to fix an encoding detail at one).

### K4 — the theme's enforcement is split, and the half that is not yet built is the half a customer notices

**Open. Owner: `control-plane-engineer`, on the bot write endpoint.**

The database CHECK covers **the key set and the value types**: `theme` is an object, its keys are a
subset of `{primary, accent, radius}`, and each present value is a string. It does **not** cover the
value **grammar**, and three rules therefore live nowhere yet:

* whether `primary` / `accent` are legal `oklch()` triples,
* whether `radius` is one of the values `packages/design-tokens` publishes,
* and the one easiest to miss — whether the supplied colour can be given **readable text at all**.

The third is a refusal the renderer already makes. `apps/web/src/lib/theme.ts` measures an unreachable
band (its docblock records L in [0.538, 0.634] for some chroma/hue combinations, bottoming out at
4.143:1) and returns `null` rather than shipping unreadable text, and it flags the control plane
explicitly for the matching write-side refusal. Until the FormRequest lands, **a customer can be told
their colour was accepted and then be served the platform default**, with nothing anywhere saying why.

The migration's docblock names this so that the PR writing the FormRequest cannot claim nobody said;
this finding exists so that the gap is visible from the findings log too, rather than only from a
comment inside the file that has the gap.

**Work on the closing half was already in the working tree when this was written**, uncommitted and
concurrent — `App\Rules\ReadableThemeColor`, `App\Support\Theme\OklchColor` and
`App\Support\Theme\ThemeVocabulary` exist while no bot FormRequest does yet. So the honest status is
*in flight, not neglected*, and the closing condition is a rule reaching a request class rather than a
rule class existing:

```bash
ls services/core-api/app/Http/Requests | grep -i bot
grep -rn 'ReadableThemeColor\|ThemeVocabulary' services/core-api/app/Http/Requests
```

Both silent means the refusal still has nowhere to run.

### K5 — `apps/web`'s theme module flags the control plane about a column that is not called that

**Open, and it is a pointer defect rather than a behaviour defect. Owner: `admin-web-engineer`** —
`docs/` does not edit `apps/`.

```bash
grep -rn 'theme_configuration' apps/web/src/lib/theme.ts services/core-api
```

`apps/web/src/lib/theme.ts` reads *"Laravel validates `bots.theme_configuration` on write"*. The
shipped column is `bots.theme` (migration `2026_08_19_001400`), and `theme_configuration` exists
nowhere in `services/core-api`. `docs/11` §16.3 lists the field in prose as *"Theme configuration"*
and names no column, so neither spelling contradicts the spec — but a cross-tree flag addressed to
another agent that names a non-existent column is the kind of pointer that sends the reader looking
for a migration that was never written, which is precisely the failure the K4 flag exists to prevent.
Fix the name in the flag, not the column: `theme` is what the CHECK, the cast and the model all use.

### K6 — two docblocks say §6.4 *explicitly excludes* bot publish; §6.4 is silent, and the same docblocks say so one paragraph later

**Open, and found while writing ADR-056 rather than by the effort. Owner: `control-plane-engineer`
(two comment lines). The grant is correct; one sentence of its stated justification is not.**

```bash
grep -rn 'excludes bot publish' services/core-api/app
sed -n '/^### 6.4 Knowledge Manager/,/^### 6.5/p' docs/01-product-scope.md
```

`Permission::BotsManage` and `OrgRole::grants()` both justify `bots.manage → Owner/Admin` with *"§6.3
lists 'Manage bots' as an Organization Administrator capability, and §6.4 **excludes bot publish**
from the Knowledge Manager explicitly."* Read §6.4: it is a six-item responsibility list about
uploads, websites, parsed content, reprocessing, source deletion and freshness. **Bots do not appear
in it in either direction** — which is precisely what the *next* paragraph of both docblocks says,
correctly, when it labels the `bots.view` grant an extension of the specification. One file therefore
reads §6.4's silence as an explicit exclusion for `manage` and as a genuine silence for `view`, two
paragraphs apart.

**Nothing about the shipped grants changes.** `bots.manage → Owner/Admin` is supported by §6.2
(*"Create and publish bots"*, Owner) and §6.3 (*"Manage bots"*, Administrator) without needing §6.4
at all; the argument is *positive grant to two roles*, not *explicit denial to a third*. **Why it is
worth two comment edits anyway:** an "explicitly excludes" claim is the sentence a later reviewer
cites when refusing a Phase C6 or Phase E request, and citing it sends them to a section that says
nothing — the exact failure mode ADR-056 exists to prevent, since the whole point of labelling the
`bots.view` grant an extension is that a silence somebody filled in must not read later as something
the spec said. A justification that overstates the spec in the *strict* direction is the mirror image
of the one this repo has been careful about, and it is no more true.

The fix is to say what §6.2 and §6.3 say and to stop citing §6.4 for `manage`. ADR-056's Decision
paragraph already carries the corrected wording and a pointer here.

## The security read of the bots surface — L1–L6, 2026-08-19

A read-only audit of the two commits that landed the bots schema and its endpoints. Verdict was
**needs-changes**: two Should-fix, no Blocking. Recorded under **L** because `S1`–`S17` above are the
second scaffolding audit round and mean something else entirely — an earlier draft of ADR-056's
amendment cited "finding S2" and landed on the evidence-threshold-scale finding, which is the
pointer-goes-somewhere-false failure ADR-036 exists to prevent.

What the read did **not** find is worth stating, because an audit that reports only its hits reads as
a list of defects rather than as coverage: no dropped tenant predicate, no unscoped binding, no role
mapped to the wrong permission, and no credential or prompt reaching a response, an audit row or a
log. The composite-key claim was checked against the **live schema** (`psql \d bots`) rather than the
migration source, and the grouped-OR filter against **dumped SQL** — the tenant predicate is `AND`ed
outside the disjunction, which is the spelling that does not drop it off the second arm.

| # | Finding | State |
| --- | --- | --- |
| **L1** | `BotResource` published `system_instruction` and `answer_style_instruction` unconditionally, so an **Analyst** — a role holding `bots.view` and no other permission in the catalog — could read every bot's operator-authored system prompt. Live, not latent. The codebase already contradicted itself: `AuditLogger` refuses that same field from `details` because it is "the exact string a prompt-injection review is about", while the API handed it over unredacted. ADR-056's justification named "the name, the model and the answer mode" and never mentioned it | **Closed** by the management-only projection amended into ADR-056. Verified by mutation: reverting the field to unconditional turns the knowledge_manager and analyst rows red |
| **L2** | Deleting a bot destroys its widget origin allow-list with no record of what it permitted — contradicting the reason `bot_domains` gives for its own `ON DELETE RESTRICT`, which is that a security review may later need to reconstruct it. Latent: no route creates a domain yet | **Open**, owned by the step that lands the bot-domains endpoints. It goes live exactly when it is easiest to forget, because the delete path is already written and green |
| **L3** | The bot delete path's TODO named conversations but not the Qdrant `bot_ids` payload term, the four Valkey key families, or any verification step | **Closed** as an enumeration. The Qdrant step is a payload-term **removal**, not a delete-by-filter — `bot_ids` is a list on each point, so filtering on it would destroy chunks other bots still answer from |
| **L4** | A legal slug can match the log redactor's vendor-key shape (`sk-` + 12 chars), so a `bot.deleted` row can degrade to two bare fingerprints with no `_redacted` sibling | **Documented, not fixed.** Tenant self-harm along the designed degradation path |
| **L5** | FormRequest validation runs before `Gate::authorize`, so a `bots.view`-only member gets 422 rather than 403 on a malformed body | **Accepted.** Ordering hygiene, not a leak: no query runs and the rule sets deliberately carry no `unique:`/`exists:` |
| **L6** | No per-organization bot quota; the admin throttle is the only bound, and every bot row is a retrieval scope | **Open**, owned by the quotas step |

**Carried forward to whoever builds the public runtime surface.** The publish guard deliberately says
nothing about `access_mode`, and `published` + `public` is what makes a bot answerable anonymously.
When that surface lands, an **empty** `bot_domains` allow-list must deny every origin — expressed as
"some active row matches this exact origin", which is false for the empty set, and never as "no row
forbids it", which is true for it. `BotDomainStatus::permitsEmbedding()` now carries that rule and
still has no consumer.

**One thing the read judged and did not flag**, recorded so the judgement is reviewable rather than
invisible: `provider_connection_id` and `provider_model_id` are visible to an Analyst, who holds no
`providers.view` and therefore cannot resolve either ULID to a vendor, a label or a `last_four`
through any endpoint. What they learn is configuration topology — which bots share a connection. It
is the only remaining thing on the row that ADR-056's justification does not name.

## The Phase B audits — M1–M7, 2026-08-19

Two read-only reads over the whole bots console effort. `git log --oneline b976735..HEAD` is the
phase — **read the range rather than a count**: the number arrived in the commissioning brief as
*fourteen*, was *thirteen* when measured, and moves again on the next commit, which makes it the
worked example of the rule this file keeps applying to other people's sentences (ADR-036).

**The verdicts, which are the half an audit write-up usually loses.** Security: **clean, with nits.**
Contract: **consistent, with nits.** Neither read returned a **Blocking** issue. Between them they
found **no cross-tenant read or write**, no dropped tenant predicate, no missing authorization check,
no role mapped to the wrong permission, and **no path by which a provider credential reaches a
response, a log or an audit row**. An audit recorded only as its hits reads as a worse result than it
was, and the two Should-fix items below (M1, M3) were both found *inside* code that was otherwise
doing the right thing.

**Five claims were confirmed by execution rather than by reading** — each one had been flagged in the
brief as *distrust this, it is asserted by the code that would be wrong*:

| Claim distrusted | How it was confirmed |
|---|---|
| The composite foreign keys that stop a bot naming another tenant's model actually exist in the database, rather than only in a migration file | Queried from `pg_constraint` on the live schema, not read from `database/migrations/` |
| A child row of one bot cannot resolve under a **different bot in the same organization** — the failure that every cross-tenant assertion in the repo passes against | `tests/Security/BotChildEndpointAccessTest.php` → *"404s a child of a DIFFERENT bot inside the SAME organization"*, asserted on its own for that reason |
| The delete path's child summary is read **inside** the transaction, **before** the children are removed — a summary read anywhere else records zeroes for exactly the row that needed them | Executed against the delete path (`app/Services/Bots/BotService.php`, `BotChildSummary`); the recorded counts are non-zero and match the rows that were then deleted |
| `tests/Contract/ThemeGrammarParityTest.php` genuinely **parses** `apps/web/src/lib/theme.ts` rather than restating its constants in PHP | Read the parse: `repoFile('apps/web/src/lib/theme.ts')` with the constants extracted by pattern. A test carrying its own copy of the regex would be the third copy and the first to go stale |
| `public_bot_id` is unguessable, at all three layers that constrain it | `App\Support\Kb\PublicBotIdentifier` mints `pub_` + 16 CSPRNG bytes hex-encoded; `bots_public_bot_id_shape` CHECKs `^[A-Za-z0-9_-]{1,64}$`; the hosted-chat route segment refuses anything else. The minted grammar is a strict **subset** of the other two, so a minted value can never be the one that discovers a disagreement between them |

**Why the prefix is `M`.** `K1`–`K6` are the bots-schema findings and `L1`–`L6` are the security read
of the bots *surface*; `S1`–`S17` mean the second scaffolding audit round and have already caused one
ADR to cite the wrong finding. `M` continues `K` and `L` and is **not** `docs/23`'s class letter `M`
(*"needs a machine or a person this host does not have"*) — a class letter never appears as `§ M3`.

**M1–M5 are this phase's own findings and are all fixed**, in commits `769fbf2` and `a0b2ea7`.
**M6 and M7 are outside Phase B, already false before it started, and are recorded rather than
fixed** — neither is in a tree `docs/` may write to.

### M1 — `prohibited` does not mean "must not be present", and the mechanism is not the one the brief assumed *(CLOSED)*

**Closed in `769fbf2`. Owner was `control-plane-engineer`; the rule is now `missing`.**

```bash
grep -n "'status' => \['missing'\]" services/core-api/app/Http/Requests/UpdateBotRequest.php
grep -n 'function validateProhibited' -A3 \
  services/core-api/vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php
```

`validateProhibited` is `! $this->validateRequired(...)`. So it **passes** for `null`, for `""` and
for `[]` — and `validated()` keeps the key, because the field was declared. `ConvertEmptyStringsToNull`
turns a cleared control's `""` into `null` before validation runs, which is exactly the shape a stale
client still modelling `status` as an optional field emits. The key then reached `BotEdit`, the
repository wrote `NULL` into a `NOT NULL` column, PostgreSQL raised **23502**, and **the rename
carried in the same request was lost behind a 500 with no field-keyed error**. An array-valued
`status` was worse still: a type error before the database was touched at all.

`missing` is the rule that fails on **presence, regardless of value** — `! Arr::has($this->data,
$attribute)`. The four passing shapes are now a dataset asserting a 422, the validation class, a
`status`-keyed error, and that the accompanying rename did **not** land; plus the case that must
still work, a PATCH carrying only a name.

**The mechanism correction, recorded because it differs from the conclusion the brief handed over.**
The brief reasoned that `Prohibited` behaves as an implicit rule. It is **not** in this framework's
`$implicitRules` list, while `Missing` **is** — read `$implicitRules` in `Validation/Validator.php`.
The consequence is a split the outcome table hides: for `null` and `[]` the rule **runs** and returns
`! validateRequired`; for `""` it is **skipped entirely, *because* it is not implicit**. Both routes
end in a pass, so the finding stands exactly as reported — but it was **measured against the
installed framework rather than inherited**, and the two routes matter to anyone who later reasons
about which values a `prohibited`-shaped rule can see.

Each fix carries a **negative control**: reinstating the old rule fails exactly the three
empty-value rows.

### M2 — a homoglyph changed the host, in a file that stated twice that all its mutations are identity-preserving *(CLOSED)*

**Closed in `769fbf2`. Fixed at the class, not at the instance.**

```bash
# Every surviving `mb_strtolower` hit is a DOCBLOCK WARNING against it, not a call. The live folds
# are the two `strtolower(` lines; if that ever inverts, this finding has been reopened by an edit.
grep -n 'mb_strtolower\|[^_]strtolower(' services/core-api/app/Support/Web/ExactOrigin.php
```

`App\Support\Web\ExactOrigin` folded case with `mb_strtolower()`, which applies **Unicode simple
lowercase mapping**, while the control-character guard directly above it is byte-wise. **U+212A
KELVIN SIGN lowercases to ASCII `k`**, so `https://Kelvin.example.com` — with the Kelvin sign in place
of the K — was stored as `kelvin.example.com`. Two properties the file argues at length that it does
**not** have were both false: the folding was not identity-preserving, and **two distinct inputs
collided onto one row**. On a table whose whole purpose is that a stored origin equals the `Origin`
header a browser sends, a fold that rewrites the host is a grant applied to a name the operator never
entered.

The repair is `strtolower()` — byte-wise, ASCII-only and locale-independent — and it closes the
**class** rather than the instance: an exhaustive scan of U+0080–U+2FFFF confirms **exactly one**
codepoint above ASCII folds into an ASCII letter this way. A non-ASCII host now reaches the punycode
refusal the file always documented it as reaching. The fix is additive: no existing expectation
moved, and the new rows expect the message an existing non-ASCII row already expected. Negative
control: reinstating `mb_strtolower` fails exactly the two homoglyph rows.

**The transferable shape:** a normalisation step and the guard above it must agree about what a
*character* is. A byte-wise guard followed by a Unicode-aware transform means the transform can
produce a string the guard never saw.

### M3 — a data-loss path: a *withheld* field became a form value and a save wrote `null` over both operator-authored prompts *(CLOSED)*

**Closed in `769fbf2` (server half) and `a0b2ea7` (client half). Recorded as decisions in
[ADR-060](19-repo-structure-adrs.md) and [ADR-061](19-repo-structure-adrs.md); this entry is the
defect.** It was **live on the shipped console**, not latent.

Three independently-reasonable things composed into it:

1. **The console re-derived `bots.manage` from the session role by hand** — a third spelling of the
   server's grant map, answering a question about the **session** while ADR-056's projection is
   resolved **per record, per request**.
2. **The panel defaults builder seeded every field in its tuple from the resource unconditionally.**
   So on a row fetched while the instruction fields were withheld — a role promoted mid-session, a
   cached detail row, any refetch skew — both prompts arrived `null` and became form values.
3. **`sometimes|nullable|string` accepted them.** `sometimes` leaves an **absent** key alone; a
   **present `null` clears the column**. Saving a rename wrote `null` over both operator-authored
   prompts and **returned 200**.

The repair is at the seam, in both directions: the server states what it withheld
(`instructions_visible`, ADR-060) and the client **omits** a withheld field from form state rather
than seeding it null (ADR-061), with a conditionally-rendered card body as the second line, because
React Hook Form submits a registered input's DOM value whether or not `defaultValues` named it.

**Two testing notes worth more than the fix.** The window tests assert on **keys, never values** — a
body carrying `system_instruction: null` is byte-identical to the destructive request, so a value
assertion would have passed *against* the bug. And the first version of the component test was itself
a **false green**: `elements()` on an unpainted page returns an empty list, so both *"no control"*
assertions passed against a blank document. It now awaits a control the tab **does** render before
asserting the absence of the ones it does not.

The role helper survives as an **affordance only** — it gates whether an editor is offered at all —
and its docblock now says so. The sentence removed from it, that it also decides whether those two
fields *mean* anything, **was the bug**.

### M4 — a mirrored type was hand-duplicated in `apps/web`, in the same phase as the docblock warning against exactly that duplication *(CLOSED)*

**Closed in `a0b2ea7`.**

```bash
git show 62e06f9 -- apps/web/src/lib/table/envelope.ts | grep -n 'interface PaginationMeta'
grep -n 'ListMetaResource' apps/web/src/lib/table/envelope.ts packages/contracts/src/resources/bots.ts
```

`apps/web/src/lib/table/envelope.ts` declared its own `PaginationMeta` — field for field identical to
`ListMetaResource`, which `packages/contracts/src/resources/bots.ts` mirrors and
`test/resource-drift.test.ts` compares against the generated OpenAPI document. The local copy landed
in `62e06f9`, the phase's **first** commit, and survived to its last; `resource-drift.test.ts` was
itself rewritten to catch this shape after `MemberResource` was hand-written a second time earlier in
this repository, and says so in its own comments.

**The window a local copy opens is narrow and silent, which is why it needs a finding rather than a
tidy-up:** a server-side change to the `meta` block turns `@kb/contracts` red while `apps/web`
compiles clean against a stale interface. `readMeta` guards only `page`, `per_page` and `total`, so
the pager would go on reading a field that is no longer what it says, with the drift test green in
the package that does not render it. Importing the type makes the drift test *this file's* drift test
too. There is no local envelope type either, for the same reason.

This is the failure `packages/contracts` exists to prevent and that `contract-steward` exists to
catch — see `CLAUDE.md` § *Shared directories*: *"a second copy of the frame parser is the drift
`contract-steward` exists to catch"*. It is recorded here because it got past the phase's own
reviews for the whole phase.

### M5 — the derived radius scale was declared on `:root`, so a scoped `--radius` was inert *(CLOSED)*

**Closed in `a0b2ea7`, in `packages/design-tokens/scripts/build.mjs` and its generated output.**

```bash
grep -n 'radius' packages/design-tokens/generated/theme.css
grep -n 'radius' packages/design-tokens/generated/tokens.css
```

A custom property's `var()` references are substituted **at the element that declares it**. The
`@theme inline` block emitted `--radius-sm: var(--radius-sm)` — the self-reference shape that is
correct and inert for every *literal* family (`--shadow-md`, `--text-h1`, `--font-sans`,
`--ease-out`) because unlayered CSS beats layered CSS and the real value always wins. **Radius is the
one family whose steps are derived from another property**, and for it that shape resolves the whole
chain at `:root`: a `rounded-sm` utility read a fixed length computed from the *root* `--radius`, so
writing `--radius` onto a nested element — which is exactly what the bot theme preview
(`apps/web/src/components/bot-theme-scope.tsx`) does — **moved nothing**. The console preview and the
shipped widget, which sets `--radius` at its own root, therefore disagreed about corner radius, and
each looked right in isolation.

The fix emits the **unsubstituted `calc()`** into the inline block, so the utility carries the
expression and `var(--radius)` resolves at the element. `apps/web/tests/components/design-system-css.test.tsx`
now walks every step of the scale at every value in the tenant radius enum. The `max(0px, …)` wrapper
is load-bearing and unchanged: at `--radius: 0rem`, `calc(0rem - 6px)` is `-6px`, which is an invalid
`border-radius` that the browser **discards** — the test probes for a fallback value precisely so a
discarded declaration is something an assertion can see.

### M6 — the *"CI greps for this"* claim shape survives the 2026-08-17 sweep, the sweep's own grep cannot see it, and it is not confined to `services/core-api`

**Open, and wider than it was reported. Not fixed here — `docs/` does not edit `services/` or
`apps/`. Owners are per tree: `control-plane-engineer`, `mobile-engineer`, `widget-sdk-engineer`,
and whoever owns the `services/ai-service` file a hit lands in.**

`CLAUDE.md` states that these claims were swept when `.github/` was deleted and that **a surviving
one is a bug**. They survive. Three things about *how* they survive are the finding:

**(1) The sweep's own measuring command returns clean against them.** The grep published in
`CLAUDE.md` matched `gates\.yml` and `\.github/workflows` — the deleted gate by **filename** — while
the surviving comments name it by **behaviour** and never once by filename. Run the published command
and the tree looks swept. `CLAUDE.md`'s block now carries `CI grep` as a third alternative, and the
comment beside it says why.

**(2) The obvious widening is the wrong inflection, which is `docs/22` § H1 one level up.** Matching
`CI greps` (plural) finds the `Controller.php`, `OrganizationScope.php`, `AppServiceProvider.php`,
`InternalAiClient.php` and `config/services.php` comments and **misses**
`app/Models/EmailVerificationToken.php` and `app/Models/OrganizationInvitation.php`, which both say
*"the CI grep"* — singular. H1 is a tenancy gate that greps only the plural form of the bypass it
exists to catch; this is the same defect in the sweep that was supposed to remove H1's kind of claim.
Match `CI grep` and both inflections fall out.

**(3) It was never a `services/core-api` problem.** The commissioning brief reported *five* comments
in that service. Measured at the commit rather than in prose, the non-test trees of that service
alone return more than five, and the same shape is live in `apps/mobile`, `apps/widget` and
`services/ai-service` — including `services/ai-service/app/db/writes.py`, whose comment describes
the allow-list gate that ADR-033 and `CLAUDE.md` both record as **deleted**.

**Measure it at a commit, not in the working tree**, because a commit does not move while you read it
and this tree does:

```bash
# a0b2ea7 is Phase B's last commit and is the state this finding was measured against. Swap it for
# HEAD to see the state now — expect the two to differ while the repair below is in flight.
git grep -n 'CI grep' a0b2ea7 -- apps services packages scripts infrastructure
git grep -n 'CI grep' a0b2ea7 -- services/core-api/app services/core-api/config services/core-api/database
```

**This grep is a lead, not a verdict, and that is the reason it was not simply added to the sweep and
left.** It matches the *corrections* as well as the claims: `apps/mobile/jest.config.js`,
`apps/mobile/eslint.config.mjs` and `apps/mobile/README.md` each contain the sentence recording that
**no such grep ever existed** (`docs/22` § *Found while completing the stubs*, the seventeenth false
enforcement claim). Those hits are history and must stay. Every hit needs reading before it is
touched — which is precisely the self-tripping shape the sweep's filename-only pattern was chosen to
avoid, and the cost of avoiding it was blindness.

**Why the surviving claims are load-bearing in the wrong direction.** A reader who finds
`// CI greps for both` beside a rule concludes the rule is machine-checked and does not add a test.
That is `docs/22` § F7 (a vendored ruleset counted by a gate and executed by nothing) and § F1–F13's
seventeenth claim, again. The one to repair first is
`services/core-api/app/Models/Scopes/OrganizationScope.php`, because **ADR-043's decision rests on
it**: the bypass alternative is barred there on the stated ground that *the CI grep cannot see its
singular form*, and that argument now cites a mechanism which does not exist. The correct repair is
the one the 2026-08-17 sweep used elsewhere — state the invariant, say it is held by review and by
the test suites, and name the test where one exists.

**In flight, not neglected, and recorded that way for the same reason § K4 was.** While this finding
was being written, `git status --porcelain` showed `Controller.php`, `OrganizationScope.php`,
`AppServiceProvider.php` and `InternalAiClient.php` modified in the working tree, alongside an
untracked `services/core-api/tests/Arch/StringLevelDoctrineTest.php` whose opening comment reads
*"Each rule below was a CI grep"* — a `test-engineer` converting the claims into assertions rather
than into prose, which is the better of the two repairs. That work is uncommitted and this record
does not depend on it: the closing condition is the **committed** state, and the check is the first
command above run against a commit that contains the repair.

### M7 — `kb-design-language`'s Definition of done asserts a `tokens.widget.css` subset check "asserted in CI"; the file, the export and the CI all do not exist

**Open. Not fixed here — `docs/` never edits `.claude/skills/**`. Owner: the skill's owner, with
`admin-web-engineer` (`packages/design-tokens`) and `widget-sdk-engineer` (`apps/widget`).**

```bash
grep -n 'tokens.widget.css' .claude/skills/kb-design-language/SKILL.md \
                            .claude/skills/kb-design-language/references/cross-platform.md
ls packages/design-tokens/generated/                     # index.d.ts index.js theme.css tokens.css
sed -n '/"exports"/,/^  }/p' packages/design-tokens/package.json
grep -rn 'design-tokens' apps/widget/src/app/styles.css  # imports tokens.css, the FULL set
```

The skill's Definition of done reads *"`tokens.widget.css` is a strict subset of `tokens.css` **by
name in both the `:root` and `.dark` blocks**, asserted in CI"*, and `references/cross-platform.md`
carries a table row for the file, a `node -e` verification recipe and a second checklist entry.
**No such file is generated, no package export names it, `apps/widget` imports the full `tokens.css`,
and there is no CI** (`.github/` was deleted 2026-08-17). `packages/design-tokens/src/tokens.json`
already knows: its `_legacyColors_note` says the removal of the legacy aliases belongs to
`widget-sdk-engineer` *"together with the tokens.widget.css subset that does not exist yet."* So one
file in the repository states the truth while the skill that governs it states a check.

**Why this one matters more than an ordinary stale line.** A widget agent in a later phase reads a
skill's Definition of done as a list of things to satisfy before shipping, and this entry sends it
looking for a build entry point, a generated artifact and a CI job that have never existed —
the pointer-leads-somewhere-false failure mode this file has recorded against itself repeatedly
(§ G16, § K5, and the `S2`/`L2` citation slip at the head of § *The security read of the bots
surface*). The budget argument behind the entry is **sound and unaffected**: custom properties are
not tree-shaken, so the full token set is dead weight against the widget's brotli shell budget. What
is false is only the claim that anything checks it. The honest repair is to state the subset rule as
a rule and name what would verify it — the `node -e` recipe already in `cross-platform.md` — rather
than to assert a gate.

**Where the specification and a skill disagree, documented rather than resolved.** `docs/23` carries
`tailwind-shadcn/SKILL.md:64` as class **D**: *"the spec does not enumerate `theme_configuration`; an
ADR should ratify this list before the first migration."* **The first migration has now landed and
the list was not ratified.**

```bash
grep -n "the tenant contributes six scalars" .claude/skills/tailwind-shadcn/SKILL.md
grep -n "theme - 'primary'" services/core-api/database/migrations/2026_08_19_001400_create_bots_table.php
grep -n 'logo_object_key\|avatar_object_key\|default_mode' \
  services/core-api/database/migrations/2026_08_19_001400_create_bots_table.php   # silent
```

The skill names **six** tenant scalars — `primary`, `accent`, `radius`, `logo_object_key`,
`avatar_object_key`, `default_mode` — on a column it calls `theme_configuration`. The shipped column
is `bots.theme` (§ K5) and its `bots_theme_vocabulary` CHECK admits **`primary`, `accent`, `radius`
and nothing else**; the remaining three have **no column anywhere on `bots`**, in the jsonb map or
beside it. So the divergence is not a narrower key set — it is three scalars the skill says a tenant
contributes and that the schema has no place to put.

Documented, not resolved: `docs/` does not edit `.claude/skills/**`, and the two candidate answers
are a real decision rather than a wording fix — either the three missing scalars are a Phase C/D
column addition, or the skill is describing a surface this product will not have. That is
`admin-web-engineer`'s to rule, and it needs an ADR whichever way it goes, exactly as the
`UNVERIFIED` marker asked for before the migration existed.

## The Phase B review fixes — N1–N7, 2026-08-20

A `/code-review` over `main…claude/phase-b-task-planning-ay7299` — the same Phase B branch § *The
Phase B audits* reads — returned **seven** findings. All seven are fixed, and the effort reports the
suites green; this section was written from `docs/`, which does not run them, so that verdict is
recorded as reported rather than as measured here.

**Two of the seven were wrong about the mechanism**, and that is the reason this section exists at
all rather than being a changelog. N4's premise was false outright — the rule the review said refused
a legitimate PATCH did not refuse it, and could not have — and N5's was false in part. Both were
caught because each was measured against the installed framework before anything was edited. The
closing note ties that to § *The rulings of 2026-08-12*, which records the same shape for a ruling.

**No ADR is warranted, and saying so is part of the record.** None of the seven changes an
architectural decision: five are defects against a decision already made, one (N3) adds a mechanism
in the shape of the two the repository already uses, and **N7 is a decision being *upheld* under
pressure** — the obvious repair was an optimistic update, an existing decision forbids it, and the
fix was built the harder way so the decision survives. An ADR that ratified any of these would be
recording a choice nobody made.

**Why the prefix is `N`.** `K1`–`K6` are the bots-schema findings, `L1`–`L6` the security read of the
bots surface, `M1`–`M7` the Phase B audits; `N` continues them. It is also **not** one of
`docs/23`'s class letters — those are `M`, `S`, `V`, `P`, `X`, `D`, `W` — so `§ N4` cannot be read as
a class the way `§ M3` once could.

**Measure these in the working tree, not at a commit.** The seven fixes were uncommitted when this
was written (`CLAUDE.md` § *Git*: Ankur reviews and commits), so unlike § M6 the check here is
`git diff` and `git status --porcelain`, and the line numbers below will move the moment they land.
Where a fact is likely to move, a grep is given instead.

### N1 — the Publishing tab reported unsaved edits for a form holding exactly the stored row, and the same defect was in six more places *(CLOSED)*

```bash
grep -n 'export const numericFieldValue\|export const clearableFieldValue' \
  apps/web/src/features/bots/bot-model-shared.ts
grep -rn 'clearableFieldValue\|numericFieldValue' apps/web/src/features/bots/
```

`formState.isDirty` compares form state against `defaultValues` **before the resolver runs**, so the
schema's `z.preprocess` never sees the comparison. `botPanelDefaults` seeds the three
`nullableIntField` limits (`rate_limit_per_minute`, `rate_limit_per_day`, `retention_days`) from the
resource as **numbers**, while the three number inputs on the Publishing tab wrote the raw DOM string
into form state. `'60' !== 60`, so typing a seeded value back in — or merely touching and restoring
it — left the panel dirty **permanently**, and `useUnsavedBotEdits` then had the editor shell
interpose its *"Leave without saving?"* dialog on every tab change for a form nobody had edited. That
is the one dialog in the editor whose whole purpose is to stop an operator losing work, and this
taught them to click through it.

**The repo already held the fix, one tab over.** `numericFieldValue` in
`apps/web/src/features/bots/bot-model-shared.ts:251` exists for exactly this failure and its docblock
says so in the same words with a different literal (`'20' !== 20`); the Model tab used it and the
Publishing tab did not. The repair adopts `asFieldText` for the read direction and
`numericFieldValue(raw, null)` for the write, and **deletes two private near-duplicates**
(`numberText`, `clearableTextValue`) that did the read half correctly and the write half not at all.

**The part worth recording is that the defect was in seven places rather than one.** The review found
the three number inputs. `consent_text` on the same tab had it in text form, and so did **all five**
`clearableText` fields on the Identity tab — `description`, `welcome_message`, `placeholder_text`,
`system_instruction`, `answer_style_instruction`. `clearableText` preprocesses blank-or-whitespace to
`null` (which is what `TrimStrings` then `ConvertEmptyStringsToNull` do server-side before any rule),
so a control writing the raw `''` leaves `'' !== null` against a stored null, and a type-and-delete
arms the guard the same way. A new `clearableFieldValue`
(`bot-model-shared.ts:277`) is that preprocess expression and nothing else — deliberately **not**
trimming, because trimming into form state moves the caret and eats a space the operator is still
typing after. **This is ADR-036's lesson in component form: a private copy of a shared helper is
where the fix fails to arrive**, and it is § M4 again one layer down — there a hand-copied *type*,
here a hand-copied *helper*.

**Deliberately not changed, and the distinction is the finding's edge.**
`bot-create-dialog.tsx`'s `description` is the same code shape and is **not** the same defect:
`botCreateDefaults()` seeds it as `''`, and that dialog reads no `isDirty` at all. Mapping blank to
`null` there would make form state disagree with the factory that seeded it, to fix nothing. The
comment beside the control now says that, so the next reader sweeping for this shape does not
"complete" the sweep by breaking it.

### N2 — the create-bot dialog reopened holding a slug the server had rejected, under a stale 422 *(CLOSED)*

```bash
grep -n 'DialogTrigger asChild\|function CreateBotForm' apps/web/src/features/bots/bot-create-dialog.tsx
grep -n 'form.reset' apps/web/src/features/bots/bot-create-dialog.tsx   # expect: nothing
```

`useForm` and `useMutation` sat in `CreateBotForOrganization`, a component that **never unmounts** —
only the `<Dialog>` subtree was conditionally rendered — and `form.reset()` ran in `onSuccess`
alone. There are five ways to close that dialog (Cancel, Escape, the overlay, the corner X, a
successful create) and the reset covered one. Submit a duplicate handle, press Escape, reopen: the
refused slug and its 422 banner were both still there, an error about a request the operator had not
just made. The component's own comment claimed *"the screen carries no form state … while it is
closed"*, which was false the whole time.

**The fix makes the comment true rather than adding four resets.** `<Dialog>` is now unconditional;
the form and its mutation live in a child rendered inside `<DialogContent>`, which Radix unmounts on
close; and `form.reset()` is **gone entirely**. Unmount covers all five paths, and a reset is a
second, weaker spelling of the same intention — the shape that needed one is precisely the shape that
forgot to call it. `queryClient` and the list query key stay in the parent on purpose, so
`onSettled` still fires for a request that was in flight when the dialog closed.

**The second-order gain is the reason this is not merely tidier.** Conditioning the `<Dialog>` root
had also removed the node Radix restores focus to and the node its exit animation plays on, so
**focus return and the close animation were both silently broken** — two behaviours the primitive is
there to provide, and the old comment credited it with providing. A `DialogTrigger asChild` supplies
the return target, and the regression test asserts it on `document.activeElement` after an
**Escape** — chosen because it is the close path with no handler of ours on it, and therefore the one
a per-path reset is likeliest to miss.

### N3 — `admin.bots.index` published none of its five query parameters, so a generated client could not reach page 2 *(CLOSED)*

```bash
grep -n 'ProvidesOpenApiQueryParameters' services/core-api/app/Console/Commands/DumpOpenApiCommand.php \
     services/core-api/app/Http/Requests/IndexBotsRequest.php
grep -n 'public static function openApiQueryParameters' services/core-api/app/Support/Http/ListQuery.php
```

`DumpOpenApiCommand::parameters()` derived its entire output from `$route->parameterNames()`, which
returns **URI placeholders and nothing else**, and hard-coded `'in' => 'path'`. It had no query
branch because it had never needed one: `IndexBotsRequest` is this API's **first and only**
query-string FormRequest. So the operation published `organization` and stopped, and a client
generated from the committed document got `listBots(organization)` with no way to ask for a second
page, choose a sort column, choose a direction, or pass a filter — against an endpoint that validates
and honours all five. **Functionality removed with nothing reported**, which is the drift direction
the contract suite exists to catch and the direction it is hardest to notice, because nothing fails.

**Why it was not fixed by parsing `rules()`, which is the obvious design.** Two independent reasons,
both recorded in the new interface's docblock so the next person does not re-propose it:

1. **Mechanical.** `packages/contracts/rules/UpdateBotRequest.json` and its siblings are dumps of
   `rules()` *after* Laravel stringified them, so a closed set arrives as the literal
   `in:"id","name",…` — embedded quotes and all, because `Rule::in()` quotes every member. Reading an
   `enum` back out means writing a parser for Laravel's rule serialization, complete with quoting,
   escaping and the comma inside a value, against a format nobody promised to keep stable.
2. **Substantive, and the stronger of the two.** The manifest **cannot express the facts a caller
   most needs**. A validation rule has no vocabulary for a default: `page` is "an integer at least 1"
   and says nothing about being 1 when absent, because the default is an argument to
   `ListQuery::fromValidated()` and is therefore the *endpoint's* decision, not the rule's. Same for
   `per_page` and `sort`. A document derived from the rules alone would publish three parameters
   whose absent behaviour is exactly the part a client has to guess.

The repair follows the two mechanisms already in this repository — `ProvidesOpenApiSchema` lets a
Resource describe the JSON it emits, `#[ResponseShape]` names the status codes — with a third:
`App\Support\Contracts\ProvidesOpenApiQueryParameters`, implemented by `IndexBotsRequest`, satisfied
by a generic `ListQuery::openApiQueryParameters()` at `ListQuery.php:145`. **Its placement is the
mechanism**: it sits directly beside `ListQuery::rules()` and reads every bound from the same
constant the rule reads, so the two descriptions of one contract cannot drift without a reviewer
seeing both. `DumpOpenApiCommand` gained one shared `formRequestClass()` for its two questions, so
"which FormRequest does this action take" is answered once rather than by two reflections that can
disagree.

**The diff is `+63/−0`.** `organization` is byte-identical and still first, which is load-bearing:
the committed document is compared byte for byte, so query parameters are appended and nothing above
them moves. `tests/Contract/OpenApiDocumentTest.php` asserts the published values against
`ListQuery`'s own constants and `IndexBotsRequest::SORTABLE` rather than against literals — a
hard-coded `100` in the test would agree with a document describing a ceiling the server does not
enforce.

**And the thing this fix does not close, recorded because it is now load-bearing on a human.**
Nothing verifies that the committed artifacts are current. `OpenApiDocumentTest.php:1349` already
carries the note: the *"keeps the committed document current"* test called
`kb:dump-openapi --check` at the default path, a CI step took the assertion over, and **the premise
became true again on 2026-08-17 when `.github/` was deleted**. The suite proves the generator is
deterministic and that `--check` *can* fail, against a temp path; it proves nothing about
`packages/contracts/openapi/core-api.openapi.json`. Both dumps here were re-run by hand and both
`--check`s are reported to exit 0 — unverified from `docs/`, which runs neither — and **the next
change to `rules()`, and the next implementer of `ProvidesOpenApiQueryParameters`, needs the same
manual step with nothing to remind them.** That is § *Removing CI/CD*'s "the committed OpenAPI
artifact unchecked again", now with one more producer feeding it.

### N4 — `UpdateBotRequest`'s `required_with`: the review's premise was wrong, and *that* is the finding *(CLOSED)*

**Recorded as a correction, not as a bug fix.** A record written from the review's wording would be
false, which is the § K1 and § M1 shape a third time: the reported defect was real in its
*conclusion* and wrong in its *mechanism*, and only measurement told them apart.

The review claimed `'provider_connection_id' => [… 'required_with:provider_model_id']` refused a
legitimate model-only PATCH. **It did not, and it could not have.** The four reachable bodies,
measured against the real `rules()` before anything was edited:

| body | old behaviour |
| --- | --- |
| `{"provider_model_id":"01J…"}` | **passes** — no 422, the shape the review said was refused |
| `{"provider_connection_id":null}` | **passes** the rule; the service refuses it |
| `{"provider_connection_id":null,"provider_model_id":null}` | **passes** |
| `{"provider_connection_id":null,"provider_model_id":"01J…"}` | **422** — the one shape the rule did decide |

The mechanism is `sometimes`, which sits before `required_with` in the array:

```bash
grep -n 'function passesOptionalCheck' -A 10 \
  services/core-api/vendor/laravel/framework/src/Illuminate/Validation/Validator.php
```

`passesOptionalCheck()` short-circuits **every remaining rule** for an absent key. So a body naming
only the model never reached `required_with` at all, and a body clearing only the connection left the
sibling absent and never reached it either. The rule therefore decided **one of four shapes,
redundantly** — `BotService::assertModelSelection()` already refuses that same pair on that same
field with a fuller message — and was **silent on the shape its own error message described**
(*"Clearing the connection while keeping the model…"*, which is the connection-only body against a
bot with a model stored).

**It was still removed**, because the design the file's own comment states is the right one: one
check on the **resulting** pair, in `BotService::assertModelSelection()`, reading the half the body
did not name off the stored row through `resolved()`. `UpdateBotRequest.php:170` is now
`['bail', 'sometimes', 'nullable', 'string', 'ulid']` and the `required_with` message is gone. **The
only behavioural change** is that the fourth shape now receives the service's fuller message instead
of a second, shorter one on the same field — two spellings of one refusal being how they drift.

`StoreBotRequest` **keeps** the rule, and the asymmetry is the difference between a POST and a PATCH
rather than an oversight: on a create the body *is* the resulting pair, so a declarative rule can
decide it. The Zod mirror had to move with it — `crossField` was shared by both bot schemas and is
now split into `crossFieldShared`, `crossFieldCreate` (which keeps `modelNeedsConnection`) and
`crossFieldSettings` (which does not). Mirroring it on the settings schema anyway would make
`@kb/contracts` refuse a body the server accepts, which the probe harness reports as *"form blocks
input the server accepts"*.

**The general rule, stated because it will recur on every PATCH surface this product grows:** *a
validation rule sitting behind `sometimes` cannot enforce anything about an absent key, so a
cross-field invariant on a PATCH has to be checked against the resulting state and not against the
body.* The evidence pair is the counter-example that shows the rule is not "never use `required_with`
on a PATCH": `evidence_threshold` and its scale are `required_with` each other on both verbs,
correctly, because neither half means anything without the other **whatever is stored**.

### N5 — audit rows describing edits that did not happen *(CLOSED)*

```bash
grep -n 'wasChanged' services/core-api/app/Repositories/Eloquent/EloquentBotRepository.php
grep -n 'changed === \[\]' services/core-api/app/Repositories/Eloquent/EloquentBotStarterQuestionRepository.php
```

`BotService::update()` wrote a `bot.updated` row unconditionally. A PATCH naming a field at its
current value passes the controller's empty-body guard, Eloquent finds the model clean and issues no
UPDATE — `save()` reaches `performUpdate()` only when the model is dirty — and an **append-only** row
then claimed an edit that never occurred. The trail was wrong in the one direction nobody audits it
in: not a missing row, an invented one. The codebase already refused exactly this on exactly this
ground in three places — `BotService::transition()`, `BotDomainService::changeStatus()`, and the
controller's own empty-body message.

**Why the fix is a gate and not a 422, which is the part that needed deciding.** `BotService::update()`
deliberately **excludes the bot's own row** from the slug-collision check, on the recorded ground that
*"a console that re-submits the whole form would report every save as a duplicate of itself"* — so a
whole-form resubmit is a **supported shape** here, and `movesRetrievalConfiguration()` makes the same
concession for a knob named at its stored value. Refusing the no-op would break the save button on an
unchanged form. The lifecycle siblings that *do* refuse theirs are a genuinely different case: there
the request names a **move**, and a move to the state you already hold is a caller who has misread the
row rather than a form being saved.

`EloquentBotRepository::update()` now gates the audit closure on `$bot->wasChanged()`
(`EloquentBotRepository.php:294`). **`wasChanged()` and not a pre-save `isDirty()`**, because the two
answer different questions and only one survives the version bump: `wasChanged()` reads `$changes`,
which `performUpdate()` syncs, so it also reports true for the
`retrieval_configuration_version` bump this method applies **itself** — correctly, since a moved
configuration version *is* an edit and it invalidates every cached answer for the bot. A pre-save
`isDirty()` would have missed it. `EloquentBotStarterQuestionRepository::update()` short-circuits on
`$changed === []` (`:140`), where both branches above it are value comparisons rather than presence
tests.

**A correction to the review here too.** It claimed that path stored `changed: ''`. **It did not.**
`AuditLogger::sanitize()` normalises with `mb_substr(trim($value), …)` and skips a value that was
already empty on arrival — silently, and that silence is relied on by name elsewhere (`capabilities`
on the provider-model operations is `implode(',', $flags)`, which legitimately produces `''`). So the
key was simply **absent**, and the row read as an ordinary edit rather than as a vacuous one. **The
defect was the row existing at all**, not its payload — and the payload being unremarkable is what
made it survive the phase's own reviews.

### N6 — the pager could print a row range past the total *(CLOSED)*

`apps/web/src/components/server-data-table.tsx` derived `last = first + rowsOnPage - 1`, mixing two
sources that do not move together. `pageIndex` and `pageSize` come from the **URL** and move
synchronously on a click; `rowsOnPage` is the row model's length, which under TanStack Query's
`placeholderData` is still the **previous page's** count until the fetch lands. Paging to a short
final page therefore rendered, for the duration of the request, a window describing rows the envelope
says do not exist — "126–150 of 137" on the surface whose entire job is to say how much there is.
`canNext` was derived from `last` and inherited the same skew, so it could offer a Next past the end
or, on a shrinking page, refuse one that exists.

```bash
grep -n 'Math.min(first + rowsOnPage\|const canNext' apps/web/src/components/server-data-table.tsx
```

`last` is now clamped to `rowCount` (`:514`) — the label may be a page behind but can never be
nonsense — and `canNext` is `(pageIndex + 1) * pageSize < rowCount` (`:516`), derived from the URL
and the envelope total **alone**, so the placeholder window cannot reach it. The regression test
renders a 25-row page-5 window at `page=6` against a 137-row total and asserts the string `150`
appears nowhere in the document, which is the assertion that would have failed before.

### N7 — the origin status select snapped back mid-request, and the fix deliberately did **not** make it optimistic *(CLOSED)*

**This is the one that upholds an existing decision under pressure, so the reasoning is the record
and the diff is the footnote.**

`OriginStatusSelect` is fully controlled on `row.status`, and the status mutation is
invalidate-and-re-read. So choosing "Active" re-rendered the trigger still reading "Pending" for the
whole PATCH **plus** the refetch — a control that visibly ignores the click, on the one screen where
the control decides whether a page on the internet may boot this widget.

The obvious repair is an optimistic update, and it was **explicitly rejected**:

```bash
grep -n 'optimistic flip would claim' apps/web/src/features/bots/bot-origins.tsx   # :176-177
grep -rn 'onMutate' apps/web/src apps/web/tests                                     # expect: nothing
```

`bot-origins.tsx:176-177` states it: an optimistic flip *"would claim one the server may have
refused, on the control that decides whether a page on the internet can boot this widget."* There are
**zero `onMutate` calls anywhere in `apps/web`**, and three other files record the same decision for
their own surfaces — `features/models/model-list.tsx:64` (*"an optimistic flip would claim a write
that may have been refused"*), `app/(admin)/sources/page.tsx:17` (deletion and disable are two-phase
and verified), and `features/bots/bot-starter-questions.tsx:82`. The one apparent counter-example is
not one: `features/chat/chat-surface.tsx:79`'s "optimistic user turn" is local component state
reconciled against the server's echo by `clientMessageId`, not a query-cache write.

**The fix shows in-flight *intent* with no cache write.** `changeStatus.variables` is react-query's
own record of the argument currently in flight, so the chosen status needs **no new state** that
could disagree with it, no cache write and no rollback path; the cache holds the server's row
throughout, and the instant the mutation settles every trigger falls back to `row.status`. Beside it:
a spinner (`motion-reduce:animate-none`), `aria-busy` on the trigger, and an `sr-only`
`role="status"` live region — because `aria-busy` is announced by nothing on its own and `disabled`
is announced as "unavailable" with no reason, so without the region the only feedback was visual.

**The regression test is the argument.** It holds the PATCH open, asserts the trigger reads the
chosen value and is busy, then **has the server refuse** — answering with the row unchanged — and
asserts the trigger settles back to "Pending". *That assertion is impossible to write under an
optimistic update*, which is the point: a client that had written the cache would now be claiming a
grant the server withheld and would need a rollback path to un-claim it. The test is in
`apps/web/tests/components/bot-publishing-panel.test.tsx`, because `BotOrigins` renders inside that
tab.

This also carries § L2's warning forward unchanged: the delete path still destroys a bot's origin
allow-list with no record of what it permitted, and nothing here touches that.

### Two of seven were wrong about the mechanism, and both were caught by measuring first

The review returned seven findings and **two of them misdescribed the mechanism** — N4 entirely, N5
in part. Neither would have been visible from the diff. N4's premise was refuted by reading
`passesOptionalCheck()` in the installed framework rather than reasoning about rule order; N5's by
reading `AuditLogger::sanitize()` rather than trusting the reported payload. In both cases the
*outcome* the review asked for was still right — remove the rule, stop writing the row — and the
*reason* was not, which is precisely the combination that survives a fix and then misleads the next
reader, because a record written from the review's wording would have been a confident false
statement about how Laravel validates and how this repository audits.

This file already has a convention for it. § *The rulings of 2026-08-12* records that **four of nine
rulings went a different way from the brief that asked for them, and in three the investigation
refuted the premise**, and says why that is written down: *"a ruling recorded only as its outcome
invites the same question next quarter."* § K1 and § M1 apply the same treatment to a reported
finding. **The same reasoning applies to a review finding, and this section is that convention being
extended to one**: a review is a hypothesis with a suggested fix attached, the fix can be right while
the hypothesis is wrong, and the cheap way to tell — measuring the claim against the installed
dependency before editing anything — is also the only way. Applying the review's wording without it
would have produced two entries here that read as authoritative and were false.

## The Phase C1 DDL decisions — ADR-062…065, 2026-08-20

Four decisions from **Phase C, step C1** — the step that lands the
`knowledge_sources → source_items → source_versions → document_elements / chunks` cascade as Laravel
migrations. Decision text, rejected options, costs and revisit conditions are in
[`docs/19`](19-repo-structure-adrs.md); this section records the *shape* the four have in common,
because it is the reason there are four of them rather than none.

**All four are the same kind of question: two sources inside this repository disagreed about a
column, and DDL cannot abstain.** Prose can carry a disagreement indefinitely — this file is largely a
record of that — but a `CREATE TABLE` picks one spelling and every reader downstream inherits it. So
the four were forced by the medium, not by anyone deciding to revisit the schema.

**The losing side was the same document in all four cases**, and naming it is the point:
`services/core-api/database/factories/KnowledgeSourceFactory.php`'s docblock, whose *"COLUMNS THIS
MUST PRODUCE"* list is a faithful transcription of `docs/11` §16.4 and has **never been executed by
anything**, because `definition()` throws. A column list that no statement has ever run against is
indistinguishable, on the page, from one that has — it is well-organized, it cites its sources, and it
was wrong in four places. **That is the finding underneath the four ADRs**, and it generalizes past
this factory: a scaffold that documents a contract it cannot exercise decays exactly like a count in
prose (ADR-036) and has no measuring command, because there is nothing to measure until the thing it
describes exists.

**Three of the four are the specification losing to an implementation contract written later.**
ADR-062 drops the `knowledge_sources` active-version pointer that §16.4 lists; ADR-063 adds an
`ocr_cfg_version` §16.4 does not have and keeps `activated_at`/`retired_at` over the factory's
`published_at`; ADR-064 keeps one 15-value vocabulary against the factory's seven-value rollup. Each
names its deviation in its own status line rather than editing an extract, per `docs/00-index.md`'s
rule. **ADR-065 goes the other way and is the one to read**: it overrules
`.claude/skills/postgresql-patterns/SKILL.md`, which is an accepted skill and is normally the binding
side — and it leaves that skill wrong in writing, which is § P1 below.

**Two of the four rest on a constraint rather than on a preference, and those are the ones a future
change is most likely to undo by accident.** ADR-062's single pointer and ADR-063's two timestamps are
not naming choices: they are the two terms of
`CREATE UNIQUE INDEX … WHERE activated_at IS NOT NULL AND retired_at IS NULL`, the index that makes
*at most one live version per item* provable by PostgreSQL instead of by ordering discipline inside a
Celery task that is delivered at least once. A schema that adopted `published_at`, or a sibling
pointer on `knowledge_sources`, would still pass every test in the suite on the day it landed.

**And C1 closed finding #79**, on the removal condition the 2026-08-12 ruling stated — see
§ *The rulings of 2026-08-12*, **G2** and the closing note appended to it. The close required no
re-litigation and adopted neither of the two repairs that ruling rejected.

## Found while landing the source cascade — P1–P3, 2026-08-20

**Why `P`.** `O1`–`O27` is § *Open after scaffolding*, so the letter after `N` was taken. This file
has picked a letter around a collision before — § L1's preamble records `L` being chosen because
`S1`–`S17` already meant the second scaffolding audit round, after an ADR amendment citing *"finding
S2"* landed on the wrong one. `P` is the first free letter.

**All three are owed work in trees `docs/` does not own, and none of them is fixed here.** That is the
common shape rather than a coincidence: a schema landing for the first time makes three documents
about the absence of that schema go false at once, and every one of them lives behind a boundary this
agent does not cross.

### P1 — `postgresql-patterns` line 52 specifies `bytea` for `content_hash`, and ADR-065 overrules it *(OPEN — owed by the skill's owner)*

```bash
grep -n 'content_hash' .claude/skills/postgresql-patterns/SKILL.md
```

The line reads `content_hash bytea NOT NULL, -- 32 raw bytes, not 64 hex chars`, inside the runnable
DDL that is the authority for every other column on `source_versions`. ADR-065 rejects it in favour of
`char(64) COLLATE "C"` hex, for four reasons measured in this tree — the Python emits hex
(`chunker.py:963`), the ingest key composes `content_hash` as a `str` and guards it with an `in`
test that raises `TypeError` against `bytes`, § **J3** records two silent `BinaryCast` defects on
`bytea` columns in this repository, and the same `CREATE TABLE` already stores a sha256 as
`ingest_key char(64) COLLATE "C"` four lines below.

**Why this is a finding and not just an ADR.** The skill is *right by default* here — it is the
binding source for the other three decisions in the same step — so a reader consulting it for the next
migration or the next Eloquent cast will write `bytea` and be following the correct procedure. The
disagreement is therefore live and load-bearing until the skill moves, and the failure it produces is
the silent kind: a `bin2hex` applied to a value that is already hex returns a valid 128-character
string that hashes to a well-formed ingest key for an identity that does not exist, so the version
never dedupes against its own completed run and nothing raises.

**Owner:** whoever owns `postgresql-patterns`. **Not fixed here**, and the boundary is the reason:
this agent does not edit `.claude/skills/`, because documentation drifting from a skill is bad and
documentation silently rewriting one is worse. § **J7** is the closed precedent for how an item of
this shape ends — a skill's worked example refusing what ADR-047 permits, recorded here as a
divergence and then corrected **by the skill's owner**, not by the document that found it. This is the
second such divergence in the register and the first one still open, so `postgresql-patterns` and
`docs/19` disagree in writing until it closes; the migrations follow ADR-065 meanwhile.

### P2 — `app/deletion/relational.py`'s docblock says no migration creates `chunks` or `document_elements` *(OPEN — owed by `deletion-engineer`)*

```bash
sed -n '188,197p' services/ai-service/app/deletion/relational.py
```

`RELATIONAL_PURGE_ORDER`'s preamble states that *"**no migration in this repository creates either
table**, so their column names are unverified against a schema in a way the two sparse entries are
not."* Both halves went false with C1. The second half is the useful one and is the reason this is
worth a row: the two entries' column names — `organization_id`, `source_version_id` — can be checked
against a real migration for the first time, and the docblock currently tells a reader not to bother.
It is the same claim-shape the 2026-08-17 sweep chased across five trees (§ **M6**): a comment that
was true when written, describing an absence, with nothing watching for the absence ending.

### P3 — nothing compares the status CHECK constraints to `SourceState` *(OPEN — owed by `test-engineer`)*

ADR-064 puts one vocabulary on three columns. The Python half asserts its own membership at import
(`grep -n '^assert len(SourceState)' services/ai-service/app/ingestion/states.py`); the SQL half
asserts its own inside each `CHECK`; **the two are joined by nothing.** A state added to the enum and
to two of the three constraints is a row that saves in one table and fails in another, discovered at
the ingestion status callback rather than in a suite.

The test is small and crosses both runtimes — read `SourceState`, read the constraint text out of
`information_schema.check_constraints`, compare as **sets** in both directions — and it is named here
rather than left implied because ADR-064's revisit condition (`len(SourceState)` changing) is exactly
the moment it is either written or missed. **This is not a defect today**: the three constraints and
the enum agree as C1 lands. It is a missing tripwire on an invariant whose violation is silent, which
is the category this file exists for.
