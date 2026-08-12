"""The collection schema, in one module so a Qdrant migration is a single edit.

Vector configuration, quantization, the HNSW multitenancy pattern, and the payload index
list live here together. They are kept together because they change together: the next
server minor deprecates ``on_disk``/``always_ram`` in favour of a ``memory`` enum, and that
migration should touch one file.

**This service owns the schema; ``app/ingestion/`` and ``app/deletion/`` are tenants of it.**
Ingestion writes points and deletion removes them, and neither may add a vector, change a
distance, or add a payload index. A second definition of any constant below is drift that
nothing detects until ranking quietly moves.

**The dense width is no longer a constant here, because it is no longer ours.** Embeddings
are produced by an external provider through the adapter layer, so the width is a property
of *(provider, model, requested dimensions)* and changes when a bot's provider connection
changes. It arrives on ``EmbeddingSpace``, which is recorded with the source version that
was indexed under it — the same place the rest of that version's identity lives — and is
verified against the live collection at bootstrap. A module constant here would be a number
this service invented about someone else's model, and the failure it produces is a 400 on
the first upsert at best and, when two widths reach one collection, silently wrong ranking
forever.

Three consequences, all load-bearing:

* **Changing embedding model is a reindex, not a config edit.** The collection is created
  with a fixed width and distance; nothing rewrites that in place.
* **The collection name encodes the space, so two spaces co-exist.** That co-existence is
  what makes the reindex publishable atomically (ADR-010, `kb-source-lifecycle`): the old
  space keeps serving every query until the new one is fully indexed and verified, and the
  active-version pointer moves once. A single fixed name would force a destructive
  in-place swap with a window where the corpus is half-migrated and every answer is drawn
  from whichever half happened to be written.
* **The same model at two widths is two spaces.** Several APIs take ``dimensions`` as a
  *request* parameter (Matryoshka truncation), so the model id alone does not identify the
  space. Width is in the identity for that reason.

What breaks if the pieces drift apart:

* **A name reused across embedding spaces.** Two spaces in one collection do not error —
  cosine distance is defined between any two vectors of equal width, and two providers can
  easily agree on 1024 while disagreeing about everything else — they just rank nonsense
  above the right answer, for the subset of content embedded under the other model, forever.
* **Payload indexes built after ingestion.** Qdrant generates the extra HNSW edges for a
  payload field *only once that field's index exists*, and tenant co-location happens during
  optimization. Both apply going forward, never retroactively, so a late index leaves every
  filtered query scanning while ``is_tenant=True`` appears to do nothing. Repair means
  forcing a re-index by nudging ``ef_construct``, which rebuilds every segment.
* **A vector renamed.** ``dense`` and ``sparse`` are part of the wire contract. A collection
  with named vectors has no default branch, so every query names one; renaming either is a
  re-index, not a rename.

**The ``sparse`` branch has a producer again** (finding C2, closed). BGE-M3 produced dense
and sparse from one local forward pass and API embedding endpoints return dense only, which
left the vector declared and unfed for a while; it is now filled by locally-computed BM25 in
``app/retrieval/sparse.py``. BM25 is a statistical ranking function rather than a model — no
weights, no training, no inference — so it is outside ADR-030's prohibition, which is about
local *model inference* and not about local computation.

Two consequences land on this module. ``SPARSE_ANALYZER_VERSION`` joins the space identity,
because two lexical analyzers in one collection are as incomparable as two embedding models
and fail more quietly. And ``SPARSE_MODIFIER`` stays ``None`` — see its own note; the reason
is now a tenancy one that survives the change of producer.

The collection holds nothing authoritative (ADR-010): payloads carry identifiers and
citation locators only, and the whole thing is reconstructible from PostgreSQL and object
storage. The point id is the deterministic ``uuid5`` defined by ``kb-source-lifecycle`` and
is written back to ``chunks.vector_point_id``, so PostgreSQL can always name every point it
owns — that is ingestion's obligation, recorded here because it is a property of the schema.
"""

from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from typing import TYPE_CHECKING, Final, Literal

# Runtime, not ``TYPE_CHECKING``: the two bootstrap functions at the foot of this module
# construct ``VectorParams``, ``ScalarQuantization`` and ``KeywordIndexParams`` rather than
# merely annotating them.
from qdrant_client import models

