# Unverified claims

Every `<!-- UNVERIFIED -->` marker in the skill library, collected so unconfirmed
claims are visible rather than buried in the file that makes them.

**75 markers across 75 lines in 47 files**, re-swept 2026-08-10 and **re-measured
unchanged on 2026-08-11** — all three commands below returned the same figures, and
the whole-`.claude/` variant still returns 78. Treat that as a measurement with a
timestamp, not as a standing fact: a skill-library pass was in flight when it was
taken, and `.claude/` is not this file's to hold still. **No line carries
two any more** — `celery-workers/SKILL.md` lost its CUDA/fork marker with ADR-030,
and the surviving reload-cost marker moved to `:153`. Regenerate with a grep over
`.claude/skills/` after any wave of edits; the per-file list at the foot of this
file has **not** been regenerated and its line numbers and per-file counts are
stale wherever ADR-030 rewrote a skill (see the delta note in § *Raised by ADR-030*,
which predicted exactly this figure).

```bash
grep -rn  UNVERIFIED .claude/skills/ --include=*.md | wc -l   # 75 lines
grep -rno UNVERIFIED .claude/skills/ --include=*.md | wc -l   # 75 markers
grep -rn  UNVERIFIED .claude/skills/ --include=*.md | cut -d: -f1 | sort -u | wc -l   # 47 files
```

The previous figure was **81 / 80 / 47**.

**Sweep definition.** `grep -rn UNVERIFIED .claude/skills/` — `SKILL.md` **and**
every `references/*.md`, since several skills were split into sibling reference
files to meet the 200-line budget and a `SKILL.md`-only grep now misses seven
files carrying eleven markers. Three spellings exist and all three count, re-measured
2026-08-10: the colon form `UNVERIFIED: …` (**71**) plus a bare `<!-- UNVERIFIED -->`
with the claim in the prose above or beside it (**4** — was 5) make the 75. **One of
the 71 is not an HTML comment at all** — the inline parenthetical
`(UNVERIFIED: …)` inside a `//` code comment
(`tanstack-query-table/references/sources-table-example.md:53`), which an earlier
sweep missed entirely and which a `<!--`-anchored pattern would miss
again. No `// UNVERIFIED` or `# UNVERIFIED`
line-comment form currently exists in the tree; grep for it anyway, because
nothing stops one appearing in a Python or YAML block.

**Three mentions are excluded** because they are prose *about* the convention,
not claims marked by it: `.claude/SKILL-TEMPLATE.md:79` (the instruction to
append the marker), and `.claude/agents/provider-adapter-engineer.md:45` and
`:56` (telling that agent to mark and to report unverified vendor behaviour). A
whole-`.claude/` grep returns **78/78/49** and those three are the difference.
*(Was 84/83/50 against the previous sweep.)*

**Delta from the previous count of 84 markers / 83 lines / 48 files.** Three
markers were retired by fixes, not by deletion of the claim:

- `github-actions-pipeline/SKILL.md:77` (**V**, in a skill deleted 2026-08-17) — "qdrant/qdrant image contents
  not re-checked this revision" went away with the CI image bump to
  `qdrant/qdrant:v1.18.3` / `valkey/valkey:9.1.1`, which replaced the guess with
  a pin owned by `docker-compose-stack`. That file now carries **no** markers,
  which is the whole file-count change (47, not 48).
- `kb-architecture-map/SKILL.md:165` (**P**) — "end-to-end propagation of a PHP
  client abort into cancellation of the upstream provider stream is untested"
  went away with audit finding A6: the prescribed control-plane cancel endpoint
  was removed in favour of the propagated-disconnect chain the three implementer
  skills already build, so there is no longer a claim about an endpoint that does
  not exist.
- `tanstack-query-table/SKILL.md:23` (**!**) — the `retry-after`-versus-`KbError`
  contradiction was resolved by defining one `KbError` in `packages/contracts`
  with five snake_case fields including `retry_after`. It was a cross-skill
  defect, and it is now closed rather than parked.

Offsetting them, `celery-workers/SKILL.md:149` gained a second marker
(CUDA/fork interaction against the pinned torch build, **V**), so the class totals
move by only one net in **P** and one in **!**.

Two files changed identity without changing their marker count, because the
content moved into a reference sibling: `prometheus-grafana-loki-tempo/SKILL.md`
→ `references/retention-and-correlation.md` (2), and
`tanstack-query-table/SKILL.md` → `references/sources-table-example.md` (1).

A marker is not a defect. It means the author wrote the claim from knowledge and
could not confirm it against an authoritative source — which is exactly what we
asked them to do rather than assert it confidently or drop it. What matters is
*why* it could not be confirmed, because that decides who closes it and when.

## Classes

**The per-class counts below sum to 81 and are therefore stale by the same six markers
the header records.** They are not re-derived here on purpose: re-triaging six retired
markers into their classes is a judgement call per marker, and a table of guessed
counts is worse than one that says it is behind. Deleting the six was almost entirely
ADR-030's doing (`bge-reranker` went from four markers to zero when the local
cross-encoder left), so the shrinkage is concentrated in **M**. Re-derive the whole
table in the same pass that regenerates the per-file list.

| Class | Count | What it means | Closes when |
|---|---|---|---|
| **M** — Measure | 20 | A number extrapolated, estimated, or taken from someone else's benchmark. | The system runs and you measure it. |
| **S** — Source not read | 17 | An authoritative source exists; the author ran out of budget before reading it. | Someone reads it. **Cheapest class to close — start here.** |
| **V** — Version re-check | 15 | Confirmed at `master`, or at a version near ours. | Confirmed against the version we actually pin. |
| **P** — Vendor silence | 15 | No authoritative source exists to read. Inferred from SDK defaults, omission, or source. | Never, by reading. Only by probing — and it can change without notice. |
| **X** — Deliberate doctrine | 5 | Synthesized from practice; no citation is claimed. | It does not. Correctly labelled, not a debt. |
| **D** — Needs a decision | 5 | The spec is silent and someone must choose. | An ADR lands. Also tracked in `docs/22`. |
| **W** — Announced, not shipped | 2 | The vendor has said it is coming. | The vendor ships it. |
| **!** — Escalate | 2 | **Not an unverified claim.** See below. |  |

The **P** class is the one to understand. Fifteen claims cannot be verified by
reading, because the vendor has published nothing — NVIDIA documents no auth
header, no rate-limit schema, and no credit-exhaustion response; DeepSeek
publishes no rate-limit headers and no request ID. Waiting for documentation is
not a plan. These close by asserting the behaviour in a live fixture at
implementation time, and they can regress silently whenever the vendor changes
something, so the adapter must fail loudly rather than assume.

**X** is the opposite and needs no action: five places where the author
deliberately wrote doctrine with no citation and said so — the upload sandbox
parameters, the SVG handling rules, the Opengrep ruleset licence reading. Labelling
them was the honest move.

## Escalate — these two are not unverified claims

They were parked in `UNVERIFIED` notation but are cross-skill defects that need
resolving before the code they describe is written:

1. **`openai-api/SKILL.md:166`** — OpenAI returns `RateLimitError` for both a rate
   limit and an exhausted billing account. The adapter wants to map the billing
   case to `provider_auth`, but `kb-error-taxonomy` defines that class as
   *"provider rejected our credential"*, which is narrower than a billing state.
   Left as-is, an exhausted account is retried and falls back, and the org is
   never told. **Owner: the taxonomy.** *Partly answered since this was written:*
   `kb-error-taxonomy` now carries an eighteenth class, `provider_billing`
   (fallback `no`, never counted toward the breaker, footnote 2), and the adapter
   marker survives only because the OpenAI side still has to map **429
   `insufficient_quota`** into it rather than into `provider_rate_limit`. Close it
   when `openai_adapter.py` exists and the mapping is asserted.

   **Half met, measured 2026-08-11, and the missing half is the half that closes
   it.** `services/ai-service/app/providers/openai_adapter.py` **exists**, and the
   mapping is written: `BODY_CODE_TO_CLASS` at `:250` carries
   `"insufficient_quota": ErrorClass.PROVIDER_BILLING`, the table at `:243`
   documents the 429 split, and `:364` records that the split is decided on the
   body code rather than on the status. But
   `/usr/bin/grep -rn insufficient_quota services/ai-service/tests/` returns
   **nothing** — no test exercises the mapping, and there is no
   `test_openai_*.py` in `tests/unit/`. So the item stays **open**, and it is
   worth being precise about why rather than rounding up to closed: the whole
   original finding is that OpenAI returns one exception type for two different
   states, and a mapping table asserting that in a dict is exactly the artifact a
   refactor rewrites without noticing. The close condition was written as *"and
   the mapping is asserted"* for that reason. **Owner:**
   `provider-adapter-engineer`, with `test-engineer` for the assertion.
