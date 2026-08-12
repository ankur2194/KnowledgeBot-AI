"""The lexical arm — BM25, computed here, with its corpus statistics scoped per tenant.

**This module closes finding C2.** BGE-M3 emitted dense *and* learned-sparse vectors from one
local forward pass, which is what made the dense + sparse + RRF design free. ADR-030 removed
local model inference and API embedding endpoints return dense only, so the sparse arm lost its
producer and hybrid search became dense-only — which took the degraded path's only selection
signal with it, because branch agreement needs two branches.

The resolution is the one ``CLAUDE.md`` already permits: *"The rule is no local model inference
for embedding or reranking, not no local computation — BM25 would be a statistical ranking
function, not a model."* BM25 has no weights, no training and no inference; it is arithmetic
over term statistics. Computing it here does not touch ADR-030.

WHERE THE STATISTICS LIVE, AND WHY NOT IN QDRANT
------------------------------------------------
BM25 needs two corpus numbers per query term: the number of documents in scope (``N``) and the
number of them containing the term (``df``). Qdrant can supply neither safely on the server we
pin.

Its ``modifier=IDF`` computes document frequencies **collection-wide, across every tenant**.
That is a relevance error — one organization's corpus skewing another's ranking — and a weak
cross-tenant statistical oracle, because a term's contribution to a score reveals how common
that term is in documents the caller may not read. Server 1.19.0 adds
``SearchParams(idf=IdfCorpusParams(corpus=<org filter>))`` to scope it; **ADR-021 pins 1.18.3**,
where that parameter does not exist. So ``SPARSE_MODIFIER`` stays ``None`` and the IDF factor is
applied **by us, to the query vector**, from statistics read under the same scope the tenant
filter uses.

That choice buys three things at once, and the third is the one that makes it the right one
rather than merely the available one:

1. **No cross-tenant statistics, on the pinned server.** The document frequencies behind a
   query come from ``(org_id, allowed_version_ids)`` and nothing else — the identical scope the
   four mandatory payload filters express. Bound to the read by ``CorpusStatistics.for_scope``,
   which raises rather than letting a statistics set taken for one scope weight another's query.
2. **Qdrant stays rebuildable** (non-negotiable 4). Nothing statistical is stored in the
   collection. The document-side vector is a pure function of *(chunk text, analyzer version,
   the three fixed BM25 parameters below)* — no ``N``, no ``df``, no ``avgdl`` read from a live
   corpus — so re-encoding a chunk during an ADR-010 rebuild reproduces byte-identical
   ``indices`` and ``values``. Had the IDF factor been baked into the document vector instead,
   every ingest would depend on the corpus size at the moment it ran, a rebuild would produce a
   *different* index that passes every count and checksum ADR-010 specifies, and only the
   ranking would move.
3. **A deletion or a disable takes effect on the statistics at the same instant it takes effect
   on retrieval**, because both are scoped by the same resolved active-version set rather than
   by anything cached against the organization as a whole.

The physical store is PostgreSQL — a rollup keyed by ``(organization_id, source_version_id,
analyzer, term_id)`` plus a per-version document total keyed by the same scope without the
term, written beside the chunks of the version being indexed and removed with it. Both are
derived and rebuildable exactly like the vectors: the term set of a version is a pure function
of its chunk text. Both are on this service's write allow-list (``app/db/writes.py``) and
their schema is a Laravel migration; see ``CorpusStatisticsStore`` for the read shape.

WHAT IS AND IS NOT IMPLEMENTED HERE
-----------------------------------
The arithmetic is implemented, because it is small and because two of its lines carry traps
that a later reader would not see (the IDF sign, and the document-side vector's independence
from the corpus). ``analyze`` is not: choosing a tokenizer is a per-language decision with a
real consequence — whitespace segmentation makes an entire CJK sentence one term, and the
sparse arm is then worth nothing for those languages while looking healthy in every metric.
That decision is the remaining work and it is deliberately left as a stated gap rather than
filled with a plausible default.

THE FOUR FILTERS APPLY TO THIS BRANCH IDENTICALLY
-------------------------------------------------
A sparse query is a query. It goes out through ``app/retrieval/search.py`` like the dense one,
carrying the same single ``Filter`` object built by ``tenant_filter`` — organization, bot
access, active source status, active source version. Nothing here builds a filter, issues a
request, or holds a client; this module produces vectors and numbers.
"""