if TYPE_CHECKING:
    from qdrant_client import QdrantClient

__all__ = [
    "COLLECTION",
    "DENSE_DISTANCE",
    "DENSE_ON_DISK",
    "DENSE_VECTOR_NAME",
    "HNSW_M",
    "HNSW_PAYLOAD_M",
    "MAX_DIMENSIONS",
    "PAYLOAD_INDEXES",
    "QUANTIZATION_ALWAYS_RAM",
    "QUANTIZATION_QUANTILE",
    "QUANTIZATION_TYPE",
    "SCHEMA_VERSION",
    "SPARSE_ANALYZER_VERSION",
    "SPARSE_MODIFIER",
    "SPARSE_VECTOR_NAME",
    "AnalyzerMismatch",
    "DimensionMismatch",
    "EmbeddingSpace",
    "PayloadIndex",
    "assert_analyzer",
    "assert_dimensions",
    "ensure_collection",
    "ensure_payload_indexes",
]

#: Bumped when the payload contract, the vector names, or the index list change in a way
#: that makes an existing collection unreadable by this code. It is part of the collection
#: name, so a bump is a reindex — which is the honest cost of those changes and the reason
#: the number is here rather than implied.
SCHEMA_VERSION: Final[int] = 1

#: Qdrant's own ceiling on a dense vector. A width outside ``0 < d <= MAX_DIMENSIONS`` is
#: rejected when the space is constructed rather than when the collection is created, so a
#: provider registry row carrying a zero or a placeholder fails at the point it is read
#: instead of at the first upsert of a long ingestion run.
MAX_DIMENSIONS: Final[int] = 65_536

DENSE_VECTOR_NAME: Final[str] = "dense"
SPARSE_VECTOR_NAME: Final[str] = "sparse"

#: The identity of the lexical arm — tokenizer, normalization, term-id hash, and the three
#: pinned BM25 parameters. It lives here, beside the rest of the schema, because it is a
#: **field of ``EmbeddingSpace`` and therefore part of the collection name**; the arithmetic
#: and the reasoning behind each parameter are in ``app/retrieval/sparse.py``, which imports
#: this rather than restating it.
#:
#: Why it is in the space at all, given the cost: points encoded under two analyzers in one
#: collection do not error. The changed terms simply hash elsewhere and stop matching, so the
#: older half of the corpus quietly loses its lexical recall while dense retrieval keeps every
#: panel populated — the same class of silent, permanent wrongness as two dense spaces sharing
#: a name, just narrower. Putting the version in the space makes the migration the one this
#: repository already has: a second collection, both live, the active-version pointer moving
#: once. The alternative is an in-place re-encode sweep with a half-migrated window nothing
#: can detect.
#:
#: The cost, stated rather than discovered: a bump changes the collection name, so it re-embeds
#: the **dense** vectors too, and those are billed provider calls now (ADR-030). Settle the
#: tokenizer before the first large ingest; a tokenizer fix afterwards is a corpus-wide
#: re-embed, not a configuration edit.
SPARSE_ANALYZER_VERSION: Final[str] = "bm25/v1"

#: Cosine is the default because every embedding API here documents its vectors as
#: normalized. It is part of the space identity all the same: the same vectors under dot
#: product rank differently, and a collection created with one and queried expecting the
#: other produces a plausible ordering that is simply wrong.
DENSE_DISTANCE: Final[str] = "Cosine"

#: Originals on disk, quantized vectors resident. The pairing is the point: neither half
#: is a useful setting alone.
DENSE_ON_DISK: Final[bool] = True

#: Scalar int8 (4x) rather than binary or 1-bit. Qdrant's own guidance is that 1-bit
#: compression loses significant precision below roughly a thousand dimensions, so the right
#: choice is now width-dependent rather than fixed: a 3072-dim space (``text-embedding-3-
#: large``) sits well clear of that boundary and a 1024-dim one sits on it. Moving any space
#: to binary or 4-bit is allowed only behind a recall measurement on that space, and the
#: measurement does not transfer to another space.
QUANTIZATION_TYPE: Final[str] = "int8"
QUANTIZATION_QUANTILE: Final[float] = 0.99
QUANTIZATION_ALWAYS_RAM: Final[bool] = True

