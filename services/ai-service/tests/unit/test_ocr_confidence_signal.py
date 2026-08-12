"""The two OCR page-quality signals, and why neither is a page-mean confidence.

`CONF_WARN = 0.75` compared a **character-weighted page mean** against a fixed floor. On the
pinned engine that warning could not fire. Measured on rapidocr 3.9.2 / PP-OCRv6 across five
independent degradation axes (ink density, defocus blur, render resolution, JPEG quantisation,
additive noise) and two page shapes, the page mean never falls below ~0.72 on any page still
bearing text, and stays above 0.81 on every realistically degraded one. It *rises* again as a
page degrades further, because the surviving cells are an increasingly selective subset.

The cause is not the one the skill used to name. `Global.text_score` (0.5) deletes almost
nothing — re-running the sweep at `text_score=0.0` recovers a measured 0.003 of coverage. Nor
is it `RapidOCR.build_final_output`'s unconditional drop of empty recognitions, which sounds
like the culprit and, instrumented, fired on 1 of 14 measured pages. Almost all of the loss is
at **detection**: the DB detector simply proposes fewer regions as a page degrades (13 → 15 →
6 → 3 → 1 → 0 across an ink sweep; 5 on the corpus's degraded page against 10 / 10 / 4 on its
clean ones), and a region it does propose is one the recogniser then reads confidently. The
selection happens before recognition runs, so no statistic over recogniser confidence can undo
it. Degradation is expressed as absence, not as low confidence.

So the confidence signal became character **mass** below a per-cell floor, and coverage got the
production-computable definition it never had. Every number below is measured, not chosen, and
the point of the test is that the two clean/degraded populations stay separated by a margin
wide enough that an engine build reading somewhat better or worse cannot flip a page.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Final

import numpy as np
import pytest

from app.ingestion.ocr.guarded import (
    CELL_TRUST_FLOOR,
    COVERAGE_WARN,
    INK_FRACTION_BAND,
    LOW_CONF_MASS_WARN,
    assess,
    low_confidence_mass,
    page_confidence,
    page_coverage,
)


@dataclass(frozen=True, slots=True)
class Cell:
    """Satisfies the `OcrCell` protocol. `text` carries the measured non-whitespace length,
    which is all any statistic here weighs by."""

    confidence: float
    text: str
    from_ocr: bool = True

    @classmethod
    def of(cls, confidence: float, chars: int, *, from_ocr: bool = True) -> Cell:
        return cls(confidence, "x" * chars, from_ocr)


def cells(*pairs: tuple[float, int]) -> list[Cell]:
    return [Cell.of(confidence, chars) for confidence, chars in pairs]


# ── measured cell distributions, rapidocr 3.9.2 / PP-OCRv6 at 300 dpi ────────────────────────
# (recognition score, non-whitespace character count) per cell, exactly as the engine returned
# them. Pages 1, 2 and 4 of the corpus fixture are clean; page 3 is the deliberately degraded
# one. The prose cases are a second page shape (dense 10pt body text) degraded along one axis
# each, so the clean population is not a single document's worth of evidence.

FIXTURE_PAGE_1: Final = cells(
    (0.9982, 25), (0.9999, 13), (0.9997, 17), (0.9917, 15), (0.9923, 31),
    (0.9986, 31), (0.9980, 49), (0.9997, 12), (0.9930, 45), (0.6367, 27),
)  # fmt: skip
FIXTURE_PAGE_2: Final = cells(
    (1.0000, 9), (0.9955, 5), (0.9853, 15), (0.9920, 50), (0.9971, 10),
    (0.9995, 18), (0.9967, 19), (0.9998, 15), (0.9813, 31), (0.9998, 46),
)  # fmt: skip
FIXTURE_PAGE_3_DEGRADED: Final = cells(
    (0.9858, 18), (0.6862, 31), (0.7775, 25), (0.7215, 13), (0.9746, 25),
)  # fmt: skip
FIXTURE_PAGE_4: Final = cells(
    (1.0000, 13), (0.9984, 36), (0.9931, 15), (0.9942, 10),
)  # fmt: skip

PROSE_CLEAN: Final = cells(
    (0.9970, 47), (0.9970, 64), (0.9885, 61), (0.6861, 8), (0.9994, 60), (0.9950, 63),
    (0.9922, 62), (0.9939, 66), (0.9970, 65), (0.9992, 61), (0.9854, 63), (0.9881, 60),
    (0.9963, 50),
)  # fmt: skip
PROSE_JPEG_Q5: Final = cells(
    (0.9886, 47), (0.9930, 64), (0.6767, 11), (0.6733, 14), (0.6551, 7), (0.9977, 63),
    (0.9947, 62), (0.9993, 66), (0.9984, 65), (0.9921, 61), (0.9829, 63), (0.9969, 60),
    (0.9941, 50),
)  # fmt: skip
PROSE_NOISE_150: Final = cells(
    (0.6449, 12), (0.9955, 64), (0.6320, 7), (0.9920, 63), (0.9984, 60), (0.9894, 63),
    (0.9784, 62), (0.9964, 66), (0.9877, 66), (0.9937, 60), (0.9676, 62), (0.9729, 59),
    (0.6448, 5),
)  # fmt: skip
PROSE_DOWNSCALE_010: Final = cells(
    (0.8534, 49), (0.8843, 62), (0.9457, 61), (0.8742, 62), (0.8685, 60), (0.8462, 64),
    (0.8573, 64), (0.8682, 66), (0.7714, 60), (0.8946, 63), (0.7919, 61), (0.8641, 49),
)  # fmt: skip
PROSE_INK_0155: Final = cells(
    (0.7799, 6), (0.8155, 7), (0.7289, 8), (0.7695, 9), (0.7556, 15),
)  # fmt: skip
PROSE_INK_015: Final = cells((0.6537, 7), (0.7879, 8), (0.8513, 9))

#: Pages an admin should never see a confidence warning for. `PROSE_DOWNSCALE_010` is the
#: adversarial member: rendered at a tenth of the resolution, it reads at 0.92 ground-truth
#: coverage — a page the engine handled — while carrying the lowest cell scores of any clean
#: page. It is what keeps `CELL_TRUST_FLOOR` from drifting upward.
ACCEPTABLE: Final = {
    "fixture page 1": FIXTURE_PAGE_1,
    "fixture page 2": FIXTURE_PAGE_2,
    "fixture page 4": FIXTURE_PAGE_4,
    "prose clean": PROSE_CLEAN,
    "prose jpeg q=5": PROSE_JPEG_Q5,
    "prose noise sigma=150": PROSE_NOISE_150,
    "prose downscale=0.10": PROSE_DOWNSCALE_010,
}

#: Pages that must warn. Each lost most of its text to the empty-recognition filter.
DEGRADED: Final = {
    "fixture page 3": FIXTURE_PAGE_3_DEGRADED,
    "prose ink=0.155": PROSE_INK_0155,
    "prose ink=0.15": PROSE_INK_015,
}


class TestTheMeanCannotBeThresholded:
    """The finding itself, frozen so nobody reintroduces `mean < 0.75`."""

    @pytest.mark.parametrize("name", sorted(ACCEPTABLE | DEGRADED))
    def test_no_page_mean_falls_below_the_old_threshold(self, name: str) -> None:
        mean = page_confidence((ACCEPTABLE | DEGRADED)[name], "rapidocr")
        assert mean is not None
        assert mean >= 0.75, (
            f"{name}: mean {mean:.4f} — if this ever fails the floor has moved and the "
            "measurements in the module docstring need re-taking"
        )

    def test_the_mean_leaves_too_narrow_a_window_to_threshold(self) -> None:
        """The mean does order the two populations correctly — it is not inverted. What it does
        not do is leave room to stand between them.

        Every degraded page sits above 0.75, so the shipped threshold was unreachable; and the
        window where a working threshold *could* sit is under a tenth of a point wide and both
        of its edges are engine-build artefacts. A threshold placed in there fires on everything
        or nothing depending on the build, which is the hazard that rules the statistic out
        rather than merely retuning it. The same comparison on character mass, in
        `TestLowConfidenceMass`, has a 3x window instead of a 1.1x one.
        """
        worst_degraded = min(page_confidence(page, "rapidocr") or 0.0 for page in DEGRADED.values())
        best_clean = min(page_confidence(page, "rapidocr") or 0.0 for page in ACCEPTABLE.values())
        assert 0.75 < worst_degraded < best_clean
        assert best_clean - worst_degraded < 0.10
        assert best_clean / worst_degraded < 1.15

    def test_junk_cells_appear_on_clean_pages_too(self) -> None:
        """Why "any cell below the floor" is not the rule either. Not every clean page carries
        one — fixture page 2 bottoms out at 0.9813 — but enough do that the rule is unusable:
        the clean corpus page 1 carries a cell at 0.6367 and the clean prose page one at 0.6861.
        A rule that fires on a fold line, a page border or a speck fires on ordinary documents.
        """
        with_junk = [
            name
            for name, page in ACCEPTABLE.items()
            if min(cell.confidence for cell in page) < CELL_TRUST_FLOOR
        ]
        assert "fixture page 1" in with_junk
        assert "prose clean" in with_junk
        assert len(with_junk) >= len(ACCEPTABLE) // 2


class TestLowConfidenceMass:
    @pytest.mark.parametrize("name", sorted(ACCEPTABLE))
    def test_acceptable_pages_do_not_warn(self, name: str) -> None:
        mass = low_confidence_mass(ACCEPTABLE[name], "rapidocr")
        assert mass is not None
        assert mass <= LOW_CONF_MASS_WARN, f"{name}: false positive at mass {mass:.3f}"

    @pytest.mark.parametrize("name", sorted(DEGRADED))
    def test_degraded_pages_warn(self, name: str) -> None:
        mass = low_confidence_mass(DEGRADED[name], "rapidocr")
        assert mass is not None
        assert mass > LOW_CONF_MASS_WARN, f"{name}: missed at mass {mass:.3f}"

    def test_the_separating_margin_is_wide_on_both_sides(self) -> None:
        """A threshold sitting just under a measured floor fires on everything or nothing
        depending on the engine build. This asserts the gap the choice of 0.30 rests on."""
        worst_acceptable = max(
            low_confidence_mass(page, "rapidocr") or 0.0 for page in ACCEPTABLE.values()
        )
        best_degraded = min(
            low_confidence_mass(page, "rapidocr") or 0.0 for page in DEGRADED.values()
        )
        assert worst_acceptable < LOW_CONF_MASS_WARN < best_degraded
        assert LOW_CONF_MASS_WARN / worst_acceptable > 1.5
        assert best_degraded / LOW_CONF_MASS_WARN > 1.5

    def test_weighting_is_by_character_not_by_cell(self) -> None:
        """One junk cell among four good ones is a quarter of the cells and ~3% of the text."""
        page = cells((0.60, 3), (0.99, 40), (0.99, 40), (0.99, 40))
        mass = low_confidence_mass(page, "rapidocr")
        assert mass is not None
        assert mass == pytest.approx(3 / 123)

    def test_digital_cells_are_excluded(self) -> None:
        """Digital cells sit at 1.0 and would dilute the mass on any mixed page, hiding an
        unreadable scanned figure inside a mostly-born-digital page."""
        ocr_only = cells((0.60, 50), (0.99, 50))
        mixed = [*ocr_only, Cell.of(1.0, 5000, from_ocr=False)]
        assert low_confidence_mass(mixed, "rapidocr") == low_confidence_mass(ocr_only, "rapidocr")

    def test_a_page_with_no_ocr_text_is_none_not_zero(self) -> None:
        """0.0 would read as 'all text trusted'. A page the engine read nothing on is a
        coverage problem, and only coverage should speak for it."""
        assert low_confidence_mass([], "rapidocr") is None
        assert low_confidence_mass([Cell.of(1.0, 80, from_ocr=False)], "rapidocr") is None

    def test_tesseract_scores_are_normalized_before_the_floor(self) -> None:
        """A raw 0–100 score against a 0–1 floor trusts every cell unconditionally, which is
        how switching engines silently removes the whole warning class."""
        page = [Cell.of(70.0, 30), Cell.of(95.0, 30)]
        assert low_confidence_mass(page, "tesserocr") == pytest.approx(0.5)
        assert low_confidence_mass(page, "rapidocr") == pytest.approx(0.0)


class TestPageCoverage:
    """Ink-in-box, on synthetic rasters with known geometry."""

    @staticmethod
    def raster(ink_boxes: list[tuple[int, int, int, int]], size: int = 600) -> np.ndarray:
        page = np.full((size, size), 255, dtype=np.uint8)
        for x0, y0, x1, y1 in ink_boxes:
            page[y0:y1, x0:x1] = 0
        return page

    @staticmethod
    def poly(x0: int, y0: int, x1: int, y1: int) -> list[tuple[float, float]]:
        return [(x0, y0), (x1, y0), (x1, y1), (x0, y1)]

    def test_all_ink_inside_a_text_box_is_full_coverage(self) -> None:
        page = self.raster([(100, 100, 160, 130)])
        assert page_coverage([self.poly(95, 95, 165, 135)], page) == pytest.approx(1.0)

    def test_ink_outside_every_box_lowers_coverage(self) -> None:
        """Two equal ink blocks, one of them detected: half the page's ink went unread."""
        page = self.raster([(100, 100, 160, 130), (300, 300, 360, 330)])
        assert page_coverage([self.poly(95, 95, 165, 135)], page) == pytest.approx(0.5, abs=0.02)

    def test_a_sparse_clean_page_is_not_penalised(self) -> None:
        """The defect in the obvious alternative: box-area-over-page-area scores a four-line
        signature block below a degraded full page. Normalising by the page's own ink removes
        the density term, so a nearly-empty page whose little text was read scores ~1.0."""
        page = self.raster([(100, 100, 130, 112)])
        coverage = page_coverage([self.poly(95, 95, 135, 117)], page)
        assert coverage == pytest.approx(1.0)
        box_area_fraction = (135 - 95) * (117 - 95) / (600 * 600)
        assert box_area_fraction < COVERAGE_WARN  # the rule we did NOT adopt would warn here

    def test_no_detected_text_over_a_page_full_of_ink_is_zero(self) -> None:
        page = self.raster([(100, 100, 300, 200)])
        assert page_coverage([], page) == pytest.approx(0.0)

    def test_a_speckle_dominated_page_reports_unmeasurable(self) -> None:
        """Otsu binarises noise as ink, most of it lands outside every box, and coverage
        collapses to a false warning. Measured: 0.26 on a page whose true coverage was 0.83."""
        rng = np.random.default_rng(20260807)
        page = rng.integers(0, 256, (600, 600), dtype=np.uint8)
        coverage = page_coverage([self.poly(100, 100, 200, 200)], page)
        assert coverage != coverage  # NaN

    def test_a_blank_page_does_not_warn(self) -> None:
        """`needs_ocr` skips blank pages; warning on them would be pure noise."""
        assert page_coverage([], np.full((600, 600), 255, dtype=np.uint8)) == 1.0

    def test_the_ink_band_brackets_every_real_page_measured(self) -> None:
        low, high = INK_FRACTION_BAND
        assert low < 0.0027  # sparsest real page measured (fixture page 4)
        assert high > 0.0646  # densest real page measured (prose at blur=6.0)
        assert high < 0.2003  # speckle-dominated page, denoised — must stay out of band


