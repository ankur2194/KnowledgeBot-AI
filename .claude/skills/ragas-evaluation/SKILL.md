---
name: ragas-evaluation
description: Ragas 0.4.3 as the LLM-judged half of the RAG evaluation suite in services/ai-service/app/evaluation/. Use whenever building or changing an eval run, picking or pinning a judge model, adding a metric, wiring the regression gate, or explaining why a faithfulness number moved. Judge calls are provider calls and go through our adapter, our quota, and our error taxonomy — never Ragas' own LLM clients. Measures the pipeline kb-rag-query-contract defines; never redefines a stage or a default. Pairs with celery-workers (the evaluate queue).
---

# Ragas Evaluation

`ragas==0.4.3` (released 2026-01-13, `requires_python >=3.9`), installed **only** in the `ai-worker-evaluation` image. Judge and embedder are our own provider adapters.
**Authoritative spec:** docs/16-evaluation.md §21, docs/11-data-model.md §16.7, docs/07-rag-query-pipeline.md §12.12/§12.19, docs/17-testing-performance.md §22–23, docs/13-security.md §18.10

## Non-negotiables

- **An eval run executes the real query pipeline, including the tenant filter.** The harness calls the same entry point chat does, so all 20 stages of `kb-rag-query-contract` run and `tenant_filter(ctx, allowed_version_ids)` (`kb-tenancy-isolation`) is constructed by the same code. A harness that queries Qdrant directly — or passes `allowed_version_ids=None` "because it's just eval" — measures a system we do not ship, and scores a corpus wider than any tenant can see. Retrieval quality then looks *better* than production.
- **Every judge call is a provider call.** It goes through `kb-provider-adapter-contract`, writes its own `provider_calls` row with `operation="evaluation.judge"`, counts against the organization's quota and cost, and maps failures into `kb-error-taxonomy`. Ragas ships its own OpenAI/LangChain clients; using them puts an un-metered, un-traced, un-rate-limited egress path into the data plane and bills a tenant's eval to nobody.
- **The judge model is pinned to a dated snapshot, stored in `evaluation_runs.model_configuration`, and compared only against runs with the identical value.** Scores are not comparable across judge models or across versions of one model. An alias (`gpt-5.2`, `deepseek-v4-flash`) is a moving judge — `deepseek-api` documents that alias rolling in place — so a baseline drifts overnight with no config change and the delta gets attributed to retrieval.
- **The dataset must contain unanswerable questions, and the run must report a false-refusal rate and a false-answer rate.** The evidence threshold (stage 12) is what makes the bot refuse; a dataset of only answerable questions cannot detect a threshold set to 0 or to 10. §21.5 blocks a release on "unanswerable-question hallucination increases" — that number does not exist unless the dataset creates it.
- **Eval content lands in the eval store, never in logs or traces.** `evaluation_results` holds the question, the generated answer, and retrieved evidence — verbatim tenant text. `kb-observability-conventions` bans it from Loki, which has no per-tenant access control, and capture is gated on the organization's §18.10 privacy switches (`kb-security-baseline`). CI runs against the synthetic `samples/` corpus, which has no tenant and therefore no switch to honour.
- **A single Ragas score is never a release gate on its own.** Instance-level agreement with humans is near noise; system-level ranking is usable. §21.3's "automated metrics must be supplemented with human review" is a hard requirement, not advice — see Gotchas.

## How we use it

### The metric set we ship, and what we rejected

Ragas 0.4.3 moved everything to `ragas.metrics.collections`; the legacy `ragas.metrics` namespace is deprecated and **removed at v1.0**. Every collections metric is `await metric.ascore(**kwargs) -> MetricResult` (`.value`, `.reason`, `.traces`; `float()` works). Cost is the deciding factor — each call is a real provider call at our prices.