from __future__ import annotations

import hashlib
import math
from collections import Counter
from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from typing import Final, Protocol

from qdrant_client import models

from app.retrieval.collection import SPARSE_ANALYZER_VERSION
from app.retrieval.tenancy import TenantContext

__all__ = [
    "BM25_AVGDL",
    "BM25_B",
    "BM25_K1",
    "MAX_QUERY_TERMS",
    "SPARSE_ANALYZER_VERSION",
    "TERM_ID_MODULUS",
    "CorpusStatistics",
    "CorpusStatisticsStore",
    "EmptySparsePassage",
    "EmptySparseQuery",
    "SparseAnalyzerMismatch",
    "SparseStatisticsUnavailable",
    "as_sparse_vector",
    "encode_passage",
    "encode_query",
    "evidence_fingerprint",
    "idf",
    "passage_weights",
    "query_weights",
    "term_frequencies",
    "term_id",
    "tokenize",
]

#: ``SPARSE_ANALYZER_VERSION`` is re-exported, not defined here. It lives in
#: ``app/retrieval/collection.py`` beside the rest of the schema because it is a field of
#: ``EmbeddingSpace`` and therefore part of the collection name — a bump is a reindex. Every
#: constant below is an input to it: change one and the version changes with it, or two
#: incomparable lexical encodings end up in one collection with nothing to tell them apart.
#:
#: Term-frequency saturation. Above ``k1`` further repetitions of a term add almost nothing,
#: which is the whole reason BM25 beats raw tf. 1.2 is the value every mainstream
#: implementation ships and it is a starting point to be moved by an evaluation run, never by
#: intuition — and moving it changes the document-side vector, so it is a re-encode.
BM25_K1: Final[float] = 1.2

#: Length normalization strength: 0 disables it, 1 normalizes fully. 0.75 is the standard.
BM25_B: Final[float] = 0.75

#: **A fixed constant, not a measured corpus average, and that is the point.** The textbook
#: ``avgdl`` is a live corpus statistic, which would make every document vector a function of
#: the corpus at the moment it was written — every new ingest would invalidate the ranking of
#: everything already indexed, and an ADR-010 rebuild would produce different numbers from the
#: same text. Pinning it keeps ``passage_weights`` pure and keeps the collection rebuildable.
#:
#: The approximation is cheap here because chunks are already size-bounded by the chunker
#: (`kb-chunking-rules`), so the length spread BM25's normalization exists to correct is small.
#: It is part of the analyzer identity for the same reason ``BM25_K1`` is.
BM25_AVGDL: Final[float] = 256.0

#: Qdrant sparse ``indices`` are unsigned 32-bit, so terms hash into this space. Collisions
#: merge two terms' postings — a mild relevance error at realistic vocabulary sizes, never a
#: tenancy one, since a collision cannot move a point across the payload filter.
TERM_ID_MODULUS: Final[int] = 2**32

#: Personalization for the term hash. Fixed forever: it is what makes a term id reproducible
#: across processes, hosts and rebuilds. **Never ``hash()``** — Python randomizes ``str``
#: hashing per interpreter under ``PYTHONHASHSEED``, so the indexer and the reader would
#: compute different ids for the same word, every sparse query would match nothing, and the
#: symptom is HTTP 200 with an empty lexical branch and no error anywhere.
_TERM_ID_PERSON: Final[bytes] = b"kb-sparse-v1"

#: Distinct query terms that may reach the statistics lookup. It bounds one PostgreSQL read and
#: one sparse index traversal per request, inside a 1.5 s leg that already contains provider
#: round-trips; without it a pasted document as a "question" is an unbounded ``IN`` list.
#:
#: **A default chosen here, not read from the contract.** The excess is dropped by descending
#: query-term frequency then ascending term id — deterministic, and it sheds the most-repeated
#: terms first, which in a long pasted query are the least discriminating ones. Both the number
#: and the ordering want an evaluation run.
MAX_QUERY_TERMS: Final[int] = 64


class SparseAnalyzerMismatch(Exception):
    """A query was analyzed by a different analyzer than the points it will be matched against.

    The failure this prevents is silent and total: term ids differ, so no posting matches, the
    sparse branch returns nothing, and the run reads as "the corpus has no lexical match for
    this question" rather than as a version skew.
    """


