"""A real document, a real parse, and the two things that could only ever be claimed here.

Everything below needs the pinned weights on disk at `ARTIFACTS_PATH` and the golden corpus in
`samples/`. Without either it skips: an assertion about what a model produced is worth nothing
when no model ran, and a green tick from a skipped parser is the failure this file exists to
prevent someone shipping.

Two claims, neither of which any stub or unit test can make:

* **The OCR quality signal is live.** Every threshold in `app/ingestion/ocr/` was measured and
  then read by nothing, because nothing ever handed `assess` a cell. The scanned fixture's
  deliberately-degraded page is the one page in the corpus that must produce a warning, and it
  is asserted by name rather than by "some warning exists".
* **The parse is reproducible.** Publication verifies an expected chunk total before it
  activates a version, and point ids are derived from document order. A parser that is
  nondeterministic makes a document that succeeded on Monday fail publication on Tuesday, with
  nothing to point at. `chunker_cfg_version` has always covered the chunker's own settings; that
  the *parse* underneath it is stable was never checked.
"""

from __future__ import annotations

from pathlib import Path
from typing import Any, Final

import pytest

from app.ingestion.parsing.converter import ARTIFACTS_PATH, parse_document
from tests.support.tree import SERVICE_ROOT

#: **There are no `filterwarnings` marks on this file, and their absence is load-bearing.**
#:
#: The suite runs `-W error::DeprecationWarning`, and the first *real* parse is where that meets
#: upstream: `RapidOcrModel.__init__` reads a pydantic-deprecated `rec_font_path` while
#: constructing the OCR stage, and `docling_core` warns when a list item's parent is not a list
#: group during document *assembly* — which, promoted to an exception, is swallowed by the
#: pipeline's own `except Exception` and returns the whole document as
#: `ConversionStatus.FAILURE`. `parse_document` then raises `error_class="parsing"`,
#: non-retryable, on a document that parsed correctly.
#:
#: Both are now exempted **globally**, in `pyproject.toml`'s `addopts`, scoped by message and by
#: upstream module. Two marks here used to hide it, which meant the defect was invisible from
#: every other test that parses a document — including any future one. If a mark reappears on
#: this file, the global narrowing has regressed and this file is concealing it again.
pytestmark = [pytest.mark.integration]

_CORPUS: Final[Path] = SERVICE_ROOT.parents[1] / "samples" / "corpus" / "documents"
_SCANNED: Final[Path] = _CORPUS / "scanned" / "scanned-po-88214.pdf"
_HANDBOOK: Final[Path] = _CORPUS / "handbook" / "handbook-en-v1.pdf"

_NO_WEIGHTS = pytest.mark.skipif(
    not (ARTIFACTS_PATH / "docling-project--docling-layout-heron").is_dir(),
    reason=(
        f"the pinned weights are not at {ARTIFACTS_PATH}; they are baked into "
        "knowledgebot/ai-service:dev and mounted from the `models` volume"
    ),
)
_NO_CORPUS = pytest.mark.skipif(
    not _SCANNED.is_file(), reason="the golden corpus is not present in this checkout"
)


@pytest.fixture(scope="module")
def scanned() -> Any:
    return parse_document(path=_SCANNED, max_pages=50, max_bytes=50_000_000)


@_NO_WEIGHTS
@_NO_CORPUS
def test_a_scanned_document_parses_into_structure_and_not_into_text(scanned: Any) -> None:
    """Heading levels and table membership are the chunker's inputs and cannot be recovered
    once the document is flattened, so what this asserts is that the *shape* survived."""
    assert scanned.status in {"success", "partial_success"}
    assert scanned.elements, "no elements at all"
    kinds = {type(item).__name__ for item, _level in scanned.elements}
    assert "SectionHeaderItem" in kinds, kinds
    assert "GroupItem" in kinds, "with_groups=True asked for these and they are not here"
    assert max(level for _item, level in scanned.elements) > 1, "the tree is flat"


@_NO_WEIGHTS
@_NO_CORPUS
def test_the_ocr_quality_signal_is_live_and_names_the_degraded_page(scanned: Any) -> None:
    """**Finding #23.** The signal existed and nothing fed it.

    Asserted per page, because the document mean cannot express this: the corpus fixture's
    document `ocr_score` is 0.92 — `good` — while one of its four pages is unreadable enough to
    change an answer.
    """
    pages = scanned.confidence["pages"]
    assert scanned.confidence["engine"] == "rapidocr"
    assert pages, "no per-page record at all"
    assert all(page["ocr_cells"] > 0 for page in pages.values()), "no cells reached `assess`"

    flagged = {number: page["warnings"] for number, page in pages.items() if page["warnings"]}
    assert flagged, "not one page carried a warning on a document with a degraded page in it"
    assert any(
        warning.startswith("ocr_low_confidence:")
        for warnings in flagged.values()
        for warning in warnings
    ), flagged

    # And the arm that fires is the *mass* one, which is the whole reason the confidence signal
    # was redefined: the page mean saturates near 0.81 and cannot be thresholded at all.
    worst = max(pages.values(), key=lambda page: page["ocr_low_confidence_mass"] or 0.0)
    assert worst["ocr_low_confidence_mass"] > 0.30
    assert worst["ocr_confidence"] is not None and worst["ocr_confidence"] > 0.70


