"""The only module in this service that reads from Qdrant.

**Every call in this file passes a ``query_filter`` built by ``tenancy.tenant_filter``.**
Not most calls. Not the ones a reviewer would call tenant-facing. Every call — there is no
debug helper, no admin path, no test hook, and no ``internal=True`` keyword here, because
none is built.

**Why this file exists as a file.** The Qdrant-filter CI gate greps all of ``app/`` for
``query_points(``, ``.search(``, ``scroll(`` and ``count(``, then excludes this path by
literal string. The exclusion is what makes the rule mechanical: a ``query_points(`` written
anywhere else under ``app/`` is a **build failure**, not a review question — and it needs to
be, because the violation it catches is invisible at runtime. An unfiltered vector read
returns HTTP 200, at normal latency, with a normal number of candidates. Nothing in the
response distinguishes it from a correct one.

**Because it is the exempt path, a reviewer must read every line of this file.** The gate
that protects the other forty modules protects nothing here; the only remaining control on
these few functions is someone reading them. Treat a diff to this file the way you would
treat a diff to an authentication routine, and keep the file small enough that reading all
of it is realistic. That smallness is a feature, and it is the reason fusion, dedup,
reranking and packing live in ``app/rag/`` instead of here — they need no client access, so
they stay outside the exemption.

Note for the same reason: ``count(`` matches ``str.count(`` and ``list.count(`` too, so an
ordinary Python method call by that name anywhere else under ``app/`` fails the job. Prefer
a different spelling rather than reaching for the ``# tenancy-exempt:`` annotation, which
exists for genuinely unavoidable cases and should stay rare enough to read in full.

**Shape of the chat query.** Two separately filtered branch queries, fused in Python by
``app/rag/evidence.py``. Never one prefetch plus a server-side fusion query: a fused
response carries one score per point, so the per-branch rank *and* score that stage 9 and
the admin playground require were discarded server-side and are not recoverable from the
result. The playground then shows a fused score and four dashes beside it, and no code
change can refill them. Server-side fusion survives only in the evaluation harness's
comparison run, which is ``rag-eval-engineer``'s module, not this one.

**The sparse branch is fed again, and it is still optional at the call site.** BGE-M3 produced
dense and sparse from one local forward pass and API embedding endpoints return dense only,
which left the lexical arm without a producer for a while (finding C2). It has one now:
``app/retrieval/sparse.py`` computes BM25 locally — a statistical ranking function, not a
model, so outside ADR-030 — and hands a ``models.SparseVector`` to this file exactly as the
dense embedding call hands a list of floats.

The parameter stays optional because the dense-only shape remains reachable and must remain
*visible* rather than accidental: a query that analyzes to no terms has nothing to ask the
lexical branch, and a bot may have the arm switched off. So ``sparse`` is passed or it is not,
and a dense-only run is a first-class, traced state. Read the degradation honestly before
relying on it — a dense-only run has no lexical recall at all, so exact tokens a dual encoder
is weak on (part numbers, error codes, surnames, API symbols) simply do not surface, and with
reranking also optional, a dense-only run without a reranker is cosine similarity and nothing
else, which is why ``select_unranked`` refuses to select on it.

Two properties of the lexical arm that this file relies on and does not enforce. The query
vector carries the IDF factor, computed per request from statistics scoped to the caller's own
``(org_id, allowed_version_ids)`` — Qdrant's own ``modifier=IDF`` computes document frequencies
collection-wide across every tenant and is unscopable on the pinned server, so it stays off.
And the vector's analyzer must match the space's: term ids from another analyzer are legal
integers that match no posting, so the branch returns nothing and raises nothing.
``assert_analyzer`` is the sparse counterpart of ``assert_dimensions`` and is called in the
same two places.

**The query vector now costs a network call.** Embedding the query is a provider API call
made through the adapter layer, carrying a per-organization credential, inside the same
retrieval budget the whole leg shares. Three things follow. It must be deadline-bounded like
any other provider call. Its failure is a provider error in ``kb-error-taxonomy`` terms and
is **never** reported as "not in your sources" — an embedding outage is not an evidence
outage. And its width is a property of the provider and model, not of this service, which is
why every function here takes an ``EmbeddingSpace`` and asserts against it rather than
trusting the length of the list it was handed.
"""

