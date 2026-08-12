"""Stages 17-18 — citation identifiers, assigned from evidence and validated against it.

**A citation is assigned at pack time, before the model is called.** Every packed chunk
receives its label in stage 13; the labels are the only identifiers the model ever sees, and
they are the only ones that may appear in an answer. Nothing here ever *derives* a citation
from generated text.

What breaks if labels are attached afterwards: the model writes fluent prose that matches no
passage it was shown, and no post-hoc mapping can retroactively find one — measured citation
recall collapses by roughly threefold versus generating inline against pre-assigned labels.
The visible symptom is an answer citing ``[S7]`` when six chunks were packed, or citing a
document the trace shows was never retrieved. Both are unfixable downstream because the
association never existed.

**Extracting labels from output is validation, not assignment**, and the difference is the
direction of trust. Stage 18 reads every label out of the answer and checks each one back
against the packed set: a label that is not in that set is stripped and recorded as an
unknown citation label, never displayed and never resolved to a chunk. A model-authored
identifier is a defect to be caught, not an input to be honoured.

**The evidence the labels point at is untrusted data.** It reaches the prompt as one fenced,
labelled region placed after the platform and bot instruction sections, never interpolated
into them, and the platform section states that instructions found inside sources do not
override it. A crawled page saying "ignore previous instructions" is then a passage the model
was asked to read, not a line it was asked to obey. That fencing is stage 14's to build; it
is stated here because these labels are the identifiers that region carries, and a citation
whose text was allowed to act as an instruction is the failure this whole chain guards.
"""

from __future__ import annotations

import re
from collections.abc import Sequence
from dataclasses import dataclass, replace
from typing import Final

from app.rag.stages import StageRun

__all__ = [
    "CITATION_LABEL_PATTERN",
    "LABEL_PREFIX",
    "REQUIRE_AT_LEAST_ONE",
    "CitationCheck",
    "PackedEvidence",
    "assign_labels",
    "label_for",
    "validate_answer_citations",
]

#: Labels are ``S1``, ``S2``, ... in packed order. Short because the model reproduces them
#: literally, and sequential because the packed order is the only ordering the model sees.
LABEL_PREFIX: Final[str] = "S"

#: The bracketed form the model is instructed to emit, and the form stage 18 extracts. It is
#: deliberately narrow: a permissive pattern that accepts, say, a bare ``S7`` would match
#: ordinary prose and turn a coincidence into a citation.
CITATION_LABEL_PATTERN: Final[re.Pattern[str]] = re.compile(r"\[S(\d+)\]")

#: An answer that makes factual source-grounded claims and cites nothing is a failed
#: generation, not a good answer. A refusal is exempt — a refusal with no citations is the
#: correct output of the evidence gate, and treating it as a citation failure would turn the
#: one honest outcome into an error.
REQUIRE_AT_LEAST_ONE: Final[bool] = True


@dataclass(frozen=True, slots=True)
class PackedEvidence:
    """One packed chunk and the label the model will see for it.

    Frozen because the label-to-chunk association is the artifact everything downstream
    trusts: the citation rows written for the UI, the excerpt the reader opens, and the
    validation in stage 18 all resolve through it. A mutable association is one accidental
    reassignment away from a citation pointing at the wrong passage — which renders as a
    perfectly plausible answer with a plausible source link.
    """

    label: str
    chunk_id: str
    source_id: str
    source_version_id: str
    rerank_score: float
    token_count: int


@dataclass(frozen=True, slots=True)
class CitationCheck:
    """The outcome of stage 18, for the trace and for the caller."""

    #: Labels present in the answer and present in the packed set. These become citation
    #: rows.
    resolved: tuple[str, ...]
    #: Labels the model produced that were not packed. Stripped from the answer before it
    #: leaves, and recorded with the unknown-citation-label reason.
    unknown: tuple[str, ...]
    #: True when the answer asserted source-grounded facts and cited nothing while
    #: citations were enabled.
    uncited_claims: bool


def label_for(position: int) -> str:
    """The label for a chunk at ``position`` in packed order, 1-indexed.

    Positions are assigned in the order chunks land in the context, not in reranked order —
    the packer places the best chunk first and the second-best last, so a label derived from
    rank rather than from packed position would number the context out of sequence.
    """
    return f"{LABEL_PREFIX}{position}"


