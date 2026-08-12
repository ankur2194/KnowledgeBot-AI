"""`profile_page` and `needs_ocr` — deciding, per page, whether an engine runs at all.

The asymmetry that governs every threshold here is on the record in the module under test: a
false "needs OCR" costs thirty seconds, a false "does not" loses the page from the index
permanently. So every test below that asserts a *skip* is asserting a permanent loss is
correct, and there are only four shapes for which that is true.

Three defects this file exists to keep out, each of which produces a working-looking pipeline:

* **Reading the text dict instead of the trace.** The dict shows an invisible OCR layer as
  ordinary text, so every already-OCRed scan is OCRed again — twice the bill and twice the
  error rate, with a fuller-looking index.
* **The bitmask written as a chain.** `FILLED & STROKED` is 0, so `not (flags & 0)` is True for
  every character on every page. Every page then grades "already has an OCR layer" and OCR
  stops platform-wide with no error anywhere.
* **Summing image rectangles instead of unioning them.** One image drawn twice sums past the
  page area, trips `IMAGE_COVERAGE_FULL`, and rasterizes a page that is mostly white.
"""

from __future__ import annotations

import io
from dataclasses import replace
from typing import Any, Final

import pymupdf
import pytest
from PIL import Image

from app.ingestion.ocr.guarded import (
    IMAGE_COVERAGE_BLANK,
    IMAGE_COVERAGE_FULL,
    INVISIBLE_RATIO_MAX,
    MARGIN_RATIO,
    MIN_DIGITAL_CHARS,
    REPLACEMENT_RATIO_MAX,
    PageProfile,
    _paint_mask,
    _span_is_invisible,
    escalate_after_empty,
    needs_ocr,
    profile_page,
)

PAGE_WIDTH: Final[float] = 612.0
PAGE_HEIGHT: Final[float] = 792.0


def _png(colour: tuple[int, int, int] = (200, 10, 10)) -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", (64, 64), colour).save(buffer, format="PNG")
    return buffer.getvalue()


def _page() -> Any:
    document = pymupdf.open()
    return document.new_page(width=PAGE_WIDTH, height=PAGE_HEIGHT)


def _body(page: Any, lines: int = 20, *, render_mode: int = 0, text: str | None = None) -> None:
    for index in range(lines):
        page.insert_text(
            (100, 150 + index * 20),
            text or f"Born digital body text line {index} with several words",
            fontsize=11,
            render_mode=render_mode,
        )


# ── the invisible test, on the bits themselves ───────────────────────────────


def test_the_paint_mask_is_read_from_mupdf_and_is_not_the_familiar_literal() -> None:
    """`8 | 16` is a previous MuPDF flag layout. On the pinned build FILLED is 16, STROKED is
    32, and 8 is BOLD — so the literal reads "neither bold nor filled" and is wrong in both
    directions at once."""
    from pymupdf import mupdf

    assert _paint_mask() == mupdf.FZ_STEXT_FILLED | mupdf.FZ_STEXT_STROKED
    assert mupdf.FZ_STEXT_BOLD == 8
    assert _paint_mask() != 8 | 16, "the literal has silently become correct; re-read the flags"


def test_the_mask_is_an_or_and_the_chained_form_marks_everything_invisible() -> None:
    """The trap named in the docstring, on a real flag value rather than in prose.

    A filled span carries FILLED. Against `FILLED | STROKED` it grades visible. Against
    `FILLED & STROKED` — which is 0 — every `&` is 0, so `not (...)` is True and the span grades
    invisible. Every page on the platform then looks like it already carries an OCR layer.
    """
    from pymupdf import mupdf

    painted = int(mupdf.FZ_STEXT_FILLED)
    visible_span = {"char_flags": painted, "type": 0}
    stroked_span = {"char_flags": int(mupdf.FZ_STEXT_STROKED), "type": 1}
    ocr_layer_span = {"char_flags": 0, "type": 3}

    assert not _span_is_invisible(visible_span, painted=_paint_mask())
    assert not _span_is_invisible(stroked_span, painted=_paint_mask())
    assert _span_is_invisible(ocr_layer_span, painted=_paint_mask())

    chained = int(mupdf.FZ_STEXT_FILLED & mupdf.FZ_STEXT_STROKED)
    assert chained == 0
    assert _span_is_invisible(visible_span, painted=chained)
    assert _span_is_invisible(stroked_span, painted=chained)


