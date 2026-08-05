# Unverified claims

Every `<!-- UNVERIFIED -->` marker in the skill library, collected so unconfirmed
claims are visible rather than buried in the file that makes them.

**84 markers across 83 lines in 48 files** (one line carries two). Generated at the
close of Pass 4; regenerate with a grep over `.claude/skills/` after any wave of edits.

A marker is not a defect. It means the author wrote the claim from knowledge and
could not confirm it against an authoritative source — which is exactly what we
asked them to do rather than assert it confidently or drop it. What matters is
*why* it could not be confirmed, because that decides who closes it and when.

## Classes

| Class | Count | What it means | Closes when |
|---|---|---|---|
| **M** — Measure | 20 | A number extrapolated, estimated, or taken from someone else's benchmark. | The system runs and you measure it. |
| **S** — Source not read | 17 | An authoritative source exists; the author ran out of budget before reading it. | Someone reads it. **Cheapest class to close — start here.** |
| **V** — Version re-check | 15 | Confirmed at `master`, or at a version near ours. | Confirmed against the version we actually pin. |
| **P** — Vendor silence | 16 | No authoritative source exists to read. Inferred from SDK defaults, omission, or source. | Never, by reading. Only by probing — and it can change without notice. |
| **X** — Deliberate doctrine | 5 | Synthesized from practice; no citation is claimed. | It does not. Correctly labelled, not a debt. |
| **D** — Needs a decision | 5 | The spec is silent and someone must choose. | An ADR lands. Also tracked in `docs/22`. |
| **W** — Announced, not shipped | 2 | The vendor has said it is coming. | The vendor ships it. |
| **!** — Escalate | 3 | **Not an unverified claim.** See below. |  |

The **P** class is the one to understand. Sixteen claims cannot be verified by
reading, because the vendor has published nothing — NVIDIA documents no auth
header, no rate-limit schema, and no credit-exhaustion response; DeepSeek
publishes no rate-limit headers and no request ID. Waiting for documentation is
not a plan. These close by asserting the behaviour in a live fixture at
implementation time, and they can regress silently whenever the vendor changes
something, so the adapter must fail loudly rather than assume.

**X** is the opposite and needs no action: five places where the author
deliberately wrote doctrine with no citation and said so — the upload sandbox
parameters, the SVG handling rules, the Semgrep licence reading. Labelling them
was the honest move.

## Escalate — these three are not unverified claims

They were parked in `UNVERIFIED` notation but are cross-skill defects that need
resolving before the code they describe is written:

1. **`openai-api/SKILL.md:166`** — OpenAI returns `RateLimitError` for both a rate
   limit and an exhausted billing account. The adapter wants to map the billing
   case to `provider_auth`, but `kb-error-taxonomy` defines that class as
   *"provider rejected our credential"*, which is narrower than a billing state.
   Left as-is, an exhausted account is retried and falls back, and the org is
   never told. **Owner: the taxonomy.** Either widen the class or add one.
2. **`tanstack-query-table/SKILL.md:23`** — its retry predicate expects a
   `retry-after`, while `nextjs-app-router` constructs `KbError(error_class,
   retryable)` with no such field. Two skills describing the same client error
   object differently. **Reconcile before either ships.**
3. **`tanstack-query-table/SKILL.md:123`** — the admin needs the response to echo
   which org Laravel scoped the request to, so a stale org context is detectable.
   No spec field provides it. **This is an API gap for `control-plane-engineer`,
   not a documentation problem.**

## Full list, by file

Line numbers are accurate as of this commit and will drift as files are edited.

**`anthropic-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 180 | P | Haiku 4.5's response to `thinking={"type":"adaptive"}` is undocumented — `output_config.effort` is confirmed to error there, so catalogue it without REASONING and the question does not arise. |

**`bge-m3-embeddings/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 164 | M | the specific 16 / 24 GB pairing is an estimate; measure with `kb_embedding_batch_duration_seconds` before pinning |
| 167 | M | Qdrant's docs describe the modifier but do not explicitly rule it out for learned-weight models |
| 168 | M | the paper describes self-knowledge distillation across the three heads and no MRL loss; BAAI has not published MRL results for M3 |

**`bge-reranker/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 28 | S | HAKARI-Bench (arXiv:2606.22778) via search summary; I did not read the results table. |
| 152 | M | extrapolated from a Medium benchmark measuring 100 contexts in 1.4 s on an A10 and a TEI/A100 figure of ~800 pairs/s; I measured neither. |
| 153 | M | single blog measurement, hardware unspecified. |
| 178 | M | mechanism is standard for cross-encoders; I found no measurement of the magnitude for this model. |

**`celery-workers/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 147 | M | reload cost not yet measured on our models |

