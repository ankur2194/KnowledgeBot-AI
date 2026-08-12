"""Stages 6-8 — the second filter gate, and what the two branch calls actually put on the wire.

``tests/unit/README.md`` forbids asserting anything about a query *result* in this tier, and
nothing here does: the stand-in client evaluates no filter and returns exactly what it was
handed. What is asserted is the **request** — that each branch carried a filter with all four
terms in ``must``, named its own branch, projected the payload the pipeline reads, and asked
for no vectors. Those are the properties that fail silently, and they need no server.

The isolation claim itself belongs to ``tests/integration/`` against two organizations and a
real Qdrant. A filter that was built correctly and never executed proves nothing about
isolation — but a filter that was never built at all is provable here, and that is the failure
with no symptom: an unfiltered vector read is a legal, successful, HTTP 200 query.
"""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from dataclasses import dataclass, field
from typing import Any, Final

import pytest
from opentelemetry import trace
from qdrant_client import models

from app.retrieval import search
from app.retrieval.collection import AnalyzerMismatch, DimensionMismatch, EmbeddingSpace
from app.retrieval.search import (
    BRANCH_STAGES,
    PAYLOAD_PROJECTION,
    assert_scoped,
    branch_query,
    retrieve_branches,
)
from app.retrieval.tenancy import MANDATORY_FILTER_KEYS, EmptyScopeError, tenant_filter
from tests.support.fake_qdrant import RecordingClient, point

VERSIONS: Final[tuple[str, ...]] = ("01JQZ0000000000000000VER1", "01JQZ0000000000000000VER2")
SPACE: Final = EmbeddingSpace("openai", "text-embedding-3-large", 8)
DENSE: Final[list[float]] = [0.1] * 8


@dataclass(frozen=True)
class Ctx:
    """Structurally a ``TenantContext``. Two organizations, because one cannot fail a scope
    test even when the assertion is about the request rather than the result."""

    org_id: str
    bot_id: str | None = "01JQZ00000000000000000BOT"


ORG_A: Final = Ctx(org_id="01JQZ000000000000000000A")
ORG_B: Final = Ctx(org_id="01JQZ000000000000000000B")


@dataclass
class RecordingTracer:
    """Captures the ``(name, kind)`` of every span opened, and nothing else.

    Deliberately not an SDK ``TracerProvider`` with an in-memory exporter: installing a global
    provider is a once-per-process side effect that leaks into every other test in the session,
    and the second ``set_tracer_provider`` is silently ignored — so the first test file to do it
    would decide what the rest of the suite observes.
    """

    spans: list[tuple[str, trace.SpanKind]] = field(default_factory=list)

    @contextmanager
    def start_as_current_span(
        self, name: str, *, kind: trace.SpanKind = trace.SpanKind.INTERNAL, **_: Any
    ) -> Iterator[trace.Span]:
        self.spans.append((name, kind))
        yield trace.INVALID_SPAN


def sparse_vector() -> models.SparseVector:
    return models.SparseVector(indices=[3, 40], values=[0.5, 1.0])


def keys_in_must(built: models.Filter) -> list[str]:
    assert built.must is not None
    return [
        condition.key for condition in built.must if isinstance(condition, models.FieldCondition)
    ]


# ── assert_scoped: the second gate, and the one that catches a match-all ──────


def test_a_filter_with_conditions_passes_through_unchanged() -> None:
    """It returns the filter rather than ``None`` so it can wrap the argument at the call site.

    That shape is the point: ``query_filter=assert_scoped(tenant_filter(...))`` cannot be
    written without the check, whereas a checker returning ``None`` can be forgotten one line
    above the call it was meant to protect.
    """
    built = tenant_filter(ORG_A, VERSIONS)
    assert assert_scoped(built) is built