| Metric | `ascore` kwargs | Needs `reference` | LLM calls/case | Verdict |
|---|---|---|---|---|
| `Faithfulness` | `user_input, response, retrieved_contexts` | no | **2** | **Ship.** Groundedness is the product claim. |
| `ContextRecall` | `user_input, retrieved_contexts, reference` | **yes** | **1** | **Ship.** Cheapest signal that retrieval found the answer. |
| `ContextPrecisionWithReference` | `user_input, reference, retrieved_contexts` | **yes** | **N, one per context** | Experiments only — a `for` loop over `retrieved_contexts` with an `await` inside, so it costs `rerank.retain` (6–10) calls *and* serializes. See Gotchas: the docs say 1. |
| `ResponseGroundedness` | `response, retrieved_contexts` | no | 2 | Reject — duplicates Faithfulness at the same price, and never sees `user_input`. |
| `AnswerRelevancy` | `user_input, response` | no | 3 + 2 embedding | **Reject.** See Gotchas: it zeroes correct refusals and never sees the contexts. |
| `NoiseSensitivity` | `user_input, response, reference, retrieved_contexts` | yes | 4+ | Reject for routine runs; diagnostic only. |
| `AnswerCorrectness` | — | yes | 3 | Reject — overlaps human review, which we do anyway. |

Shipped set = **3 judge calls per case**. The rest of §21.3 costs **zero** LLM calls and is computed deterministically from `retrieval_traces` and `citations`: retrieval hit rate, source recall against `evaluation_cases.expected_sources`, citation correctness and completeness, latency, cost, and both refusal rates. Compute those first; they are more actionable and they cannot drift.

**Budget it.** 500 cases × 3 calls = 1,500 judge calls per run, each re-sending the question plus all packed contexts. Adding `ContextPrecisionWithReference` at `rerank.retain=8` takes it to 5,500 — a 3.7× bill for a metric that is not in the regression gate. Adding the full suite takes it past 8,000.

### Running it

```python
# services/ai-service/app/evaluation/runner.py
import math
from ragas.metrics.collections import Faithfulness, ContextRecall
from ragas.llms.base import InstructorBaseRagasLLM

class AdapterJudge(InstructorBaseRagasLLM):
    """Ragas' judge, routed through OUR provider adapter.

    The ABC is exactly two methods and carries no RunConfig and no callbacks, so
    timeout, retry, concurrency and token metering are entirely ours — which is
    correct anyway: kb-error-taxonomy owns retry policy, not a library default.
    """
    def __init__(self, adapter, caps, spec, ctx, meter):
        self.adapter, self.caps, self.spec, self.ctx, self.meter = adapter, caps, spec, ctx, meter

    async def agenerate(self, prompt: str, response_model):
        req = ChatRequest(
            org_id=self.ctx.org_id, bot_id=self.ctx.bot_id, trace_id=self.ctx.trace_id,
            provider_connection_id=self.spec.provider_connection_id,
            model=self.spec.model,              # dated snapshot, never an alias
            system="You are an evaluation judge. Answer only with the requested JSON.",
            messages=[Message(role="user", content=prompt)],   # judged text is DATA, not instruction
            response_schema=response_model.model_json_schema(),  # needs Capability.STRUCTURED_OUTPUT
            temperature=0.0, max_output_tokens=1024, stream=False,
            timeouts=self.ctx.timeouts,
        )
        result = await self.adapter.complete(req, self.caps)
        # One provider_calls row per judge call, attributed to the org that owns the dataset.
        self.meter.record(result, operation="evaluation.judge", run_id=self.ctx.run_id)
        return response_model.model_validate_json(result.text)

    def generate(self, prompt, response_model):
        raise NotImplementedError("the eval worker is async; the sync path must never be reached")


async def score_case(case, ctx, judge) -> dict:
    # THE REAL PIPELINE. Same entry point as chat, so stages 1–20 and the four
    # mandatory Qdrant filters are built by production code, not by the harness.
    turn = await chat_pipeline.answer(
        org_id=ctx.org_id, bot_id=ctx.bot_id, question=case.question,
        config=ctx.retrieval_config,          # frozen: retrieval_configuration_version
    )
    contexts = [e.block.text for e in turn.trace.packed]
    refused = turn.trace.insufficient_evidence

    # Refusal path first — deterministic, free, and the only thing that tests stage 12.
    row = {
        "refused": refused,
        "false_refusal": refused and not case.unanswerable,      # threshold too high
        "false_answer": (not refused) and case.unanswerable,     # threshold too low → invention
        "crag_score": 0 if refused else (1 if case.unanswerable is False else -1),
        "source_recall": _recall(turn.trace, case.expected_sources),
        "citations_valid": all(c.chunk_id in turn.trace.packed_ids for c in turn.citations),
        "latency_ms": turn.trace.total_ms,
    }

    # A refusal has no claims to ground, and Faithfulness returns NaN on zero extracted
    # statements. Skip it explicitly and record the skip, so the denominator stays honest.
    if refused or not contexts:
        row |= {"faithfulness": None, "context_recall": None, "skipped": "refused_or_empty"}
        return row

    f = await Faithfulness(llm=judge).ascore(
        user_input=case.question, response=turn.answer, retrieved_contexts=contexts)
    row["faithfulness"] = None if math.isnan(float(f)) else float(f)
    row["faithfulness_reason"] = f.reason          # kept for human review, never for the mean

    if case.reference:                              # ContextRecall is reference-only
        r = await ContextRecall(llm=judge).ascore(
            user_input=case.question, retrieved_contexts=contexts, reference=case.reference)
        row["context_recall"] = None if math.isnan(float(r)) else float(r)
    return row


def aggregate(rows: list[dict]) -> dict:
    """Never np.nanmean. A dropped row is a finding, not a missing value."""
    scored = [r["faithfulness"] for r in rows if r.get("faithfulness") is not None]
    answerable = [r for r in rows if not r["case_unanswerable"]]
    unanswerable = [r for r in rows if r["case_unanswerable"]]
    return {
        "faithfulness_mean": sum(scored) / len(scored) if scored else None,
        "faithfulness_n": len(scored),                     # ALWAYS reported beside the mean
        "faithfulness_skipped": len(rows) - len(scored),   # gate fails if this moves
        "false_refusal_rate": sum(r["false_refusal"] for r in answerable) / max(len(answerable), 1),
        "false_answer_rate": sum(r["false_answer"] for r in unanswerable) / max(len(unanswerable), 1),
        "crag_mean": sum(r["crag_score"] for r in rows) / len(rows),
    }
```