from __future__ import annotations

import asyncio
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from typing import TYPE_CHECKING, Final, Literal

from opentelemetry import trace
from qdrant_client import models

from app.rag.stages import span_for
from app.retrieval.collection import (
    SPARSE_ANALYZER_VERSION,
    EmbeddingSpace,
    assert_analyzer,
    assert_dimensions,
)
from app.retrieval.tenancy import (
    MANDATORY_FILTER_KEYS,
    EmptyScopeError,
    TenantContext,
    tenant_filter,
)

if TYPE_CHECKING:
    from qdrant_client import AsyncQdrantClient

__all__ = [
    "BRANCH_STAGES",
    "BRANCH_TIMEOUT_SECONDS",
    "PAYLOAD_PROJECTION",
    "QDRANT_SERVER_RRF_K",
    "BranchName",
    "Candidate",
    "assert_scoped",
    "branch_query",
    "retrieve_branches",
]

#: The two named vector branches. A collection with named vectors has no default branch, so
#: ``using=`` is required on every call; omitting it errors or searches the wrong branch.
BranchName = Literal["dense", "sparse"]

#: Branch to the stage whose span name it records, as a closed lookup rather than an f-string.
#: ``span_for`` already raises on an unknown stage; this map is what stops a branch name from
#: ever being the input to a span name in the first place. A derived name errors nowhere — it
#: exports cleanly and matches no rule, alert, or dashboard query in the repository.
BRANCH_STAGES: Final[Mapping[BranchName, str]] = {
    "dense": "dense_retrieval",
    "sparse": "sparse_retrieval",
}

#: One tracer for the module. Created at import; the OTel API hands out a proxy that re-binds
#: when ``app.observability.otel.configure`` installs the real provider, which is what makes
#: import-time creation safe in a Celery worker where the provider is built after ``fork()``.
_TRACER: Final = trace.get_tracer("app.retrieval.search")

#: Client-side ceiling on a single branch request. It is not the stage budget — the whole
#: retrieval leg targets 1.5 s and a branch that takes five seconds has already lost the
#: request. It is the backstop that keeps a wedged server from holding the connection until
#: the caller's deadline, and it is set explicitly because the client default is no timeout.
BRANCH_TIMEOUT_SECONDS: Final[float] = 5.0

#: Payload fields returned on the query path, listed explicitly rather than returning the
#: whole payload. Identity for dedup and deletion, locators for citation, ``token_count``
#: for the packer's budget arithmetic, ``content_hash`` for dedup.
#:
#: The chunk **body text is deliberately not here** — it lives in PostgreSQL, and the
#: reranker must score the exact string that was embedded, prefix included. Hydrating that
#: text is a separate step; scoring the payload instead would rerank a document that does
#: not exist in the index.
#:
#: ``overlap_of`` IS NOT HERE, AND IT IS STILL WRITTEN. Ingestion sets it on every carried
#: chunk (`app/ingestion/chunking/chunker.py`) and it remains in the Qdrant payload and in
#: the metadata schema `kb-chunking-rules` pins — this tuple is what the *query* path asks
#: for, and since the near-duplicate penalty was dropped by ruling (finding #80, 2026-08-12;
#: `docs/22` § G3 and § G10) nothing on this path reads it. It was projected "for dedup",
#: and `app.rag.evidence.dedup_and_diversify` dedups on ``content_hash`` and ``source_id``
#: alone. A field carried with a stale justification is the thing that later reads as
#: evidence that dedup uses it, which is why this is a deletion rather than a comment fix.
#:
#: ``with_vectors`` is always False on this path: a returned embedding is recoverable
#: plaintext, roughly 92% of a 32-token input by published inversion results.
PAYLOAD_PROJECTION: Final[tuple[str, ...]] = (
    "chunk_id",
    "source_id",
    "source_item_id",
    "source_version_id",
    "seq",
    "content_hash",
    "token_count",
    "content_type",
    "lang",
    "heading_path",
    "page",
    "page_end",
    "slide",
    "sheet",
    "row_range",
    "table_ref",
    "url",
    "anchor",
    "char_start",
    "char_end",
)