class SparseStatisticsUnavailable(Exception):
    """The corpus statistics for this scope could not be read. **An error, never a skip.**

    The tempting degradation is to fall back to uniform IDF — a plain term-frequency dot
    product. Do not: it produces a complete, plausible ranking in which common words weigh as
    much as part numbers, forever, with nothing in the trace to say so. That is the same shape
    as an outage laundered into a rerank skip (`bge-reranker`), and it is worse here because
    there is no capability gap it could legitimately be confused with.

    An organization whose scope genuinely holds no documents is not this: the statistics load
    succeeds and reports zero, and every branch returns nothing because there is nothing to
    return.
    """


class EmptySparsePassage(Exception):
    """A chunk analyzed to no terms, so it has no document-side lexical vector.

    A real case rather than a defect — an image-only chunk, or a table of bare numerals under a
    tokenizer that drops digits — and it needs a decision from ingestion rather than a vector.
    The upserter's rule is the one that matters: a point may be written with **no** sparse
    vector, deliberately and recorded as such, but never with an empty one. An empty sparse
    vector is accepted at upsert, matches nothing forever, and halves the hybrid branch for that
    chunk with no error on either side, so the two states must not be reachable by the same
    code path.
    """


class EmptySparseQuery(Exception):
    """The query analyzed to no terms, so there is no lexical vector to send.

    Raised rather than returning an empty ``SparseVector``, which Qdrant accepts and matches
    nothing forever. The two states must not be confused by the caller: "the lexical branch ran
    and found nothing" is a strong signal that the degraded path reads as disagreement, while
    "there was nothing to ask" is no signal at all. A run that hits this is a **dense-only run**
    and must be recorded as one — see ``retrieve_branches``, which returns only the branches it
    actually queried for exactly this reason.
    """


def term_id(term: str) -> int:
    """The stable 32-bit id a term occupies in the sparse index.

    Deterministic across processes, hosts, interpreter restarts and rebuilds, because the
    indexer and the reader must agree and nothing detects it when they do not. Hashing rather
    than a stored vocabulary keeps the write path corpus-independent and keeps a readable
    vocabulary out of the index — which is a nice property and **not** a security control.
    """
    if not term:
        raise ValueError("an empty term has no id; the analyzer must not emit one")
    digest = hashlib.blake2b(term.encode("utf-8"), digest_size=4, person=_TERM_ID_PERSON)
    return int.from_bytes(digest.digest(), "big")


def tokenize(text: str) -> Sequence[str]:
    """Text to analyzed terms: segmentation, case folding, Unicode normalization.

    **Unimplemented, and it is the whole remaining decision.** Whitespace segmentation is the
    default everyone reaches for and it makes an entire Chinese, Japanese or Thai sentence one
    term — the sparse arm then contributes nothing for those languages while every metric in
    the pipeline stays green, because a branch that matches nothing is indistinguishable from a
    corpus that contains nothing. Character bigrams are the usual answer for unsegmented
    scripts; the choice, and whether it varies by the chunk's ``lang``, belongs in an evaluation
    run over the golden corpus.

    Two rules that survive whichever tokenizer wins, because they are identity, not tuning:

    * The same function analyzes passages and queries. A query analyzed differently from the
      passages hashes elsewhere and matches nothing.
    * Any change to it is a ``SPARSE_ANALYZER_VERSION`` bump, and therefore a reindex.

    What this does *not* change: term overlap across scripts is zero either way, so
    cross-lingual retrieval rides on the dense branch exactly as it did under BGE-M3's learned
    lexical weights. The lexical arm is for exact tokens — part numbers, error codes, surnames,
    API symbols — in the language they were written in.
    """
    raise NotImplementedError(
        "the tokenizer is the open half of C2: pick segmentation and normalization per script, "
        "then bump SPARSE_ANALYZER_VERSION"
    )


def term_frequencies(terms: Iterable[str]) -> dict[int, int]:
    """Analyzed terms to ``{term_id: frequency}``. Pure, and the same on both sides."""
    frequencies: dict[int, int] = {}
    for term, occurrences in Counter(terms).items():
        identifier = term_id(term)
        frequencies[identifier] = frequencies.get(identifier, 0) + occurrences
    return frequencies


