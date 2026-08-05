---
name: bge-reranker
description: Cross-encoder reranking with bge-reranker-v2-m3 in services/ai-service/ — the loader, the score scale, and the evidence threshold that sits on it. Use whenever writing or tuning the rerank stage, setting evidence.min_score, picking a reranker model or max_length, or debugging a bot that refuses on good evidence, answers from noise, or blows the 1.5 s retrieval budget. Owns the score scale; stage order belongs to kb-rag-query-contract. Pairs with kb-chunking-rules (chunks must fit max_length).
---

# BGE Reranker

`BAAI/bge-reranker-v2-m3`, pinned by commit sha, loaded with `FlagEmbedding` ≥1.3 `FlagReranker`, scored on the **sigmoid scale**.
**Authoritative spec:** docs/07-rag-query-pipeline.md §12.11–12.12, docs/05-tech-stack.md §9.12, docs/14-reliability.md §19.6, docs/17-testing-performance.md §23

## Non-negotiables

- **The evidence threshold sits on this model's score and on nothing else.** RRF discards magnitude by construction — the top fused candidate scores `2/(k+1)` whether it is a perfect match or noise — so §12.12 is literally unimplementable on the fused score, and dual-encoder cosine is not comparable across queries either (`kb-rag-query-contract`). This file owns the scale that number is expressed on.
- **The scale is pinned to sigmoid, stored beside the threshold, and asserted at startup.** `normalize=True` on every call, `evidence.scale = "sigmoid"` in the retrieval config snapshot, and a startup assertion that a known-irrelevant fixture pair scores < 0.01. A threshold of `0.3` is strict on the logit scale and lenient on the sigmoid scale; nothing raises when the two are swapped, so the assertion is the only thing standing between a config edit and a bot that answers from noise.
- **Never buy latency with truncation.** The reranker's `max_length` must exceed the chunker's ceiling; when the budget is tight, cut `rerank.candidates`, not passage length. A dropped candidate is traced (`below_rerank_candidate_cutoff`) and costs recall visibly; a truncated passage silently changes the distribution the threshold was calibrated on.
- **The reranker is an optional dependency.** An open reranker breaker reports degraded and must never fail `/health/ready` (`kb-error-taxonomy`, `kb-observability-conventions`). §19.6 permits fused-only retrieval when bot policy allows it — but the degraded path may not threshold on the fused score. What refuses instead is defined below.
- **The threshold is a function of `(model sha, scale, loader, max_length, chunker_version)`.** Change any one and the number is void: re-tune it with an eval run and bump `retrieval_configuration_version` (§21.4). Every one of those five values goes into the config snapshot, not just the threshold.
- **The reranker scores the string that was embedded**, prefix and all (`kb-chunking-rules`), not the raw chunk body. Scoring a different string than the one retrieval matched means the reranker is judging a document that does not exist in the index, and it invalidates the stored `token_count` the length assertion depends on.

## How we use it

### Model choice — `bge-reranker-v2-m3` stays, and it is not stale

No BGE reranker has shipped since `bge-reranker-v2.5-gemma2-lightweight` on **2024-07-26**; there is no v3. The v2 family is all Apache-2.0, so licence does not decide it — size does.

| Model | Params | Verdict |
|---|---|---|
| `bge-reranker-v2-m3` | 568M (XLM-R-large, `max_position_embeddings` 8194) | **Ship this.** Same XLM-RoBERTa backbone and tokenizer as our BGE-M3 embedder (`bge-m3-embeddings`), so chunk `token_count` computed at ingest is directly reusable for the length assertion below. Ranked 1st of 54 rerankers on NanoMIRACL multilingual retrieval at 87.57 <!-- UNVERIFIED: HAKARI-Bench (arXiv:2606.22778) via search summary; I did not read the results table. --> |
| `bge-reranker-v2-gemma` | ~3B | ~5× the compute for a family that already misses the CPU budget. No. |
| `bge-reranker-v2-minicpm-layerwise` | ~3B, `cutoff_layers` 8–40 | The layerwise trick still runs a 3B forward pass to layer 8; it does not get under a 568M encoder. No. |
| `bge-reranker-v2.5-gemma2-lightweight` | gemma-2-9b base | Out of the question in-process (`fastapi-service`: every uvicorn worker loads its own copy). |
| `Qwen3-Reranker-0.6B` (non-BGE) | 0.6B, Apache-2.0 | The one credible challenger at our size, and stronger on instruction-following relevance. But it is a *generative* pointwise reranker — its "score" is a yes/no token probability, a different scale requiring a different threshold. Named A/B candidate for the first eval run (docs/16 §21), not a v1 swap. |