#: Qdrant's server-side fusion constant, and a **different number with a different owner**
#: from ``app.rag.evidence.RRF_K``. Qdrant scores ``1/((pos+1)/weight + k - 1)`` over a
#: 0-based position, so its ``k`` is the literature's ``k`` plus one: 61 here is what 60
#: means on our side of the wire. It exists only so the evaluation harness's "what would
#: server fusion have done" comparison can record the ``k`` it actually ran under. Nothing
#: asserts these two numbers are equal, and a comparison report that omits its ``k`` is the
#: defect.
QDRANT_SERVER_RRF_K: Final[int] = 61


@dataclass(slots=True)
class Candidate:
    """One row of the playground's retrieval table, carried through stages 9 to 13.

    A candidate found by only one branch keeps ``None`` for the other branch's rank. That
    asymmetry is the most diagnostic field in the panel, and it was also the relevance
    signal the degraded path leant on when the reranker was unavailable — branch agreement
    is structural rather than magnitude-based, which is why it was used and never the fused
    score.

    **That signal is only there when there are two branches.** On a dense-only run every
    sparse field is ``None`` for every candidate, agreement is undefined, and the degraded
    ordering is the dense ranking unchanged. A panel that renders "found by both branches"
    as a quality badge must therefore read the run's branch set, not the emptiness of these
    fields, or every dense-only run looks like a corpus in which nothing agrees.
    """

    point: models.ScoredPoint
    dense_rank: int | None = None
    dense_score: float | None = None
    sparse_rank: int | None = None
    sparse_score: float | None = None
    fused_score: float = 0.0

    @property
    def chunk_id(self) -> str:
        """The ULID every later stage identifies this candidate by. Raises if it is absent.

        Read from the payload rather than from ``point.id``, which is the deterministic
        ``uuid5`` point id and deliberately a different value. ``chunk_id`` is what dedup keys
        on, what an exclusion is recorded against, and what a citation resolves through, so a
        point that reached retrieval without one is a payload-contract violation upstream —
        loudly, here, rather than as a ``None`` that becomes the string ``"None"`` in a trace
        and groups every unidentifiable candidate into one playground row.
        """
        payload = self.point.payload or {}
        chunk_id = payload.get("chunk_id")
        if not isinstance(chunk_id, str) or not chunk_id:
            raise ValueError(
                f"point {self.point.id!r} carries no chunk_id in its payload. Every point is "
                "written with the full metadata schema (kb-chunking-rules); one without it "
                "cannot be deduped, excluded with a reason, or cited"
            )
        return chunk_id