def test_the_stale_literal_misgrades_a_stroked_span_and_a_bold_ocr_layer() -> None:
    """Why the mask is read from MuPDF rather than frozen. Both errors are silent and they point
    the classifier in opposite directions on different pages."""
    from pymupdf import mupdf

    stale = 8 | 16  # BOLD | FILLED on the pinned build
    stroked_only = {"char_flags": int(mupdf.FZ_STEXT_STROKED), "type": 1}
    bold_ocr_layer = {"char_flags": int(mupdf.FZ_STEXT_BOLD), "type": 3}

    assert _span_is_invisible(stroked_only, painted=stale)  # a visible heading, called invisible
    assert not _span_is_invisible(bold_ocr_layer, painted=stale)  # an OCR layer, called text

    assert not _span_is_invisible(stroked_only, painted=_paint_mask())
    assert _span_is_invisible(bold_ocr_layer, painted=_paint_mask())


@pytest.mark.parametrize(
    ("mode", "invisible"),
    [(0, False), (1, False), (2, False), (3, True), (4, False), (5, False), (6, False), (7, True)],
)
def test_the_render_mode_fallback_covers_all_eight_modes(mode: int, invisible: bool) -> None:
    """The build resolved here puts no `char_flags` on a trace span, so the render mode is the
    live path. Modes 4-7 are modes 0-3 plus a clip, so the painting question is `mode % 4`, and
    mode 7 — clip only — is invisible exactly as mode 3 is."""
    assert _span_is_invisible({"type": mode}, painted=_paint_mask()) is invisible


def test_char_flags_win_over_the_render_mode_when_the_build_supplies_them() -> None:
    """Two spellings of one fact across a version range whose floor is 1.26.7. `char_flags` is
    the direct statement, so a build that carries it is believed."""
    assert _span_is_invisible({"char_flags": 0, "type": 0}, painted=_paint_mask())
    assert not _span_is_invisible({"char_flags": _paint_mask(), "type": 3}, painted=_paint_mask())


# ── profile_page against real pymupdf pages ─────────────────────────────────


def test_a_born_digital_page_reads_as_text_with_no_image_and_nothing_invisible() -> None:
    page = _page()
    _body(page)
    profile = profile_page(page)
    assert profile.chars > MIN_DIGITAL_CHARS
    assert profile.invisible_ratio == 0.0
    assert profile.replacement_ratio == 0.0
    assert profile.image_coverage == 0.0
    assert needs_ocr(profile) is None


def test_a_scan_with_an_invisible_ocr_layer_is_recognised_and_skipped() -> None:
    """The failure this whole function exists for. Through `get_text()` these spans are ordinary
    text and the page would be OCRed a second time, on top of the layer already there."""
    page = _page()
    page.insert_image(pymupdf.Rect(0, 0, PAGE_WIDTH, PAGE_HEIGHT), stream=_png())
    _body(page, render_mode=3)

    profile = profile_page(page)
    assert profile.chars > 0
    assert profile.invisible_ratio == 1.0
    assert profile.image_coverage == pytest.approx(1.0)
    assert needs_ocr(profile) is None

    # And the same page read through the dict, which is what the trace exists to avoid: the
    # invisible layer arrives as plain text with nothing marking it.
    assert "Born digital body text" in page.get_text()


def test_a_bare_scan_with_no_text_layer_goes_to_full_page() -> None:
    page = _page()
    page.insert_image(pymupdf.Rect(0, 0, PAGE_WIDTH, PAGE_HEIGHT), stream=_png())
    profile = profile_page(page)
    assert profile.chars == 0
    assert profile.image_coverage == pytest.approx(1.0)
    assert needs_ocr(profile) == "FULL_PAGE"