**`crawl4ai-crawler/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 8 | V | exact Playwright version range crawl4ai 0.9.2 pins |
| 157 | S | no authoritative confirmation that route interception covers 100% of browser-initiated subresource types |

**`deepseek-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 158 | P | whether the OpenAI-shaped endpoint rejects an unknown model id with 400 rather than remapping is not documented; assert it with a live fixture before trusting model-id validation to the provider. |
| 161 | W | not yet in effect as of 2026-08-04; the pricing page says an official announcement is pending. |
| 162 | P | absence of headers is inferred from their omission across the rate-limit and error-code pages, not from a positive statement. |

**`docker-compose-stack/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 146 | V | the ignored-key list is read from docker/compose source, not from prose docs |

**`docling-parsing/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 174 | M | fidelity of the native legacy backends vs a LibreOffice round-trip is unmeasured |
| 175 | M | no upstream determinism guarantee exists |

**`expo-react-native/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 147 | S | the plan gate was read from the code-signing doc page, not from the pricing page |
| 149 | S | that these two files are reachable from an Expo config plugin under CNG was not re-checked against the SDK 57 config-plugin docs |
| 165 | P | expo/fetch is documented as supporting AbortSignal.timeout, but that it covers the streamed body rather than only the response headers was not confirmed against the source |

**`fastapi-service/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 11 | V | 3.14 wheel coverage for the ML stack not re-checked |

**`github-actions-pipeline/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 77 | V | qdrant/qdrant image contents not re-checked this revision |

**`iframe-postmessage-bridge/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 30 | S | the capability rationale is documented in Channel Messaging design discussion, not as a normative MDN/WHATWG sentence. |
| 181 | M | no normative source states a payload limit; third-party measurements put ~100 KiB inside a 100 ms budget. |

**`kb-architecture-map/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 60 | D | _(bare marker)_ |
| 143 | D | _(bare marker)_ |
| 165 | P | _(bare marker)_ |

**`kb-chunking-rules/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 173 | P | whether Docling reliably marks a paragraph as continued across a page break for scanned PDFs |

**`kb-deletion-and-verification/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 157 | V | fix merged to Qdrant `dev` 2026-07-07; not confirmed in a tagged release |
| 162 | D | no spec section assigns ownership of erasure sweeps over these conversation-side copies |
| 165 | S | that Qdrant snapshots skip compaction is inferred from its source, not documented |

**`kb-error-taxonomy/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 72 | M | _(bare marker)_ |

**`kb-observability-conventions/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 195 | V | confirm against the SDK version you pin |

**`kb-provider-adapter-contract/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 185 | P | DeepSeek publishes no request-ID header; treat as null |

**`kb-rag-query-contract/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 129 | M | mechanism is consistent with reported "rewrites distort intent" findings, but I found no study isolating entity-drop rates. |
| 130 | M | no measured study of this failure; the adjacency rule is spec-mandated and the mechanism is inferred. |

**`kb-security-baseline/references/file-upload-safety.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 48 | V | PEP 706 flipped the default in a specific CPython release; passing the argument makes the version stop mattering. |
| 101 | X | neither the OWASP File Upload nor XXE cheat sheet currently has an SVG section; this is doctrine, not citation. |
| 118 | P | whether scrub()'s javascript/remove_links handling incidentally strips /Launch and /OpenAction is not stated in the docs. |
| 137 | X | the specific cgroup/seccomp parameters are synthesized from standard practice; the "run in a sandboxed environment" instruction itself is sourced, from Docling's own advisories. |

**`kb-security-baseline/references/ssrf-and-crawling.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 74 | S | the route-interception mitigation is standard Playwright API usage, but I found no authoritative writeup confirming it intercepts 100% of browser-initiated subresource types. |

**`kb-security-baseline/references/widget-embedding-and-output.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 74 | P | CHIPS size/count/lifetime quotas are not documented on MDN; Safari's exact 2026 ITP behaviour was not re-verified. |

**`kb-source-lifecycle/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 84 | D | spec names Archived but never defines its semantics |

**`kb-tenancy-isolation/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 13 | V | v1.16 changelog + maintainer comment in qdrant discussion #7987, read at master; confirm against the version we actually pin |
| 93 | V | propagation and the local-client divergence were read from qdrant and qdrant-client source at master, not from documentation — recheck on version bumps |