def passage_weights(frequencies: Mapping[int, int], document_length: int) -> dict[int, float]:
    """The **document side** of BM25: term saturation and length normalization only.

    Deliberately corpus-independent. Every input is a property of this one chunk plus the three
    pinned parameters, so the result is reproducible from the chunk's text alone — which is what
    keeps the collection rebuildable (ADR-010) and what lets a re-encode be compared against
    what is already indexed. The IDF factor that *would* make it corpus-dependent is applied to
    the query vector instead; see ``query_weights``.
    """
    if document_length <= 0:
        raise ValueError("a chunk with no analyzed terms has no length to normalize against")
    normalizer = BM25_K1 * (1.0 - BM25_B + BM25_B * document_length / BM25_AVGDL)
    weights: dict[int, float] = {}
    for term, frequency in frequencies.items():
        if frequency <= 0:
            raise ValueError(f"term {term} carries a non-positive frequency {frequency}")
        weights[term] = frequency * (BM25_K1 + 1.0) / (frequency + normalizer)
    return weights


def idf(document_total: int, document_frequency: int) -> float:
    """Inverse document frequency, in the form that **cannot go negative**.

    ``ln(1 + (N - df + 0.5) / (df + 0.5))``. The classical Robertson/Sparck-Jones form omits the
    outer ``1 +`` and turns negative once a term appears in more than half the documents — which
    under a dot-product scorer means a *matching* term subtracts from the score, so a document
    containing every query word can rank below one containing none of them. Lucene adopted this
    form for that reason and so do we.

    Both numbers come from the caller's own scope. There is no repository-wide corpus here and
    there must not be one.
    """
    if document_total < 0 or document_frequency < 0:
        raise ValueError("corpus statistics are counts and cannot be negative")
    if document_frequency > document_total:
        raise ValueError(
            f"document frequency {document_frequency} exceeds the scope total {document_total}; "
            "the statistics were read over a wider scope than the query is filtered to, which "
            "is the cross-tenant shape this module exists to prevent"
        )
    return math.log(1.0 + (document_total - document_frequency + 0.5) / (document_frequency + 0.5))


@dataclass(frozen=True, slots=True)
class CorpusStatistics:
    """The document frequencies behind one query, and the scope they were read over.

    The scope travels with the numbers rather than beside them so that applying one
    organization's statistics to another's query is a raise rather than a subtly different
    ranking. ``for_scope`` is what performs that check, and it is the tenancy control on this
    branch that is not already covered by the payload filter.

    ``document_frequencies`` holds only the query's own terms. A term absent from the mapping
    has ``df = 0`` in this scope, which is the highest IDF the formula can produce — correct,
    and worth stating because it means an unknown term is treated as maximally rare rather than
    dropped.
    """

    org_id: str
    analyzer: str
    evidence_fp: str
    document_total: int
    document_frequencies: Mapping[int, int]

    def __post_init__(self) -> None:
        if not self.org_id:
            raise ValueError("corpus statistics without an organization are not scoped at all")
        if not self.evidence_fp:
            raise ValueError(
                "corpus statistics must carry the fingerprint of the active-version set they "
                "were read over, or nothing can check they match the query's own scope"
            )
        if self.document_total < 0:
            raise ValueError("document_total is a count and cannot be negative")

    def for_scope(
        self, ctx: TenantContext, allowed_version_ids: Sequence[str], *, analyzer: str
    ) -> CorpusStatistics:
        """Return self, or raise because these numbers belong to a different read.

        Three ways this fires, all of them silent otherwise: statistics resolved for another
        organization, statistics resolved before a source was disabled or deleted (the
        active-version set moved, so the fingerprint moved), and statistics resolved under a
        different analyzer, whose term ids are a different space entirely.
        """
        if self.org_id != ctx.org_id:
            raise SparseStatisticsUnavailable(
                f"corpus statistics were read for organization {self.org_id!r} and this query "
                f"is scoped to {ctx.org_id!r}"
            )
        if self.analyzer != analyzer:
            raise SparseAnalyzerMismatch(
                f"corpus statistics were read under analyzer {self.analyzer!r}, the query is "
                f"analyzed by {analyzer!r}; term ids are not comparable across analyzers"
            )
        expected = evidence_fingerprint(allowed_version_ids)
        if self.evidence_fp != expected:
            raise SparseStatisticsUnavailable(
                "corpus statistics were read over a different active-version set than this "
                "query is filtered to. A disable or a delete moves that set, and stale "
                "statistics would weight the query by documents the filter now excludes"
            )
        return self

    def idf_for(self, term: int) -> float:
        """IDF for one term in this scope. Absent means ``df = 0``, i.e. maximally rare."""
        return idf(self.document_total, self.document_frequencies.get(term, 0))