def test_overlapping_image_rectangles_are_unioned_and_never_exceed_one() -> None:
    """The trap the docstring does not name. Two placements of one image — a logo drawn twice, a
    scan laid down in overlapping strips — sum past the page area, so a page that is 42% image
    reports 52% and a page that is 100% image reports 200%."""
    page = _page()
    page.insert_image(pymupdf.Rect(50, 50, 450, 450), stream=_png())
    page.insert_image(pymupdf.Rect(250, 250, 550, 550), stream=_png())

    page_area = PAGE_WIDTH * PAGE_HEIGHT
    summed = (400 * 400 + 300 * 300) / page_area
    union = (400 * 400 + 300 * 300 - 200 * 200) / page_area

    profile = profile_page(page)
    assert profile.image_coverage == pytest.approx(union)
    assert profile.image_coverage < summed


def test_one_image_drawn_twice_does_not_report_two_hundred_percent_coverage() -> None:
    """The consequential form: the sum trips `IMAGE_COVERAGE_FULL` on a page that is mostly
    white, and a page rasterized at 300 dpi for nothing is thirty seconds of a document's
    five-minute budget."""
    page = _page()
    small = pymupdf.Rect(100, 100, 300, 300)
    page.insert_image(small, stream=_png())
    page.insert_image(small, stream=_png((10, 10, 200)))

    profile = profile_page(page)
    assert profile.image_coverage == pytest.approx(200 * 200 / (PAGE_WIDTH * PAGE_HEIGHT))
    assert profile.image_coverage <= 1.0
    assert profile.image_coverage < IMAGE_COVERAGE_FULL


def test_a_full_bleed_image_drawn_twice_stays_at_one() -> None:
    page = _page()
    whole = pymupdf.Rect(0, 0, PAGE_WIDTH, PAGE_HEIGHT)
    page.insert_image(whole, stream=_png())
    page.insert_image(whole, stream=_png((10, 200, 10)))
    assert profile_page(page).image_coverage == pytest.approx(1.0)


def test_an_image_overflowing_the_page_is_clipped_to_it() -> None:
    """A bleed box larger than the trim box is ordinary in print-ready PDFs, and unclipped it
    reports coverage above 1.0 for the same reason a double-draw does."""
    page = _page()
    page.insert_image(pymupdf.Rect(-200, -200, PAGE_WIDTH + 200, PAGE_HEIGHT + 200), stream=_png())
    assert profile_page(page).image_coverage == pytest.approx(1.0)


def test_only_the_centre_box_counts_as_a_page_s_text() -> None:
    """A page whose only text is a running header still needs OCR — that is what `MARGIN_RATIO`
    is for, and it is the difference between indexing a scan and indexing its page number."""
    page = _page()
    page.insert_text((20, 20), "Confidential - Acme Corporation - Q3 Review", fontsize=9)
    page.insert_text((20, PAGE_HEIGHT - 20), "page 7 of 42", fontsize=9)
    page.insert_image(pymupdf.Rect(0, 0, PAGE_WIDTH, PAGE_HEIGHT), stream=_png())

    profile = profile_page(page)
    assert profile.chars == 0, "furniture in the margin was counted as the page's text"
    assert needs_ocr(profile) == "FULL_PAGE"

    # The same glyphs moved inside the centre box do count.
    inside = _page()
    inside.insert_text((PAGE_WIDTH * MARGIN_RATIO + 10, PAGE_HEIGHT / 2), "Acme", fontsize=9)
    assert profile_page(inside).chars == 4


def test_whitespace_is_not_a_character() -> None:
    """`MIN_DIGITAL_CHARS` is a floor on *text*. Counting spaces makes a page of indentation
    look born-digital and skips the scan under it."""
    page = _page()
    page.insert_text((100, 400), "a" + " " * 40 + "b", fontsize=11)
    assert profile_page(page).chars == 2


