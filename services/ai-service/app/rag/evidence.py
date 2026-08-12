"""Stages 9-12 — fusion, dedup, and the gate that decides whether the bot answers at all.

**The evidence threshold sits on the reranker's scale and on nothing else — and that scale
is now the provider's, not ours.** The retired local cross-encoder made the threshold a
repo-wide constant: one model, one scale, one number. Reranking is an external API call now,
so the threshold is a property of a ``(provider, model)`` pair and lives with the evaluation
run that produced it, in ``app.rag.rerank.CALIBRATIONS``. There is deliberately no default in
this module. The old pairing — ``0.30`` on a sigmoid — was ``bge-reranker-v2-m3``'s and is
void; NVIDIA's ranking models return unbounded logits, on which ``0.30`` means "clearly
relevant" rather than "leans irrelevant", roughly inverting the gate. Nothing raises when the
scales are swapped: ``0.30`` is a valid float on both, the trace stores a plausible score
either way, no test fails, and only the aggregate refusal rate moves. That is why every score
carries its scale as a field and this stage asserts on it.

**Changing the reranker model re-tunes the number.** The threshold is a function of
(provider, model, scale, chunker version). Change any one and it is void: re-derive it from
an evaluation run and bump the retrieval configuration version. It is not portable across
models, scales, or chunker versions, and it is not portable from another model's scale
either — a threshold copied off a different reranker produces constant refusal or confident
nonsense, and both look like a corpus problem.

The tuning procedure is not intuition: score at least fifty answerable and fifty unanswerable
questions through the full pipeline, keep the top-1 reranker score per question, and set the
threshold at the 5th percentile of the answerable distribution — accepting about 5% false
refusals, which is the failure users actually complain about. Then report the false-answer
rate. If more than roughly a tenth of unanswerable questions clear it, the distributions
overlap and the problem is retrieval or chunking; raising the threshold from there trades
answerable recall one for one.

**Never threshold on the fused score, and that prohibition survives the reranker becoming
optional.** Reciprocal rank fusion discards magnitude by construction — only ranks enter the
formula — so the top fused candidate scores the same whether it is a verbatim match or noise,
and a cutoff there can only ever trim the tail. The symptom is a bot that never refuses
however irrelevant the corpus. Dual-encoder cosine is not thresholdable either: it is not
calibrated and not comparable across queries.

**So a run without a reranker has no evidence *threshold*** — and what it has instead is
branch agreement, which is the one relevance signal RRF preserves because it is structural
rather than magnitude-based. ``select_unranked`` keeps only candidates carrying both a dense
and a sparse rank, and refuses when none do. A bot on a provider with no ranking endpoint will
still answer from weaker evidence than one with a reranker, and it must be possible to see
which kind of run produced any given answer — the skip reason on the trace is what makes that
visible, and an evaluation comparing the two without reading it is comparing two pipelines and
calling the difference a regression.

**That arm needs two branches, and it has them again.** Finding C2 — API embedding endpoints
return dense only, so the sparse arm lost its producer — is closed by locally-computed BM25 in
``app/retrieval/sparse.py``, so branch agreement is a signal a normal run actually carries and
the degraded path is a working path rather than a dead end.

**Nothing below changes as a result, and that is the point.** ``select_unranked`` still selects
on agreement and still raises ``BranchAgreementUnavailable`` on a single-branch run, because a
dense-only run is still reachable — a bot with the lexical arm switched off, a query that
analyzes to no terms, a deployment mid-rollout. On such a run there is no agreement to read,
and the shape that gets written by accident is "take the top N of the fused order", which on
one branch *is* the dense order: a cutoff on dual-encoder cosine with none of the words that
would make it reviewable. The guard was never a placeholder for C2; it was the statement that
this pipeline has exactly two selection signals and will not invent a third.

**Nothing clearing the threshold is a correct answer, not an error.** The bot states the
answer is not in the sources, records an insufficient-evidence event, and does not fall
through to model knowledge. That event is a chat metric, not a log line, and the metric for
it is not an error rate. A reranking *outage* is the opposite case and must never produce
that sentence: a dependency failure is an error, and telling the user the answer is not in
their sources when the truth is that a vendor returned 503 is a lie the trace cannot later
correct.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import TYPE_CHECKING, Final

from app.rag.rerank import RerankOutcome, RerankScaleMismatch, Scored
from app.rag.stages import ExclusionReason, StageRun
from app.retrieval.search import BranchName, Candidate

if TYPE_CHECKING:
    from qdrant_client.models import ScoredPoint

__all__ = [
    "DENSE_TOP_K",
    "HYBRID_BRANCHES",
    "RERANK_CANDIDATES",
    "RERANK_RETAIN",
    "RRF_K",
    "SPARSE_TOP_K",
    "BranchAgreementUnavailable",
    "apply_threshold",
    "as_candidates",
    "dedup_and_diversify",
    "fuse",
    "select_unranked",
]

#: Our Python fusion constant, textbook ``1/(k + rank)`` with 1-indexed ranks. Sixty is the
#: convention every mainstream engine and the fusion literature use.
#:
#: It is set explicitly and never inherited: the client's own default is **2**, at which the
#: rank-1 hit of each branch dominates roughly thirty times more sharply than the literature
#: default, and the symptom is hybrid search suddenly preferring whatever the sparse branch
#: returned first on queries where dense was obviously right.
#:
#: ``app.retrieval.search.QDRANT_SERVER_RRF_K`` is a different number with a different owner
#: — server-side fusion scores over a 0-based position, so its ``k`` is this one plus one.
#: Nothing asserts the two are equal, and each path snapshots the ``k`` it actually used.
RRF_K: Final[int] = 60

#: Branch depth. Both branches retrieve wide so the reranker has something to rescue: a
#: cross-encoder can only reorder what it was handed, so recall at candidate depth is a hard
#: ceiling on final quality. Raising these is the first tuning experiment, and the shape of
#: the pipeline — retrieve wide, pack narrow — is built for exactly that.
#:
#: ``SPARSE_TOP_K`` is no longer contingent. It was, while the lexical arm had no producer —
#: BGE-M3 emitted dense and sparse from one local pass and API embedding endpoints return dense
#: only — and finding C2 is now closed by locally-computed BM25 in ``app/retrieval/sparse.py``.
#: The depth is unchanged at 20 by that: it is a retrieval parameter, not a property of
#: whatever produces the vector, and the two branches are deliberately equal-depth so neither
#: dominates the fusion by having been given more candidates to contribute.
#:
#: A dense-only run still reads as "the sparse branch was not run" rather than as "the sparse
#: depth was quietly set to zero", because ``retrieve_branches`` returns only the branches it
#: queried and nothing infers a branch's existence from an empty result.
DENSE_TOP_K: Final[int] = 20
SPARSE_TOP_K: Final[int] = 20

#: How many fused candidates the reranker scores, and how many survivors are packed. The
#: contract states these as ranges (20-30 and 6-10); the shipped values are the midpoints,
#: chosen here because a range is not a runnable configuration. Both move only through an
#: evaluation run with a configuration snapshot attached.
#:
#: The candidate count is now also a cost and a latency number, not just a quality one:
#: every candidate is a passage shipped to a vendor and billed, and ranking APIs cap the
#: passages per request, so a depth above that cap becomes multiple round-trips.
#:
#: The retain cap stays small on purpose: answer accuracy is U-shaped in packed position and
#: worst in the middle, so adding evidence past a point makes answers worse rather than
#: better. It applies to the unranked path too, where it matters more — there, the ordering
#: it truncates was never judged by anything that read the query.
RERANK_CANDIDATES: Final[int] = 25
RERANK_RETAIN: Final[int] = 8

#: The branch set the degraded path requires, because branch agreement is meaningless without
#: two branches to agree. Named rather than written inline so ``select_unranked`` compares
#: against a stated expectation instead of counting keys and hoping.
HYBRID_BRANCHES: Final[frozenset[BranchName]] = frozenset({"dense", "sparse"})


class BranchAgreementUnavailable(Exception):
    """The degraded path was asked to select without the signal it selects on.

    Raised when stage 11 was skipped and retrieval ran only one branch. There is then no
    threshold (no rerank score), no agreement (no second branch), and nothing left but "take
    the top N of a dense ranking" — which is a cutoff on dual-encoder cosine wearing a
    different hat, and dual-encoder similarity is not calibrated or comparable across queries.

    This was finding **C2** reaching the request path — API embedding endpoints return dense
    only, so for a while nothing produced a sparse query vector, and a bot whose provider also
    could not rerank had neither of the two selection signals this pipeline has. C2 is closed
    (``app/retrieval/sparse.py``) and the class stays, unchanged, because the *state* it
    describes is still reachable: the lexical arm switched off for a bot, a query that analyzes
    to no terms, a rollout where one side has the analyzer and the other does not.

    What it buys is unchanged too. The gap is an incident somebody sees rather than a silent
    degradation that answers from cosine similarity and reports nothing.
    """


def as_candidates(
    branches: Mapping[BranchName, Sequence[ScoredPoint]],
) -> dict[BranchName, list[Candidate]]:
    """The seam between stage 8 and stage 9, and **the only place a point becomes a candidate**.

    ``retrieve_branches`` returns ``ScoredPoint`` per branch and ``fuse`` takes ``Candidate``
    per branch; something has to bridge them, and where it lives is a real decision rather than
    a formality. It is here — beside fusion, in ``app/rag/`` — for two reasons. Putting it in
    ``app/retrieval/search.py`` would grow the one file the Qdrant CI gate exempts by literal
    path, and that file's smallness is what makes "a reviewer reads every line of it" realistic.
    Putting it in the stage runner would make it a step nothing names, and it would be written a
    second time by the evaluation harness.

    It is deliberately as thin as it looks: one bare ``Candidate`` per point per branch. The
    merge — one candidate carrying both branches' ranks and scores — is ``fuse``'s, because
    pooling by chunk identity *is* stage 9. A wrapper that also merged would be stage 9 written
    twice, in two places, with two tie-break rules.

    Raises through ``Candidate.chunk_id`` on a point with no ``chunk_id``, here rather than
    three stages later: a point that reached retrieval without one cannot be deduped, excluded
    with a reason, or cited, and the ``None`` would otherwise become the string ``"None"`` and
    group every unidentifiable candidate into one playground row.
    """
    wrapped: dict[BranchName, list[Candidate]] = {}
    for name, points in branches.items():
        candidates = [Candidate(point=point) for point in points]
        for candidate in candidates:
            _ = candidate.chunk_id
        wrapped[name] = candidates
    return wrapped


def fuse(
    branches: Mapping[BranchName, Sequence[Candidate]],
    *,
    k: int = RRF_K,
) -> list[Candidate]:
    """Stage 9. Reciprocal rank fusion, in Python, keeping every per-branch number.

    Fusion happens here rather than server-side because a server-fused response carries one
    score per point: the per-branch rank *and* score are discarded before they reach us and
    are not recoverable from the result. The playground then shows a fused number and four
    dashes beside it, and no later code change can refill them.

    Ranks are 1-indexed and a candidate absent from a branch contributes zero. Branch scores
    are carried for the trace and never enter the sum — the formula is deliberately blind to
    magnitude, which is exactly why the threshold cannot live on its output.

    **One branch is a valid input and a degenerate one.** With only ``dense`` present the
    output ordering is the dense ordering and the fused score is a fixed function of rank,
    carrying no information at all. The stage still runs — it is not skipped, and the fused
    field is still populated so a trace from a dense-only run has the same shape as any
    other — but nothing may read a fused score from such a run as evidence of agreement,
    because there was nothing to agree with. Zero branches is a programming error, not an
    empty result: it means retrieval was never issued.

    Ties break on ``chunk_id`` and never on insertion order. Two branches are iterated in
    ``dict`` order and RRF produces exact ties constantly — every candidate found at the same
    rank in one branch and absent from the other scores identically — so an order-dependent
    sort makes the packed set a function of which branch happened to be first in a mapping, and
    a replay of the same trace can pack a different set. That is the same argument
    ``apply_threshold`` makes about its own slice.

    Records ``kb.retrieval.fuse``, with the branch set on the span. The set, not the count:
    "one branch" and "the sparse branch returned nothing" are different runs and read
    identically otherwise.
    """
    if not branches:
        raise ValueError(
            "fuse received no branches. Zero branches is not an empty result — an empty result "
            "is a branch that ran and matched nothing — it means retrieval was never issued, "
            "and returning [] here would render that as a corpus with no answer in it"
        )
    unknown = set(branches) - HYBRID_BRANCHES
    if unknown:
        raise ValueError(
            f"fuse received branch(es) {sorted(unknown)}; the collection has exactly two named "
            f"vectors, {sorted(HYBRID_BRANCHES)}. A third key is a branch nothing else in the "
            "pipeline knows how to read a rank from"
        )

    pool: dict[str, Candidate] = {}
    for name, candidates in branches.items():
        for rank, candidate in enumerate(candidates, start=1):
            merged = pool.setdefault(candidate.chunk_id, Candidate(point=candidate.point))
            if name == "dense":
                merged.dense_rank = rank
                merged.dense_score = candidate.point.score
            else:
                merged.sparse_rank = rank
                merged.sparse_score = candidate.point.score
            # Ranks only. The branch scores were carried onto the candidate two lines up, for
            # the trace and for the playground, and they are deliberately not in this sum: RRF
            # is blind to magnitude by construction, which is exactly why the evidence
            # threshold cannot live on its output.
            merged.fused_score += 1.0 / (k + rank)

    return sorted(pool.values(), key=lambda candidate: (-candidate.fused_score, candidate.chunk_id))


def dedup_and_diversify(
    candidates: Sequence[Candidate],
    run: StageRun,
    *,
    max_per_document: int,
) -> list[Candidate]:
    """Stage 10. Remove exact duplicates, cap per document, spare adjacent chunks.

    A chunk adjacent by (source version, sequence) to something already retained is exempt
    from the per-document cap. Without the exemption the answer stops one sentence short of
    the fact and the missing sentence is in the corpus — the chunk that completed the passage
    was dropped for belonging to a document that had already contributed its quota.

    Every drop calls ``run.exclude``. Records ``kb.retrieval.dedupe``.

    **There is no near-duplicate penalty, and its absence is a ruling rather than a gap
    (2026-08-12).** ``docs/07-rag-query-pipeline.md`` §12.10 says "penalize near duplicates",
    and this function does not: the exclusion reason ``near_duplicate_penalized`` and the
    ``penalize_near_duplicates`` stub that raised for it are both deleted. Three questions had
    to be answered before a body could be written, and a fourth fact answered all three by
    making the stage vacuous:

    1. **No detector exists here.** No simhash, MinHash or shingling implementation is in this
       tree — the only occurrences of those words are in this docstring. The one similarity
       signal available is the chunker's ``overlap_of``, which is not a similarity measure at
       all: it is a pointer the splitter writes to a *known* overlap it just produced.
    2. **A penalty has no scale to live on.** It would adjust ``fused_score``, and RRF is
       magnitude-free by construction (see ``fuse``), so subtracting a constant re-orders
       candidates by an amount unrelated to how similar they are. The honest alternatives were
       a rank penalty or nothing.
    3. **The reason was misfiled.** ``near_duplicate_penalized`` described a candidate that is
       *retained*, so recording it through ``run.exclude`` would have broken the identity
       ``tests/unit/test_evidence_threshold.py`` asserts — scored minus packed equals excluded
       — by putting one candidate on both sides.

    **The fourth fact: the narrow detector is exactly the set the adjacency exemption already
    spares, so any implementation is vacuous by construction.** ``chunker.py:844`` sets
    ``overlap_of = chunks[-1].metadata.chunk_id`` — always the *immediately preceding* chunk —
    and ``seq`` is ``first_seq + len(chunks)`` (``chunker.py:833,856``), strictly sequential
    within a source version. So every ``overlap_of`` link is an adjacency by
    ``(source_version_id, seq)``, and the detector can only fire when its target is present
    and retained, which is precisely the condition ``_position_of`` tests below. Its firing set
    is a subset of the exemption set, so the penalty would never once apply. Writing it would
    have shipped a stage that provably never fires, which is worse than not having it: the
    trace would carry a stage name, the playground a column, and neither would ever have a row.

    Reopening this needs a *real* detector (a shingle/simhash pass over chunk text, or a
    content-hash equivalence class that survives an exact-duplicate drop), a penalty expressed
    in ranks rather than fused score, and a second trace verb meaning "adjusted, not dropped".
    Until all three exist, stage 10 does what is decidable: exact duplicates by
    ``content_hash``, the per-document cap by ``source_id``, and the adjacency exemption that
    makes the cap safe to apply at all.

    Payload fields are read from ``PAYLOAD_PROJECTION`` and each missing one means something
    different, so each is handled differently rather than uniformly:

    * ``source_id`` **raises**. It is one of the six payload fields asserted at write time, the
      per-document cap is meaningless without it, and the alternative — treating the candidate
      as its own document — quietly switches the cap off for exactly the malformed points. Same
      reasoning as ``Candidate.chunk_id``, which raises for the same class of violation.
    * ``content_hash`` missing is tolerated and means "cannot be proven identical". Dedup is a
      quality control, not a correctness one, and inventing a hash from the projected payload
      would compare metadata rather than content.
    * ``source_version_id`` or ``seq`` missing means adjacency does not apply. Erring towards
      not-exempt keeps the cap honest; the cost is a chunk that could have completed a passage.
    """
    retained: list[Candidate] = []
    #: content hash -> the chunk that claimed it. A dict rather than a count, deliberately:
    #: `list.count(` is what the Qdrant-verb CI gate greps for outside `app/retrieval/search.py`.
    claimed_by: dict[str, str] = {}
    #: source id -> how many of its chunks are retained so far.
    per_document: dict[str, int] = {}
    #: (source version, seq) of everything retained, for the adjacency test.
    retained_positions: set[tuple[str, int]] = set()

    for candidate in candidates:
        payload = candidate.point.payload or {}

        content_hash = payload.get("content_hash")
        if isinstance(content_hash, str) and content_hash in claimed_by:
            # Unconditional, and checked before adjacency: an exact duplicate contributes no
            # text the retained copy does not already contribute, so exempting it would spend
            # context budget on a byte-for-byte repeat.
            run.exclude(candidate.chunk_id, ExclusionReason.EXACT_DUPLICATE)
            continue

        source_id = payload.get("source_id")
        if not isinstance(source_id, str) or not source_id:
            raise ValueError(
                f"candidate {candidate.chunk_id} carries no source_id in its payload. It is one "
                "of the six fields every point is written with, and without it the per-document "
                "cap has no document to cap"
            )

        position = _position_of(payload)
        adjacent = position is not None and (
            (position[0], position[1] - 1) in retained_positions
            or (position[0], position[1] + 1) in retained_positions
        )
        if not adjacent and per_document.get(source_id, 0) >= max_per_document:
            run.exclude(candidate.chunk_id, ExclusionReason.DOCUMENT_DIVERSITY_CAP)
            continue

        retained.append(candidate)
        if isinstance(content_hash, str):
            claimed_by[content_hash] = candidate.chunk_id
        # An exempt chunk still counts towards its document's total. It occupies context like
        # any other, and not counting it would let one long adjacent run take the whole answer
        # while the cap reported itself satisfied.
        per_document[source_id] = per_document.get(source_id, 0) + 1
        if position is not None:
            retained_positions.add(position)

    return retained


def _position_of(payload: Mapping[str, object]) -> tuple[str, int] | None:
    """``(source_version_id, seq)`` if both are present and well-typed, else ``None``.

    The version id is part of the key rather than the source id: sequence numbers restart per
    version, so two versions of one document contain a ``seq`` 4 that are not neighbours and
    are not even the same text.
    """
    version = payload.get("source_version_id")
    seq = payload.get("seq")
    if isinstance(version, str) and version and isinstance(seq, int) and not isinstance(seq, bool):
        return version, seq
    return None


def apply_threshold(
    outcome: RerankOutcome,
    run: StageRun,
    *,
    retain: int = RERANK_RETAIN,
) -> list[Scored]:
    """Stage 12, on a run that reranked. Keep what clears the bar. Empty means refuse.

    Takes the whole outcome rather than a list of scores so the threshold and the scale
    cannot be supplied by the caller: both come from ``outcome.calibration``, which came
    from the evaluation run that produced them. Asserts that every ``Scored.scale`` equals
    ``calibration.scale`` before comparing anything — cheap, and the only structural defence
    against a threshold calibrated on one scale being applied on another.

    **That assertion only means something because the two sides come from different places.**
    ``Scored.scale`` is what the provider reported on the response (``RerankResult.scale``);
    ``calibration.scale`` is what the evaluation run that produced ``min_score`` was derived
    on. A provider or model change that moves the scale under an unchanged threshold is
    exactly a disagreement between those two, and it is invisible in every other signal: the
    scores stay plausible floats, nothing raises, and only the aggregate refusal rate moves.
    Sourcing ``Scored.scale`` from the calibration instead — which is what a bare
    ``list[float]`` return from the adapter forced — makes this line compare a value with
    itself, and it can then never fail.

    Raises on a skipped outcome rather than thresholding the fused order. That substitution
    is the precise bug this stage exists to prevent, and it is more tempting now than it was
    when the reranker was local and always present: the skipped path has candidates, they
    have numbers, and the numbers look like scores.

    Two distinct exclusions, both recorded: candidates below the threshold, and candidates
    that cleared it but fell outside the retain cap. The second is the one that gets
    forgotten, because a slice does not read like a filter — and then the panel shows fewer
    candidates than were reranked, every one of them passing, and no row saying why.

    An empty return is the refusal path, and it is a legitimate outcome rather than a
    failure: it sets the insufficient-evidence flag on the trace and increments the
    empty-retrieval metric. A dependency outage is not an evidence outage and must not be
    reported to the user as "not in your sources".

    Records ``kb.retrieval.threshold``.
    """
    calibration = outcome.calibration
    if calibration is None:
        raise ValueError(
            "apply_threshold received a skipped rerank. There is no score to threshold, and "
            "the fused order is not a substitute — RRF discards magnitude, so a cutoff there "
            "can only trim the tail. Use select_unranked"
        )

    for scored in outcome.scored:
        if scored.scale is not calibration.scale:
            raise RerankScaleMismatch(
                f"{calibration.provider}/{calibration.model} reported scores on the "
                f"{scored.scale.value} scale, but min_score {calibration.min_score} was "
                f"derived on {calibration.scale.value} by {calibration.derived_from}. The "
                "same float is valid on both, so nothing downstream would raise — only the "
                "refusal rate would move. Re-derive the calibration for the scale the "
                "provider actually returns"
            )

    # Sorted here rather than trusted from stage 11. The retain cap below is a slice, and a
    # slice over an unsorted list silently keeps the wrong candidates while every trace row
    # still looks well-formed. Python's sort is stable, so ties keep stage 11's order and a
    # replay reproduces the same packed set.
    ranked = sorted(outcome.scored, key=lambda s: s.score, reverse=True)

    over: list[Scored] = []
    for scored in ranked:
        if scored.score < calibration.min_score:
            run.exclude(
                scored.candidate.chunk_id,
                ExclusionReason.BELOW_EVIDENCE_THRESHOLD,
                score=scored.score,
            )
        else:
            over.append(scored)

    # The retain cap drops candidates too, and every one of them is recorded. This is the drop
    # that gets forgotten, because `[:retain]` reads as a slice rather than as a filter — and
    # then the panel shows fewer candidates than were reranked, all of them above the
    # threshold, with no row explaining the difference.
    for scored in over[retain:]:
        run.exclude(
            scored.candidate.chunk_id,
            ExclusionReason.ABOVE_RETAIN_LIMIT,
            score=scored.score,
        )

    return over[:retain]


def select_unranked(
    outcome: RerankOutcome,
    run: StageRun,
    *,
    branches: frozenset[BranchName],
    retain: int = RERANK_RETAIN,
) -> list[Candidate]:
    """Stage 12, on a run that skipped reranking. **Branch agreement, not the fused order.**

    There is no score to threshold: fused ranks carry no magnitude and dense cosine is not
    calibrated across queries, so any cutoff invented here would be a number chosen to look
    like a gate. What survives RRF is *structural* — whether a candidate was found by both
    branches — and that is what this path selects on. Candidates found by one branch only are
    dropped with ``no_branch_agreement``; if none agree, the bot refuses with
    ``insufficient_evidence``, exactly as the thresholded path does when nothing clears.

    ``branches`` is the set retrieval **actually queried**, not inferred from the candidates.
    On a dense-only run every ``sparse_rank`` is ``None`` for every candidate, which is
    indistinguishable at this level from a sparse branch that ran and matched nothing — the
    first has no signal and the second has a very strong one. ``retrieve_branches`` returns
    only the branches it queried for this reason; pass its key set.

    Raises ``BranchAgreementUnavailable`` when fewer than both branches ran. That is finding
    C2 arriving on the request path, and the alternative — falling through to "take the top N
    of the dense ranking" — is a cosine cutoff with no name, no threshold and no trace row.
    It is not this function's job to resolve C2, only to refuse to hide it.

    Not implemented here, and deliberately: the §19.6 split where a **strict-RAG bot forbids
    degraded retrieval entirely** and fails the request rather than answering from an unranked
    set. That is bot policy and belongs to the caller — a dependency gap reported to the user
    as "not in your sources" is a lie, so the caller must be able to tell the two apart before
    it reaches a refusal message.

    What the degraded mode costs is worth stating rather than discovering: with no reranker
    the bot refuses only when nothing agrees across branches, so it will answer from the best
    of a weak set where a reranked run would have refused. That is why ``outcome.skipped``
    belongs on the trace and on the span, and why a degraded run must be excluded from
    evaluation baselines — it is a different pipeline, not a regression.

    Raises on an applied outcome: a run that reranked must go through the gate.

    Records ``kb.retrieval.threshold`` with the skip reason on it, so the stage is present in
    every trace and a missing span always means a bug rather than a configuration.
    """
    if outcome.applied:
        raise ValueError(
            "select_unranked received an applied rerank. A run that scored its candidates "
            "goes through apply_threshold; serving its fused order instead would discard the "
            "one gate the bot has"
        )
    if branches != HYBRID_BRANCHES:
        raise BranchAgreementUnavailable(
            f"stage 11 was skipped ({outcome.skipped.value if outcome.skipped else '?'}) and "
            f"retrieval ran {sorted(branches)} — branch agreement needs "
            f"{sorted(HYBRID_BRANCHES)}. With neither a rerank score nor a second branch "
            "there is no selection signal left, and taking the top N of the dense ranking is "
            "a threshold on dual-encoder cosine, which is not comparable across queries. See "
            "finding C2"
        )

    agreeing: list[Candidate] = []
    for candidate in outcome.unranked:
        if candidate.dense_rank is None or candidate.sparse_rank is None:
            run.exclude(candidate.chunk_id, ExclusionReason.NO_BRANCH_AGREEMENT)
        else:
            agreeing.append(candidate)

    # Same recorded drop as the thresholded path, for the same reason: the panel must account
    # for every candidate on both paths, or the two are not comparable.
    for candidate in agreeing[retain:]:
        run.exclude(candidate.chunk_id, ExclusionReason.ABOVE_RETAIN_LIMIT)

    return agreeing[:retain]


# Checked at import rather than in a test: retaining more than were ever scored is a
# configuration that produces a short context and no error.
assert RERANK_RETAIN <= RERANK_CANDIDATES
assert DENSE_TOP_K > 0 and SPARSE_TOP_K > 0