#: Qdrant's documented multitenant pattern: no collection-wide graph, one sub-graph per
#: ``org_id``, so a query never traverses another tenant's region. It is a performance and
#: locality property, never an access control — the filter is the access control.
HNSW_M: Final[int] = 0
HNSW_PAYLOAD_M: Final[int] = 16

#: Left unset. Finding **C2 is closed** and the sparse arm is now locally-computed BM25
#: (``app/retrieval/sparse.py``) — which is precisely the case IDF was designed for, so the
#: original reason for leaving this unset has expired and the setting stays unset anyway.
#: Three reasons, in the order they were decided:
#:
#: 1. *Expired.* IDF is for BM25-style vectors carrying raw term frequencies, and BGE-M3's
#:    lexical weights were already learned term importance, so the modifier double-counted
#:    rarity. That model is gone; this reason went with it.
#: 2. *Decisive, and a tenancy one.* Qdrant computes document frequencies **collection-wide
#:    across every tenant** with the modifier on, which is both a relevance error (one
#:    tenant's corpus skewing another's ranking) and a weak cross-tenant statistical oracle:
#:    a term's score reveals how common it is in documents the caller cannot read. Server
#:    1.19.0 adds ``SearchParams(idf=models.IdfCorpusParams(corpus=<org filter>))`` to scope
#:    it per tenant — **we are pinned to 1.18.3** (ADR-021), where that parameter does not
#:    exist. There is no way to turn this on today without accepting cross-tenant statistics.
#: 3. *Consequent.* Because of 2, the IDF factor is applied by us to the **query** vector from
#:    statistics read under ``(org_id, allowed_version_ids)``. Turning the server modifier on
#:    now would multiply it in twice, and the symptom would be rare terms dominating every
#:    lexical result — a ranking change with no error and no diff.
#:
#: Consequence worth keeping in view: because the document-side vector carries no corpus
#: statistic, it is a pure function of the chunk's text, so the collection stays rebuildable
#: (ADR-010) and a rebuild reproduces byte-identical sparse vectors.
SPARSE_MODIFIER: Final[str | None] = None

#: TRANSITIONAL, and the only fixed collection name left. It names the collection a
#: single-space deployment bootstraps, and it exists because ``app/api/health.py``,
#: ``app/ingestion/indexing/upserter.py`` and the test harness each need *a* name today. It
#: is deliberately no longer ``kb_bge_m3_v1``: that name asserted a model this service no
#: longer runs, and reading it as the current space is how content embedded by a provider
#: lands in a collection named for a different one.
#:
#: **It cannot survive the second embedding space, and a reindex creates one by
#: definition.** Every read and write path must take ``EmbeddingSpace.collection`` from the
#: source version it is operating on. Deriving it from an environment variable is not the
#: repair — a name that can be overridden per environment is a name that can disagree
#: between the indexer and the reader, and that disagreement reads as an empty corpus, HTTP
#: 200, no error anywhere.
COLLECTION: Final[str] = "kb_chunks_v1"


class DimensionMismatch(Exception):
    """A vector's width does not match the space it is being used against.

    Raised rather than logged. A wrong-width query vector is a 400 from the server if you
    are lucky and a silently mis-ranked answer if the two spaces happen to agree on width,
    and the second case is undetectable from the response.
    """


class AnalyzerMismatch(Exception):
    """A sparse vector was produced by a different analyzer than the space was built with.

    The sparse counterpart of ``DimensionMismatch``, and strictly quieter: there is no width to
    disagree about, so nothing is rejected at the wire. Term ids from another analyzer simply
    match no posting, the lexical branch returns nothing, and the run reads as a corpus with no
    exact-term hit rather than as a version skew.
    """