def assign_labels(packed: Sequence[PackedEvidence], run: StageRun) -> tuple[PackedEvidence, ...]:
    """Fix the label-to-chunk association and record it on the trace.

    Called from the packer, before the prompt is built and therefore before any provider
    call. The recorded mapping is what stage 17 writes citation rows from and what stage 18
    validates against; a trace missing it cannot answer "which passage was that".

    ``PackedEvidence`` is frozen, so this returns replacements rather than assigning in place —
    the association is the artifact everything downstream trusts, and a mutable one is a single
    accidental reassignment away from a citation that points at the wrong passage and renders
    as a perfectly plausible answer with a perfectly plausible source link.

    Labels follow **packed order and never reranked order**. The packer places the best chunk
    first and the second-best last, so a label derived from rank would number the context out
    of sequence and the model would be reproducing identifiers that do not run in the order it
    reads them.
    """
    labelled = tuple(
        replace(evidence, label=label_for(position))
        for position, evidence in enumerate(packed, start=1)
    )
    # Recorded here, at assignment, and not by the caller. A trace that records the mapping
    # somewhere else records whatever that place believed the mapping to be.
    run.record_labels({evidence.label: evidence.chunk_id for evidence in labelled})
    return labelled


def validate_answer_citations(
    answer: str,
    packed: Sequence[PackedEvidence],
    run: StageRun,
    *,
    require_at_least_one: bool = REQUIRE_AT_LEAST_ONE,
) -> CitationCheck:
    """Stage 18. Check every label the answer produced against the packed set.

    A label not in ``packed`` is never resolved, never displayed, and never looked up
    against Qdrant or PostgreSQL to see whether such a chunk exists — resolving a
    model-authored identifier is how an answer starts citing a chunk that was never
    retrieved, and an identifier-addressed fetch has no tenant filter of its own.

    Conflicts between sources are surfaced by the prompt, not resolved here: the model names
    the conflict, cites both labels, and says which rule it applied. Validation checks that
    both labels were packed; it does not adjudicate between them.

    **One spelling, normalized at extraction.** ``CITATION_LABEL_PATTERN`` matches the
    bracketed form the model emits (``[S1]``) and ``PackedEvidence.label`` holds the bare form
    (``S1``). Comparing the two as they come makes the intersection empty for every
    well-formed answer, so the bot reports 100% unknown labels, strips every citation it was
    given, and raises nothing. The brackets are dropped here so everything downstream — the
    trace, the citation rows, the check below — speaks the bare form.

    **``run`` is not written to, and that is ruling D6 rather than an omission.**
    ``StageRun.exclude`` takes a ``chunk_id``, and an unknown label has none — having none is
    what makes it unknown. ``app/rag/stages.py`` forbids excluding something that was never
    retrieved, and stages 17-20 record into trace fields rather than opening spans of their
    own. ``CitationCheck.unknown`` **is** the record.

    **The caller strips; nothing here does.** ``CitationCheck`` deliberately carries no cleaned
    answer — ``unknown`` names every label to remove and that is sufficient. Do not add a
    second stripper: two of them disagree the moment one learns about a new label form, and the
    disagreement shows up as a citation marker left in prose that resolves to nothing.
    """
    packed_labels = {evidence.label for evidence in packed}

    # First appearance order, deduplicated. A model that cites [S2] four times produced one
    # citation, and four rows would make the panel's totals a function of prose style.
    cited: list[str] = []
    for match in CITATION_LABEL_PATTERN.finditer(answer):
        label = f"{LABEL_PREFIX}{match.group(1)}"
        if label not in cited:
            cited.append(label)

    resolved = tuple(label for label in cited if label in packed_labels)
    # Never resolved, never displayed, and **never looked up** against Qdrant or PostgreSQL to
    # see whether such a chunk exists. That last clause is the security half: an
    # identifier-addressed fetch carries no tenant filter of its own, so honouring a
    # model-authored identifier is how an answer starts citing a chunk nobody retrieved.
    unknown = tuple(label for label in cited if label not in packed_labels)

    # `bool(packed)` is the refusal exemption, derived rather than flagged: `apply_threshold`
    # returns [] when nothing cleared, so an empty packed set *is* the refusal path, and a
    # refusal with no citations is the correct output of the evidence gate.
    #
    # The residual, stated and accepted: a bot that refuses in prose while evidence *was*
    # packed reads here as uncited claims. That is a rarer and more useful false positive than
    # the alternative, which is trusting the model's own account of whether it answered.
    uncited_claims = require_at_least_one and bool(packed) and not resolved

    return CitationCheck(resolved=resolved, unknown=unknown, uncited_claims=uncited_claims)