class CorpusStatisticsStore(Protocol):
    """The seam to the document-frequency rollup. **The read is tenant-scoped like any other.**

    Implemented against PostgreSQL, which is where every derived, rebuildable artifact of this
    service already lives. The shape the implementation must have, stated here because the
    implementation is on the far side of a boundary this agent does not cross:

    * A rollup keyed ``(organization_id, source_version_id, analyzer, term_id) ->
      document_frequency``, plus a per-version document total keyed by the same scope without
      the term. The analyzer is in **both** keys and is not decoration: term ids are
      ``blake2b(term)`` under a fixed personalization, so two analyzers are two id spaces over
      the same text and a read that summed across them would return a number that is not a
      document frequency of anything, with nothing raised. Both are pure functions of the
      version's chunk text
      under ``SPARSE_ANALYZER_VERSION``, so both are reproducible during an ADR-010 rebuild and
      neither is authoritative.
    * Written in the same transaction as the version's ``chunks`` rows, against the new,
      not-yet-active ``source_version_id`` — so it publishes atomically with the version and a
      redelivered task overwrites rather than accumulates.
    * Removed with the version, by the same identifiers deletion already uses. A retired
      version's terms must stop weighting queries at the instant its vectors stop matching
      them, which is automatic here because the read is scoped by the active-version set.
    * **Two reads, not one, and the second is the one that gets forgotten.** ``load`` returns a
      ``document_frequencies`` mapping *and* a ``document_total``, and they come from the two
      different tables under two different aggregations:

      1. ``sparse_term_frequencies`` — ``organization_id = $1 AND source_version_id = ANY($2)
         AND analyzer = $3 AND term_id = ANY($4)``, ``sum(document_frequency)`` **grouped by
         term_id**. The scope spans several versions and a term is counted once per version, so
         the per-term sum is the read and not a convenience.
      2. ``sparse_version_statistics`` — ``organization_id = $1 AND source_version_id = ANY($2)
         AND analyzer = $3``, ``sum(document_total)`` over the version set. There is no
         ``term_id`` term: this is the IDF numerator, one number for the whole scope.

      The second predicate is a strict prefix of the first, which is exactly why documenting
      only the first reads as complete. It is not: a store that issued only statement 1 would
      return a ``CorpusStatistics`` it cannot populate, and one that issued statement 2 without
      the analyzer would return a plausible number rather than an error (below).

    * **The analyzer predicate is not optional on either read, and it is easier to forget on the
      totals.** It is the leading edge of the primary key alongside the first two columns on
      both tables, and ``load`` takes ``analyzer`` precisely so it can be bound rather than
      assumed. A document total *reads* as analyzer-independent — it counts chunks, and a
      version has the same chunks under either analyzer — but the column is not independent,
      because the row is written per analyzer: an analyzer-blind sum over a version indexed
      under two of them adds both rows into the numerator. And precisely because the count
      really does not depend on the analyzer, those two rows normally hold the *same* number, so
      the wrong answer is a clean doubling — a corpus twice its real size, every score shifted
      by one constant, no row missing, nothing raised. A fixture seeding equal totals under two
      analyzers cannot tell a bound predicate from an unbound one; the migration's own test
      seeds two *different* totals for that reason.

    * ``$2`` is the same ``allowed_version_ids`` the tenant filter is built from — not the
      organization as a whole — **on both reads**. Scoping to the org would leave a weak oracle
      *inside* an organization, across bots, since a term's weight would then reflect documents
      the queried bot has no access to; scoping to the resolved version set removes it and makes
      the statistics agree with what is searchable.

    * **The totals read is unconditional.** It runs even when ``term_ids`` is empty, which is a
      real call — a query that analyzed to no terms is a dense-only run, and its caller may
      still ask. A total of zero is what distinguishes "this scope holds no documents", where
      every branch correctly returns nothing, from a failed lookup, which is
      ``SparseStatisticsUnavailable`` and never a zero.

    * **What ``load`` must stamp on the value it returns**, because ``for_scope`` has nothing
      else to check against: ``org_id`` from ``ctx``, ``analyzer`` from its own argument, and
      ``evidence_fp`` from ``evidence_fingerprint(allowed_version_ids)`` over the exact list it
      bound to ``$2``. Deriving the fingerprint from anything else — the organization's whole
      version set, a resolution cached earlier in the request — turns ``for_scope`` into a check
      that passes on statistics read over a wider scope than the query is filtered to, which is
      the one thing it exists to refuse.

    **The two statements above exist in three places and must agree placeholder for
    placeholder**, because whoever implements the Python store will read exactly one of them:
    ``2026_08_07_000600_create_sparse_corpus_statistics_tables.php`` (which states both, one
    beside each ``CREATE TABLE``), ``App\\Repositories\\Eloquent\\
    EloquentSparseCorpusStatisticsRepository`` (the only implementation that exists — Laravel's,
    read-only, written to prove the schema can express this scoping), and here.
    ``tests/unit/test_sparse_store_read_shape.py`` compares this docstring against the migration
    so a half-stated read is a failing test rather than a second, narrower query.

    **Both halves of the boundary this once blocked on have moved.** The two tables were
    admitted to ``app/db/writes.py``'s allow-list on the properties that list encodes — derived
    and rebuildable, untouched by any public API path, migrated by Laravel — and the migration
    exists. What is still not done is the implementation **in this service**: no module here
    issues either statement, and this Protocol has no concrete implementation. Deletion is the
    other open half — a purged version's rows are removed by nothing today; see
    ``app/deletion/tasks.py``'s ``_purge_relational``.

    Failure is an error, never a degradation — see ``SparseStatisticsUnavailable``.
    """

    async def load(
        self,
        ctx: TenantContext,
        allowed_version_ids: Sequence[str],
        term_ids: Sequence[int],
        *,
        analyzer: str,
    ) -> CorpusStatistics:
        """Document frequencies for ``term_ids`` within this exact scope."""
        ...