def test_mojibake_is_measured_as_a_share_of_the_page() -> None:
    """A broken or absent ToUnicode map yields U+FFFD. The text layer is present, plentiful and
    useless, which is exactly the page a character count alone will skip.

    Written through `TextWriter` with a font that *has* the glyph, because the base-14 fonts do
    not: `insert_text` silently maps U+FFFD onto Helvetica's middle dot, which would make this
    test pass against an implementation that counted nothing.
    """
    page = _page()
    writer = pymupdf.TextWriter(page.rect)
    font = pymupdf.Font("cjk")
    for index in range(10):
        writer.append((100, 200 + index * 20), "�" * 30, font=font, fontsize=11)
    writer.write_text(page)

    profile = profile_page(page)
    assert profile.chars == 300
    assert profile.replacement_ratio == pytest.approx(1.0)
    assert needs_ocr(profile) == "FULL_PAGE"


def test_an_empty_page_reports_zeros_rather_than_dividing_by_zero() -> None:
    profile = profile_page(_page())
    assert profile == PageProfile(
        chars=0, image_coverage=0.0, invisible_ratio=0.0, replacement_ratio=0.0
    )
    assert needs_ocr(profile) is None


# ── needs_ocr: the ordered spec ──────────────────────────────────────────────


def _profile(
    *,
    chars: int = 0,
    image_coverage: float = 0.0,
    invisible_ratio: float = 0.0,
    replacement_ratio: float = 0.0,
) -> PageProfile:
    return PageProfile(
        chars=chars,
        image_coverage=image_coverage,
        invisible_ratio=invisible_ratio,
        replacement_ratio=replacement_ratio,
    )


def test_mojibake_is_tested_before_has_text() -> None:
    """The load-bearing ordering. A page can be text-bearing *and* unusable at the same time,
    and a mojibake page is the worst case of it: thousands of characters, none of them a word.
    Tested after the character count, it is skipped as born-digital and the page is lost."""
    page = _profile(chars=5_000, replacement_ratio=0.9)
    assert needs_ocr(page) == "FULL_PAGE"
    assert needs_ocr(replace(page, replacement_ratio=0.0)) is None


def test_mojibake_is_tested_before_the_invisible_layer_check() -> None:
    """The other ordering the first step protects. An OCR layer written from a broken font map
    is both invisible and garbage; skipping on "it already has a layer" keeps the garbage."""
    assert needs_ocr(_profile(chars=800, invisible_ratio=1.0, replacement_ratio=0.5)) == "FULL_PAGE"


def test_an_existing_ocr_layer_is_tested_before_image_coverage() -> None:
    """A scan with a searchable layer is 100% image *and* already read. Testing coverage first
    re-OCRs every already-processed scan in the corpus, at full price, adding error."""
    assert needs_ocr(_profile(chars=800, image_coverage=1.0, invisible_ratio=0.95)) is None


def test_an_invisible_layer_on_a_page_with_no_text_does_not_skip() -> None:
    """`chars > 0` guards step 2. A blank page has `invisible_ratio` 0.0 by construction, but
    the guard is what stops a future ratio convention from turning "no text" into "all of the
    (zero) text is invisible" and skipping every scan."""
    assert needs_ocr(_profile(chars=0, image_coverage=1.0, invisible_ratio=1.0)) == "FULL_PAGE"


def test_a_full_page_image_beats_the_digital_character_floor() -> None:
    """A scan with a caption underneath it: 120 real characters and a full-page raster. Testing
    the character floor first skips the scan and indexes the caption."""
    assert (
        needs_ocr(_profile(chars=MIN_DIGITAL_CHARS + 20, image_coverage=IMAGE_COVERAGE_FULL))
        == "FULL_PAGE"
    )


def test_a_born_digital_page_is_skipped() -> None:
    assert needs_ocr(_profile(chars=MIN_DIGITAL_CHARS, image_coverage=0.3)) is None
    assert needs_ocr(_profile(chars=MIN_DIGITAL_CHARS - 1, image_coverage=0.3)) is not None


def test_a_blank_or_vector_only_page_is_never_rasterized() -> None:
    assert needs_ocr(_profile(image_coverage=IMAGE_COVERAGE_BLANK - 0.001)) is None
    assert needs_ocr(_profile(image_coverage=IMAGE_COVERAGE_BLANK)) == "PDF_AWARE_LAYOUT_REGIONS"