def assert_scoped(query_filter: models.Filter) -> models.Filter:
    """Last check before a filter reaches the client. Returns it, or raises.

    Requires ``must`` to be exactly :data:`~app.retrieval.tenancy.MANDATORY_FILTER_KEYS`, as
    ``FieldCondition``\\ s, in that order — and requires ``must_not`` to be empty. Nothing
    weaker is a check. A gate that only rejects an empty ``must`` cannot fail for any input
    ``tenant_filter`` can produce, so it would be silent against the edit it exists to catch:
    a term dropped from the builder, or moved out of ``must``.

    Three rejections, each a different way to lose a tenant:

    * **Empty or absent.** ``Filter(must=[])`` is a **match-all** rather than a match-none —
      the server evaluates ``must`` with ``.all()``, and ``.all()`` over an empty list is
      true. So the one mistake that produces a full cross-tenant scan produces an object that
      looks, in a debugger and in a review diff, exactly like a filter. The asymmetry is worth
      knowing: the same mistake in ``should`` uses ``.any()`` and matches *nothing*, so it
      fails loudly. Only ``must`` fails silently.
    * **Short, reordered, or padded.** Each of the four terms prevents a different concrete
      failure and none is implied by another (``app/retrieval/tenancy.py``). A filter carrying
      three of them is a filter that still returns results, at normal latency, out of the
      wrong bot or the retired version. Equality against the tuple — rather than a subset test
      — is what makes ``tenant_filter`` the only representable builder: a fifth term would
      only narrow, but a checker that admits an unknown term cannot tell narrowing from a
      nested clause it did not read, and there is no caller that needs one.
    * **Negative.** A ``match`` is not satisfied by a point missing the key entirely, so a
      point whose payload lost ``org_id`` is visible to nobody under a positive ``must`` and
      to *everybody* under a ``must_not``. One of those is a support ticket, the other is a
      breach, so a non-empty ``must_not`` is refused here even though this file builds none.

    Exported, so a caller can hand it a filter this module did not build. That is the reason
    the rule is stated against the shared constant instead of against ``tenant_filter``'s
    return value.
    """
    if query_filter.must_not:
        raise EmptyScopeError(
            "a filter reached the client with a non-empty `must_not`. A tenancy term "
            "expressed negatively does not scope: a `match` is not satisfied by a point "
            "missing the key, so a point whose payload lost a key is visible to nobody "
            "under a positive `must` and to everybody under a `must_not`"
        )

    must = query_filter.must if isinstance(query_filter.must, list) else []
    keys = tuple(c.key for c in must if isinstance(c, models.FieldCondition))
    if keys != MANDATORY_FILTER_KEYS or len(keys) != len(must):
        raise EmptyScopeError(
            f"a filter reached the client whose `must` is {keys!r}, not the mandatory "
            f"{MANDATORY_FILTER_KEYS!r} as plain field conditions in that order. Every term "
            "is mandatory and none is implied by another; the empty case is the worst of "
            "them, because Filter(must=[]) and Filter(must=None) are the same object to the "
            "server and both are match-all: `must` is evaluated with .all(), and .all() of "
            "nothing is true"
        )
    return query_filter


async def branch_query(
    client: AsyncQdrantClient,
    ctx: TenantContext,
    allowed_version_ids: Sequence[str],
    query: Sequence[float] | models.SparseVector,
    *,
    space: EmbeddingSpace,
    using: BranchName,
    limit: int,
) -> list[models.ScoredPoint]:
    """One filtered branch query — stage 7 (``dense``) or stage 8 (``sparse``).

    ``ctx`` and ``allowed_version_ids`` are positional and required so the filter is built
    here, from the caller's authenticated scope, and cannot be passed in pre-built by a
    caller that got it wrong. The call site sets ``query_filter`` from
    ``assert_scoped(tenant_filter(ctx, allowed_version_ids))``, ``with_vectors`` False, and
    ``with_payload`` to ``PAYLOAD_PROJECTION``.

    ``space`` names the collection and fixes the dense width. It is required rather than
    defaulted because there is no longer one collection: a reindex onto a new embedding
    model creates a second one and both are live while the new version is being published,
    so a call that does not say which space it means is a call that can read the half the
    caller did not intend.

    Records ``kb.retrieval.dense`` or ``kb.retrieval.sparse`` — a CLIENT span, named from
    the closed catalogue in ``app.rag.stages``, never minted from the branch name at
    runtime.

    ``client`` is the **async** client. The signature was ``QdrantClient`` — the synchronous
    one — awaited in name only, which does not error and does not concurrently do anything: it
    blocks the event loop for the whole round trip and stalls every other stream in the worker,
    including their heartbeats, which is the failure ``app/rag/stages.py`` names beside the
    ``Stage`` protocol.
    """
    with _TRACER.start_as_current_span(span_for(BRANCH_STAGES[using]), kind=trace.SpanKind.CLIENT):
        response = await client.query_points(
            collection_name=space.collection,
            query=query if isinstance(query, models.SparseVector) else list(query),
            using=using,
            query_filter=assert_scoped(tenant_filter(ctx, allowed_version_ids)),
            limit=limit,
            with_payload=PAYLOAD_PROJECTION,
            with_vectors=False,
            timeout=int(BRANCH_TIMEOUT_SECONDS),
        )
    # `QueryResponse`, not a bare list. Returning the response itself would put a client type
    # into every downstream stage's signature for the sake of one attribute.
    return response.points


