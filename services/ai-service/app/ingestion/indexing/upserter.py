"""Writing a version's points, and proving the write landed before anything activates.

This module is the ingestion **write** path into Qdrant. It is not a retrieval entry point,
and the difference matters for CI: the tenancy gate greps all of `app/` for the retrieval call
shapes and fails any line outside `app/retrieval/search.py` that does not carry a
`# tenancy-exempt: <reason>` marker on the same line.

**These calls carry the marker, and here is the reason they are entitled to it.** The four
mandatory query filters (`org_id`, `bot_ids`, `source_status`, active `source_version_id`)
express "what may this asker see". None of them is expressible here and one of them is
actively wrong: bot assignment is a control-plane fact that can change after indexing, the
source status is still a processing state, and the version being written is by construction
*not* the active one — `tenant_filter()` would raise on that scope, correctly. What this path
uses instead is an **identity** scope, `org_id` plus the single `source_version_id` being
written, and it returns no rows to any caller: an upsert returns an acknowledgement and the
verification returns an integer. So the exemption is narrow and must stay narrow — a read that
returns payloads to a request belongs in `app/retrieval/search.py`, behind the real filter.

Three flags decide whether the whole atomic-publication mechanism is real or theatre:

* **`wait=True` on every upsert.** The default is an acknowledgement of receipt, not a commit.
  Verifying against that ack reads a collection still absorbing writes — it passes on a warm
  collection under light load and exposes a partial version under a bulk reindex.
* **`exact=True` on every verification.** The approximate total can be off by hundreds
  mid-optimization, so comparing it against the expected chunk total both false-passes and
  false-fails.
* **Deterministic point ids** (`app.ingestion.identity.point_id`). Without them a redelivered
  batch writes a second set of points and the verification then *passes* on double the
  expected total only if you compare with `>=`; compare with `==` and it fails forever.

The collection schema, the payload-index set, and the named-vector contract belong to
`retrieval-engineer` and are imported from `app/retrieval/collection.py` rather than restated
here. A new indexed payload field is a request to that agent, never an edit here.

That includes the collection *name*, and the name is no longer one constant. With a locally
pinned model there was exactly one embedding space, so one name served; embeddings are a
provider call now, the width and the space are per model, and `EmbeddingSpace.collection`
derives the name from the space's own fields. **Every write here takes the space of the source
version being written** and never a service-level default: a name read from configuration is a
name the indexer and the reader can disagree about, and that disagreement produces zero
candidates with no error on either side. `COLLECTION` survives only as the single-space
bootstrap name and must not be reached for once a second space exists.

Every payload identifier is written as a **ULID string**, and the payload indexes are
`keyword` for exactly that reason — a UUID-typed index over ULID values matches nothing, which
is indistinguishable from an empty corpus. The write side and the index side have to agree on
this or the failure is total and silent; `ULID_PATTERN` is asserted at write time so the
disagreement surfaces on the first upsert rather than on the first query.
"""

from __future__ import annotations

import re
import time
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Final

from qdrant_client import models

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.chunking.chunker import CHUNK_METADATA_FIELDS, Chunk
from app.ingestion.embedding.embedder import ChunkVectors
from app.ingestion.identity import point_id
from app.observability.instruments import VECTOR_UPSERT_DURATION, VECTOR_UPSERT_POINTS
from app.retrieval.collection import (
    COLLECTION,
    DENSE_VECTOR_NAME,
    SPARSE_VECTOR_NAME,
    EmbeddingSpace,
)

__all__ = [
    "PAYLOAD_IDENTIFIER_FIELDS",
    "ULID_PATTERN",
    "UPSERT_BATCH_SIZE",
    "UPSERT_WAIT",
    "VECTOR_NAMES",
    "VERIFY_EXACT",
    "VerificationResult",
    "to_point",
    "upsert_points",
    "verify_indexed_total",
]

#: Points per upsert request. Small enough that a redelivery repeats little work, large enough
#: that a 4 000-chunk version is not 4 000 round trips. Batching is not a correctness knob —
#: deterministic ids are — so this is tunable without re-versioning anything.
UPSERT_BATCH_SIZE: Final[int] = 128

#: Never `False`, never omitted. See the module docstring; the wire default and the Python
#: client's default disagree, so a rebuild script driving raw REST gets the wire's `false`.
UPSERT_WAIT: Final[bool] = True

#: Never `False`, never omitted, and not the same field as `SearchParams.exact`.
VERIFY_EXACT: Final[bool] = True