2. **`tanstack-query-table/references/sources-table-example.md:53`** — the admin
   needs the response to echo which org Laravel scoped the request to, so a stale
   org context is detectable. No spec field provides it. **This is an API gap for
   `control-plane-engineer`, not a documentation problem.** (Was
   `tanstack-query-table/SKILL.md:123` before the file split.)

~~3. **`tanstack-query-table/SKILL.md:23`** — its retry predicate expects a
`retry-after`, while `nextjs-app-router` constructs `KbError(error_class,
retryable)` with no such field.~~ **Resolved.** `KbError` is now defined exactly
once, in `packages/contracts`, with five snake_case fields — `error_class`,
`retryable`, `retry_after` (seconds, parsed from the `Retry-After` *response
header*, not the JSON envelope), `request_id`, `message` — and both skills'
Definition-of-done lines grep for a second declaration and for camelCase
spellings. Recorded here rather than deleted: the finding was real, and the
`instanceof`-across-a-forked-class failure it predicted is now a named gotcha in
both files.

## Raised by scaffolding — not markers in the tree

Five claims surfaced while the skeleton was built. They are **not** `UNVERIFIED` markers and do not
move the 75/75/47 counts above: three are claims the *specification* makes and nobody has measured, and
two are claims made about a config file's behaviour. They are recorded here because that is
where unconfirmed claims belong regardless of which document made them, and because the sweep grep over
`.claude/skills/` will never find them.

If any of these is fixed by adding a marker to the owning skill, delete it from this section and let the
sweep count it — do not leave it in both places.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **Traefik's static config file may not interpolate `${VAR}` at all.** `traefik-routing` writes `email: "${ACME_EMAIL}"` into `traefik.yaml`. Compose interpolates the **compose file**, not a file mounted into a container — so unless Traefik v3.7 templates its own static config, that value reaches ACME as a literal 13-character string. The failure is not a startup error: registration fails or registers a nonsense contact, and the first signal is a certificate that never renews. | **S** | `traefik-routing` | Read the v3.7 static-configuration docs. If it does not template, the value must come from `TRAEFIK_CERTIFICATESRESOLVERS_…_ACME_EMAIL` as an environment variable, which Compose *does* interpolate. |
| **The 1.5 s retrieval budget holds on the GPU profile only.** Rerank at 25 pairs is ~0.1–0.4 s on an A10-class card and **seconds on CPU fp32**. §23 states the budget unconditionally. A CPU-only deployment — which is the default self-hosted shape — either runs int8 at a measured lower candidate depth or turns reranking off and takes the degraded branch-agreement path. | **M** | `bge-reranker`, `kb-rag-query-contract` | Measure both profiles with `kb_rerank_duration_seconds`, then state the budget per profile instead of once. Until then the CPU path has a latency target it may not be able to meet. |
| **Per-stage latency budgets do not exist in the specification.** §23 gives three aggregates only — 0.25 s for rewriting, a 1.5 s retrieval leg, 4 s to first token. Every intra-leg allocation quoted in the skills is estimated from those three, not measured. | **M** | `kb-rag-query-contract` | Re-derive from measured p99.9 per stage once the pipeline runs. Same class, and the same caveat, as `kb-error-taxonomy`'s nine timeout defaults. |
| ~~**The `brianium/paratest` constraint compatible with PHPUnit 13 is a guess.**~~ **CLOSED 2026-08-10 — the resolver ran.** It was written from the version graph, not from a resolver run. Until `composer.lock` existed, `composer.json`'s constraint was unproven — and it is on the parallel-test path, so a wrong constraint fails the CI test job rather than the build. `composer.lock` is now committed with `brianium/paratest` resolved, and the constraint was **corrected in the process**: `^7.23.1`, not the earlier `^9.0` placeholder, which had assumed paratest's major tracked PHPUnit's. It does not — paratest has no 8.x or 9.x, and Pest v5.0.3 requires exactly `^7.23.1`. Left in this table rather than deleted: the guess was wrong in a way that would have failed only the CI test job, which is the outcome the row predicted. | **V** | `pest-testing` | *Closed.* |
| **A per-workspace `node-linker=hoisted` scopes to that workspace and nothing else.** `apps/mobile/metro.config.js` documents the escape hatch it deliberately does *not* take, and asserts that if it is ever taken it belongs in `apps/mobile/.npmrc` rather than the root, because the root's `shared-workspace-lockfile=false` already gives `apps/mobile` its own `pnpm-lock.yaml`. Plausible — pnpm resolves `.npmrc` per directory — but ~~**no install has ever run in this repository**~~ **(false since 2026-08-10: installs have run. Six `pnpm-lock.yaml` files are committed and `node_modules/` exists at the root and in `apps/{web,widget,mobile}` and `packages/contracts`. The premise this row rested on is gone; the claim itself is still unverified, because `node-linker=hoisted` has never actually been set anywhere, so the scoping behaviour remains unobserved. The row's cost changed: it is now one experiment away rather than blocked, and the closing procedure below is executable today.)** nobody has observed the resulting `node_modules` layout under a hoisted linker, and the claim's whole value is the "and nothing else". If it is wrong, hoisting for Metro's benefit silently relaxes resolution for `apps/web`, `apps/widget` or `packages/*` too, and the symptom is a phantom dependency that resolves in development and is absent from a published artifact. | **S** | `expo-react-native` / `mobile-engineer` | Read pnpm's `.npmrc` resolution and `node-linker` docs against the pinned pnpm 10, then confirm by running the install and diffing the four workspaces' `node_modules` layouts. The file's own suggested tripwire — `grep -rn --include='.npmrc' 'node-linker' . --exclude-dir=node_modules` returning exactly one documented line — is a check that the setting is *scoped and explained*, not evidence that scoping works. Nothing runs it yet. |

## Raised by ADR-030 — not markers in the tree

ADR-030 (no local model inference for embedding or reranking — `docs/19` §28, argument in `docs/22`)
replaced two locally-pinned models with **five vendors' product catalogues**, and a product catalogue is
not a thing you can verify by reading our repository. Everything below is a claim this project now
depends on and nobody has tested against a live provider. Like the section above, these are **not**
`UNVERIFIED` markers and do not move the 75/75/47 counts.