@dataclass(frozen=True, slots=True)
class EmbeddingSpace:
    """The identity of one embedding space — everything that makes vectors comparable.

    Recorded with the source version that was indexed under it, not read from this
    service's environment. That placement is the whole point: the reader must embed its
    query with the same provider, model and width the passages were embedded with, and the
    only authority on what that was is the version's own row. A service-level setting would
    let the indexer and the reader disagree, and the disagreement produces zero candidates
    or wrong ones, never an error.

    ``distance`` is part of the identity because the same vectors ranked under a different
    metric are a different index. ``dimensions`` is part of it because several APIs take a
    width parameter, so the model id does not imply it.

    ``sparse_analyzer`` is part of it because the collection carries **two** vectors on one
    point, and the second one is no longer produced by whatever produced the first. It was
    BGE-M3's learned lexical head — same model, same pass, so it needed no identity of its own —
    and it is locally-computed BM25 now (finding C2, closed). Two analyzers in one collection
    are as incomparable as two embedding models in one collection and fail even more quietly:
    the mismatched half loses its lexical recall and raises nothing. Trailing and defaulted so
    every existing call site keeps working; in the name's digest, so a bump is a reindex.
    """

    provider: str
    model: str
    dimensions: int
    distance: str = DENSE_DISTANCE
    schema_version: int = SCHEMA_VERSION
    sparse_analyzer: str = SPARSE_ANALYZER_VERSION

    def __post_init__(self) -> None:
        if not self.provider or not self.model:
            raise ValueError("an embedding space needs both a provider and a model id")
        if not self.sparse_analyzer:
            raise ValueError(
                "an embedding space needs a sparse analyzer version; an empty one puts two "
                "lexical encodings in one collection with nothing to tell them apart"
            )
        if not 0 < self.dimensions <= MAX_DIMENSIONS:
            raise ValueError(
                f"dimensions must be within 1..{MAX_DIMENSIONS}, got {self.dimensions!r} — "
                "a zero or placeholder width means the provider model registry row was not "
                "populated, and it must not reach collection creation"
            )

    @property
    def collection(self) -> str:
        """The collection name for this space. Deterministic, and the same on both sides.

        Two parts, and both are needed. The readable prefix is for a human reading a
        dashboard label or a Qdrant console; the digest is what actually guarantees
        distinctness, because slugging is lossy — ``embed-v1.5`` and ``embed_v1_5`` collapse
        to the same prefix, and a collision would put two embedding spaces in one collection,
        which is the one failure this whole module is shaped to prevent.

        The digest covers every field of the identity, so changing the distance, the requested
        width, or the sparse analyzer changes the name even when the model id does not.
        """
        digest = hashlib.blake2b(
            "\x00".join(
                (
                    self.provider,
                    self.model,
                    str(self.dimensions),
                    self.distance,
                    str(self.schema_version),
                    self.sparse_analyzer,
                )
            ).encode(),
            digest_size=5,
        ).hexdigest()
        readable = f"{_slug(self.provider)}_{_slug(self.model)}"[:48].strip("_")
        return f"kb_{readable}_{self.dimensions}_v{self.schema_version}_{digest}"


def _slug(value: str) -> str:
    """Lowercase, alphanumerics and underscores only. Lossy on purpose — see ``collection``."""
    return re.sub(r"[^a-z0-9]+", "_", value.lower()).strip("_")


@dataclass(frozen=True, slots=True)
class PayloadIndex:
    """One payload index, as data. The bootstrap translates these into client parameters."""

    field: str
    schema: Literal["keyword", "uuid"]
    #: Set on ``org_id`` alone. It co-locates one organization's vectors on disk so a
    #: tenant-scoped query is a sequential read. It grants and denies nothing.
    is_tenant: bool = False


#: Created **before** the first point is written, never after. The six fields are the
#: payload floor that makes the mandatory filter expressible at all; the full payload is a
#: superset carrying ``chunk_id``, ``seq``, ``page`` and the rest of the chunk metadata
#: schema, which does not need an index because nothing filters on it.
#:
#: Every identifier here is a **ULID**, so the schema is ``keyword`` rather than ``uuid``.
#: This is a deliberate divergence from the ``qdrant-hybrid-search`` example, which shows
#: ``UuidIndexParams`` on the five identifier fields: the chunk metadata schema types every
#: payload identifier as a 26-character ULID, and the one genuine UUID in the system is the
#: point id, which is not a payload field. A UUID index over ULID values is a startup-time
#: rejection at best and a filter that matches nothing at worst — and a filter that matches
#: nothing looks exactly like an empty corpus: HTTP 200, zero candidates, no exception.
PAYLOAD_INDEXES: Final[tuple[PayloadIndex, ...]] = (
    PayloadIndex("org_id", "keyword", is_tenant=True),
    PayloadIndex("bot_ids", "keyword"),
    PayloadIndex("source_id", "keyword"),
    PayloadIndex("source_item_id", "keyword"),
    PayloadIndex("source_version_id", "keyword"),
    PayloadIndex("source_status", "keyword"),
)