`jina-reranker-v2-base-multilingual` is faster and smaller but **CC-BY-NC-4.0** — unusable in a self-hostable product. Do not let a benchmark blog talk you into it.

### The two loaders disagree about everything that matters

| | `FlagEmbedding.FlagReranker` | `sentence_transformers.CrossEncoder` |
|---|---|---|
| Default score | **raw logit**, unbounded (`normalize=False`) | **sigmoid**, because `activation_fn=None` → `nn.Sigmoid()` when `num_labels == 1` |
| Default length | `max_length=512`, `query_max_length=None` | `max_length=None` → tokenizer's `model_max_length`, which is **8192** for this model |
| Truncation | `truncation='only_second'` — the **passage** loses its tail, never the query | same |

Same weights, same checkpoint, two different scales and a 16× difference in what gets truncated. We standardise on `FlagReranker` with every length and the `normalize` flag passed **explicitly** — never inherited.

### Loading and scoring

```python
# services/ai-service/app/rag/rerank.py
import time
from dataclasses import dataclass

from FlagEmbedding import FlagReranker
from prometheus_client import Counter, Histogram

MODEL_PATH = "/models/bge-reranker-v2-m3"  # baked at image build from a pinned commit sha;
                                           # never "BAAI/..." at runtime — HF resolves `main`,
                                           # and a silent weight change voids the threshold.
SCALE = "sigmoid"          # the scale `evidence.min_score` is expressed on. Stored in the snapshot.
QUERY_MAX_TOKENS = 64
PAIR_MAX_TOKENS = 800      # ≥ kb-chunking-rules MAX_TOKENS (700) + query + specials.
                           # Above the 512 BAAI fine-tunes at; validate on the eval set.

_dur = Histogram("kb_retrieval_duration_seconds", "retrieval stage latency", ["stage"])
_empty = Counter("kb_retrieval_empty_total", "queries yielding no evidence", ["reason"])


def load_reranker(device: str) -> FlagReranker:
    """Called once from FastAPI `lifespan`. ~2.3 GB fp32 resident, per process."""
    model = FlagReranker(
        MODEL_PATH,
        devices=[device],
        use_fp16=device.startswith("cuda"),  # x86 CPUs have no fp16 GEMM kernel — see Gotchas
        query_max_length=QUERY_MAX_TOKENS,
        max_length=PAIR_MAX_TOKENS,
        normalize=True,                      # sigmoid. The single most load-bearing kwarg here.
    )
    # Scale assertion: an obviously irrelevant pair must land near 0, not near -5.
    probe = model.compute_score([("what is a panda?", "Ship hulls are welded, not riveted.")],
                                normalize=True)
    assert 0.0 <= probe[0] < 0.01, f"reranker scale is not {SCALE}: probe={probe[0]}"

    # Budget check: 25 pairs at full length must fit the §23 slice. Log-and-degrade, never
    # fail readiness — the reranker is optional (kb-observability-conventions).
    t0 = time.perf_counter()
    model.compute_score([("q" * 40, "p" * 2000)] * 25, normalize=True)
    model.warm_rerank_seconds = time.perf_counter() - t0
    return model


@dataclass(frozen=True)
class Scored:
    candidate: "Candidate"
    score: float  # sigmoid, [0, 1]


def rerank(model, query: str, cands: list["Candidate"], cfg, trace) -> list[Scored]:
    pool = cands[: cfg.rerank_candidates]
    for c in cands[cfg.rerank_candidates:]:
        trace.exclude(c, "below_rerank_candidate_cutoff")

    # Query FIRST, passage second — reversing them raises nothing and quietly destroys ranking.
    # `embed_text` is the exact string that was embedded (heading prefix included).
    pairs = [(query, c.embed_text) for c in pool]
    for c in pool:  # truncation would silently move the score off the calibrated distribution
        assert c.token_count + QUERY_MAX_TOKENS + 4 <= PAIR_MAX_TOKENS, c.chunk_id

    with _dur.labels(stage="rerank").time():
        raw = model.compute_score(pairs, normalize=True, batch_size=len(pairs))
    scores = [raw] if isinstance(raw, float) else raw  # a single pair returns a scalar, not a list

    out = sorted((Scored(c, s) for c, s in zip(pool, scores)), key=lambda x: -x.score)
    trace.rerank = [(x.candidate.chunk_id, x.score, SCALE) for x in out]
    return out


def apply_threshold(scored: list[Scored], cfg, trace) -> list[Scored]:
    """Stage 12. `cfg.evidence_min_score` is on `cfg.evidence_scale`, which must equal SCALE."""
    assert cfg.evidence_scale == SCALE, "threshold calibrated on a different scale"
    passing = [x for x in scored if x.score >= cfg.evidence_min_score]
    for x in scored:
        if x.score < cfg.evidence_min_score:
            trace.exclude(x.candidate, "below_evidence_threshold", score=x.score)
    for x in passing[cfg.rerank_retain:]:
        trace.exclude(x.candidate, "above_retain_limit", score=x.score)
    if not passing:
        trace.insufficient_evidence = True
        _empty.labels(reason="below_threshold").inc()
    return passing[: cfg.rerank_retain]
```