Fan out **one Celery task per case** on the `evaluate` queue (`celery-workers`), not one task per run: a 500-case run at 3 judge calls each will not finish inside any sane `task_time_limit`, and a hard-limit kill loses the whole run. We run `result_backend = None` with `task_ignore_result`, so there is no chord — each case task writes its own `evaluation_results` row and the last one to decrement a durable counter chains the aggregation task explicitly, exactly as `kb-deletion-and-verification` chains `verify` after `purge`.

### Golden sets

Two kinds, and they never mix. **`samples/`** holds the platform golden set — synthetic documents, no tenant, committed to git, versioned with the repo. It is what CI's §21.5 regression gate runs, because CI has no organization and therefore no §18.10 switch to honour. **`evaluation_datasets`** (docs/11 §16.7) holds per-tenant sets, org-scoped like every other record, created by an admin from real conversations.

A case pins `source_version_id`s, not source ids. When a source is recrawled or reprocessed a new version activates and the fixed question set is now asking about different text: mark every case whose pinned version is no longer active as `stale` and exclude it from the aggregate rather than letting it silently score against new content. A run whose stale count changed is not comparable to the previous one — that comparison is exactly how a chunker change gets read as a prompt regression.

## Gotchas

- **Faithfulness reads 0.86 on a run where a third of the answers were refusals, and the number rises every time the bot gets *more* cautious.** Ragas returns `MetricResult(value=float("nan"))` when statement extraction yields nothing — which is precisely what a refusal produces — and the legacy aggregator uses `np.nanmean`, dropping those rows from the denominator entirely. The metric structurally cannot see the failure mode you care about most, and improving refusal behaviour *raises* the score. Skip refusals explicitly, report `faithfulness_n` beside every mean, and gate on the skip count moving.
- **A perfectly correct refusal scores 0.0 on answer relevancy, so tuning the threshold to reduce hallucination tanks the metric.** `AnswerRelevancy` generates `strictness=3` reverse-questions from the answer, embeds them, and computes `score = cosine_sim.mean() * int(not all_noncommittal)` — a hard zero, gated on `np.all` over three sampled verdicts, so two "noncommittal" votes out of three still return the full cosine score. It is bimodal by construction. It also takes only `user_input` and `response`: it never sees the retrieved contexts, so a fluent, confidently wrong answer scores high. This is why it is not in our set.
- **A metric run at "temperature 0" gives a different number on a re-run with no code change.** Two independent causes. Ragas' own defaults are not zero — `InstructorModelArgs` is `temperature=0.01, top_p=0.1`, and the legacy `get_temperature(n)` returns `0.3` whenever a metric samples more than one completion. And temperature 0 is not deterministic at the provider anyway: inference kernels are not batch-invariant, so the reduction order depends on server load; Thinking Machines measured **80 unique completions out of 1,000** at temperature 0. Set `temperature=0.0` in the adapter, accept residual variance, and never read a single-run delta as signal.
- **"Faithfulness went 0.82 → 0.79" gets a sprint of retrieval work, and the cause was the judge.** Ragas' `seed` parameter is dead code in 0.4.3 and the documented `in_ci=True` reproducibility flag has been **deleted** from the codebase while still appearing in the docs — so there is no library-level determinism lever left. <!-- UNVERIFIED: the mkdocstrings-generated reference pages were Cloudflare-rate-limited throughout; if a documented seed or temperature contract exists anywhere, it is there. Source at the v0.4.3 tag has neither. --> Nothing else pins the judge either. An audit of judge stability found the strongest model still flipping **14.7% of verdicts** merely under A/B order reversal. Compare only same-judge runs; when the judge must change, re-baseline every historical run you intend to compare against, or the series is broken.
- **Two runs differ by 0.03 and the release is blocked, or a real 5-point regression ships.** Eval questions are a sample, and a bare mean has no error bar. Use **paired differences** on the same question set (removes question-difficulty variance) and **clustered** standard errors when several questions come from one source document — which is the normal shape of a RAG golden set, and treating those as independent understates the interval badly. `arXiv:2411.00640` is the recipe; the gate compares intervals, not point estimates.
- **Ragas scores rank two pipeline configs correctly but are worthless on any individual answer.** Instance-level correlation with human labels is ~0.08 Pearson for faithfulness and ~0.12 for answer relevancy — *below plain ROUGE-L at 0.32*, against a human–human ceiling of 0.64 (RAGChecker, NeurIPS 2024 D&B). System-level ranking is fine (Kendall τ ≈ 0.73–0.94). So: A/B whole configurations in aggregate, never surface a per-answer Ragas score in the admin UI, and never use one as a user-facing confidence signal.
- **The bot answers correctly and Ragas marks it down.** GroUSE (COLING 2025) unit-tests generator failure modes and finds Ragas predicts **0.75** where 1.0 is correct when an answer adds true-but-unrequested detail, and **0.723** when an answer is incomplete but wholly relevant. It has **no criterion at all for wrongly refusing to answer** — the failure mode our evidence threshold exists to trade against. That gap is the entire reason the refusal counters above are hand-rolled and free.
- **The eval budget is approved from the docs and the bill comes in 8× higher.** The one per-metric call-count statement on the whole Ragas docs site says "Context Precision and Context Recall each require **one LLM call each**." Recall genuinely is one. **Precision is one call per retrieved context** — `context_precision/metric.py` loops `for context in retrieved_contexts` with an `await` inside the body, in both the with-reference and without-reference variants. At our `rerank.retain` of 6–10 the docs understate the metric by most of an order of magnitude, and because the loop is sequential it stretches wall-clock too. Cost every metric from the source, never from that page.
- **Importing `AspectCritic` from `ragas.metrics` emits a deprecation warning telling you to import it from `ragas.metrics.collections`, and that import raises `ImportError`.** `AspectCritic` and `SimpleCriteriaScore` were removed in the 0.4 restructure; the warning text is generated from the class name and is simply wrong for these two. Use `DiscreteMetric` for custom binary criteria. Likewise, collections metrics **cannot** be passed to the deprecated `evaluate()` — they are not instances of the legacy `Metric` base — so the two APIs cannot be mixed at all. <!-- UNVERIFIED: both failures are read off v0.4.3 source, not executed. -->
- **The FastAPI image grows by hundreds of megabytes and starts resolving LangChain versions.** `ragas` hard-depends on `langchain`, `langchain-core`, `langchain-community`, `langchain_openai`, `openai`, `datasets>=4.0.0`, `networkx` and `scikit-network` — none optional. Ragas is the assigned evaluation owner, so the weight is accepted rather than argued — but it is accepted **in one image**. Install it in `ai-worker-evaluation` only, never in `ai-api` or the ingestion workers, and keep the import inside the eval task. That containment is the same precedent ADR-016 set when it kept an unusable framework out of the query path: a dependency earns its place in the image that needs it, not in every image.
- **Judge cost appears as a mysterious spike in one tenant's bill on the day someone ran an experiment.** It is not mysterious and it is not a bug — but only if it is attributed. Ragas' own `CostCallbackHandler` is a LangChain callback and `InstructorBaseRagasLLM.agenerate` accepts no callbacks, so Ragas cannot meter this path at all. Metering is ours: one `provider_calls` row per judge call, `operation="evaluation.judge"`, `evaluation_runs.id` carried through, and the run's total surfaced in the admin before the run is started.
- **A judge prompt containing a crawled page follows an instruction inside it.** Retrieved text is passed to the judge as the *user* message, never the system message, and the judge's system prompt states its only job is to emit the requested JSON. Retrieved content is untrusted data in the eval path exactly as it is in the answer path (`kb-security-baseline`, docs/07 §12.14) — an eval harness is not a trusted context.