def test_an_empty_must_list_is_rejected_because_the_server_reads_it_as_match_all() -> None:
    """``Filter(must=[])`` is the one mistake that produces a full cross-tenant scan.

    The server evaluates ``must`` with ``.all()``, and ``.all()`` over an empty list is true.
    In a debugger and in a review diff the object looks exactly like a filter.
    """
    with pytest.raises(EmptyScopeError, match="match-all"):
        assert_scoped(models.Filter(must=[]))


def test_an_absent_must_is_the_same_thing_spelled_differently() -> None:
    """``Filter(must=None)`` and ``Filter(must=[])`` are one object to the server.

    Rejecting only the list form is the shape that reviews as correct and passes the empty
    filter, because over gRPC an empty clause vector normalizes to absent on the way out.
    """
    with pytest.raises(EmptyScopeError, match="match-all"):
        assert_scoped(models.Filter(must=None))


def test_conditions_in_should_do_not_satisfy_the_gate() -> None:
    """``should`` is an OR. A tenant term there widens into ``tenant OR anything_else``, and
    the gate must not count it as scope merely because the filter is not empty."""
    with pytest.raises(EmptyScopeError):
        assert_scoped(
            models.Filter(
                should=[
                    models.FieldCondition(key="org_id", match=models.MatchValue(value="x")),
                ]
            )
        )


def test_the_same_class_is_raised_by_both_gates() -> None:
    """One ``except EmptyScopeError`` covers the builder and the checker.

    Two exception classes for one failure means a caller that handles the first and lets the
    second escape as a 500 — or, worse, handles the second and swallows the first.
    """
    with pytest.raises(EmptyScopeError):
        tenant_filter(ORG_A, [])
    with pytest.raises(EmptyScopeError):
        assert_scoped(models.Filter(must=[]))


# ── the three shapes an empty-`must` check waves through ─────────────────────
#
# The gate used to be `if not query_filter.must: raise`, and at its only call site the argument
# is always `tenant_filter(ctx, allowed_version_ids)` — which by construction returns four
# conditions and can never return an empty `must`. So the check could not fail for any input it
# could receive, and its whole value is against a future edit. Against the most likely future
# edit — a term dropped from or moved inside `tenant_filter` — it was silent. Deleting the
# `bot_ids` term from the builder was measured at eight test failures elsewhere; the runtime
# control sitting between the filter and the wire noticed nothing.
#
# Each of the three below was ACCEPTED by that version and must now raise.


def test_a_one_term_filter_is_not_scope() -> None:
    """Three of the four terms missing, and the filter is not empty, so it passed.

    `bot_ids` alone stops a bot answering out of an unassigned source and does nothing about
    the organization, the source status, or the retired version — and a query carrying it
    returns results, at normal latency, with a normal number of candidates.
    """
    with pytest.raises(EmptyScopeError, match="mandatory"):
        assert_scoped(
            models.Filter(
                must=[
                    models.FieldCondition(key="bot_ids", match=models.MatchAny(any=[ORG_A.bot_id])),
                ]
            )
        )


def test_a_filter_whose_terms_are_not_tenancy_terms_at_all_is_rejected() -> None:
    """A non-empty `must` naming something else entirely is the degenerate case of the same
    bug: the old gate counted conditions, and any condition satisfied it."""
    with pytest.raises(EmptyScopeError, match="mandatory"):
        assert_scoped(
            models.Filter(
                must=[
                    models.FieldCondition(key="lang", match=models.MatchValue(value="en")),
                ]
            )
        )


def test_tenancy_expressed_in_must_not_is_rejected_even_beside_a_populated_must() -> None:
    """The serious one, and the reason `must_not` is checked separately.

    `app/retrieval/tenancy.py` states it: a `match` is not satisfied by a point missing the
    key entirely, so a point whose payload lost `org_id` is visible to nobody under a positive
    `must` and to EVERYBODY under a `must_not`. The old gate saw a non-empty `must` — one
    unrelated term was enough — and waved the negative scope straight through to the client.
    """
    with pytest.raises(EmptyScopeError, match="must_not"):
        assert_scoped(
            models.Filter(
                must=[
                    models.FieldCondition(key="lang", match=models.MatchValue(value="en")),
                ],
                must_not=[
                    models.FieldCondition(
                        key="org_id", match=models.MatchValue(value=ORG_B.org_id)
                    ),
                ],
            )
        )