#: Every identifier in a point payload is a ULID string. Asserted at write time because the
#: failure is otherwise invisible and total: a UUID-shaped `org_id` satisfies no `must` term,
#: so the tenant filter correctly excludes everything and every bot in every organization
#: retrieves nothing — HTTP 200, normal latency, no exception, no log line. The tempting fix
#: is to widen the filter, which converts an outage into a cross-tenant leak.
#: BOTH CASES, AND THE LOWERCASE HALF IS NOT COSMETIC. Crockford's alphabet is defined
#: case-insensitively and Laravel uses BOTH spellings on the same request: `HasUlids::newUniqueId()`
#: lowercases, so every model key crossing the seam is lowercase
#: (`01m0swft82zf81qx2d032qepb7`), while an id minted with `Str::ulid()` is uppercase — the
#: submission that proved it carried a lowercase `source.id` and an uppercase `job_id` in one body.
#: This pattern was uppercase-only in four modules at once, so the first real submission from the
#: first real tenant was refused 422 on `source.id` and `items.0.id` (2026-08-24, `docs/22` § R6).
#: Nothing is lowered or upper-cased on the way through: an id is compared byte-for-byte in Qdrant
#: filters and in the `chunks` table, so normalising here would be a second identity for one row.
ULID_PATTERN: Final[str] = r"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"

#: The named-vector contract, imported rather than restated. A collection with named vectors
#: has no default branch, so these names travel on every write and every query; renaming one is
#: a re-index, not a rename.
#:
#: Whether the sparse name still belongs here is **finding C2 and not this module's call**: the
#: local model produced dense and learned-sparse vectors together, and API embedding endpoints
#: produce dense only. The tuple keeps both because `app/retrieval/collection.py` still declares
#: both, and the write side must never disagree with the schema — if the sparse branch is
#: dropped, it is dropped there first and here second.
VECTOR_NAMES: Final[tuple[str, str]] = (DENSE_VECTOR_NAME, SPARSE_VECTOR_NAME)

#: The payload fields checked against `ULID_PATTERN` before a point is built. Exactly the
#: identifier fields — not `bot_ids`, which is checked element-wise, and not the citation
#: locators, which are integers and strings with no shape to assert.
#:
#: They are checked **here**, on the write side, rather than only in a Pydantic model on the
#: read side: a payload written as a UUID is accepted by Qdrant, indexed by a `keyword` index,
#: and matched by nothing forever. The first observation of that is an organization whose bots
#: all retrieve zero candidates at HTTP 200.
PAYLOAD_IDENTIFIER_FIELDS: Final[tuple[str, ...]] = (
    "chunk_id",
    "org_id",
    "source_id",
    "source_item_id",
    "source_version_id",
)

_ULID = re.compile(ULID_PATTERN)


@dataclass(frozen=True, slots=True)
class VerificationResult:
    """The pre-activation proof. `passed` is `expected == indexed`, never `<=` — see the
    module docstring on why a tolerant comparison hides duplicated points."""

    source_version_id: str
    expected: int
    indexed: int
    passed: bool


def _fail(message: str, error_class: ErrorClass = ErrorClass.VALIDATION) -> KbError:
    """`validation` by default, and never retried: the next attempt builds the identical point
    from the identical chunk, so a retryable class here burns the delivery cap on a certainty."""
    return KbError(error_class, message, origin=Origin.SELF)


def _payload_value(value: Any) -> Any:
    """One metadata value as the payload carries it — JSON shapes only.

    Tuples become lists and datetimes become ISO-8601 strings. Both conversions are here rather
    than left to the client's serializer because the payload is compared, not just stored: the
    ADR-010 rebuild proof re-derives these values from PostgreSQL and object storage, and a
    rebuild that writes `"2026-08-11T00:00:00+00:00"` where the original wrote a datetime object
    the client happened to render differently is a rebuild that cannot be shown to be identical.
    """
    if isinstance(value, tuple):
        return [_payload_value(item) for item in value]
    if isinstance(value, datetime):
        return value.isoformat()
    return value