def evidence_fingerprint(allowed_version_ids: Sequence[str]) -> str:
    """A stable digest of the resolved active-version set.

    Order-independent and duplicate-independent, because the set is what matters and the
    resolver's ordering is not part of the contract. Used to bind a statistics read to the scope
    it was taken over, and it is the same value a retrieval or answer cache key must carry
    (`valkey-keyspaces`, `kb-tenancy-isolation`) so a cached entry cannot outlive the evidence
    it was built from.
    """
    if not allowed_version_ids:
        raise ValueError(
            "an empty active-version set has no fingerprint; tenant_filter raises on that scope "
            "and nothing downstream should have been reached"
        )
    joined = "\x00".join(sorted(set(allowed_version_ids)))
    return hashlib.blake2b(joined.encode("utf-8"), digest_size=16).hexdigest()


def query_weights(
    frequencies: Mapping[int, int],
    stats: CorpusStatistics,
) -> dict[int, float]:
    """The **query side** of BM25: the IDF factor, from this scope's statistics.

    Splitting BM25 across the two vectors this way is exact rather than an approximation.
    Qdrant scores a sparse pair as the dot product over shared indices, so

        sum_t  (qtf_t * idf_t) * (tf_t * (k1+1) / (tf_t + k1 * (1 - b + b * dl/avgdl)))

    is the BM25 score of the passage for the query, term for term. What the split buys is that
    every corpus-dependent quantity sits on the *query* vector, where it is computed per request
    under the caller's own scope — so no tenant's statistics are ever written into the index, and
    ``SPARSE_MODIFIER`` can stay ``None`` on the server we pin.
    """
    weights: dict[int, float] = {}
    for term, frequency in frequencies.items():
        if frequency <= 0:
            raise ValueError(f"term {term} carries a non-positive frequency {frequency}")
        weights[term] = frequency * stats.idf_for(term)
    return weights