def test_a_must_not_is_refused_even_alongside_a_complete_tenant_filter() -> None:
    """It would only narrow, and it is still refused.

    Nothing in this service builds one, so a `must_not` arriving here means a caller assembled
    a filter by hand — and the checker cannot tell the narrowing case from the case above by
    reading the clause. Refusing the whole shape is what keeps `tenant_filter` the only
    representable builder.
    """
    built = tenant_filter(ORG_A, VERSIONS)
    with pytest.raises(EmptyScopeError, match="must_not"):
        assert_scoped(
            models.Filter(
                must=built.must,
                must_not=[
                    models.FieldCondition(key="lang", match=models.MatchValue(value="en")),
                ],
            )
        )


def test_a_dropped_term_is_caught_even_when_the_other_three_are_perfect() -> None:
    """The edit the gate exists for, expressed directly: `tenant_filter` loses one term.

    This is the mutation Batch 4 measured at eight failures in the builder's own suite. The
    point of this assertion is that the LAST control before the wire fails too, rather than
    depending on those eight.
    """
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    for dropped in range(len(MANDATORY_FILTER_KEYS)):
        short = [c for index, c in enumerate(built.must) if index != dropped]
        with pytest.raises(EmptyScopeError, match="mandatory"):
            assert_scoped(models.Filter(must=short))


def test_the_terms_are_checked_in_order_rather_than_as_a_set() -> None:
    """`MANDATORY_FILTER_KEYS` is a tuple and it is compared as one.

    A reordered `must` is not itself a leak, but the constant is the single statement of the
    mandatory set and a set comparison here quietly makes half of it decorative — the order
    the module documents stops being a property anything holds it to.
    """
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    reversed_terms = list(reversed(built.must))
    with pytest.raises(EmptyScopeError, match="mandatory"):
        assert_scoped(models.Filter(must=reversed_terms))


def test_a_fifth_term_is_refused_rather_than_ignored() -> None:
    """Fails closed. An extra term only narrows, but a gate that admits an unknown clause
    cannot distinguish it from a nested one it did not read, and no caller needs one."""
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    with pytest.raises(EmptyScopeError, match="mandatory"):
        assert_scoped(
            models.Filter(
                must=[
                    *built.must,
                    models.FieldCondition(key="lang", match=models.MatchValue(value="en")),
                ]
            )
        )


def test_a_nested_filter_smuggled_into_must_is_refused() -> None:
    """A ``Filter`` inside ``must`` carries no ``key``, so a check that only reads
    ``FieldCondition.key`` would skip it and still count four. The length comparison is what
    stops the four mandatory terms being accompanied by a clause nobody read."""
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    with pytest.raises(EmptyScopeError, match="mandatory"):
        assert_scoped(
            models.Filter(
                must=[
                    *built.must,
                    models.Filter(
                        should=[
                            models.FieldCondition(
                                key="org_id", match=models.MatchValue(value=ORG_B.org_id)
                            ),
                        ]
                    ),
                ]
            )
        )


def test_the_rule_is_read_from_the_shared_constant_and_not_restated() -> None:
    """The gate and the builder must agree by construction, not by transcription.

    `MANDATORY_FILTER_KEYS` is the single statement of the mandatory set. If the gate held its
    own copy, adding a fifth mandatory term to `tenant_filter` would make every real query
    raise — which is loud — but REMOVING one would leave the gate asserting a key nothing
    builds, and the reviewer would delete the gate's copy to make the suite green.
    """
    import inspect

    source = inspect.getsource(search.assert_scoped)
    assert "MANDATORY_FILTER_KEYS" in source
    for key in MANDATORY_FILTER_KEYS:
        assert f'"{key}"' not in source, f"{key!r} is restated in assert_scoped rather than read"