### The threshold: ship `evidence.min_score = 0.30` on the sigmoid scale

0.30 is logit ≈ −0.85 — "the model leans irrelevant but is not confident". It sits far above the mass of genuinely off-topic pairs (the model card's own irrelevant example scores −5.65 → **0.0035**) while still failing candidates the cross-encoder actively rates against. It is a starting point, and shipping it untuned to a customer corpus is the defect §12.12 warns about.

Tune it once per corpus shape, not per intuition:

1. Take ≥50 answerable and ≥50 unanswerable questions from the eval set (docs/16 §21.2).
2. Score both sets through the full pipeline; keep the **top-1 rerank score per question**.
3. Set `evidence.min_score` to the **5th percentile of the answerable top-1 distribution** — i.e. accept ~5% false refusals, the failure users actually complain about.
4. Report the resulting false-answer rate: the fraction of unanswerable questions whose top-1 clears it. If that exceeds ~10%, the two distributions overlap and the problem is retrieval or chunking, not the threshold — raising the threshold from here trades answerable recall 1-for-1.
5. Re-run on any change to the five snapshot values. The number is not portable across models, scales, or chunker versions.

One knob serves two decisions — the per-chunk packing floor and the refusal gate. Keep it that way for v1. If tuning pulls them apart (the floor that avoids false refusals lets junk into the context), that is the signal to split `evidence.min_score` from `evidence.refuse_below` — record it in docs/22 as a contract change, don't fork it locally.

### Latency, and what the CPU profile does to §23

§23 budgets 1.5 s for the whole retrieval leg. Reranking is the most expensive stage in it.

| Deployment | 25 candidates @ ≤512 tokens |
|---|---|
| A10-class GPU, fp16 | ~0.1–0.4 s <!-- UNVERIFIED: extrapolated from a Medium benchmark measuring 100 contexts in 1.4 s on an A10 and a TEI/A100 figure of ~800 pairs/s; I measured neither. --> |
| CPU, torch fp32 | **seconds, not milliseconds** — the same benchmark measured 100 contexts in **257 s** on a small CPU host <!-- UNVERIFIED: single blog measurement, hardware unspecified. --> |
| CPU, ONNX or OpenVINO int8, `max_length` cut | still likely over budget at 25 candidates |

**On CPU, this model at the shipped candidate count does not fit the budget — not marginally, by an order of magnitude.** So: the `gpu` Compose profile (docs/18 §24.5) is a requirement, not an optimisation, for any deployment that claims §23. A CPU-only deployment has exactly two honest options — run ONNX/OpenVINO int8 with `rerank.candidates` cut to a *measured* value, or set `rerank.enabled = false` and take the degraded path below. Quietly leaving it on and eating 10 s is not one of them.

The startup benchmark in `load_reranker` exists because a wall-clock deadline cannot save you: `anyio.to_thread.run_sync` defaults to `abandon_on_cancel=False`, so a running `compute_score` cannot be cancelled (`fastapi-service`). **Bound the work, not the clock** — cap `candidates × max_length` at a measured budget and report degraded when the warm benchmark exceeds `rerank.budget_ms`.

### When the reranker is unavailable, what refuses?

Breaker open, model unloaded, or `rerank.enabled = false`. There is no score, therefore no threshold. Do **not** substitute the fused score — that is the exact bug §12.12 exists to prevent.

- **Bot policy forbids degraded retrieval (default for strict-RAG bots):** fail the request as `internal_dependency` with `degraded=True` (`kb-error-taxonomy`). A dependency outage is not an evidence outage and must not be reported to the user as "not in your sources".
- **Bot policy permits it (§19.6):** keep only candidates that carry **both** a `dense_rank` and a `sparse_rank` — branch agreement is the one relevance signal RRF preserves, because it is structural rather than magnitude-based — cap to `rerank.retain`, and pack those. If no candidate appears in both branches, refuse with `insufficient_evidence` and `kb_retrieval_empty_total{reason="no_match"}`.
- Either way: `kb.degraded = true` on the span, the event recorded per §19.6, and the answer still fully cited. Degraded answers are excluded from eval regression baselines — they are measuring a different pipeline.

## Gotchas

- **The refusal rate flips to ~0% or ~100% after a refactor that changed no numbers.** Someone swapped `FlagReranker` for `sentence_transformers.CrossEncoder` (or dropped `normalize=True`). `CrossEncoder` applies `nn.Sigmoid()` by default for `num_labels=1`; `FlagReranker` does not. A threshold of `0.3` means "logit 0.3, sigmoid 0.57, strict" under one and "sigmoid 0.3, lenient" under the other — so one direction refuses on good evidence and the other answers from noise, and neither raises. The startup probe assertion in `load_reranker` is the only detector.
- **A chunk whose answer is in its last third never clears the threshold, while the playground shows it retrieved with a strong dense score.** `truncation='only_second'` truncated the passage at `max_length` (default **512**), so the reranker scored a prefix while the packer would have packed the whole chunk. Our chunker ships up to 700 tokens (`kb-chunking-rules`), so this fires on the largest, most information-dense chunks — tables and long policy sections. Fix is the length assertion above, not a smaller chunk.
- **`RuntimeError: "addmm_impl_cpu_" not implemented for 'Half'`, or the CPU container is several times slower than the fp32 one.** `use_fp16=True` copied from the model card, which assumes CUDA. x86 CPUs have no fp16 GEMM kernel; torch either refuses or emulates. Gate `use_fp16` on the device string; on CPU use bf16 (needs AVX512-BF16/AMX) or int8, never fp16.
- **Reranking barely changes the order — NDCG sits on top of the fused baseline and the whole stage looks pointless.** The pair tuple is `(passage, query)`. The model returns a perfectly plausible float either way; nothing errors. Cross-encoders are order-sensitive: query first, always, and assert it in a unit test with an obviously-asymmetric fixture.
- **Score-weighted packing or averaging treats a perfect match and a mediocre one as identical.** The sigmoid saturates: logits 3 and 12 both map to ≈0.95–1.0. Normalized scores are for the threshold and the playground only. If you ever need arithmetic on scores — weighting, averaging, a fusion of reranker with something else — do it on the logit and convert once at the end.
- **A single reranked query returns a float and the caller crashes on `len()`.** `compute_score` returns a scalar for one pair and a list for many. Bites exactly at `rerank.candidates = 1` in a test fixture or when dedup collapses the pool to one survivor.
- **The container hangs at `Waiting for application startup.` on a fresh host, and the healthcheck kills it in a loop.** The weights were not in the image, so lifespan is downloading 2.3 GB from HuggingFace with no timeout. Bake the model into the image (or a mounted volume) at a pinned commit sha, and set `start_period` above the measured cold-load time (`fastapi-service`).
- **Memory triples after "just adding workers".** Each uvicorn worker loads its own ~2.3 GB copy alongside the embedder. One process per container, scale by replica (`fastapi-service`).
- **Refusal rate differs sharply between languages on the same corpus.** Cross-encoder logits are better calibrated than cosine but are not calibrated *across* languages or passage lengths, so one global threshold is really a per-language operating point. Measure per-language in step 2 of the tuning procedure; if the spread is material, the threshold moves to per-bot config — bots are usually single-language. <!-- UNVERIFIED: mechanism is standard for cross-encoders; I found no measurement of the magnitude for this model. -->
- **A pod restart loop whenever the reranker is slow or down.** Readiness checked the reranker. It is an optional dependency: degraded, never unready (`kb-error-taxonomy`, `kb-observability-conventions`).

## Official docs

- [BAAI/bge-reranker-v2-m3 model card](https://huggingface.co/BAAI/bge-reranker-v2-m3) — the `normalize` flag, the worked `-5.65 → 0.0035` example, Apache-2.0.
- [FlagEmbedding — BGE-Reranker-v2 docs](https://bge-model.com/bge/bge_reranker_v2.html) — the family table, `FlagReranker` / `LayerWiseFlagLLMReranker` / `LightWeightFlagLLMReranker`, `cutoff_layers`, `compress_ratio`.
- [`FlagEmbedding/abc/inference/AbsReranker.py`](https://github.com/FlagOpen/FlagEmbedding/blob/master/FlagEmbedding/abc/inference/AbsReranker.py) and [`inference/reranker/encoder_only/base.py`](https://github.com/FlagOpen/FlagEmbedding/blob/master/FlagEmbedding/inference/reranker/encoder_only/base.py) — the real defaults (`max_length=512`, `normalize=False`, `batch_size=128`) and the `truncation='only_second'` call.
- [FlagEmbedding model list](https://github.com/FlagOpen/FlagEmbedding) — release dates; confirms no reranker after 2024-07-26.
- [Sentence Transformers — CrossEncoder API](https://sbert.net/docs/package_reference/cross_encoder/cross_encoder.html) — the `activation_fn=None → nn.Sigmoid()` default that makes the other loader disagree.
- [Sentence Transformers — CrossEncoder inference efficiency](https://sbert.net/docs/cross_encoder/usage/efficiency.html) — ONNX / OpenVINO / int8 backend selection for the CPU profile.
- [Qwen/Qwen3-Reranker-0.6B](https://huggingface.co/Qwen/Qwen3-Reranker-0.6B) — the A/B candidate, and why its score is a different scale.

## Definition of done

- [ ] Model loaded from a path baked at a pinned commit sha; the sha, `SCALE`, loader name, `max_length`, and `chunker_version` all appear in the retrieval config snapshot.
- [ ] `normalize=True` passed explicitly at every call site; the startup probe asserts an irrelevant pair scores < 0.01 and the process refuses to serve otherwise.
- [ ] `assert chunk.token_count + query_max_length + 4 <= max_length` runs per candidate; a fixture with a 700-token chunk asserts it does **not** truncate.
- [ ] A unit test scores a deliberately asymmetric pair both ways and asserts `(query, passage)` scores higher — pair order is locked.
- [ ] `evidence.min_score` derived from the P5-of-answerable procedure with the eval run id recorded; an unanswerable question produces a refusal, `insufficient_evidence`, and `kb_retrieval_empty_total{reason="below_threshold"}`.
- [ ] `kb_retrieval_duration_seconds{stage="rerank"}` emitted; the warm startup benchmark is logged and compared to `rerank.budget_ms`, and retrieval end-to-end is measured under 1.5 s on the target profile (docs/17 §23) — or the deployment is on record as GPU-required.
- [ ] Reranker down: `/health/ready` stays green, `kb.degraded=true` is set, and a test asserts the degraded path uses branch agreement — never the fused score — and that a policy-forbidden bot gets `internal_dependency`, not a refusal.
- [ ] `use_fp16` gated on a CUDA device (CPU-profile smoke test loads and scores without raising), and `compute_score`'s scalar-vs-list return handled with a single-candidate test.