def assert_dimensions(space: EmbeddingSpace, vector_length: int) -> None:
    """Guard a vector's width against the space it is about to be used with.

    Called on the query path before the vector reaches the client, and on the write path
    before the first upsert of a batch. It is cheap and it is the only thing standing
    between a provider that quietly returned a different width — a model alias that moved, a
    ``dimensions`` parameter that was dropped from a request, a truncation the API applied
    without saying so — and a collection that accepts the write and ranks wrongly.
    """
    if vector_length != space.dimensions:
        raise DimensionMismatch(
            f"{space.provider}/{space.model} returned a {vector_length}-dim vector for a "
            f"{space.dimensions}-dim space ({space.collection}); the space is fixed at "
            "creation, so this is a reindex, not a configuration change"
        )


def assert_analyzer(space: EmbeddingSpace, analyzer_version: str) -> None:
    """Guard a sparse vector's analyzer against the space it is about to be used with.

    The sparse counterpart of ``assert_dimensions``, and it matters more rather than less. A
    wrong-width dense vector is usually a 400 from the server; a wrong-analyzer sparse vector is
    always accepted, because sparse indices are just integers and every integer is legal. The
    only symptom is a lexical branch that stops returning anything for the half of the corpus
    encoded under the other analyzer.

    Called on the query path before the vector reaches the client, and on the write path before
    the first upsert of a batch — the same two places as ``assert_dimensions``.
    """
    if analyzer_version != space.sparse_analyzer:
        raise AnalyzerMismatch(
            f"sparse vector was produced by analyzer {analyzer_version!r} for a space built on "
            f"{space.sparse_analyzer!r} ({space.collection}); term ids are not comparable "
            "across analyzers, and a mismatched sparse query matches nothing without erroring"
        )


def _sparse_modifier() -> models.Modifier | None:
    """``SPARSE_MODIFIER`` as the client's own enum. ``None`` today, and stated rather than
    omitted — read the constant's note for why the reason is a tenancy one and not a
    relevance one."""
    return None if SPARSE_MODIFIER is None else models.Modifier(SPARSE_MODIFIER)


def _verify_dense_branch(client: QdrantClient, space: EmbeddingSpace) -> None:
    """Assert a live collection still has the shape ``space`` describes.

    Three ways it can disagree and all three raise ``DimensionMismatch``: the dense branch is
    missing or renamed, its width moved, or its distance moved. One exception class because
    all three have the same remedy — a reindex into a new collection — and the same failure if
    they are tolerated: writes are accepted for a while and the ranking is silently wrong.

    The sparse branch is deliberately not asserted present. A collection may legitimately hold
    points written before the lexical arm was switched on, and ``assert_analyzer`` already
    stops a vector from the wrong analyzer reaching such a collection.
    """
    configured = client.get_collection(space.collection).config.params.vectors
    dense = configured.get(DENSE_VECTOR_NAME) if isinstance(configured, dict) else None
    if not isinstance(dense, models.VectorParams):
        raise DimensionMismatch(
            f"{space.collection} has no {DENSE_VECTOR_NAME!r} vector. A collection with named "
            "vectors has no default branch, so a renamed or unnamed vector is not something a "
            "query can fall back to — it is a reindex"
        )
    if dense.size != space.dimensions:
        raise DimensionMismatch(
            f"{space.collection} was created {dense.size}-dim and {space.provider}/"
            f"{space.model} is {space.dimensions}-dim. The width is fixed at creation; two "
            "widths in one collection rank nonsense above the right answer, forever"
        )
    if dense.distance != models.Distance(space.distance):
        raise DimensionMismatch(
            f"{space.collection} ranks under {dense.distance} and the space says "
            f"{space.distance}. The same vectors under another metric are a different index, "
            "and the wrong one produces a plausible ordering rather than an error"
        )