The class that dominates here is **P** — vendor silence — and for a new reason worth naming: with a
pinned local model, a claim about it was wrong or right *permanently*. A claim about a hosted model can
be **true when written and false three weeks later, with no signal**. These do not close once; they need
a fixture that re-asserts them.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **`CANARY_PRECISION = 4` absorbs a provider's fleet noise without absorbing a re-trained model.** *(The owning skill has since gained a marker for this — `bge-m3-embeddings/SKILL.md:97`, "never measured…". By this file's own rule that means this row is **retired at the next sweep regeneration** and the marker is counted instead; it is kept here until then because the counts at the top of this file are themselves stale and the sweep has not been re-run.)* `app/ingestion/embedding/embedder.py:143` rounds each probe vector to four decimals before hashing, on the reasoning that embedding APIs are not bit-reproducible across their own accelerators, batch shapes and kernel versions. **Both halves are unmeasured.** Too fine and the digest flaps on the vendor's deploys, re-versioning the whole corpus for nothing — and because the digest is folded into `embedding_model_version` and therefore into the **ingest key**, a flap does not merely alarm: it makes every resubmission a new source version. Too coarse and a genuine re-quantization lands inside the rounding and is never detected, which is the exact failure C3 exists to catch. The file says so itself, in the constant's own docstring. | **M** | `ingestion-engineer` | Run the five-probe set hourly for a day against **each** configured embedding provider and confirm the digest is constant. If it flaps at 4, the finding is not "raise the precision" — it is that **the digest belongs in telemetry only and must come out of the ingest key**, with drift raised as an alert a human reads rather than as an identity change a worker acts on. |
| **The per-provider rerank-endpoint availability matrix.** ***Still true about the vendors, and now incomplete about us — read the note under this table before citing it.*** The whole of C1 rests on it: *NVIDIA NIM exposes a ranking endpoint; OpenRouter reaches one only through specific models; OpenAI, Anthropic and DeepSeek do not offer one.* `app/providers/contract.py` states the count structurally — "only two of the five vendors can embed and only two can rerank" — and **names neither pair**, so the matrix exists in prose in `app/rag/rerank.py` and in `docs/22` § C1 and nowhere as an asserted fact. No adapter implements `embed` or `rerank` yet, so nothing has ever called any of these endpoints. A vendor adding one silently makes the matrix *pessimistic* (a stage skipped that could have run); a vendor withdrawing one silently makes it *optimistic*, which is a 404 on the request path. | **P** | `provider-adapter-engineer` | Assert it per adapter in a live fixture at implementation time, and make the absence of the method — which is already the capability gate `RerankAdapter` enforces — the thing the fixture checks. It never closes permanently; treat a catalogue change as a contract change. |
| **The per-provider embedding availability matrix, and the same argument.** "Three of the five vendors have no embedding endpoint and never will have one on our schedule" (`app/providers/contract.py`, `EmbeddingAdapter`). This one is load-bearing in a way the rerank matrix is not: a bot on a provider that cannot embed **cannot ingest at all**, so this is a connection-save-time validity question, not a degradation. | **P** | `provider-adapter-engineer` | Same fixture. Additionally: decide whether an organization may configure a chat provider that cannot embed, and if so which connection supplies the embedding credential — that half is a control-plane question and is not recorded as decided anywhere. |
| **Embedding context windows, and the vendor truncation defaults behind `PROVIDER_TRUNCATION_POLICY`.** `embedder.py` states that `text-embedding-3-large` accepts 8192 tokens and errors above it, that "plenty of API embedding models accept 512", and that "at least one" vendor defaults a `truncate`/`truncation` parameter to trimming the end. The design depends on the third claim: `check_window` and the `"reject"` policy exist to force a 400 rather than accept a silently shortened passage. **No vendor is named for the third claim anywhere in the tree.** | **P** | `provider-adapter-engineer` / `ingestion-engineer` | Per vendor, send a deliberately over-window passage and assert a 4xx. A 200 with a plausible vector is the failure, and it is indistinguishable from success without this test — which is the whole reason the policy is a constant and not a default. |
| **Qdrant ≥ 1.19.0 offers `SearchParams(idf=IdfCorpusParams(corpus=…))` for per-tenant IDF.** `app/retrieval/collection.py:149-151` rests C2's option (b) on it: we are pinned at **1.18.3** (ADR-021), where the parameter does not exist, so collection-wide IDF across every tenant is currently the only IDF available. Written from release notes, not from a running 1.19.x. | **S** | `retrieval-engineer` | Read the 1.19 release notes and the client's `SearchParams` signature. If the parameter is not what the comment describes, C2's BM25 option loses one of its three answers and the per-organization query-side IDF becomes the only tenant-safe route. |
| **Vector-width figures used to argue that a model change is a reindex.** 1024 for bge-m3 and **3072** for `text-embedding-3-large` are quoted in `docs/22` § C3 and in `collection.py`'s quantization note (which additionally reasons that 1-bit compression loses significant precision below roughly a thousand dimensions, so a 3072-dim space clears it and a 1024-dim one sits on the boundary). Both widths are taken from vendor documentation; neither has been read off a response here, and a `dimensions` request parameter can change either. | **V** | `retrieval-engineer` | The bootstrap probe in `ensure_collection` already does this: embed one short string and assert the length equals `space.dimensions`. The claim closes the first time that runs against each configured model. |

**The rerank matrix now has a third axis, and stating it as two makes it read as a schedule when it is
a refusal.** Both rerank rows in this file — the one above and the `INVERTED — CLOSED` row in the next
section — say *"NIM and OpenRouter `SUPPORTED`"*. That is **true about the vendors** and **incomplete
about this platform**. `app/providers/capabilities.py:637` states the axes in as many words:

> **Three axes, not two, and the third is finding #47.** The vendor must publish the endpoint, the row
> must claim it, *and* the provider's score scale must be one this pipeline can threshold.

**OpenRouter passes the first two and fails the third**, and the answer landed on is **"structurally
ineligible", not "not yet"**. `RerankCalibration.__post_init__` (`app/rag/rerank.py:219-225`) raises on
any scale whose `may_threshold` is `False`; `RERANK_SCALE` deliberately has no `openrouter` entry, so
`rerank_scale("openrouter")` is `UNCALIBRATED`; stage 11 requires a calibration and stage 12 asserts
against it. **A calibration for OpenRouter is therefore unconstructible, not missing** — there is no
evaluation run, no budget and no amount of waiting that produces one, because the scale is a property
of the *upstream* cross-encoder and several of those sit behind one credential with no documented
normalization (that is **#108**, and it is a contract change: re-keying `RERANK_SCALE` on
`(provider, model)` with the upstream pinned by `provider.order` + `allow_fallbacks: false`).

**Two consequences for anyone reading these rows.** First, the vendor cell stays `SUPPORTED` on
purpose — OpenRouter *does* publish `/api/v1/rerank`, and writing `UNSUPPORTED` there would record a
denial nobody made and would put a false statement about a vendor into a matrix whose entire value is
that every cell cites a page somebody read. The eligibility question is a fact about **us**. Second,
the fixture these rows ask for closes the **vendor** axis only. It cannot close the third, and a
green fixture against OpenRouter's rerank endpoint would be evidence that the platform can call it —
not that the platform can use the answer. **Owner of the third axis:** `retrieval-engineer`, with
`rag-eval-engineer` for any calibration; it closes for a provider the day an evaluation run over the
golden corpus yields a `(provider, model)` threshold with a recorded `derived_from`.

*One honest imprecision was recorded in the code rather than papered over, and is now **closed**:*
when `can_rerank` returned `False` for an uncharacterized scale, `rerank_gate` reported
`PROVIDER_LACKS_CAPABILITY` — and the provider does have the endpoint. The accurate reason is the
fifth `RerankSkipReason` member, `PROVIDER_SCALE_UNCALIBRATED`, added with its entry in the closed
metric label set. `rerank_gate` now takes all three eligibility axes rather than the AND alone,
because a single boolean cannot say *why* it is `False` and the two whys have opposite remedies.
**What is not closed is the eligibility itself:** OpenRouter is still ineligible, still for the reason
above, and this member reports that state accurately rather than removing it.

**One row in the section above is now partly void, and is left standing rather than edited.** *"The
1.5 s retrieval budget holds on the GPU profile only"* was written about a local cross-encoder on CPU
fp32. There is no GPU profile any more and no local reranker — but the budget is **not** thereby met:
it is now a **network** budget covering a query-embedding round trip plus one or more ranking round
trips, capped by each vendor's per-request passage limit, and an unbatched loop over 25 candidates is
25 sequential HTTP calls inside a 1.5 s leg (`app/rag/rerank.py`). Same claim, same **M** class,
entirely different measurement — and the owner moves from `bge-reranker` to `provider-adapter-engineer`
and `retrieval-engineer` together.

**The counts at the top of this file are stale, and ADR-030 is what made them stale.** Measured against
the tree at the time of writing, `grep -rn UNVERIFIED .claude/skills/ --include=*.md` returns **75**,
not 81. The two files the per-file list below records for this area have already been rewritten by
their owners against ADR-030: **`bge-m3-embeddings/SKILL.md` now carries 2 markers, not 3** (both new —
which measurement supplies the token estimate, and `CANARY_PRECISION` never having been measured), and
**`bge-reranker/SKILL.md` carries 0, not 4** — its four claims measured a local cross-encoder's latency
on hardware this project no longer requires, and they went with the model rather than being parked.
Their entries in the per-file list are therefore wrong at the recorded line numbers. **Regenerate the
sweep**; the delta is recorded here rather than applied piecemeal, because a partial re-count is worse
than a stale one that says so.

## Raised by ADR-031…035 — not markers in the tree