async def retrieve_branches(
    client: AsyncQdrantClient,
    ctx: TenantContext,
    allowed_version_ids: Sequence[str],
    dense: Sequence[float],
    sparse: models.SparseVector | None = None,
    *,
    space: EmbeddingSpace,
    dense_limit: int,
    sparse_limit: int | None = None,
) -> dict[BranchName, list[models.ScoredPoint]]:
    """Stages 7 and 8 together, as concurrent siblings, under one filter *expression*.

    Two queries are two chances to forget the filter, so neither branch is handed one: each
    ``branch_query`` builds its own from the same ``(ctx, allowed_version_ids)`` this function
    was given, and each passes it through ``assert_scoped``. The two filters are therefore
    identical by construction rather than by a caller remembering to share an object — which
    is the stronger property, and the reason there is deliberately **no** optional pre-built
    filter parameter here. Such a parameter reopens exactly the hole ``branch_query``'s
    positional scope closes: a caller that built the filter wrong, or built it for another
    tenant, and a signature with nowhere to notice. The two branch latencies stay separate
    metrics even though the wall-clock cost is one of them, not the sum.

    ``sparse`` and ``sparse_limit`` are passed together or not at all. The pairing is
    checked rather than assumed, because the shape that gets written by accident is a sparse
    vector with no depth (which reads as depth zero and returns nothing) or a depth with no
    vector (which reads as "sparse was configured" in a trace that never queried it). Both
    look like a lexical arm that stopped working, and neither raises.

    The returned mapping contains **only the branches actually queried**, so a dense-only
    run is visible as a one-key result rather than as an empty list that could equally mean
    the corpus had no lexical match. Fusion reads the key set; nothing downstream infers a
    branch's existence from the emptiness of its results.

    Both vectors are checked against ``space`` before either call goes out, and for the same
    reason in two different shapes: a dense vector of the wrong width and a sparse vector from
    the wrong analyzer are both produced by a producer that has drifted from the collection.
    The dense mismatch is usually a 400; the sparse one is always accepted, matches no posting,
    and returns an empty branch with no error — which is why it is checked here rather than
    left to the server.

    Returns raw points per branch. Fusion is stage 9 and belongs to ``app/rag/evidence.py``:
    keeping it there keeps this file short enough that reading all of it is realistic.
    """
    if (sparse is None) != (sparse_limit is None):
        raise ValueError(
            "sparse and sparse_limit are one decision — pass both to run the lexical arm, "
            "or neither to run dense-only"
        )
    assert_dimensions(space, len(dense))
    if sparse is not None:
        assert_analyzer(space, SPARSE_ANALYZER_VERSION)

    # A TaskGroup rather than `gather`: if one branch fails the other is cancelled rather than
    # left running against a request that has already lost, and the failure propagates instead
    # of arriving as a value in the results list that a `for` loop would happily rank.
    async with asyncio.TaskGroup() as group:
        running: dict[BranchName, asyncio.Task[list[models.ScoredPoint]]] = {
            "dense": group.create_task(
                branch_query(
                    client,
                    ctx,
                    allowed_version_ids,
                    dense,
                    space=space,
                    using="dense",
                    limit=dense_limit,
                )
            )
        }
        if sparse is not None and sparse_limit is not None:
            running["sparse"] = group.create_task(
                branch_query(
                    client,
                    ctx,
                    allowed_version_ids,
                    sparse,
                    space=space,
                    using="sparse",
                    limit=sparse_limit,
                )
            )

    # Only the branches actually queried. A dense-only run is a one-key result, never a
    # two-key result with an empty list — "the sparse branch was not run" and "the sparse
    # branch matched nothing" are different runs, and the degraded path in
    # ``app/rag/evidence.py`` selects on the first and refuses on the second.
    return {name: task.result() for name, task in running.items()}