**`laravel-control-plane/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 165 | S | bailout-skips-finally confirmed from secondary sources, not php.net; register_shutdown_function is documented as the exception |
| 168 | S | the exact scope of Guzzle's `timeout` under StreamHandler was not re-read from source |

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
| 52 | P | no official page states whether a session change clears prefetched RSC payloads; treat back/forward after logout as an empirical check, not an assured behaviour. |
| 68 | X | absence from React's documented list, not an explicit prohibition |

**`nvidia-nim-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 26 | P | the header is the OpenAI-SDK default and every catalog snippet uses it, but NVIDIA publishes no normative auth page |
| 29 | P | NVIDIA publishes no rate-limit or 429-header schema for the catalog |
| 149 | P | NVIDIA documents no status/body for credit exhaustion |

**`ocr-pipeline/references/engine-selection.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 17 | S | terms not read |
| 48 | M | that equivalence is an inference from RapidOCR shipping PP-OCR exports, not a measurement. |

**`openai-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 166 | ! | `kb-error-taxonomy` defines `provider_auth` as "provider rejected our credential", which reads narrower than a billing state — flagged for the taxonomy owner rather than fixed here. |

**`openrouter-api/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 139 | S | the generation-endpoint reference page 404s from the current docs nav; only its existence and query shape are confirmed, not its field set |
| 140 | P | whether the undocumented top-level `provider` field is still populated on non-error responses |

**`opentelemetry-instrumentation/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 175 | S | exact flush point in SDK 1.15.0 not read; the absence of a background thread is not in doubt |

**`pest-testing/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 137 | V | _(bare marker)_ |
| 163 | S | TIA's exact opt-in flag and its cache invalidation rules were not read from the CLI reference |

**`postgresql-patterns/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 33 | M | the per-entry byte figures are computed from IndexTupleData (8B) + MAXALIGN'd datum + ItemIdData (4B), not measured — confirm with pgstatindex on a seeded table before quoting them |

**`preact-vite-library/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 47 | M | published figures for both disagree by 2× depending on entry points measured; re-measure against our own build before quoting a number in a PR. |
| 174 | X | acknowledged at spec level; not re-tested against every 2026 browser, so the launcher must still be legible with no stylesheet applied. |

**`prometheus-grafana-loki-tempo/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 112 | M | derived from ~1.7 bytes/sample, not measured — re-derive from `prometheus_tsdb_head_series` once real traffic exists |
| 124 | V | `matcherType` accepts `label` as well as `regex`; confirm against the pinned Grafana before relying on it, and keep a `matcherRegex` over the JSON as the fallback. |

**`pydantic-contracts/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 176 | V | that the default handler's output includes the `input` key was not re-checked against the pinned FastAPI 0.141.1 |

**`pytest-ai-service/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 128 | S | celery.contrib.pytest fixture names and the default pool of celery.contrib.testing.worker.start_worker were not re-read from source this revision |

**`qdrant-hybrid-search/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 11 | W | v1.19.0's GA status — inferred from the Docker `latest` digest, not from an announcement |

**`ragas-evaluation/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 145 | S | the mkdocstrings-generated reference pages were Cloudflare-rate-limited throughout; if a documented seed or temperature contract exists anywhere, it is there. Source at the v0.4.3 tag has neither. |
| 150 | S | both failures are read off v0.4.3 source, not executed. |

**`seaweedfs-s3/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 154 | P | not confirmed whether #6583/#6578 still reproduce on 4.40; both remain open with no closing commit |

**`security-scanning-toolchain/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 45 | X | _(bare marker)_ |
| 169 | V | confirm the default in the Composer version pinned by the image |

**`tailwind-shadcn/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 60 | D | the spec does not enumerate `theme_configuration`; an ADR should ratify this list before the first migration. |
| 125 | M | the build-time cost of `@reference` at our scale is not measured; the directive itself is documented. |

**`tanstack-query-table/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 23 | ! | `nextjs-app-router` constructs `new KbError(error_class, retryable)` with no retry-after; the two skills must be reconciled before either ships. |
| 123 | ! | no spec field; Laravel must add it |

**`valkey-keyspaces/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 165 | V | not re-checked against Laravel 13 |

**`valkey-keyspaces/references/key-catalog.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 75 | V | module packaging in the official 9.1 image not confirmed |

**`vitest-playwright/SKILL.md`**

| Line | Class | Claim / how it resolves |
|---|---|---|
| 36 | M | the abort gap was measured on msw 2.15.0 / Node 22, not stated in MSW's docs; the browser service-worker path is designed to propagate cancellation and was not measured |
| 166 | M | measured on msw 2.15.0, undocumented |


