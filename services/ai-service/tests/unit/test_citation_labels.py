"""Stages 13, 17 and 18 — labels assigned from evidence, and validated back against it.

The direction of trust is the whole subject. Assignment goes evidence → label, before the model
is called. Validation goes answer → label → packed set, and a label that does not resolve is
stripped rather than looked up. Reversing either direction produces an answer that reads
perfectly and cites a passage nobody retrieved.

The two seams that make this silently wrong and are asserted below:

* **One spelling.** ``CITATION_LABEL_PATTERN`` matches ``[S1]`` and ``PackedEvidence.label``
  holds ``S1``. Compare them as they come and the intersection is empty for every well-formed
  answer: the bot reports 100% unknown labels, strips every citation it was given, and raises
  nothing.
* **The refusal exemption.** ``apply_threshold`` returns ``[]`` when nothing clears, so an
  empty packed set *is* the refusal path. A refusal with no citations is the correct output of
  the evidence gate, and flagging it as uncited claims turns the one honest outcome into an
  error.
"""

from __future__ import annotations

from typing import Any, Final

from app.rag.citations import (
    CITATION_LABEL_PATTERN,
    LABEL_PREFIX,
    PackedEvidence,
    assign_labels,
    label_for,
    validate_answer_citations,
)
from app.rag.stages import ExclusionReason


class RecordingRun:
    def __init__(self) -> None:
        self.excluded: list[tuple[str, ExclusionReason]] = []
        self.labels: dict[str, str] | None = None

    @property
    def retrieval_configuration_version(self) -> str:
        return "retr/test-1"

    def exclude(
        self, chunk_id: str, reason: ExclusionReason, *, score: float | None = None
    ) -> None:
        self.excluded.append((chunk_id, reason))

    def record_labels(self, labels: Any) -> None:
        self.labels = dict(labels)

    def remaining_seconds(self) -> float:
        return 10.0


def packed_chunk(chunk_id: str, *, label: str = "") -> PackedEvidence:
    return PackedEvidence(
        label=label,
        chunk_id=chunk_id,
        source_id="src-1",
        source_version_id="ver-1",
        rerank_score=0.7,
        token_count=120,
    )


THREE: Final = [packed_chunk("chunk-a"), packed_chunk("chunk-b"), packed_chunk("chunk-c")]


# ── assignment ───────────────────────────────────────────────────────────────


def test_labels_run_s1_upwards_in_packed_order() -> None:
    labelled = assign_labels(THREE, RecordingRun())
    assert [item.label for item in labelled] == ["S1", "S2", "S3"]
    assert [item.chunk_id for item in labelled] == ["chunk-a", "chunk-b", "chunk-c"]


def test_labels_follow_packed_order_and_not_reranked_order() -> None:
    """The packer places the best chunk first and the second-best last, so a label derived from
    rank would number the context out of the sequence the model reads it in."""
    by_rank = [
        packed_chunk("best"),
        packed_chunk("worst"),
        packed_chunk("second"),
    ]
    labelled = assign_labels(by_rank, RecordingRun())
    assert [(item.label, item.chunk_id) for item in labelled] == [
        ("S1", "best"),
        ("S2", "worst"),
        ("S3", "second"),
    ]


def test_assignment_replaces_rather_than_mutating() -> None:
    """``PackedEvidence`` is frozen because the association is what everything downstream
    trusts. A mutable one is a single accidental reassignment away from a citation pointing at
    the wrong passage — which renders as a plausible answer with a plausible source link."""
    original = packed_chunk("chunk-a", label="stale")
    labelled = assign_labels([original], RecordingRun())
    assert original.label == "stale"
    assert labelled[0].label == "S1"
    assert labelled[0] is not original


def test_the_mapping_is_recorded_on_the_trace_at_assignment_time() -> None:
    """A trace missing it cannot answer "which passage was that", which is the only question a
    disputed citation ever raises."""
    run = RecordingRun()
    assign_labels(THREE, run)
    assert run.labels == {"S1": "chunk-a", "S2": "chunk-b", "S3": "chunk-c"}


def test_an_empty_packed_set_records_an_empty_mapping_rather_than_nothing() -> None:
    """A refusal is a run that packed nothing, and its trace must still say so — "no labels
    recorded" and "the stage did not run" have to be distinguishable."""
    run = RecordingRun()
    assert assign_labels([], run) == ()
    assert run.labels == {}


def test_the_label_helper_and_the_assignment_agree() -> None:
    labelled = assign_labels(THREE, RecordingRun())
    assert [label_for(position) for position in (1, 2, 3)] == [item.label for item in labelled]


# ── the spelling seam ────────────────────────────────────────────────────────


def test_the_pattern_matches_the_bracketed_form_the_model_emits() -> None:
    assert CITATION_LABEL_PATTERN.findall("as stated [S2] and [S11]") == ["2", "11"]


