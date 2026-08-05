# The stage runner we build instead

Companion to `../SKILL.md`. Haystack's `Pipeline` is a DAG runtime with dict-routed inputs; our query
path is 20 fixed stages in one order, so a list is the whole framework. This file holds the runner in
full — it was in the skill body before ADR-016 retargeted that file to the decision record.

Stage order and defaults are owned by `kb-rag-query-contract`; span names by
`kb-observability-conventions`; the filter by `kb-tenancy-isolation`. This is only the shape.

```python
# services/ai-service/app/rag/pipeline.py — the whole framework, and it is this long.
from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass, field
from typing import Any, Awaitable, Callable

from opentelemetry import trace

tracer = trace.get_tracer("app.rag.pipeline")   # instrumentation scope (a module path), NOT a
                                                # span name — spans come from the catalogue below.

# `kb-observability-conventions` owns every span name in the query pipeline and declares them
# permanent: `kb.<domain>.<operation>`, and there is no `rag` domain. The Retrieval dashboard,
# the alerts and the trace-to-logs links all key on these exact strings, so a stage that invents
# `kb.rag.<stage>` renders those panels empty with no error anywhere. A stage that fans out into
# several catalogued spans owns them itself and registers `None`.
STAGE_SPANS: dict[str, str | None] = {
    "normalize": "kb.query.normalize",
    "filters":   "kb.retrieval.filters",
    "retrieve":  None,                  # fans out → kb.retrieval.dense + kb.retrieval.sparse
    "fuse":      "kb.retrieval.fuse",
    "dedupe":    "kb.retrieval.dedupe",
    "rerank":    "kb.retrieval.rerank",
    "threshold": "kb.retrieval.threshold",
    "pack":      "kb.context.pack",
    "prompt":    "kb.prompt.build",
}


@dataclass(frozen=True)
class RetrievalConfig:
    version: str                  # retrieval_configuration_version — every trace carries it (§21.4)
    fusion_k: int = 60            # explicit and ours. Qdrant's server-side RRF defaults to k=2;
    dense_top_k: int = 20         # Haystack's DocumentJoiner hardcodes 61. Neither is a choice.
    sparse_top_k: int = 20
    disabled: frozenset[str] = frozenset()


@dataclass
class Candidate:
    chunk_id: str
    dense_rank: int | None = None
    dense_score: float | None = None
    sparse_rank: int | None = None
    sparse_score: float | None = None
    fused_score: float = 0.0


@dataclass
class Ctx:
    tenant_filter: Any            # models.Filter from kb-tenancy-isolation. Positional, no default,
    cfg: RetrievalConfig          # and no framework layer able to substitute one.
    search: Callable[[str, int, Any], Awaitable[list[tuple[str, float]]]]  # thin qdrant-client wrapper
    branches: dict[str, list[tuple[str, float]]] = field(default_factory=dict)
    candidates: list[Candidate] = field(default_factory=list)
    stage_log: list[dict[str, Any]] = field(default_factory=list)


Stage = Callable[[Ctx], Awaitable[None]]


async def run_pipeline(stages: list[tuple[str, Stage]], ctx: Ctx) -> Ctx:
    """20 fixed stages in one order: a list, not a graph. Nothing branches, loops, or rewires at
    runtime, so a DAG engine buys nothing — and a list cannot silently leave an input unconnected."""
    for name, stage in stages:
        if name in ctx.cfg.disabled:
            ctx.stage_log.append({"stage": name, "status": "disabled"})   # config-off, never a jump
            continue
        span_name = STAGE_SPANS[name]     # KeyError beats minting an uncatalogued name at runtime
        t0 = time.perf_counter()
        try:
            if span_name is None:                                         # stage owns its own spans
                await stage(ctx)
            else:
                with tracer.start_as_current_span(span_name) as span:
                    span.set_attribute("kb.retrieval_configuration_version", ctx.cfg.version)
                    await stage(ctx)
        finally:                                                          # a raising stage still times
            ctx.stage_log.append({"stage": name, "ms": round((time.perf_counter() - t0) * 1000, 2)})
    return ctx


async def retrieve(ctx: Ctx) -> None:
    """Two single-vector queries, not one server-side fusion query: a fused Qdrant response carries
    one score per point and no per-branch rank, and stages 7-9 must record both. The two catalogued
    CLIENT spans are concurrent siblings, exactly as the trace tree declares them."""
    async def branch(using: str, limit: int) -> list[tuple[str, float]]:
        with tracer.start_as_current_span(f"kb.retrieval.{using}", kind=trace.SpanKind.CLIENT):
            return await ctx.search(using, limit, ctx.tenant_filter)

    dense, sparse = await asyncio.gather(
        branch("dense", ctx.cfg.dense_top_k),
        branch("sparse", ctx.cfg.sparse_top_k),
    )
    ctx.branches = {"dense": dense, "sparse": sparse}


async def fuse(ctx: Ctx) -> None:
    pool: dict[str, Candidate] = {}
    for branch, hits in ctx.branches.items():
        for rank, (chunk_id, score) in enumerate(hits, start=1):          # RRF ranks are 1-indexed
            c = pool.setdefault(chunk_id, Candidate(chunk_id))
            setattr(c, f"{branch}_rank", rank)
            setattr(c, f"{branch}_score", score)                          # survives fusion — §8.24
            c.fused_score += 1.0 / (ctx.cfg.fusion_k + rank)
    ctx.candidates = sorted(pool.values(), key=lambda c: -c.fused_score)


if __name__ == "__main__":                                                # python -m app.rag.pipeline
    async def fake_search(using: str, limit: int, flt: Any) -> list[tuple[str, float]]:
        assert flt is not None, "unfiltered search"   # the assertion a document store cannot host
        return [("a", 0.91), ("b", 0.88)] if using == "dense" else [("b", 4.2), ("c", 3.1)]

    out = asyncio.run(run_pipeline(
        [("retrieve", retrieve), ("fuse", fuse)],
        Ctx(tenant_filter=object(), cfg=RetrievalConfig(version="rc_2026_08_01"), search=fake_search),
    ))
    print(*out.candidates, sep="\n")
    print(out.stage_log)
```

Stages 10–13 and 17 are already written in `kb-rag-query-contract` (`dedup_and_diversify`, `select`,
`pack`); they plug into this runner unchanged.

## Why each piece is shaped the way it is

- **`tenant_filter` is a positional field on `Ctx` with no default.** A `Ctx` cannot be constructed
  without one, and no stage receives a filter argument it could override. This is the property a
  document store cannot offer: an abstraction that *accepts* a filter can *replace* one.
- **`STAGE_SPANS` is a lookup, not a format string.** An unknown stage raises `KeyError` at wiring
  time instead of exporting an off-catalogue span that silently matches no dashboard query.
- **`fuse` writes `dense_rank`/`dense_score`/`sparse_rank`/`sparse_score` onto the candidate before
  adding to `fused_score`.** The §8.24 playground reads all four; any fusion returning one number per
  candidate has already destroyed data no downstream code can rebuild.
- **`fusion_k` comes from `RetrievalConfig` and lands in `retrieval_configuration_version`.** Every
  other fusion implementation pins its own constant (Qdrant server-side: 2; Haystack `DocumentJoiner`:
  61), and the §21.5 regression gate cannot explain a diff it cannot attribute.