class TestAssess:
    def test_the_confidence_arm_alone_fires_on_the_corpus_degraded_page(self) -> None:
        """**Corrected 2026-08-12, and the correction is the point of the test.**

        This used to feed `assess` a coverage of 0.207 and assert both arms fired. 0.207 is a
        real number and it is not this one: it was measured offline, from a standalone engine
        call on the 300 dpi raster. Through `parse_document` — the only path that ever reaches
        `assess` — that same page reads **0.5054**, because docling's own invocation recovers 11
        OCR cells on it where the offline call recovered 5.

        So the coverage arm does not fire on this page and is not supposed to. `COVERAGE_WARN`
        is a catastrophe floor whose ceiling is set by page skew, not a quality gate; the
        distribution and the reason are recorded on the constant. The confidence arm is what
        catches this page, which is why the two signals were split.
        """
        assert assess(FIXTURE_PAGE_3_DEGRADED, "rapidocr", 0.5054) == ["ocr_low_confidence:0.62"]
        assert COVERAGE_WARN < 0.5054

    @pytest.mark.parametrize("name", sorted(ACCEPTABLE))
    def test_clean_pages_are_silent(self, name: str) -> None:
        assert assess(ACCEPTABLE[name], "rapidocr", 0.95) == []

    def test_a_clean_but_crooked_page_does_not_warn(self) -> None:
        """Measured through `parse_document`: 5° of skew takes a page the engine read with cell
        recall 1.000 down to 0.3909 coverage, because the OCR rectangles are axis-aligned and
        the ink is not. Every threshold at 0.40 or above warns on an ordinary crooked scan, and
        that is the constraint that keeps `COVERAGE_WARN` at 0.30."""
        assert assess(ACCEPTABLE["fixture page 1"], "rapidocr", 0.3909) == []

    def test_coverage_still_fires_alone(self) -> None:
        """The two signals do not move together, and this is the case that proves the design is
        still two signals: a page whose few surviving cells all read confidently, but which the
        engine barely read at all. Measured at blur=6.0 — mass 0.124, coverage 0.152."""
        page = cells((0.9059, 97))
        assert assess(page, "rapidocr", 0.152) == ["ocr_low_coverage:0.15"]

    def test_confidence_still_fires_alone(self) -> None:
        assert assess(FIXTURE_PAGE_3_DEGRADED, "rapidocr", 0.95) == ["ocr_low_confidence:0.62"]

    def test_unmeasurable_coverage_is_its_own_warning(self) -> None:
        """Not silence, and not a fabricated 0.00 coverage number that would read as evidence
        of a bad scan when it is really evidence of a bad measurement. NaN is reserved for that
        case: OCR ran, and the ink mask cannot be trusted."""
        assert assess(FIXTURE_PAGE_2, "rapidocr", float("nan")) == ["ocr_coverage_unmeasurable"]

    def test_a_page_with_no_ocr_at_all_is_silent_on_both_arms(self) -> None:
        """**The defect this replaced fired on every born-digital page in the corpus** — 14 of
        14 handbook pages and 2 of 2 datasheet pages carried `ocr_coverage_unmeasurable`,
        because a page with no OCR cells has no polygons and "no polygons" was reported as a
        failed measurement.

        `None` is "no OCR ran here", which is exactly what `low_confidence_mass` already says by
        returning `None`. Both arms are now silent on it, and NaN keeps its own meaning.
        """
        assert assess([], "rapidocr", None) == []
        assert assess([Cell.of(1.0, 400, from_ocr=False)], "rapidocr", None) == []

    def test_assessment_never_raises(self) -> None:
        """A low-confidence page is `Ready with warnings`, never `Failed`."""
        assert isinstance(assess(FIXTURE_PAGE_3_DEGRADED, "rapidocr", 0.0), list)