# ── branch_query: what actually goes on the wire ─────────────────────────────


async def test_the_branch_call_carries_all_four_tenant_terms_in_must() -> None:
    client = RecordingClient()
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    sent = client.call_for("dense")["query_filter"]
    assert keys_in_must(sent) == list(MANDATORY_FILTER_KEYS)
    assert sent.should is None
    assert sent.must_not is None


async def test_the_branch_call_names_its_collection_from_the_space() -> None:
    """Not from a module constant. A reindex creates a second collection and both are live
    while the new version is published, so a call that does not say which space it means can
    read the half the caller did not intend."""
    client = RecordingClient()
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    assert client.call_for("dense")["collection_name"] == SPACE.collection


async def test_the_branch_call_names_the_branch_because_there_is_no_default_vector() -> None:
    client = RecordingClient()
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        sparse_vector(),
        space=SPACE,
        using="sparse",
        limit=5,
    )
    call = client.call_for("sparse")
    assert call["using"] == "sparse"
    assert call["limit"] == 5


async def test_the_branch_call_never_asks_for_vectors_back() -> None:
    """A returned embedding is recoverable plaintext — roughly 92% of a 32-token input by
    published inversion results — so it is not a payload field with a size cost, it is
    disclosure of the document."""
    client = RecordingClient()
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    assert client.call_for("dense")["with_vectors"] is False


async def test_the_branch_call_projects_the_payload_and_not_the_chunk_body() -> None:
    """The body lives in PostgreSQL. Projecting it here would let the reranker score the raw
    body rather than the exact string that was embedded, prefix included — a document that
    does not exist in the index."""
    client = RecordingClient()
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    projected = client.call_for("dense")["with_payload"]
    assert tuple(projected) == PAYLOAD_PROJECTION
    assert "text" not in projected
    assert "content" not in projected
    # Named fields, not the constant. The line above compares the call against
    # `PAYLOAD_PROJECTION` and therefore cannot fail for any edit to the tuple — it proves the
    # call uses the constant, not what the constant contains. These three are the assertions
    # that go red on a change to the tuple itself.
    #
    # `overlap_of` was projected "for dedup" until the near-duplicate penalty was dropped by
    # ruling (#80, 2026-08-12; `docs/22` § G10). Ingestion still writes the field; the query
    # path stops asking for it. Re-adding it needs a reader, and this line is what makes
    # adding it back a decision rather than a diff nobody reviews.
    assert "overlap_of" not in projected


async def test_the_branch_call_sets_an_explicit_timeout() -> None:
    """The client default is no timeout, so a wedged server holds the connection until the
    caller's deadline and the branch that was supposed to cost 200 ms costs the request."""
    client = RecordingClient()
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    assert client.call_for("dense")["timeout"] > 0


async def test_an_empty_scope_stops_the_branch_before_the_client_is_touched() -> None:
    """The refusal happens in the builder, so no request is recorded at all — which is what
    makes "an unfiltered call is unrepresentable" a property rather than a convention."""
    client = RecordingClient()
    with pytest.raises(EmptyScopeError):
        await branch_query(
            client,  # type: ignore[arg-type]
            ORG_A,
            [],
            DENSE,
            space=SPACE,
            using="dense",
            limit=20,
        )
    assert client.calls == []


async def test_two_organizations_send_two_different_filters() -> None:
    """One client, two calls, two scopes. A worker process is pooled and the silent failure is
    the previous request's organization still being set."""
    client = RecordingClient()
    for ctx in (ORG_A, ORG_B):
        await branch_query(
            client,  # type: ignore[arg-type]
            ctx,
            VERSIONS,
            DENSE,
            space=SPACE,
            using="dense",
            limit=20,
        )
    first, second = (call["query_filter"] for call in client.calls)
    assert first != second