@_NO_WEIGHTS
@_NO_CORPUS
def test_the_coverage_arm_does_not_fire_on_this_document_and_that_is_the_calibration(
    scanned: Any,
) -> None:
    """`COVERAGE_WARN` is a catastrophe floor, and this pins the measurement that says so.

    Every previous record of this constant quoted a coverage for the degraded page that the
    production path does not produce — 0.1965 (recovered characters over ground truth), 0.207
    (ink-in-box from a standalone engine call), 0.334 and 0.386 (an earlier note on
    `_confidence_record`). Through `parse_document` it is **0.505**, and the constant is 0.30, so
    the coverage arm is silent here on purpose.

    Asserted as a band rather than a point: this is a model's output, and the test exists to
    catch the number moving to somewhere the threshold argument no longer holds — not to
    re-freeze a float. If it drifts under 0.30 the constant's whole derivation needs redoing;
    the full distribution and the skew ceiling are on the constant.
    """
    pages = scanned.confidence["pages"]
    degraded = min(pages.values(), key=lambda page: page["ocr_coverage"] or 1.0)
    assert 0.40 < degraded["ocr_coverage"] < 0.60, degraded["ocr_coverage"]
    assert not any(
        warning.startswith("ocr_low_coverage")
        for page in pages.values()
        for warning in page["warnings"]
    ), scanned.confidence["warnings"]

    # Clean pages sit near 1.0, which is what makes the signal a signal at all.
    clean = [page["ocr_coverage"] for page in pages.values() if page["ocr_coverage"] > 0.60]
    assert len(clean) == 3 and min(clean) > 0.95, clean


@_NO_WEIGHTS
@pytest.mark.skipif(not _HANDBOOK.is_file(), reason="the handbook fixture is not present")
def test_a_born_digital_document_carries_no_ocr_warnings_at_all() -> None:
    """**The signal used to shout on every clean PDF.** Measured before the fix: 14 of 14
    handbook pages and 2 of 2 datasheet pages carried `ocr_coverage_unmeasurable`, because a
    page with no OCR cells has no polygons and "no polygons" was reported as a failed ink
    measurement rather than as "OCR did not run here".

    It would have been the most common warning string in the platform's first real ingest, on
    documents with nothing wrong with them.
    """
    parsed = parse_document(path=_HANDBOOK, max_pages=100, max_bytes=50_000_000)

    assert parsed.confidence["warnings"] == []
    for number, page in parsed.confidence["pages"].items():
        assert page["warnings"] == [], f"page {number}"
        # And the record says OCR did not run, rather than counting the digital cells as OCR
        # ones — `ocr_cells` read 41 on a page whose `ocr_confidence` was None.
        assert page["ocr_cells"] == 0, f"page {number}"
        assert page["ocr_confidence"] is None
        assert page["ocr_coverage"] is None


@_NO_WEIGHTS
@_NO_CORPUS
def test_the_parse_is_reproducible(scanned: Any) -> None:
    """The second half of finding #29, and the reason it is worth the two minutes it costs.

    Compared on the exported document rather than on object identity: what has to be stable is
    the text and the order, because that is what the chunker reads, what the content hash covers
    and what the point ids are derived from.
    """
    again = parse_document(path=_SCANNED, max_pages=50, max_bytes=50_000_000)

    assert again.status == scanned.status
    assert again.truncated == scanned.truncated
    assert len(again.elements) == len(scanned.elements)
    assert [
        (type(item).__name__, level, getattr(item, "text", None)) for item, level in again.elements
    ] == [
        (type(item).__name__, level, getattr(item, "text", None))
        for item, level in scanned.elements
    ]
    assert again.document.export_to_markdown() == scanned.document.export_to_markdown()

    # The quality record too: a signal that moves between two parses of one file is a signal
    # that cannot be compared across two versions of a document, which is what it is for.
    for number, page in scanned.confidence["pages"].items():
        assert again.confidence["pages"][number] == page


@_NO_WEIGHTS
@pytest.mark.skipif(not _HANDBOOK.is_file(), reason="the handbook fixture is not present")
def test_a_born_digital_document_keeps_its_heading_tree() -> None:
    """A one-paragraph PDF proves nothing about this stage. This one has a multi-level heading
    tree, and `HEADING_HIERARCHY_ENABLED` is the flag that decides whether the levels survive —
    with it off every heading stays at level 1 and small-to-big retrieval collapses into flat
    retrieval, with nothing failing."""
    parsed = parse_document(path=_HANDBOOK, max_pages=100, max_bytes=50_000_000)

    assert parsed.status in {"success", "partial_success"}
    headings = [
        item for item, _level in parsed.elements if type(item).__name__ == "SectionHeaderItem"
    ]
    assert len(headings) > 1
    assert len({getattr(item, "level", 1) for item in headings}) > 1, (
        "every heading came back at one level, which is the flag being off"
    )