class TestUnplacedText:
    """The third arm: OCR read the page and none of it became an element.

    Measured through `parse_document` at Gaussian blur 3.0 — the layout model classifies the
    whole page as a picture, cell recall is 1.000, element recall is 0.000, and before this arm
    existed **nothing warned**. The corpus's own degraded page uses `DEGRADE_BLUR_RADIUS = 1.7`
    and does not reach the state, which is why it is not the fixture here.
    """

    def test_read_but_unplaced_is_the_warning(self) -> None:
        """The whole point: both other arms are silent AND CORRECT. Confidence is high because
        the cells really were read confidently; coverage is high because the ink really is
        inside the cell rectangles. The page is perfect by every statistic and contributes
        nothing to the index."""
        page = ACCEPTABLE["fixture page 1"]
        assert assess(page, "rapidocr", 0.95, 0) == ["ocr_text_unplaced"]

    def test_placed_text_makes_it_silent(self) -> None:
        assert assess(ACCEPTABLE["fixture page 1"], "rapidocr", 0.95, 12) == []

    def test_an_unmeasurable_count_is_silent_not_a_warning(self) -> None:
        """`None` is "the caller could not measure it", and it must not be read as zero. A
        result shape `_placed_text_per_page` does not understand returns `None`, and treating
        that as zero would warn on every page of every document — the same false-positive class
        `ocr_coverage_unmeasurable` was before it learned to tell "no OCR ran" from "the mask is
        untrustworthy". The default is `None` for the same reason."""
        assert assess(ACCEPTABLE["fixture page 1"], "rapidocr", 0.95, None) == []
        assert assess(ACCEPTABLE["fixture page 1"], "rapidocr", 0.95) == []

    def test_a_page_with_no_ocr_cells_never_warns(self) -> None:
        """Text that was never read cannot have failed to be placed. A blank page has zero OCR
        cells and zero elements, and it is a blank page, not a defect."""
        assert assess([], "rapidocr", None, 0) == []
        assert assess([Cell.of(1.0, 400, from_ocr=False)], "rapidocr", None, 0) == []

    def test_it_stacks_with_the_other_arms_rather_than_replacing_them(self) -> None:
        """A page can be badly read AND unplaced. The list is a list."""
        assert assess(FIXTURE_PAGE_3_DEGRADED, "rapidocr", 0.152, 0) == [
            "ocr_low_confidence:0.62",
            "ocr_low_coverage:0.15",
            "ocr_text_unplaced",
        ]