async def test_the_branch_returns_the_points_and_not_the_response_object() -> None:
    """``query_points`` returns a ``QueryResponse``; returning it would put a client type into
    every downstream stage's signature for the sake of one attribute."""
    client = RecordingClient(responses={"dense": [point("c1", 0.9), point("c2", 0.5)]})
    points = await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    assert [p.payload["chunk_id"] for p in points if p.payload] == ["c1", "c2"]


def test_the_branch_map_points_at_stages_and_not_at_span_names() -> None:
    """The map holds *stage* names, so ``span_for`` is still the thing that resolves a span.

    A map holding span names directly would be a second copy of the catalogue, which is how a
    name comes to exist in two spellings.
    """
    assert set(BRANCH_STAGES) == {"dense", "sparse"}
    assert set(BRANCH_STAGES.values()) == {"dense_retrieval", "sparse_retrieval"}


async def test_the_span_a_branch_opens_is_the_catalogued_name(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """**The name that actually reached the tracer**, not the constant it should have come from.

    A name built as ``f"kb.retrieval.{using}"`` produces the same two strings today and errors
    nowhere the day a branch is renamed: it exports cleanly and matches no recording rule,
    alert or dashboard query in the repository. The failure is empty panels on the dashboard
    you opened to debug retrieval, which is why this asserts the emitted name rather than the
    map it should have been read from.

    ``span_for`` raises ``KeyError`` on an unknown stage, so a derived *stage* name would fail
    loudly — but a derived *span* name would not, and that is the one this catches.
    """
    recorder = RecordingTracer()
    monkeypatch.setattr(search, "_TRACER", recorder)
    client = RecordingClient()
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    await branch_query(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        sparse_vector(),
        space=SPACE,
        using="sparse",
        limit=20,
    )
    assert recorder.spans == [
        ("kb.retrieval.dense", trace.SpanKind.CLIENT),
        ("kb.retrieval.sparse", trace.SpanKind.CLIENT),
    ]


async def test_the_branch_span_is_a_client_span(monkeypatch: pytest.MonkeyPatch) -> None:
    """It is a network round trip to Qdrant. An INTERNAL span there makes the retrieval leg
    look like computation in every latency breakdown that splits on span kind."""
    recorder = RecordingTracer()
    monkeypatch.setattr(search, "_TRACER", recorder)
    await branch_query(
        RecordingClient(),  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        using="dense",
        limit=20,
    )
    assert recorder.spans[0][1] is trace.SpanKind.CLIENT


# ── retrieve_branches: the pairing, the concurrency, and the returned key set ──


async def test_both_branches_are_queried_and_both_carry_the_filter() -> None:
    client = RecordingClient()
    await retrieve_branches(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        sparse_vector(),
        space=SPACE,
        dense_limit=20,
        sparse_limit=20,
    )
    assert {call["using"] for call in client.calls} == {"dense", "sparse"}
    for call in client.calls:
        assert keys_in_must(call["query_filter"]) == list(MANDATORY_FILTER_KEYS)


async def test_the_two_branches_build_identical_filters() -> None:
    """Two queries are two chances to forget the filter, so neither is handed one — each builds
    its own from the same scope, which makes them identical by construction rather than by a
    caller remembering to share an object."""
    client = RecordingClient()
    await retrieve_branches(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        sparse_vector(),
        space=SPACE,
        dense_limit=20,
        sparse_limit=20,
    )
    dense_filter = client.call_for("dense")["query_filter"]
    sparse_filter = client.call_for("sparse")["query_filter"]
    assert dense_filter == sparse_filter


async def test_there_is_no_way_to_pass_a_pre_built_filter_in() -> None:
    """The parameter that would reopen the hole. A caller-supplied filter is a filter a caller
    can get wrong, or build for another tenant, with nowhere in the signature to notice."""
    import inspect

    for function in (branch_query, retrieve_branches):
        names = {name.casefold() for name in inspect.signature(function).parameters}
        assert not names & {"filter", "query_filter", "tenant_filter", "scope"}, function.__name__


async def test_a_dense_only_run_returns_one_key_rather_than_an_empty_sparse_list() -> None:
    """ "The sparse branch was not run" and "the sparse branch matched nothing" are different
    runs. The degraded path selects on the first and refuses on the second, so a two-key result
    with an empty list would make every dense-only run look like total lexical disagreement."""
    client = RecordingClient()
    branches = await retrieve_branches(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        space=SPACE,
        dense_limit=20,
    )
    assert set(branches) == {"dense"}
    assert [call["using"] for call in client.calls] == ["dense"]


async def test_a_sparse_vector_without_a_depth_is_refused() -> None:
    """Reads as depth zero and returns nothing — a lexical arm that stopped working, silently."""
    client = RecordingClient()
    with pytest.raises(ValueError, match="one decision"):
        await retrieve_branches(
            client,  # type: ignore[arg-type]
            ORG_A,
            VERSIONS,
            DENSE,
            sparse_vector(),
            space=SPACE,
            dense_limit=20,
        )
    assert client.calls == []


async def test_a_sparse_depth_without_a_vector_is_refused() -> None:
    """Reads as "sparse was configured" in a trace that never queried it."""
    client = RecordingClient()
    with pytest.raises(ValueError, match="one decision"):
        await retrieve_branches(
            client,  # type: ignore[arg-type]
            ORG_A,
            VERSIONS,
            DENSE,
            space=SPACE,
            dense_limit=20,
            sparse_limit=20,
        )
    assert client.calls == []


async def test_a_dense_vector_of_the_wrong_width_never_reaches_the_client() -> None:
    client = RecordingClient()
    with pytest.raises(DimensionMismatch):
        await retrieve_branches(
            client,  # type: ignore[arg-type]
            ORG_A,
            VERSIONS,
            [0.1] * 7,
            space=SPACE,
            dense_limit=20,
        )
    assert client.calls == []


async def test_a_sparse_vector_from_another_analyzer_never_reaches_the_client() -> None:
    client = RecordingClient()
    space = EmbeddingSpace("openai", "text-embedding-3-large", 8, sparse_analyzer="bm25/v0")
    with pytest.raises(AnalyzerMismatch):
        await retrieve_branches(
            client,  # type: ignore[arg-type]
            ORG_A,
            VERSIONS,
            DENSE,
            sparse_vector(),
            space=space,
            dense_limit=20,
            sparse_limit=20,
        )
    assert client.calls == []


async def test_each_branch_is_queried_exactly_once() -> None:
    """One call per branch, not one per candidate and not one per retry. ``call_for`` raises on
    anything else, so this is the guard against a loop appearing where a single call belongs."""
    client = RecordingClient()
    await retrieve_branches(
        client,  # type: ignore[arg-type]
        ORG_A,
        VERSIONS,
        DENSE,
        sparse_vector(),
        space=SPACE,
        dense_limit=20,
        sparse_limit=20,
    )
    client.call_for("dense")
    client.call_for("sparse")
    assert len(client.calls) == 2


async def test_a_failing_branch_does_not_leave_the_other_running() -> None:
    """Sibling cancellation. A branch left running against a request that has already lost
    holds a connection and bills a round trip nothing will read.

    ``TaskGroup`` wraps the failure in an ``ExceptionGroup``; the assertion reaches through it
    rather than pretending the wrapping is not there.
    """

    class Exploding(RecordingClient):
        async def query_points(self, **kwargs: object) -> object:
            self.calls.append(dict(kwargs))
            raise RuntimeError("qdrant said no")

    client = Exploding()
    with pytest.raises(ExceptionGroup) as raised:
        await retrieve_branches(
            client,  # type: ignore[arg-type]
            ORG_A,
            VERSIONS,
            DENSE,
            sparse_vector(),
            space=SPACE,
            dense_limit=20,
            sparse_limit=20,
        )
    assert any(isinstance(exc, RuntimeError) for exc in raised.value.exceptions)