Closing C1's embedding half, C2 and C3's mechanism ([`docs/22`](22-spec-findings-and-decisions.md)
§ *The decisions ADR-030's consequences forced*) turned several implicit assumptions into constants that
ranking now depends on. Like the two sections above, these are **not** `UNVERIFIED` markers and do not
move the 75/75/47 counts.

**Three of them share a property that is new here and worth naming: a wrong value is not merely
suboptimal, it is a reindex.** `SPARSE_ANALYZER_VERSION` is part of the collection name (ADR-034) and
every BM25 constant below is an input to it, so correcting one after the first large ingest re-embeds
the **dense** vectors too — at a provider's per-token price. These want measuring *before* a corpus
exists, which inverts the usual order.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **`BM25_AVGDL = 256.0` is close enough to the real average chunk length that fixing it costs little relevance.** `app/retrieval/sparse.py` pins it rather than measuring a live corpus average, for a good reason (a measured `avgdl` makes every document vector a function of the corpus at write time and breaks ADR-010 rebuildability). The *approximation* is then defended on the grounds that "chunks are already size-bounded by the chunker, so the length spread BM25's normalization exists to correct is small". **That second claim is unmeasured**, and it is the one carrying the relevance. If real chunk lengths cluster far from 256 analyzed terms, length normalization pushes uniformly in one direction for the whole corpus. | **M** | `retrieval-engineer` with `ingestion-engineer` | Analyze the golden corpus with the chosen tokenizer, take the mean analyzed length, and compare. If it is far off, change the constant **before** the first large ingest — afterwards it is ADR-034's reindex. |
| **`BM25_K1 = 1.2` and `BM25_B = 0.75` are the right starting points here.** They are what every mainstream implementation ships, which is a real argument and not a measurement; the file says so ("a starting point to be moved by an evaluation run, never by intuition"). | **M** | `rag-eval-engineer` with `retrieval-engineer` | An evaluation run over the golden corpus, with the same reindex caveat. |
| **`MAX_QUERY_TERMS = 64`, and dropping the most-repeated terms first, sheds the least discrimination.** A default chosen in the module rather than read from the query contract. The ordering argument — that in a long pasted query the most-repeated terms are the least discriminating — is plausible and untested, and unlike the constants above this one is query-side, so it is **not** in the analyzer identity and can be changed without a reindex. | **M** | `retrieval-engineer` | Evaluation run over long-query cases. Cheap to change; measure it anyway, because the failure is silent truncation of the lexical branch. |
| **32-bit term-id collisions are "a mild relevance error at realistic vocabulary sizes".** `term_id` hashes into `2**32` because Qdrant sparse indices are unsigned 32-bit. The tenancy half of the claim is sound and structural — a collision cannot move a point across the payload filter. The *relevance* half is a birthday-bound assertion with no vocabulary size behind it. | **M** | `retrieval-engineer` | Count distinct analyzed terms across the golden corpus once the tokenizer exists, and compute the expected collision count. It is arithmetic, and it can be done the same afternoon the tokenizer lands. |
| **INVERTED — CLOSED 2026-08-10, and it resolved the opposite way.** `grep` the matrix: **nothing is `UNVERIFIED` any more.** All fifteen cells are `SUPPORTED` or `UNSUPPORTED`, each with a `source=` naming a documentation page that was read (`capabilities.py:46` says so in as many words, and `TaskSupport.__post_init__` now *refuses* a cited `UNVERIFIED` cell). Embedding: OpenAI, NVIDIA NIM and OpenRouter `SUPPORTED`; Anthropic and DeepSeek `UNSUPPORTED`. Rerank: NIM and OpenRouter `SUPPORTED`; the other three `UNSUPPORTED` — **and that rerank pair is the vendor axis only; OpenRouter is structurally ineligible on the third axis, which is neither `UNVERIFIED` nor `UNSUPPORTED`. See the three-axis note at the end of the ADR-030 section above before citing this cell.** *(Re-verified 2026-08-11: `capabilities.py:46` still says "Nothing is `UNVERIFIED` any more" in as many words, and `/usr/bin/grep -c UNVERIFIED` over that file returns **10** occurrences of which **none is a cell** — they are prose, the enum member's own docstring, and the `_UNKNOWN` fallback for a provider that is not one of the five. Line numbers in that file have moved since this row was written and this one has not; the third-axis note cites `:637`, where the brief that commissioned it said `:634`, which is the `def` line.)* **Consequence for an accepted ADR:** [ADR-031](19-repo-structure-adrs.md#adr-031-the-organization-designates-the-embedding-connection-disagreement-is-a-refusal) § *Trade-off* still says "four of the five embedding cells are `UNVERIFIED` and fail closed". That sentence is false against the tree and is recorded — not edited — as `docs/22` **F11**. **The row's specific prediction was wrong in the cheap direction, not the expensive one** — it warned that NIM's embedding cell would be left failing closed and deny a tenant a connection that works; NIM embedding resolved `SUPPORTED` with `docs.api.nvidia.com/nim/reference/nvidia-nv-embedqa-e5-v5-infer` cited. Kept, not deleted: the row named the right cell for the right reason, and the four-documentation-pages estimate was accurate. ~~**Four of the five embedding cells in `capabilities.PROVIDER_TASKS` are `UNVERIFIED`, and one of them is probably wrong in the expensive direction.**~~ Under ADR-031 an `UNVERIFIED` cell fails closed, and for embedding "closed" means **the organization cannot ingest at all**. The matrix's own note on NIM records the tension: `bge-reranker`'s official-docs entry is titled *"NVIDIA NIM retrieval / ranking models"* and *retrieval* is NVIDIA's word for its embedding family, but no skill states it, and `contract.py` and `errors.py` use `nvidia/nv-embedqa-e5-v5` as a width-coincidence example without citing a source. A link title is not enough to write an adapter method against — and it is also not enough to refuse a tenant's ingestion on. | **S** | `provider-adapter-engineer` | Read each vendor's API reference for an embeddings route, then either move the cell to `SUPPORTED` with the source recorded or to `UNSUPPORTED` with the denial recorded. This is the cheapest high-value row in this file: it is four documentation pages, and the cost of leaving it is a tenant told to reconfigure a connection that works. |
| **The canary probe's five strings, taken as `PASSAGE`, move under a re-quantization that matters.** ADR-035 rests the entire drift detector on it. Five probes are more robust than one, but nobody has observed *any* vendor re-train behind an alias and measured what the digest did. The failure is asymmetric: a probe that does not move on a real re-train is undetected corpus splitting, and there is no second detector. | **P** | `ingestion-engineer` | It cannot close by reading. It closes the first time a vendor is observed re-pointing an alias — so the probe result belongs in telemetry from day one, where it accumulates history, rather than only being compared. See also the `CANARY_PRECISION` row above, which is the same mechanism's other half. |

**Four rows in the ADR-030 section above have changed status and are left standing rather than
rewritten.**

- **The two availability-matrix rows have lost their evidence sentences, and only those.** Both were
  argued partly on the fact that the matrix *was not written down anywhere assertable*: *"`contract.py`
  states the count structurally … and **names neither pair**, so the matrix exists in prose … and
  nowhere as an asserted fact"*, and *"No adapter implements `embed` or `rerank` yet."* **Both are
  false as of 2026-08-10.** The matrix is data in `app/providers/capabilities.py` with a cited source
  per cell; `contract.py:43-44` now says so explicitly and records that it used to make the structural
  claim; and `openai_adapter.py`, `nim.py` and `openrouter.py` all declare the `embed` / `rerank`
  methods. **The claims themselves are untouched and still class P.** Nobody has called a live
  endpoint, a vendor can withdraw one without notice, and a cited documentation page is not a
  response. What changed is only that the matrix is now *falsifiable in one place* instead of
  scattered through prose — which is what these rows asked for.

- The row *"The per-provider embedding availability matrix"* ends *"decide whether an organization may
  configure a chat provider that cannot embed, and if so which connection supplies the embedding
  credential — that half is a control-plane question and is not recorded as decided anywhere."* **It is
  decided now: [ADR-031](19-repo-structure-adrs.md#adr-031-the-organization-designates-the-embedding-connection-disagreement-is-a-refusal).**
  Yes, an organization may configure an embedding-only connection; the designation lives on
  `organizations` as a `(connection_id, model)` pair. The *matrix* half of that row is untouched and is
  still **P**.
- The row *"Qdrant ≥ 1.19.0 offers `SearchParams(idf=IdfCorpusParams(corpus=…))`"* is **no longer
  load-bearing**, and that is the change. ADR-032 chose query-side IDF, so nothing today depends on the
  1.19 parameter existing or behaving as described. It stays in the file because it is now the
  **revisit condition** for ADR-032 rather than a dependency of it — the claim has to be true before
  anyone can argue for moving the pin, and it is still unread.

## Raised by the stub-completion effort — not markers in the tree

Every implementation batch through 2026-08-11 ran on this host, and three things
this repository now depends on **cannot be observed here at all**. Like the three
sections above, these are **not** `UNVERIFIED` markers and do not move the 75/75/47
counts. They are recorded because each one is currently indistinguishable from a
verified fact when read in a report: a green local run and a green CI run look the
same in prose, and so do a passing parser test and a working stream on a phone.

The class that dominates here is a variant of **M**: not *a number nobody measured*
but *a behaviour nobody could execute*. They close by running the thing once, in the
place it actually runs.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **GitHub Actions has never executed either workflow.** Every `run:` body has been executed verbatim on this host and both YAML files parse, but nothing has exercised the Actions runtime. Specifically unproven: `docker/build-push-action@v7` honouring `load: true` + `tags:` such that a **later step in the same job** can inspect the image (the local proof used an image built by `docker compose build`, not buildx-with-load); the interaction of `load: true` with `cache-from`/`cache-to: type=gha` on cold and warm caches, and the real cost of the export; `setup-uv@v9` plus `uv run --python 3.13` downloading a managed CPython, since ubuntu-24.04 ships 3.12; the apt half of `playwright install --with-deps`; Playwright's `CI=true`-keyed behaviour as GitHub sets it; and whether the widget harness's three loopback ports are free on a hosted runner. **Extended 2026-08-13 by the auth-and-session effort:** its Pest Feature/Security/Contract suites and its `apps/web` Vitest components project have likewise run only on this host, and two of them are newly sensitive to the runner rather than to the shell — the Pest suites need `postgres-test` **and** `valkey-test` reachable as service containers (a shared cache store is load-bearing there in the opposite direction from usual; see `docs/22` § **H7**(b)), and the Browser Mode project needs a Chromium launch with `fileParallelism: false` honoured. Neither adds a new *class* of unknown; both add surface. | **M** | `platform-devops-engineer` | The first push. Nothing here can bring it forward, and no amount of local `run:` execution substitutes — the unproven parts are all *runner and action* behaviour, not shell. |
| **The cross-language signing test's CI wiring is proven locally and not on a runner.** The test skips at module level unless `knowledgebot/core-api:dev` is inspectable, and three states were measured on this host: image absent → `1 skipped`; image present with `KB_TEST_REQUIRE_PHP=1` → `17 passed`; tag renamed → `1 failed, 16 errors`. The third is the load-bearing one, because it converts a *future* dropped `load:` into a red step rather than a silent skip. All three were measured against a locally built image. | **M** | `platform-devops-engineer` | Same first push. It shares a root cause with the row above and is listed separately because it is the one artifact that can observe two runtimes agreeing on a *description* of the canonical string while disagreeing on its *bytes* — so a silent regression to `1 skipped` costs more here than elsewhere. |
| **No `apps/mobile` behaviour has been verified on a device or a simulator, anywhere in this effort.** `jest.config.js` says so itself, at the top of the file: the suite runs on **Node**, which has `ReadableStream`, a spec-complete `TextDecoder`, and a `fetch` whose `Response.body` is never null. Hermes' XHR-backed fetch has none of that and **fails by succeeding** — the request goes through, the whole answer arrives at once, and every assertion about the final text still passes. So the suite proves the *parser* is correct and can never prove that streaming works on a device. The same gap covers the SQLite history schema: the v1→v2 rebuild is proven as SQL against `node:sqlite`, not as an on-device upgrade through expo-sqlite's bridge. | **M** | `mobile-engineer` | A run on the `preview` EAS profile — `eas.json` names it as the profile that must reproduce a production streaming session, because a simulator on `development` can hide an ATS mistake. It is a checklist item, not a test file, and `jest.config.js` already says that. |
| **There is no containerised Python run and no live-stack `/health/ready` since the process wiring landed.** The verification pass that would have produced them was fenced out of every `ai-*` image build while the image defect in `docs/22` § F1 was being fixed. That fence is lifted, and the run has not been repeated. The unproven claim is narrow and specific: that `ai-api` reports **honestly healthy** now that a real psycopg pool, key ring and coordination client exist — previously it was honestly *unhealthy*, which was correct and is the state anyone re-reading old notes will find recorded. | **M** | `platform-devops-engineer` with `observability-engineer` | Bring the stack up on the rebuilt image and read `/health/ready`. Cheap; it has simply not been done since the fence lifted. |

## Raised by the 2026-08-12 rulings — not markers in the tree

Three entries. The first was a **behaviour nobody can see** — a real degradation reaching the index as
silence — and it is now closed by a detector; the second is what that closure did *not* prove, which is
the more useful half. The third is the **partial closing of a row above** — a caveat that got smaller
and must not be read as having disappeared. All are recorded here rather than in
[`docs/22`](22-spec-findings-and-decisions.md) § *The rulings of 2026-08-12* because neither is a
decision; they are the state of the evidence after it.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| ~~**A page classified as a picture loses its text with no warning of any kind.**~~ **Detector written 2026-08-12** (`docs/22` § **G17**) — `assess`'s third arm, `ocr_text_unplaced`, is exactly the comparison this row asked for: OCR produced cells on the page and the layout model placed no text element there. What remains unverified is narrower and is the row below. | — | closed | — |
| **The `ocr_text_unplaced` arm has never fired against a real page, and the reproduction we wrote cannot make it.** Its logic is covered by five unit tests, including the false-positive case the first implementation got wrong. The end-to-end run happened on **2026-08-12** inside `knowledgebot/ai-service:dev` with the real `/models` weights, and **it did not reproduce the defect**: sweeping `GaussianBlur` from 0.0 to 12.0 on a rasterised text page, `placed_text` tracked `ocr_cells` at *every* radius, so the state the arm detects never arose — including at the recorded `3.0`. Two useful controls came out of it: `ocr_low_coverage` fires correctly at 8.0, and at 12.0 OCR reads nothing so both counts are zero and the arm correctly stays **silent**. This does not show the defect is absent — 14D measured it on different input, and a synthetic blur carries none of the noise, skew or JPEG artefacts of a real scan — but **the `blur >= 3.0` figure must not be repeated as though measured through this path**. Full sweep table in `docs/22` § **G23**. | **M** | `ingestion-engineer` | **Not by a larger radius** — the sweep already reached 12.0 and found the other failure mode instead. It needs 14D's actual input: the real degraded page whose cell recall was 1.000 against element recall 0.000. Until that page is identified, the honest status is that the detector is correct, costless and unfired. |
| **`enforcement-greps` steps 2–7 have now been executed for the first time, and the workflow caveat above is unchanged.** Until 2026-08-12 the job's first step failed (`docs/22` § **G2**), exiting before it exported the two variables the later steps read — so steps 2–7 had **never run**, on this host or anywhere. With the #79 pin in place all seven `run:` bodies were executed on this host against the live tree and **all seven exit 0**. What that closes is narrow: those six step bodies are no longer *unreachable*. What it does **not** close is the row two above — this is still a local shell emulation, GitHub Actions has never executed either workflow, and every `runner and action` behaviour listed there is as unproven as it was. | **M** | `platform-devops-engineer` | The first push, exactly as for the row above. Listed separately so that "the enforcement greps have been run" is not mistaken for "the enforcement greps have been run in CI" — the two sentences differ by the only part that was ever in doubt. |

## Raised by the auth-and-session effort — not markers in the tree

Three entries, and the first two are the **same kind of claim**: a property the effort was explicitly
asked to prove, which the test layer it has *may not* assert. That is not a coverage gap in the ordinary
sense — it is the [`vitest-playwright` boundary
table](../.claude/skills/vitest-playwright/SKILL.md) doing its job. The components layer may not assert
navigation between routes, authentication, org resolution, anything server-rendered, or a tenancy claim;
both claims below are made of exactly those things. **No Vitest spec is cited as covering either**, and
that is deliberate: the two specs that come closest say so in their own headers rather than implying
coverage, which is what makes this table honest instead of a list of things nobody looked at.

The third is the manual pass that would have closed both by hand, and it has not been run.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **The back button after logout renders no authenticated payload.** Logout cancels the session query, mutates, and **replaces** the client rather than calling `clear()` — so no full navigation occurs and no Next cache is invalidated by the framework. What is unproven is what the browser does *afterwards*: whether a back navigation replays a prefetched or bfcached RSC payload containing the previous session's chrome. Vitest cannot decide it — a component spec may not assert cross-route navigation, and the browser history stack is not in scope for one — and `apps/web/tests/components/logout-button.test.tsx` states in its header that it does not claim it. `.claude/skills/nextjs-app-router/SKILL.md` additionally carries this as an `UNVERIFIED` marker of its own ("does a session change clear prefetched RSC payloads"), so the claim is unverified from *both* ends. | **M** | `admin-web-engineer` | A Playwright spec: log in, load an authenticated route, log out, press Back, and assert no authenticated payload renders. It is in `vitest-playwright`'s own Playwright column already ("back-button after logout"); the harness is out of scope for this effort, not absent from the plan. |
| **The org switch never renders the previous organization's rows mid-flight.** The five steps of the switch are asserted **in order** by `apps/web/tests/unit/switch-organization.test.ts`, which is the spec that goes red when someone reorders the algorithm — but ordering is not the claim. The claim is about what is on screen during the in-flight window, across a soft navigation, with the org living in the session rather than in the URL (so every Next cache is keyed by a URL that did not change). `apps/web/tests/components/org-switcher.test.tsx` disclaims it explicitly in its header — *"it proves NOTHING about isolation: a component test that mocks the API cannot fail an isolation test"* — and `current-org-badge.test.tsx` restates the same disclaimer rather than implying coverage. | **M** | `admin-web-engineer` | A Playwright spec against real Laravel with the two-organization fixture: switch, and assert that no row belonging to org A is present at any point after the switch begins. `vitest-playwright` names **org switch** in its Playwright column and owns the two-org harness this needs; note that the harness itself depends on `tenantPair()`, which still throws (`docs/22` § H9). |
| **The end-to-end manual pass in the effort's own verification recipe has never been run.** The recipe is explicit — bootstrap an organization, open the printed reset link, set a password, sign in, confirm `/me` returns the org, switch orgs, sign out, **press Back**, then invite a second user and read the invitation in Mailpit. It has not been executed once, because the Compose stack is down. So every claim that depends on a real browser against a real edge is unexercised, including the two above and one env prerequisite the recipe names: `SANCTUM_STATEFUL_DOMAINS` must list both hosts **with ports**, or login 419s (rendered as 401) instead of 401ing — a failure that looks like bad credentials. | **M** | `platform-devops-engineer` to bring the stack up; `control-plane-engineer` and `admin-web-engineer` to walk it | Bring the stack up and run the recipe once. Cheap, and it is the only thing that exercises the mail path, the emailed link, the cookie against a real edge, and the CSRF round trip together. |

**And the CI half of this is closed by deletion rather than by verification.** Neither workflow ever ran
on a GitHub runner, and `.github/` was removed on 2026-08-17, so the claim has no subject left — see the
first row of § *Raised by the stub-completion effort* above, and § *Removing CI/CD* in `docs/22`. What this effort adds to that row is volume, not a new unknown: a Pest Feature,
Security and Contract set and a Vitest components set that have run only on this host.

## Raised by the shutdown-determinism effort — not markers in the tree

Three entries, and the first is the awkward one: **the mechanism ADR-044 acts on is the one thing that was
not reproduced.** The half that *was* measured (a crash loop) is the half the ADR says is **not** the
cause, so the decision rests on documented daemon behaviour plus the operator's report. That is written
down deliberately, because the measured arm reads like evidence for the conclusion and is not.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **A container left running when the Docker daemon stops comes back when it next starts, and `restart: "no"` prevents it.** This is what the dev override is *for*, and it was never observed end to end. What was measured (Engine 29.6.2, 2026-08-14) is the adjacent arm: two containers exiting 255, `RestartCount` 9 under `unless-stopped` versus 0 under `no` in 30 s, and `docker stop` settling **both** — which is what *refuted* the crash-loop explanation rather than confirming this one. The revival itself is Docker's documented `unless-stopped` semantics plus Ankur's report that the containers returned after a Desktop stop; no daemon restart was performed to reproduce it, and no host reboot was performed to confirm the production posture still self-heals. | **M** | `platform-devops-engineer` | Two arms on one host, ten minutes: start one container under each policy, `wsl --shutdown` (or restart Docker Desktop), and record which one is running afterwards. Then the same with an explicit `docker stop` first, which is the exemption the policy name refers to. |
| **`compose-invariants` checks (9), (9b) and (9d) behave in CI as they do here.** All three were authored and mutation-verified on this host — four mutations for the restart pair, two for the stop-signal check, each failing with a message naming the offender — but the job body was executed by hand, and `yq`/`jq` are not even on this host's `PATH` (`docs/22` § **G13**), so the restart render was re-derived with Python for the final review rather than through the job's own `yq -o=json` path. Nothing here is a new *class* of unknown — it is the first row of § *Raised by the stub-completion effort* again — but the two checks are new and unrun. | **M** | `platform-devops-engineer` | The first push. Until then, treat a green local emulation as evidence about the *logic* and not about the job. |
| **The extra invitations-list read has no known cause.** `docs/22` § **I4** records six mechanisms tested and refuted, ~28 full-suite runs with one failure, 3/3 in isolation, and a forced event dump showing no third read even 1500 ms later; the single observed failure happened while the PHP and Python suites were running concurrently. The fix does not depend on the answer — the assertion no longer counts requests — so this is an open question, not an open risk, and it must not be written up as "fixed by MSW handler ordering" or any other unproven mechanism. | **M** | `admin-web-engineer` | Either it recurs against the new assertion (it cannot fail it, but a stray read is still visible in the handler counter under `--reporter=verbose`), or it never does. A cheap standing probe: log `invitationReads` on failure only, and let CI's first hundred runs answer it. |

**Note on classes:** all three are **M** — something to measure, not something to read. None is an
unverified *claim about a vendor*; each is a claim about this repository that nobody has exercised yet, and
the first two close on one host in under an hour.

## Raised by the live-parse effort — not markers in the tree

One entry, and it is deliberately narrow: `docs/22` § **I6** closed a real gap — the six tests asserting a
live Docling parse now run, and they passed — but *where* they run is a workflow step, and this repo's
standing caveat about workflow steps applies to it unchanged.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **The `images`-job step *"Live document parse (real weights, in the image built above)"* behaves on a GitHub runner as it does here.** The suite itself is **verified** — 6 passed in 65 s against real weights, twice, and the skip guard was proven by hiding the scanned fixture (2 passed / 4 skipped → step exit 1 naming I6). What is unrun is the step *in CI*: it depends on `load: true` newly added to the `runtime` build, and on a ~3.5 GB image being exported to the runner's daemon and then bind-mounting the checkout into it. Two things could differ there and neither is visible from this host — the runner's disk headroom during a job that already builds six images, and whether `docker run -v "$PWD":/repo:ro` resolves the same way under the runner's workspace path. Note this is **not** the same claim as "the tests pass"; it is only "the step runs". | **M** | `platform-devops-engineer` | The first push. If the export is too expensive in practice, the fallback is a dedicated job rather than dropping the step — the coverage is the only assertion in the repo that a model produced anything. |

**Also worth recording as now-verified rather than unverified**, since this file's job is to stop a green tick
standing in for evidence: `pyproject.toml`'s two `-W default:` narrowings, exempting
`rapid_ocr_model`'s deprecated `rec_font_path` read and `docling_core`'s "ListItem parent must be a list
group", were documented as measured but had **never been exercised by a real parse in this repository** —
every earlier run either skipped the parse or never reached document assembly. Both advisories fired during
the I6 run and neither failed the suite, so the narrowing is now confirmed in both directions on
docling 2.118.0. That closes the quiet assumption underneath them, not a row that was ever written here.

## Raised by the bots-schema effort — not markers in the tree

Five entries, and they share one property worth naming before the table: **none of them is a claim
about a vendor.** Each is a statement about what this host cannot exercise, so each is a place where a
green suite proves less than a reader would assume. Two of them — the PostgreSQL major version and the
Playwright browser build — are worse than "unrun", because the thing that *did* run was a **different
thing wearing the same name**, and nothing in the output says so.

| Claim | Class | Owner | How it closes |
|---|---|---|---|
| **The schema behaves on PostgreSQL 18 as it did on the 16 it was exercised against.** This host runs 16.13 (`psql -c 'select version()'`, client and server both); the project pins 18 (`postgresql-patterns` §1, and `grep -n 'image: postgres' infrastructure/docker/compose*.yaml`). **No construct was downgraded to fit** — every migration in this step uses SQL that 16 and 18 both accept, so the schema is not carrying a 16-shaped compromise. What is unverified is narrower and is in the *comments*: the index reasoning in `2026_08_19_001400` argues about PG 18's **b-tree skip scan** when it explains why `(organization_id, status)` is tenant-leading and why no separate ordering index exists. 16 has no skip scan, so the planner behaviour those paragraphs reason about was never observable here, and a plan captured on this host is evidence about 16 only. | **V** | `control-plane-engineer` | Run the migrations and the Feature suite against the pinned image once Docker is available, and re-capture `EXPLAIN` for the two list queries the comments name. The correctness half is expected to be a no-op; the plan half is the one to look at. |
| **The component tests ran on the Chromium build the lockfile pins.** They did not. The vitest components project requests `chromium` (`apps/web/vitest.config.ts`), and `/opt/pw-browsers` holds `chromium-1194` plus a `chromium_headless_shell-1234` directory whose every entry is a **symlink into 1194** — the CDN download is blocked by egress policy, so the expected build number was satisfied by pointing it at the one already present. `ls -la /opt/pw-browsers/chromium_headless_shell-1234/chrome-headless-shell-linux64/` is the whole finding. Anything the pinned build fixes or breaks relative to 1194 is invisible here, and the run reports success either way. | **V** | `admin-web-engineer` | A run on a host that can reach the Playwright CDN, or a vendored browser bundle. Until then, treat a green component suite as evidence about the *components* and not about the browser. |
| **No accessibility property of anything in this step has been verified.** There is no browser a human can look at and no human to look at it: no axe run, no keyboard-only pass, nothing viewed in dark mode, and nothing viewed at the responsive floors. The component suite loads **no CSS**, so which layout is visible at which width is asserted nowhere at all — a component can render, pass, and be laid out unusably. `kb-ui-accessibility`'s contrast matrix and focus-order requirements are unexercised by construction, not by omission. | **M** | `admin-web-engineer` with `test-engineer` | A Playwright run with axe against a real stylesheet, plus one manual keyboard-and-dark-mode pass. Neither is possible on this host; both are cheap on any host with a browser. |
| **`ext-bcmath` is unmet here and the install ran with `--ignore-platform-req`.** `php -m` shows no `bcmath`. Nothing in `services/core-api/app` or `tests` calls a `bc*` function today, so no behaviour in this step depends on it — but the platform requirement is being *waived* rather than met, and the first code that does call one will fail at runtime on a host that looks like this one rather than at install time. | **V** | `platform-devops-engineer` | Install the extension in the image and drop the flag, or drop the requirement from `composer.json` if nothing needs it. The decision is which, and it is not this step's. |
| **Docker is unavailable on this host, so nothing that needs the stack has been run.** `docker info` fails to reach `/var/run/docker.sock`. That leaves the Compose topology, the spoofed-`Host` routing test, the object-store anonymous-access check and the restore drill all untested here — the same standing gap earlier sections record, restated only because this step's migrations were exercised against a **local** PostgreSQL rather than the composed one, which is what makes the first row above a live question rather than a formality. | **M** | `platform-devops-engineer` | A host with a daemon. Unchanged from every earlier section that says so; it is listed again because the PG-version row depends on it. |

**Note on classes:** three **V** and two **M**. The two **V** rows about a version are the ones that
mislead in the same direction — in both cases something ran, reported success, and was not the thing
named — so neither closes by reading and both close by re-running somewhere else.

## Re-checked at the close of Phase B — the same five, wider

**No new entries, deliberately.** The five rows above were raised by Phase B's first step and every
one of them still holds at its last; re-tabling them under a second heading would be the parallel
account `docs/00-index.md` warns against. What changed is **scope**, and scope is the whole reason
this section exists rather than a line in a commit message: the bots-schema step was one migration
set, whereas the phase went on to ship a list screen, an editor shell, a create path, three editor
tabs and two child-resource surfaces (`git log --oneline b976735..HEAD` — read the range; the count
in the commissioning brief was wrong and moves again on the next commit). **Every one of those
screens inherits every row above.**

Each command below was re-run on this host at the close of the phase and returned what it returned
when the rows were written:

```bash
psql -c 'select version()'            # server unreachable now; psql --version is 16.13, the pin is 18
php -m | grep -i bcmath               # silent — still unmet, still waived with --ignore-platform-req
docker info                           # "failed to connect to the docker API at unix:///var/run/docker.sock"
ls -la /opt/pw-browsers/chromium_headless_shell-1234/chrome-headless-shell-linux64/
                                      # every entry a symlink into chromium_headless_shell-1194
```

**The accessibility row is the one whose scope moved most, and it is worth restating as a rule rather
than as a gap.** *No accessibility property of anything Phase B shipped has been verified by any
means* — no axe run, no keyboard-only walk, nothing viewed in dark mode, nothing viewed at the
responsive floors — across the bots list, the editor shell, all three editor tabs, the create dialog,
the origin allow-list and the starter-question editor. There is no browser session on this host and
no person at it. Four of the phase's commits say so in their own *"owed to a human"* paragraphs
rather than leaving it to be inferred, which is the right precedent and is the reason this row can be
stated with confidence rather than by assumption.

**And the sharpest half of it is not "unrun tests" but "untestable by construction":** the component
suite loads **no CSS**, so both layouts of the server-driven table are in the DOM at once and
**which one is visible at which width is asserted nowhere at all**. A component can render, pass its
suite, and be laid out unusably; the below-768px card layout in particular is fed from the same row
model as the table precisely so the two cannot disagree about *content*, and nothing anywhere checks
that either is *seen*. `kb-ui-accessibility`'s contrast matrix and focus-order requirements are
unexercised for the same reason.

**The Playwright row now covers more than it did.** Every component test added in this phase ran on
`chromium-1194` wearing the pinned build's directory name, because the CDN is blocked by egress
policy. That is a larger body of evidence about a browser the lockfile does not pin than the row was
written to describe, and the suite reports success either way.

**One row is load-bearing for a *comment* rather than for behaviour, and it is easy to lose.** The
index reasoning added in this phase argues about PostgreSQL 18's **b-tree skip scan** when it
explains why the bots indexes are tenant-leading. 16 has no skip scan, so no plan captured here is
evidence about the planner those paragraphs reason about — and the phase added more such comments
than the row was written against. The correctness half remains expected to be a no-op; the plan half
is the one to re-capture when Docker is available.

## Full list, by file

Line numbers are accurate as of this commit and will drift as files are edited.
Paths are relative to `.claude/skills/`.

**`anthropic-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 180 | P | Haiku 4.5's response to `thinking={"type":"adaptive"}` is undocumented — `output_config.effort` is confirmed to error there, so catalogue it without REASONING and the question does not arise. |

**`bge-m3-embeddings/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 164 | M | the specific 16 / 24 GB pairing is an estimate; measure with `kb_embedding_batch_duration_seconds` before pinning |
| 167 | M | Qdrant's docs describe the IDF modifier but do not explicitly rule it out for learned-weight models |
| 168 | M | the paper describes self-knowledge distillation across the three heads and no MRL loss; BAAI has not published MRL results for M3 |

**`bge-reranker/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 28 | S | HAKARI-Bench (arXiv:2606.22778) via search summary; I did not read the results table. |
| 152 | M | extrapolated from a Medium benchmark measuring 100 contexts in 1.4 s on an A10 and a TEI/A100 figure of ~800 pairs/s; I measured neither. |
| 153 | M | single blog measurement, hardware unspecified. |
| 178 | M | mechanism is standard for cross-encoders; I found no measurement of the magnitude for this model. |

**`celery-workers/SKILL.md`** — the one line carrying two markers

| Line | Class | Claim / how it resolves |
|---|---|---|
| 149 | M | reload cost not yet measured on our models |
| 149 | V | CUDA/fork interaction not re-checked against the pinned torch build |

**`crawl4ai-crawler/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 8 | V | exact Playwright version range crawl4ai 0.9.2 pins |
| 157 | S | no authoritative confirmation that route interception covers 100% of browser-initiated subresource types |

**`deepseek-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 158 | P | whether the OpenAI-shaped endpoint rejects an unknown model id with 400 rather than remapping is not documented; assert it with a live fixture before trusting model-id validation to the provider. |
| 161 | W | peak pricing not yet in effect as of 2026-08-04; the pricing page says an official announcement is pending. |
| 162 | P | absence of rate-limit headers is inferred from their omission across the rate-limit and error-code pages, not from a positive statement. |

**`docker-compose-stack/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 159 | V | the ignored-key list is read from docker/compose source, not from prose docs |

**`docling-parsing/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 135 | M | fidelity of the native legacy binary Office backends vs a LibreOffice round-trip is unmeasured |
| 136 | M | no upstream determinism guarantee exists |

**`expo-react-native/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 144 | S | the EAS plan gate was read from the code-signing doc page, not from the pricing page |
| 146 | S | that these two files are reachable from an Expo config plugin under CNG was not re-checked against the SDK 57 config-plugin docs |
| 162 | P | expo/fetch is documented as supporting `AbortSignal.timeout`, but that it covers the streamed body rather than only the response headers was not confirmed against the source |

**`fastapi-service/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 11 | V | 3.14 wheel coverage for the ML stack not re-checked |

**`iframe-postmessage-bridge/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 30 | S | the `MessagePort`-as-capability rationale is documented in Channel Messaging design discussion, not as a normative MDN/WHATWG sentence. |
| 181 | M | no normative source states a postMessage payload limit; third-party measurements put ~100 KiB inside a 100 ms budget. |

**`kb-architecture-map/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 60 | D | _(bare marker)_ the spec never says whether FastAPI writes `chunks`/`document_elements` directly or reports them through the §17.5 callback — `docs/22` open decision 2 |
| 147 | D | _(bare marker)_ trade-offs for ADR-002…010 are this skill's assessment, not spec text; only ADR-001's is stated. Closes when the ADRs are ratified |

**`kb-chunking-rules/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 174 | P | whether Docling reliably marks a paragraph as continued across a page break for scanned PDFs |

**`kb-deletion-and-verification/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 158 | V | the filter-delete replay fix merged to Qdrant `dev` 2026-07-07; not confirmed in a tagged release |
| 163 | D | no spec section assigns ownership of erasure sweeps over the conversation-side verbatim copies |
| 168 | S | that Qdrant snapshots skip compaction is inferred from its source, not documented |

**`kb-error-taxonomy/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 74 | M | _(bare marker)_ every numeric timeout default is derived to satisfy §23 with headroom; §19.4 names nine timeouts and sets no values. Re-derive from measured p99.9 |

**`kb-observability-conventions/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 175 | V | confirm the log-redaction behaviour against the SDK version you pin |

**`kb-provider-adapter-contract/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 167 | P | DeepSeek publishes no request-ID header; treat `provider_request_id` as null |

**`kb-rag-query-contract/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 134 | M | mechanism is consistent with reported "rewrites distort intent" findings, but I found no study isolating entity-drop rates. |
| 135 | M | no measured study of the dedup-removes-the-completing-chunk failure; the adjacency rule is spec-mandated and the mechanism is inferred. |

**`kb-security-baseline/references/file-upload-safety.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 48 | V | PEP 706 flipped `tarfile`'s default in a specific CPython release; passing `filter="data"` makes the version stop mattering. |
| 101 | X | neither the OWASP File Upload nor XXE cheat sheet currently has an SVG section; this is doctrine, not citation. |
| 118 | P | whether `scrub()`'s javascript/remove_links handling incidentally strips `/Launch` and `/OpenAction` is not stated in the docs. |
| 137 | X | the specific cgroup/seccomp parameters are synthesized from standard practice; the "run in a sandboxed environment" instruction itself is sourced, from Docling's own advisories. |

**`kb-security-baseline/references/ssrf-and-crawling.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 74 | S | the route-interception mitigation is standard Playwright API usage, but I found no authoritative writeup confirming it intercepts 100% of browser-initiated subresource types. |

**`kb-security-baseline/references/widget-embedding-and-output.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 82 | P | CHIPS size/count/lifetime quotas are not documented on MDN; Safari's exact 2026 ITP behaviour was not re-verified. |

**`kb-source-lifecycle/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 84 | D | spec names `Archived` but never defines its semantics — `docs/22` spec defect 6 |

**`kb-tenancy-isolation/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 13 | V | v1.16 changelog + maintainer comment in qdrant discussion #7987, read at master; confirm against the version we actually pin |
| 93 | V | filter propagation and the local-client divergence were read from qdrant and qdrant-client source at master, not from documentation — recheck on version bumps |

**`laravel-control-plane/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 122 | S | bailout-skips-`finally` confirmed from secondary sources, not php.net; `register_shutdown_function` is documented as the exception |
| 125 | S | the exact scope of Guzzle's `timeout` under StreamHandler was not re-read from source |

**`laravel-queues-valkey/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 165 | P | that Horizon still infers tags correctly from an encrypted payload — confirm on first deploy |

**`laravel-sanctum-auth/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 8 | S | passkey feature flag confirmed from Laravel News and the Fortify docs index, not from the feature list itself |

**`nextjs-app-router/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 53 | P | no official page states whether a session change clears prefetched RSC payloads; treat back/forward after logout as an empirical check, not an assured behaviour. |
| 69 | X | the no-abort-for-Server-Actions claim is absence from React's documented list, not an explicit prohibition |

**`nvidia-nim-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 26 | P | the auth header is the OpenAI-SDK default and every catalog snippet uses it, but NVIDIA publishes no normative auth page |
| 29 | P | NVIDIA publishes no rate-limit or 429-header schema for the catalog |
| 150 | P | NVIDIA documents no status/body for credit exhaustion |

**`ocr-pipeline/references/engine-selection.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 17 | S | Nemotron OCR licence terms not read |
| 48 | M | RapidOCR≡PaddleOCR equivalence is an inference from RapidOCR shipping PP-OCR exports, not a measurement. |

**`openai-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 166 | ! | `kb-error-taxonomy` defines `provider_auth` as "provider rejected our credential", which reads narrower than a billing state — flagged for the taxonomy owner rather than fixed here. See Escalate 1: the taxonomy answered with `provider_billing`; the adapter-side mapping of 429 `insufficient_quota` is what remains. |

**`openrouter-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 139 | S | the generation-endpoint reference page 404s from the current docs nav; only its existence and query shape are confirmed, not its field set |
| 140 | P | whether the undocumented top-level `provider` field is still populated on non-error responses |

**`opentelemetry-instrumentation/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 175 | S | exact flush point in PHP SDK 1.15.0 not read; the absence of a background thread is not in doubt |

**`pest-testing/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 137 | V | _(bare marker)_ that `Tests\` must be in composer `autoload-dev` for the arch expectation to scan it |
| 163 | S | TIA's exact opt-in flag and its cache invalidation rules were not read from the CLI reference |

**`postgresql-patterns/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 33 | M | the per-entry byte figures are computed from IndexTupleData (8B) + MAXALIGN'd datum + ItemIdData (4B), not measured — confirm with pgstatindex on a seeded table before quoting them |

**`preact-vite-library/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 47 | M | published react/preact size figures disagree by 2× depending on entry points measured; re-measure against our own build before quoting a number in a PR. |
| 143 | X | `adoptedStyleSheets` escaping `style-src` is acknowledged at spec level; not re-tested against every 2026 browser, so the launcher must still be legible with no stylesheet applied. |

**`prometheus-grafana-loki-tempo/references/retention-and-correlation.md`** *(was `SKILL.md:112` / `:124`)*

| Line | Class | Claim / how it resolves |
|---|---|---|
| 10 | M | derived from ~1.7 bytes/sample, not measured — re-derive from `prometheus_tsdb_head_series` once real traffic exists |
| 22 | V | `matcherType` accepts `label` as well as `regex`; confirm against the pinned Grafana before relying on it, and keep a `matcherRegex` over the JSON as the fallback. |

**`pydantic-contracts/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 124 | V | that the default validation handler's output includes the `input` key was not re-checked against the pinned FastAPI 0.141.1 |

**`pytest-ai-service/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 128 | S | `celery.contrib.pytest` fixture names and the default pool of `celery.contrib.testing.worker.start_worker` were not re-read from source this revision |

**`qdrant-hybrid-search/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 11 | W | v1.19.0's GA status — inferred from the Docker `latest` digest, not from an announcement |

**`ragas-evaluation/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 145 | S | the mkdocstrings-generated reference pages were Cloudflare-rate-limited throughout; if a documented seed or temperature contract exists anywhere, it is there. Source at the v0.4.3 tag has neither. |
| 150 | S | both import failures are read off v0.4.3 source, not executed. |

**`seaweedfs-s3/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 154 | P | not confirmed whether #6583/#6578 still reproduce on 4.40; both remain open with no closing commit |

**`security-scanning-toolchain/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 45 | X | _(bare marker)_ Opengrep's forked ruleset licence is not confirmed; check before vendoring |
| 169 | V | confirm `composer audit`'s abandoned-package default in the Composer version pinned by the image |

**`tailwind-shadcn/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 64 | D | the spec does not enumerate `theme_configuration`; an ADR should ratify the six-key list before the first migration. |
| 129 | M | the build-time cost of `@reference` at our scale is not measured; the directive itself is documented. |

**`tanstack-query-table/references/sources-table-example.md`** *(was `SKILL.md:123`)*

| Line | Class | Claim / how it resolves |
|---|---|---|
| 53 | ! | no spec field echoes the scoped org; Laravel must add it. The only marker in the tree written as an inline `(UNVERIFIED: …)` inside a `//` comment |

**`valkey-keyspaces/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 165 | V | the client-prefix-does-not-reach-queue-keys behaviour not re-checked against Laravel 13 |

**`valkey-keyspaces/references/key-catalog.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 75 | V | module packaging in the official Valkey 9.1 image not confirmed |

**`vitest-playwright/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 36 | M | the MSW abort gap was measured on msw 2.15.0 / Node 22, not stated in MSW's docs; the browser service-worker path is designed to propagate cancellation and was not measured |
| 87 | M | measured on msw 2.15.0, undocumented |
