---
name: bge-reranker
description: Reranking doctrine for KnowledgeBot AI, and the record of why the bge-reranker-v2-m3 cross-encoder is no longer loaded here — ADR-030 moved reranking to provider APIs, which makes it capability-gated rather than guaranteed. Use whenever writing or tuning the rerank stage, setting an evidence threshold, deciding what happens when a provider cannot rerank, or debugging a bot that refuses on good evidence, answers from noise, or blows the retrieval budget. Owns the score scale and the skip-versus-failure distinction; stage order belongs to kb-rag-query-contract.
---

# Reranking

**There is no local cross-encoder in this repository.** ADR-030: every ML task is an external API
call. The loader, the thread offload, the dedicated `CapacityLimiter`, the fp16 device gate and
the `max_length` pin are all gone — every one of them was a consequence of the model running
in-process.

Reranking is `rerank(req: RerankRequest) -> RerankResult`, a capability on the existing
five-provider adapter layer, with the org's own encrypted credential, the org's quota, and the
same 18-class error taxonomy as chat. **Not `rerank(query, passages, *, model) -> list[float]`**,
which is the shape `app/providers/contract.py` rejects by name and for two reasons: a bare
`(query, passages, model)` has nowhere to put `org_id`, `trace_id` or `provider_connection_id`,
so a call that ships a tenant's document text to a vendor cannot name the tenant it bills; and a
bare `list[float]` cannot carry the score scale, which forces `Scored.scale` to be copied from
the calibration and turns stage 12's scale assertion into a comparison of a value with itself.

**Implementation:** `services/ai-service/app/rag/rerank.py` (`RerankCalibration`,
`RerankSkipReason`, `rerank_gate`, `calibration_for`) and `app/rag/evidence.py`
(`apply_threshold`, `select_unranked`). `RerankScale` is **imported** from
`app/providers/contract.py` and re-exported, never redefined — this module carried a local copy
of that name with a different member set, and two enums of one name with no conversion is how a
score's meaning gets lost across a boundary that type-checks.
**Authoritative spec:** docs/07-rag-query-pipeline.md §12.11–12.12, docs/05-tech-stack.md §9.12,
docs/14-reliability.md §19.6, docs/17-testing-performance.md §23 — all describe the local
cross-encoder and are **superseded by ADR-030 on that point**; see docs/22, finding C1.

## Non-negotiables