def to_point(*, chunk: Chunk, vectors: ChunkVectors, source_status: str) -> models.PointStruct:
    """One chunk plus its vectors as a `models.PointStruct`.

    Both named vectors ride on **one** point, under `DENSE_VECTOR_NAME` and
    `SPARSE_VECTOR_NAME`, so a single upsert or delete moves the two branches together and
    they can never disagree about which chunks exist. A collection with named vectors has no
    default branch, so the names are part of the contract — renaming one is a re-index.

    The payload is the chunk metadata schema plus one field that is **not** chunk metadata:
    `source_status`. It is not on the chunk because it changes after indexing — disabling a
    source excludes it from retrieval immediately through the status filter while keeping every
    vector, so re-enabling is a payload write rather than a re-ingest.

    The payload carries identifiers and citation locators only — never the only extant record
    of anything (ADR-010). A payload key that exists in no column passes every total-based
    check and breaks the rebuild proof silently, because totals match afterwards and only the
    ranking moves. `embedding_model_id` is on the payload *and* on the `chunks` row for exactly
    that reason: it is what names the vectors a provider swap invalidated, and it must survive
    the collection being dropped.

    Asserts before returning: every identifier matches `ULID_PATTERN`, and the point id equals
    `identity.point_id(...)` for this chunk.

    **The sparse assertion is suspended pending finding C2**, not deleted. API embedding
    endpoints return dense vectors only, so nothing currently produces the learned lexical
    weights the local model used to emit, and asserting on them would fail every write. What
    replaces it until C2 lands is stricter in the direction that matters: a `ChunkVectors` with
    `sparse=None` may be written **only** while the collection has no sparse branch, and a
    sparse vector with zero indices is refused outright — an empty one is accepted by the
    upsert, matches nothing forever, and halves the hybrid branch for that chunk with no error.
    "Some points have sparse vectors and some do not" is the state this must never reach: it
    fuses a full dense ranking with a sparse ranking drawn from a subset, which is worse than
    either branch alone and looks like neither.

    **Two corrections to the paragraph above, and the second is the load-bearing one.**

    *Finding C2 is closed.* `app/retrieval/sparse.py` produces the document-side lexical vector
    from a local BM25 analyzer, so the sparse branch has a producer again and the collection
    always declares it. The rule as written — "`sparse=None` may be written only while the
    collection has no sparse branch" — would therefore forbid every `None`, which contradicts
    `ChunkVectors`, whose remaining `None` is `EmptySparsePassage`: a chunk that analyzes to no
    terms at all, which is a real document (an image-only chunk, a table of bare numerals) and
    not a defect. What survives of the rule is its point: a `None` must be a decision that
    reached this function, and an *empty* sparse vector is refused outright, because Qdrant
    accepts one, it matches nothing forever, and it halves the hybrid branch for that chunk with
    no error on either side.

    *`source_status` is a parameter.* It cannot be defaulted and it cannot be read off the
    chunk: it is not chunk metadata precisely because it changes after indexing. A default here
    would be this module inventing a lifecycle state for a row Laravel owns.
    """
    metadata = chunk.metadata
    payload: dict[str, Any] = {
        field: _payload_value(getattr(metadata, field)) for field in CHUNK_METADATA_FIELDS
    }
    # Not chunk metadata, and the one payload key that is legitimately mutable after the write:
    # disabling a source excludes it from retrieval immediately through the status filter while
    # every vector stays, so re-enabling is a payload write rather than a re-ingest.
    payload["source_status"] = source_status

    for field in PAYLOAD_IDENTIFIER_FIELDS:
        value = payload[field]
        if not isinstance(value, str) or not _ULID.match(value):
            raise _fail(
                f"payload {field}={value!r} is not a ULID. The payload indexes are `keyword` "
                "over ULID values, so a UUID-shaped identifier satisfies no `must` term: every "
                "bot in every organization retrieves nothing, at HTTP 200, with no exception "
                "and no log line. The fix is the identifier and a rebuild, never a wider filter"
            )
    for bot_id in metadata.bot_ids:
        if not _ULID.match(bot_id):
            raise _fail(f"payload bot_ids entry {bot_id!r} is not a ULID")

    if vectors.sparse is not None and not vectors.sparse.indices:
        raise _fail(
            f"chunk {metadata.chunk_id} carries a sparse vector with no indices. An empty one "
            "is accepted by the upsert and matches nothing forever — if the passage genuinely "
            "analyzes to no terms, that is `EmptySparsePassage` and it is recorded as "
            "`sparse=None`, not as an empty vector"
        )

    named: dict[str, Any] = {DENSE_VECTOR_NAME: vectors.dense}
    if vectors.sparse is not None:
        named[SPARSE_VECTOR_NAME] = vectors.sparse

    return models.PointStruct(
        # The deterministic id, recomputed here rather than carried on the chunk, so a chunk
        # whose `seq` was rewritten between chunking and indexing cannot smuggle a stale id
        # past this function. A replay writes the same ids and overwrites; anything else
        # duplicates every vector at HTTP 200.
        id=point_id(
            org_id=metadata.org_id,
            source_version_id=metadata.source_version_id,
            seq=metadata.seq,
        ),
        vector=named,
        payload=payload,
    )