def test_a_mixed_page_gets_the_region_limited_mode() -> None:
    """Some text, some figures: OCR the bitmaps and leave the digital text alone."""
    assert needs_ocr(_profile(chars=40, image_coverage=0.3)) == "PDF_AWARE_LAYOUT_REGIONS"


@pytest.mark.parametrize(
    ("name", "profile", "expected"),
    [
        ("mojibake over a scan", _profile(chars=900, replacement_ratio=0.4), "FULL_PAGE"),
        (
            "already OCRed scan",
            _profile(chars=900, image_coverage=1.0, invisible_ratio=1.0),
            None,
        ),
        ("bare scan", _profile(image_coverage=0.98), "FULL_PAGE"),
        ("born digital", _profile(chars=4_000), None),
        ("blank", _profile(), None),
        ("figure and prose", _profile(chars=30, image_coverage=0.4), "PDF_AWARE_LAYOUT_REGIONS"),
    ],
)
def test_the_six_page_shapes(name: str, profile: PageProfile, expected: str | None) -> None:
    assert needs_ocr(profile) == expected, name


def test_the_thresholds_are_boundaries_not_approximations() -> None:
    """Each cut is written with the comparison its docstring states — "above", "at or above",
    "at least", "below". An off-by-one on any of them moves a whole class of page."""
    # "above" REPLACEMENT_RATIO_MAX: exactly at it is not mojibake.
    assert needs_ocr(_profile(chars=900, replacement_ratio=REPLACEMENT_RATIO_MAX)) is None
    assert needs_ocr(_profile(chars=900, replacement_ratio=REPLACEMENT_RATIO_MAX + 0.01)) == (
        "FULL_PAGE"
    )
    # "mostly invisible": exactly at INVISIBLE_RATIO_MAX the layer check does not fire, so a
    # full-page scan underneath it is still OCRed. One point above it, the page is skipped.
    scan = _profile(chars=900, image_coverage=1.0)
    assert needs_ocr(replace(scan, invisible_ratio=INVISIBLE_RATIO_MAX)) == "FULL_PAGE"
    assert needs_ocr(replace(scan, invisible_ratio=INVISIBLE_RATIO_MAX + 0.01)) is None
    # "at or above" IMAGE_COVERAGE_FULL, and "at least" MIN_DIGITAL_CHARS.
    assert needs_ocr(_profile(image_coverage=IMAGE_COVERAGE_FULL)) == "FULL_PAGE"
    # Held above IMAGE_COVERAGE_BLANK so the blank-page cut below cannot answer for this one.
    assert needs_ocr(_profile(chars=MIN_DIGITAL_CHARS, image_coverage=0.3)) is None
    assert needs_ocr(_profile(chars=MIN_DIGITAL_CHARS - 1, image_coverage=0.3)) == (
        "PDF_AWARE_LAYOUT_REGIONS"
    )


# ── the escalation ladder ────────────────────────────────────────────────────


def test_an_empty_region_limited_run_escalates_to_full_page() -> None:
    """The region-limited mode keeps only layout clusters overlapping a bitmap or overlapping no
    PDF cell. A page with a thin junk text layer has its clusters eliminated and never reaches
    the engine — an empty result with no error, which is why it needs an explicit retry."""
    assert escalate_after_empty("PDF_AWARE_LAYOUT_REGIONS") == "FULL_PAGE"


def test_an_empty_full_page_run_does_not_escalate() -> None:
    """There is nothing above full page. Escalating here re-sends the identical request and
    spends the document's whole timeout budget on one blank page."""
    assert escalate_after_empty("FULL_PAGE") is None


def test_the_ladder_terminates_from_any_starting_mode() -> None:
    for start in ("FULL_PAGE", "PDF_AWARE_LAYOUT_REGIONS"):
        mode: str | None = start
        steps = 0
        while mode is not None:
            mode = escalate_after_empty(mode)  # type: ignore[arg-type]
            steps += 1
            assert steps <= 3, f"escalation from {start} does not terminate"