## Official docs

- [Ragas documentation](https://docs.ragas.io) — metrics, the collections API, and the `@experiment` workflow that replaces `evaluate()`.
- [vibrantlabsai/ragas on GitHub](https://github.com/vibrantlabsai/ragas) — the repository moved from `explodinggradients`; source is under `src/ragas/`, and the v0.4.3 tag is the only reliable reference for the collections API.
- [RAGAS: Automated Evaluation of RAG (arXiv:2309.15217)](https://arxiv.org/abs/2309.15217) — the original paper; note its validation set is 50 GPT-3.5-generated triples with 2 annotators.
- [RAGChecker (arXiv:2408.08067)](https://arxiv.org/html/2408.08067v1) — the meta-evaluation with the per-metric human-correlation table quoted above.
- [GroUSE (arXiv:2409.06595)](https://arxiv.org/html/2409.06595v1) — unit tests for grounded-QA evaluators; where Ragas mis-grades and what it cannot see.
- [Adding Error Bars to Evals (arXiv:2411.00640)](https://arxiv.org/abs/2411.00640) — clustered standard errors and paired differences for eval sets.
- [How to Correctly Report LLM-as-a-Judge Evaluations (arXiv:2511.21140)](https://arxiv.org/abs/2511.21140) — bias correction from a human-labelled calibration set.
- [Defeating Nondeterminism in LLM Inference](https://thinkingmachines.ai/blog/defeating-nondeterminism-in-llm-inference/) — why temperature 0 is not reproducible.
- [CRAG (arXiv:2406.04744)](https://arxiv.org/pdf/2406.04744) and [RefusalBench (arXiv:2510.10390)](https://arxiv.org/pdf/2510.10390) — the +1/0/−1 abstention scoring rule and unanswerable-question set construction.

## Definition of done

- [ ] The harness calls the production chat entry point; a test asserts an eval run for org A returns zero evidence from org B's sources, and that `retrieval_traces.filters` is populated for every case.
- [ ] Judge and embedder are `InstructorBaseRagasLLM` / `BaseRagasEmbedding` subclasses over our adapters; a grep proves no `llm_factory`, `LangchainLLMWrapper`, or direct `openai`/`langchain` client exists under `app/evaluation/`.
- [ ] Every judge call writes a `provider_calls` row with `operation="evaluation.judge"` and the run id; the run's total cost is shown before the run starts and stored in `evaluation_runs`.
- [ ] `evaluation_runs.model_configuration` records the judge's dated model id, temperature, and `ragas` version; the comparison view refuses to diff two runs whose judge configuration differs.
- [ ] The dataset contains unanswerable cases; the run reports `false_refusal_rate`, `false_answer_rate`, and the CRAG mean, and §21.5 gates on all three.
- [ ] `faithfulness_n` and `faithfulness_skipped` are stored and displayed beside every mean; no aggregation uses `np.nanmean`.
- [ ] Gate comparisons use paired differences with clustered standard errors over `source_version_id`, not raw means.
- [ ] One Celery task per case on `evaluate`; a killed worker loses one case, not the run, and the aggregation task is chained explicitly (no chord, no `GroupResult`).
- [ ] Cases pinned to `source_version_id`; a version change marks affected cases `stale` and excludes them, and the stale count is reported.
- [ ] Content capture obeys the org's §18.10 switches; a test asserts no question, answer, or retrieved chunk reaches the log pipeline. CI runs against `samples/` only.
- [ ] `ragas` appears in the `ai-worker-evaluation` requirements only; a test asserts `import ragas` fails in the API image.
- [ ] Human review recorded per §21.3 on a sampled subset; `evaluation_results.human_review_status` is populated for the gate's sample.