- **Reranking is capability-gated, not guaranteed — and the gate has two halves that are routinely
  collapsed into one.** Whether a *vendor publishes* a ranking endpoint and whether *this platform
  can threshold* what comes back are different questions, and a provider needs both. Neither is a
  vendor name to be written into prose: the authoritative matrix is
  `services/ai-service/app/providers/capabilities.py` — `PROVIDER_TASKS` for the first question,
  `RERANK_SCALE` for the second — and both are pinned by
  `tests/unit/test_provider_capability_matrix.py`. Measure rather than trust this sentence:

  ```bash
  cd services/ai-service && .venv/bin/python -c "from app.providers.capabilities import providers_offering, RERANK_SCALE; from app.providers.errors import ProviderSurface; print(sorted(providers_offering(ProviderSurface.RERANK)), sorted(RERANK_SCALE))"
  # 2026-08-12: ['nvidia_nim', 'openrouter'] ['nvidia_nim']
  ```

  As measured on that date: **two vendors publish a ranking route and one is eligible.** OpenAI,
  Anthropic and DeepSeek publish none. OpenRouter publishes `POST /api/v1/rerank` and is still not
  rerank-eligible (finding #47) — the route fronts several upstream cross-encoders on one
  credential with no documented normalization across them, so its scale is `UNCALIBRATED` and
  `RerankCalibration` refuses to be constructed on it. A local model always worked, which is why
  stage 11 could once be written as unconditional. It cannot be now. Any code, doc or test that
  treats rerank as a mandatory stage — **or that names one vendor as "the only reranker"** — is
  stale.
- **A skip is not a failure, and a failure is never recorded as a skip.** `RerankSkipReason`
  (`app/rag/rerank.py`, the authoritative list) is **closed** and contains only reasons known
  *before* the call goes out — a vendor with no ranking route, a vendor whose score scale this
  platform cannot threshold, a model nobody configured, a switch an administrator turned off, a
  deadline too short to start. There is deliberately no member for "the provider errored", "the
  call timed out" or "the response did not parse": those are errors in `kb-error-taxonomy` terms
  and they propagate as errors. A skip degrades ranking visibly and measurably; an outage
  laundered into a skip degrades ranking *and* hides an incident, and its only symptom is answer
  quality drifting for as long as nobody looks.
- **The two capability reasons are two reasons, not one** (finding #47).
  `provider_lacks_capability` says the *vendor* publishes no ranking route and is fixed by moving
  the bot to one that does; `provider_scale_uncalibrated` says the vendor publishes one and *this
  platform* cannot consume its scores, which no evaluation run fixes. Collapsing them sends an
  operator to read documentation that will tell them the endpoint exists, and then costs them a
  corpus run that ends at `RerankCalibration.__post_init__` weeks later.
- **The skip is never silent.** The stage is never jumped. When the provider cannot rerank, serve
  the RRF-fused order, record the reason on the trace, and set the degraded marker.
- **No LLM-scoring fallback.** Scoring passages by asking a chat model is a legitimate
  *configuration* and an illegitimate *fallback*. It changes cost and latency by an order of
  magnitude, and a fallback nobody chose does that silently, on the request path, at whatever
  volume the traffic happens to be. If it is wanted it is switched on deliberately, per bot, and
  it is then the configured reranker rather than a substitute for one.
- **There is no portable default threshold, and `CALIBRATIONS` is empty on purpose.** An
  uncalibrated model is one a bot may not threshold against. The honest behaviour is to raise
  `RerankNotCalibrated` at configuration time rather than answer with a number borrowed from a
  different model's distribution.
- **The evidence threshold still sits on a rerank score and on nothing else.** RRF discards
  magnitude by construction — the top fused candidate scores `2/(k+1)` whether it is a perfect
  match or noise — so §12.12 is literally unimplementable on the fused score, and dual-encoder
  cosine is not comparable across queries either. **When reranking is skipped there is no
  threshold**; what happens instead is defined below and it is not "threshold the fused score".
- **Never buy latency with truncation.** When the budget is tight, cut candidate depth, not
  passage length. A dropped candidate is traced and costs recall visibly; a truncated passage
  silently changes the distribution the calibration was derived from.

## How we use it

### The score scale is now provider-and-model dependent

The old repo-wide `SCALE = "sigmoid"` and `evidence.min_score = 0.30` were properties of
`bge-reranker-v2-m3` under `normalize=True`. Both are **void**.

| Provider shape | Scale |
|---|---|
| NVIDIA NIM ranking models | **unbounded logit** |
| Cohere-shaped ranking APIs | bounded relevance score, roughly [0, 1] |
| A generative pointwise reranker | a yes/no token probability — a third scale again |

`0.30` is a valid float on every one of those. Nothing raises when they are swapped; only the
refusal rate moves, and only in aggregate. Hence `RerankCalibration` is keyed by
`(provider, model)` and validates its own scale in `__post_init__` — a bounded scale rejects a
`min_score` outside 0..1, which is the logit-pasted-into-a-bounded-field case.

A calibration is a function of `(provider, model, scale, candidate depth, chunker version)` plus
the eval run that produced it. `derived_from` is required and must name that run: a threshold
with no evaluation behind it is a guess.

### Deriving a threshold, once per (provider, model) and corpus shape

1. Take ≥50 answerable and ≥50 unanswerable questions from the eval set (docs/16 §21.2).
2. Score both sets through the full pipeline; keep the **top-1 rerank score per question**.
3. Set `min_score` to the **5th percentile of the answerable top-1 distribution** — i.e. accept
   ~5% false refusals, the failure users actually complain about.
4. Report the resulting false-answer rate: the fraction of unanswerable questions whose top-1
   clears it. Above ~10% the two distributions overlap and the problem is retrieval or chunking,
   not the threshold — raising it from there trades answerable recall 1-for-1.
5. Re-derive on any change to the tuple above. The number is not portable across providers,
   models, scales or chunker versions. It never was portable across models; what changed is that
   the model can now differ **per organization**.

One knob serves two decisions — the per-chunk packing floor and the refusal gate. Keep it that
way for v1. If tuning pulls them apart, record it in docs/22 as a contract change; do not fork it
locally.

### When reranking does not happen, what refuses?

Skipped (no ranking route at the vendor, an uncharacterized score scale, no configured model,
disabled, or no time), or failed (an error, which is a different path). There is no score,
therefore no threshold. Do **not** substitute the fused score — that is the exact bug §12.12
exists to prevent.

- **Skipped, and bot policy permits degraded retrieval (§19.6):** keep only candidates carrying
  **both** a dense rank and a sparse rank — branch agreement is the one relevance signal RRF
  preserves, because it is structural rather than magnitude-based — cap to the retain limit, and
  pack those. If no candidate appears in both branches, refuse with `insufficient_evidence`.
  **Note this arm depends on there being two branches**, which finding C2 has put in question: if
  hybrid search resolves to dense-only, branch agreement is not available and this rule needs a
  replacement rather than a silent degradation to "take the top N".
- **Skipped, and bot policy forbids degraded retrieval (default for strict-RAG bots):** fail the
  request. A dependency gap is not an evidence gap and must not be reported to the user as "not
  in your sources".
- **Failed:** the provider error propagates with its own class. Never recorded as a skip.
- Either way: the degraded marker on the span, the event recorded per §19.6, and the answer still
  fully cited. Degraded answers are excluded from eval regression baselines — they measure a
  different pipeline.

### Latency has a different shape now

The old failure was a CPU forward pass blocking the event loop for seconds. The new one is
**round-trips**. Ranking APIs cap the passages accepted per request, so a candidate depth above
that cap becomes several sequential HTTP calls unless issued concurrently — an unbatched loop
over 25 candidates is 25 round-trips inside a 1.5 s leg.

Batch size and concurrency are properties of the provider and belong in the adapter. What belongs
here is the rule: **stage 11 must be able to state its cost before it starts, and must decline
rather than overrun.** `RERANK_MIN_USEFUL_SECONDS` is that floor — below it the stage skips with
a reason rather than starting work it cannot finish.

One thing genuinely got better: reranking no longer requires a GPU, so the deployment profile
that once made §23's 1.5 s budget GPU-only is gone along with the `gpu` Compose overlay.

## Gotchas

- **The refusal rate flips to ~0% or ~100% after a provider or model change that touched no
  numbers.** The scale changed underneath the threshold. This is the same failure the old
  sigmoid-versus-logit swap produced, except the trigger is now a per-org configuration edit
  rather than a code refactor — so it can happen for one tenant and not the rest. The scale
  assertion in `RerankCalibration.__post_init__` catches the bounded case; the unbounded case
  needs the calibration to exist at all, which is why `CALIBRATIONS` raises rather than defaults.
- **Answer quality drifts for weeks and no alert fires.** A provider outage was caught and
  recorded as a skip. This is precisely what the closed `RerankSkipReason` prevents, and it is
  why adding a `PROVIDER_ERROR` member to that enum is a bug and not a convenience.
- **A bot on one provider silently ranks worse than the same bot on another and nobody connects
  the two.** Working as designed, but it must be *visible*: the skip reason belongs on the trace
  and in a metric, or the capability gap reads as a quality problem in the retrieval stack.
- **An operator is told their provider "lacks the capability", reads the vendor's docs, and finds
  the endpoint documented.** The gate reported `provider_lacks_capability` for an eligibility
  failure that was really the uncharacterized scale — the OpenRouter case. Report
  `provider_scale_uncalibrated` there; `rerank_gate` takes the vendor axis and the scale axis as
  separate arguments precisely so a `False` can be attributed rather than guessed at.
- **Reranking barely changes the order and the whole stage looks pointless.** Check the argument
  order at the adapter boundary. Cross-encoders and ranking APIs are order-sensitive — query
  first, passages second — and both return perfectly plausible floats when reversed. Assert it
  with an obviously asymmetric fixture.
- **Score-weighted packing or averaging treats a perfect match and a mediocre one as identical.**
  A bounded scale saturates: very different logits map to nearly the same number near the top.
  Bounded scores are for the threshold and the playground only. Arithmetic on scores —
  weighting, averaging, fusing with something else — happens on the unbounded value if the
  provider exposes one, and not at all if it does not.
- **A single-candidate rerank crashes the caller.** Several ranking APIs special-case a
  one-element passage list. Bites exactly when the candidate depth is 1 in a fixture, or when
  dedup collapses the pool to one survivor.
- **A deadline cannot save a call already in flight.** The old form of this was
  `anyio.to_thread.run_sync` defaulting to `abandon_on_cancel=False`; the new form is an HTTP
  request whose cancellation still leaves the provider billing for the work. Bound the work
  before starting it — that is what `rerank_gate` is for — rather than bounding the clock around it.
- **The retrieval budget is spent before reranking starts.** Now that this is a network call
  inside a leg that already contains network calls, the arithmetic in `app/core/config.py` is
  load-bearing: attempts × (inner timeout + max backoff) + overhead must stay under the outer
  timeout, or the caller has abandoned the response we are still paying for.
- **Someone adds the reranker to `/health/ready`.** It was an optional dependency before and it is
  an external one now, which makes this strictly worse: readiness must never probe a provider.
  Traefik drops unhealthy containers and Compose's `restart:` reacts to a process exiting, not to
  a failing healthcheck, so one provider blip pulls every replica out of rotation at once with
  nothing able to restore them.

## Official docs

- [NVIDIA NIM retrieval / ranking models](https://docs.nvidia.com/nim/) — the one rerank-eligible
  provider today (its `LOGIT` scale is the only entry in `RERANK_SCALE`); see `nvidia-nim-api` for
  the adapter and the per-model request schema divergence.
- [OpenRouter `/api/v1/rerank`](https://openrouter.ai/docs/client-sdks/python/sdks/rerank) — the
  second published ranking route, Cohere-shaped, and the one this platform cannot threshold. See
  `openrouter-api` and finding #47.
- [BAAI/bge-reranker-v2-m3 model card](https://huggingface.co/BAAI/bge-reranker-v2-m3) —
  historical. The worked `-5.65 → 0.0035` example is still the clearest illustration of why a
  bare threshold number means nothing without its scale.

## Definition of done

- [ ] `RerankSkipReason` contains no member meaning the provider errored, timed out, or returned
      something unparseable; a test asserts the enum's exact membership.
- [ ] A provider without the capability produces a skip with a reason on the trace and a degraded
      marker — never a silent pass-through and never an error.
- [ ] The two capability skip reasons are distinguished by a test: a vendor with no ranking route
      yields `provider_lacks_capability`, and a vendor that publishes one whose scale is absent
      from `RERANK_SCALE` yields `provider_scale_uncalibrated`. Both are pre-call.
- [ ] No skill, doc, comment or test names a single vendor as the only reranker; the matrix is
      read from `app/providers/capabilities.py` and pinned by
      `tests/unit/test_provider_capability_matrix.py`.
- [ ] A provider that *errors* produces an error with its taxonomy class — a test asserts it is
      not recorded as a skip.
- [ ] `calibration_for(provider, model)` raises `RerankNotCalibrated` for an unconfigured pair; a
      test asserts a bot cannot threshold against an uncalibrated model.
- [ ] A bounded-scale calibration with `min_score` outside 0..1 raises at construction.
- [ ] `derived_from` is non-empty on every calibration; a test asserts a threshold cannot be
      constructed without naming the eval run behind it.
- [ ] The degraded path uses branch agreement, never the fused score; a test asserts it, and a
      second test pins what happens if only one branch exists (finding C2).
- [ ] A policy-forbidden bot fails rather than refusing, and a test distinguishes the two — a
      dependency gap reported as "not in your sources" is the user-visible bug this prevents.
- [ ] Pair order is locked by a test with a deliberately asymmetric fixture.
- [ ] A single-candidate rerank is covered.
- [ ] Stage 11 declines below `RERANK_MIN_USEFUL_SECONDS` rather than starting a call it cannot
      finish inside the retrieval budget.