def as_sparse_vector(weights: Mapping[int, float]) -> models.SparseVector:
    """Weights to Qdrant's parallel ``indices``/``values`` arrays.

    Sorted by index so two encodings of the same text are byte-identical, which is what makes a
    rebuild comparable against what is already indexed.

    An empty mapping raises. An empty sparse vector is accepted at upsert and at query, matches
    nothing forever, and halves the hybrid branch with no error on either side — the one failure
    in this module that is both silent and permanent.
    """
    if not weights:
        raise ValueError(
            "an empty sparse vector matches nothing forever and raises nothing; a chunk or a "
            "query with no analyzed terms is a decision the caller must make explicitly"
        )
    ordered = sorted(weights.items())
    for term, _ in ordered:
        if not 0 <= term < TERM_ID_MODULUS:
            raise ValueError(f"term id {term} is outside Qdrant's unsigned 32-bit index space")
    return models.SparseVector(
        indices=[term for term, _ in ordered],
        values=[float(value) for _, value in ordered],
    )


def encode_passage(text: str) -> models.SparseVector:
    """A chunk's document-side sparse vector. Deterministic; no corpus statistics involved.

    Called by ingestion on the **exact string that was embedded**, heading prefix included, so
    the lexical arm and the dense arm describe the same document. It is a pure function of that
    string and the pinned parameters: re-running it during an ADR-010 rebuild reproduces the
    same ``indices`` and ``values``, which is the property that lets a rebuild be verified by
    comparison rather than by trust.

    Raises ``EmptySparsePassage`` when the text analyzes to nothing. Ingestion must treat that
    as a chunk-level decision — a point deliberately written without a sparse vector — and never
    as an empty one.
    """
    terms = tokenize(text)
    if not terms:
        raise EmptySparsePassage(
            "the chunk analyzed to no terms, so it has no document-side lexical vector. Write "
            "the point without a sparse vector and record that it was deliberate; an empty one "
            "would be accepted and would match nothing forever"
        )
    return as_sparse_vector(passage_weights(term_frequencies(terms), len(terms)))


def encode_query(
    text: str,
    ctx: TenantContext,
    allowed_version_ids: Sequence[str],
    store_result: CorpusStatistics,
) -> models.SparseVector:
    """The query-side sparse vector — stage 8's input. Carries this scope's IDF and nothing else.

    ``store_result`` is passed in rather than fetched here so this function stays pure and so
    the statistics read is one awaited call the caller can put a deadline on, inside a leg that
    already contains provider round-trips. ``ctx`` and ``allowed_version_ids`` are positional
    and required because they are what ``for_scope`` checks the statistics against — a signature
    that let them be omitted would let a statistics set from another scope through.

    Raises ``EmptySparseQuery`` when the query analyzes to no terms. That is a dense-only run and
    must be traced as one; it is not a sparse branch that found nothing.
    """
    terms = tokenize(text)
    frequencies = term_frequencies(terms)
    if not frequencies:
        raise EmptySparseQuery(
            "the query analyzed to no terms, so there is nothing to ask the lexical branch. "
            "This is a dense-only run and must be recorded as one — an empty sparse vector "
            "would instead match nothing and read as a corpus with no lexical hit"
        )
    stats = store_result.for_scope(ctx, allowed_version_ids, analyzer=SPARSE_ANALYZER_VERSION)
    bounded = _bound_query_terms(frequencies)
    return as_sparse_vector(query_weights(bounded, stats))


def _bound_query_terms(frequencies: Mapping[int, int]) -> dict[int, int]:
    """Cap the distinct query terms at ``MAX_QUERY_TERMS``, deterministically.

    Most-repeated first out the door: in a query long enough to hit the cap, the terms repeated
    most are the ones carrying least discrimination. Ties break on term id so two identical
    queries produce identical vectors.
    """
    if len(frequencies) <= MAX_QUERY_TERMS:
        return dict(frequencies)
    ordered = sorted(frequencies.items(), key=lambda item: (item[1], -item[0]))
    return dict(ordered[:MAX_QUERY_TERMS])


# Checked at import rather than in a test: every one of these is a mis-set constant whose only
# symptom is a ranking that moved, months later, with no diff to point at.
assert 0.0 <= BM25_B <= 1.0
assert BM25_K1 > 0.0
assert BM25_AVGDL > 0.0
assert MAX_QUERY_TERMS > 0
assert TERM_ID_MODULUS == 2**32
assert len(_TERM_ID_PERSON) <= 16  # blake2b's personalization ceiling