def ensure_collection(client: QdrantClient, space: EmbeddingSpace) -> None:
    """Create ``space.collection`` if it is absent, and verify its shape if it is present.

    Idempotent and re-runnable: it is called by the bootstrap path and again by the ADR-010
    rebuild drill. Verification is not decoration — a collection that already exists with a
    different width, distance, or vector name accepts writes for a while and then ranks
    wrongly, so a mismatch must raise here rather than be silently accepted.

    The width comes from ``space`` and from nowhere else. Verify it against the provider as
    well as against the server: embed one short probe string through the adapter at
    bootstrap and assert the result's length equals ``space.dimensions``. A registry row
    that says 1024 for a model that returns 1536 is otherwise discovered on the first upsert
    of the first ingestion run, hours later, in a worker.

    TODO(retrieval-engineer): the provider half of that verification is **not built here**,
    deliberately. It needs a bound embedding adapter — ``app/providers/`` exposes
    ``embed(texts, *, model)`` on ``ProviderAdapter`` and every concrete implementation of it
    still raises ``NotImplementedError``, and the binder that resolves a connection into one
    (``app/providers/embedding_selection.py``) has no caller at bootstrap. Building the probe
    against nothing would mean either a stub that always agrees or a bootstrap that always
    fails. The docstring's "as well as" is what makes the two halves separable: the server
    check below is complete and standalone. The seam to fill is a one-string
    ``adapter.embed([probe], model=space.model)`` at the bootstrap call site, asserted with
    ``assert_dimensions(space, len(result[0]))``.
    """
    if not client.collection_exists(space.collection):
        client.create_collection(
            collection_name=space.collection,
            vectors_config={
                DENSE_VECTOR_NAME: models.VectorParams(
                    size=space.dimensions,
                    distance=models.Distance(space.distance),
                    on_disk=DENSE_ON_DISK,
                )
            },
            # ``modifier`` is passed explicitly rather than left to the client default, which
            # is also ``None``. The two are the same value and not the same statement: an
            # omitted argument reads as "nobody considered IDF here", and the reason it is off
            # is a tenancy one with a server-version dependency (see ``SPARSE_MODIFIER``).
            sparse_vectors_config={
                SPARSE_VECTOR_NAME: models.SparseVectorParams(modifier=_sparse_modifier())
            },
            quantization_config=models.ScalarQuantization(
                scalar=models.ScalarQuantizationConfig(
                    type=models.ScalarType(QUANTIZATION_TYPE),
                    quantile=QUANTIZATION_QUANTILE,
                    always_ram=QUANTIZATION_ALWAYS_RAM,
                )
            ),
            hnsw_config=models.HnswConfigDiff(m=HNSW_M, payload_m=HNSW_PAYLOAD_M),
        )
        return

    _verify_dense_branch(client, space)


def ensure_payload_indexes(client: QdrantClient, space: EmbeddingSpace) -> None:
    """Create every index in ``PAYLOAD_INDEXES``, before the first point is written.

    Call order is part of the contract: this runs after ``ensure_collection`` and before any
    ingestion task can write. Running it later is not an error and does not repair the
    graph — see the module docstring.

    Every index is ``keyword``, including the five identifier fields — the deliberate
    divergence recorded above ``PAYLOAD_INDEXES``. ``is_tenant`` is read from the data rather
    than re-decided here, so the "exactly one tenant field" assertion at the foot of this
    module governs this loop too.
    """
    for index in PAYLOAD_INDEXES:
        params = models.KeywordIndexParams(
            type=models.KeywordIndexType.KEYWORD,
            is_tenant=index.is_tenant,
        )
        try:
            client.create_payload_index(
                collection_name=space.collection,
                field_name=index.field,
                field_schema=params,
            )
        except Exception as exc:
            # Swallowed narrowly and by message, because some server builds reject an index
            # name that is already present and others return it as a no-op. Idempotence is a
            # requirement here (bootstrap and the ADR-010 rebuild drill both call this), and a
            # blanket ``except`` would also swallow a rejected schema — which is the failure
            # that leaves every filtered query scanning while ``is_tenant`` appears to do
            # nothing.
            if "already exists" not in str(exc).casefold():
                raise


# Checked at import rather than in a test: a payload floor that has drifted must not reach a
# running process, because every filter built from it still returns HTTP 200.
assert {index.field for index in PAYLOAD_INDEXES} >= {
    "org_id",
    "bot_ids",
    "source_status",
    "source_version_id",
}
assert sum(index.is_tenant for index in PAYLOAD_INDEXES) == 1