def test_a_bare_label_in_prose_is_not_a_citation() -> None:
    """A permissive pattern that accepted ``S7`` would match ordinary prose — a part number, a
    model name, a room — and turn a coincidence into a citation."""
    assert CITATION_LABEL_PATTERN.findall("the S7 chassis and section S2") == []


def test_the_bracketed_answer_resolves_against_the_bare_packed_label() -> None:
    """**The seam.** Compare the two forms as they come and every well-formed answer reports
    100% unknown labels, every citation is stripped, and nothing raises."""
    packed = assign_labels(THREE, RecordingRun())
    check = validate_answer_citations("Yes [S1], and also [S3].", packed, RecordingRun())
    assert check.resolved == ("S1", "S3")
    assert check.unknown == ()
    assert LABEL_PREFIX == "S"


# ── validation ───────────────────────────────────────────────────────────────


def test_a_label_the_model_invented_is_recorded_as_unknown() -> None:
    """Three chunks were packed; ``[S7]`` names a passage the model never saw."""
    packed = assign_labels(THREE, RecordingRun())
    check = validate_answer_citations("See [S1] and [S7].", packed, RecordingRun())
    assert check.resolved == ("S1",)
    assert check.unknown == ("S7",)


def test_an_unknown_label_is_never_excluded_through_the_stage_run() -> None:
    """``exclude`` takes a ``chunk_id`` and an unknown label has none — having none is what
    makes it unknown. ``app/rag/stages.py`` forbids excluding something never retrieved, and
    ``CitationCheck.unknown`` is the record."""
    run = RecordingRun()
    packed = assign_labels(THREE, RecordingRun())
    validate_answer_citations("See [S9].", packed, run)
    assert run.excluded == []


def test_a_repeated_citation_counts_once_and_keeps_first_appearance_order() -> None:
    """Four mentions of ``[S2]`` are one citation. Four rows would make the panel's totals a
    function of prose style."""
    packed = assign_labels(THREE, RecordingRun())
    check = validate_answer_citations(
        "[S2] says so, and [S1] agrees, and [S2] again.", packed, RecordingRun()
    )
    assert check.resolved == ("S2", "S1")


def test_an_answer_citing_nothing_while_evidence_was_packed_is_flagged() -> None:
    packed = assign_labels(THREE, RecordingRun())
    check = validate_answer_citations("The warranty covers it.", packed, RecordingRun())
    assert check.resolved == ()
    assert check.uncited_claims is True


def test_a_refusal_with_no_packed_evidence_is_not_a_citation_failure() -> None:
    """**The refusal exemption**, derived rather than configured: ``apply_threshold`` returns
    ``[]`` when nothing clears, so an empty packed set is the refusal path. Treating it as a
    citation failure turns the one honest outcome of the evidence gate into an error."""
    check = validate_answer_citations("That is not covered by your sources.", [], RecordingRun())
    assert check.resolved == ()
    assert check.unknown == ()
    assert check.uncited_claims is False


def test_the_requirement_can_be_switched_off_per_bot() -> None:
    packed = assign_labels(THREE, RecordingRun())
    check = validate_answer_citations(
        "The warranty covers it.", packed, RecordingRun(), require_at_least_one=False
    )
    assert check.uncited_claims is False


def test_an_answer_that_cites_only_unknown_labels_counts_as_uncited() -> None:
    """Nothing it cited resolves, so nothing grounds it. Reporting it as cited-but-unresolvable
    would let the UI render an answer with zero working citations as a grounded one."""
    packed = assign_labels(THREE, RecordingRun())
    check = validate_answer_citations("As [S8] explains.", packed, RecordingRun())
    assert check.resolved == ()
    assert check.unknown == ("S8",)
    assert check.uncited_claims is True


def test_the_check_carries_no_cleaned_answer_and_the_caller_strips() -> None:
    """``unknown`` names every label to remove, which is sufficient. Two strippers disagree the
    moment one learns about a new label form, and the disagreement shows up as a citation
    marker left in prose that resolves to nothing."""
    check = validate_answer_citations("[S9]", [], RecordingRun())
    assert not hasattr(check, "answer")
    assert not hasattr(check, "cleaned")


def test_validation_never_resolves_a_label_against_a_datastore() -> None:
    """An identifier-addressed fetch carries no tenant filter of its own, so honouring a
    model-authored identifier is how an answer starts citing another organization's chunk.

    Asserted by reflection rather than by prose: the function takes the packed set and nothing
    that could reach Qdrant or PostgreSQL.
    """
    import inspect

    parameters = set(inspect.signature(validate_answer_citations).parameters)
    assert parameters == {"answer", "packed", "run", "require_at_least_one"}