def upsert_points(
    *,
    client: Any,
    space: EmbeddingSpace,
    points: list[models.PointStruct],
    batch_size: int = UPSERT_BATCH_SIZE,
) -> int:
    """Write points for the new, not-yet-active version. Returns the number written.

    Safe to re-run: ids are deterministic, so a replay overwrites rather than accumulating.
    That is the only reason this may be re-driven after a redelivery without a reconciliation
    pass first.

    Targets `space.collection`, which is the space the version's chunks were embedded under and
    nothing else — not a setting, not `COLLECTION`, not the collection the reader happens to be
    querying today. Writing a version's points into a collection built for a different space is
    accepted by the server whenever the widths agree, and the only symptom is ranking. Emits
    `kb_vector_upsert_duration_seconds{collection}` and
    `kb_vector_upsert_points_total{collection,outcome}`, where the label is bounded because
    collections are per embedding configuration and never per tenant. A client failure is
    `vector_indexing` (retryable, bounded); a rejected point id or a payload failing
    `ULID_PATTERN` classifies as `validation` and is never retried, because the next attempt
    builds the identical point.
    """
    if batch_size <= 0:
        raise _fail(f"batch_size must be positive, got {batch_size}")

    label = {"collection": space.collection}
    written = 0
    for start in range(0, len(points), batch_size):
        batch = points[start : start + batch_size]
        began = time.perf_counter()
        try:
            client.upsert(collection_name=space.collection, points=batch, wait=UPSERT_WAIT)
        except KbError:
            # Already classified upstream — re-classifying it here would relabel a validation
            # failure as a retryable indexing one and retry a certainty.
            VECTOR_UPSERT_POINTS.add(len(batch), {**label, "outcome": "failure"})
            raise
        except Exception as exc:
            VECTOR_UPSERT_POINTS.add(len(batch), {**label, "outcome": "failure"})
            raise KbError(
                ErrorClass.VECTOR_INDEXING,
                f"upsert of {len(batch)} points into {space.collection} failed: {exc}",
                origin=Origin.DOWNSTREAM,
            ) from exc
        VECTOR_UPSERT_DURATION.record(time.perf_counter() - began, label)
        VECTOR_UPSERT_POINTS.add(len(batch), {**label, "outcome": "success"})
        written += len(batch)
    return written


def verify_indexed_total(
    *,
    client: Any,
    space: EmbeddingSpace,
    org_id: str,
    source_version_id: str,
    expected: int,
) -> VerificationResult:
    """Prove the collection holds exactly `expected` points for this version, in this org.

    This runs **before** readiness is reported and therefore before anything can activate. A
    mismatch fails the run: the prior version was never touched and keeps serving, which is
    the entire point of the ordering. Never repair by re-upserting inside this function — a
    verification that fixes what it measures cannot fail.

    The scope is the identity pair only: org plus this version. No status predicate and no
    bot predicate, because the version is not active and its source is not `Ready` yet — the
    retrieval filter would legitimately match zero points and the check would fail every
    healthy run.

    `space` is the same one `upsert_points` wrote under, passed rather than defaulted. A proof
    that reads a different collection than the write is not a weaker proof — it is a proof of
    something else, and with multiple embedding spaces it would report zero on a perfectly
    healthy run and, worse, could report the *expected* total from a previous space's
    collection and pass a version that was never written.
    """
    if not org_id or not source_version_id:
        raise _fail(
            "the verification scope needs both an organization and a version. An unscoped "
            "total counts every tenant's points in the collection, which passes whenever the "
            "collection is busy enough and is a cross-tenant read besides"
        )

    # Both terms in `must`, both positive, both bound to a local first so `key=` stays on the
    # same physical line as its `FieldCondition(` — the delete-key gate's grep is line-based and
    # a formatter's wrap makes it match zero lines and silently stop existing.
    organization = models.MatchValue(value=org_id)
    version = models.MatchValue(value=source_version_id)
    scope = models.Filter(
        must=[
            models.FieldCondition(key="org_id", match=organization),
            models.FieldCondition(key="source_version_id", match=version),
        ]
    )

    # The CI marker sits on the call's OWN line. The tenancy grep is line-based, so a marker one
    # line below is not a marker at all — and the call cannot fit on one line inside the line
    # limit, which is exactly the shape that tempts someone to move the comment.
    try:
        result = client.count(  # tenancy-exempt: write-path proof, org + the one version written
            collection_name=space.collection,
            count_filter=scope,
            exact=VERIFY_EXACT,
        )
    except Exception as exc:
        raise KbError(
            ErrorClass.VECTOR_INDEXING,
            f"counting {space.collection} for version {source_version_id} failed: {exc}",
            origin=Origin.DOWNSTREAM,
        ) from exc

    indexed = int(result.count)
    return VerificationResult(
        source_version_id=source_version_id,
        expected=expected,
        indexed=indexed,
        # `==`, never `>=`. A tolerant comparison passes a version whose points were written
        # twice under non-deterministic ids, which is the exact failure deterministic ids exist
        # to prevent — and it passes it silently, on a total that is plausibly large.
        passed=indexed == expected,
    )


# `COLLECTION` is the transitional single-space bootstrap name and has no environment override
# on purpose; this asserts the import resolved to a real name rather than to an empty setting
# somebody re-introduced. It is deliberately not the name any write above uses — those take
# `space.collection` — and this import exists so the assertion can hold while both names are
# live. When the second embedding space lands, the import and this line go together.
assert COLLECTION
