"""`ocr_cfg_version` — the string that makes an OCR retune reach a document.

The spec has a hole here and this string is what fills it: §13.3's ingest-key list omits OCR
entirely while §13.4 makes an OCR change a version trigger. Left as written, retuning a
threshold leaves the content hash unchanged, the resubmission dedupes against the completed run,
the admin sees "already processed", and the new setting never touches a page.

Two things in this stage cannot be seen from the option constants alone and are tested for
specifically:

* **The engine and the language list are arguments, not constants.** The engine is
  admin-selectable and the language route is per-corpus — single-script tenants to RapidOCR,
  mixed-script to tesserocr, whose one genuine advantage is several scripts in one pass. Both
  must be inside the string anyway.
* **The model revision distinguishes PP-OCR v5 from v6, which are different models rather than
  versions of one.** The engine name is `"rapidocr"` in both cases, so without the pinned
  revision folded in, a model swap is invisible to the config version.
"""

from __future__ import annotations

from typing import Final

import pytest

from app.core.errors import ErrorClass, KbError
from app.ingestion.ocr import guarded
from app.ingestion.ocr.guarded import (
    OCR_CFG_EXCLUDED,
    OCR_CFG_INPUTS,
    OCR_CFG_SCHEME,
    OCR_MODEL_PIN,
    ocr_cfg_version,
)

_META: Final[frozenset[str]] = frozenset(
    {"OCR_CFG_EXCLUDED", "OCR_CFG_INPUTS", "OCR_CFG_SCHEME", "OCR_MODEL_PIN"}
)


def _option_constants() -> set[str]:
    return {
        name
        for name in guarded.__all__
        if name.isupper() and name not in _META and not name.startswith("_")
    }


def _version(engine: str = "rapidocr", languages: tuple[str, ...] = ("en",)) -> str:
    return ocr_cfg_version(engine=engine, languages=languages)


# ── coverage ─────────────────────────────────────────────────────────────────


def test_every_tunable_is_either_folded_in_or_excluded_with_a_reason() -> None:
    declared = set(OCR_CFG_INPUTS) | set(OCR_CFG_EXCLUDED)
    options = _option_constants()
    assert declared == options, (
        f"unclassified: {sorted(options - declared)}; "
        f"named but not a module constant: {sorted(declared - options)}"
    )
    assert not set(OCR_CFG_INPUTS) & set(OCR_CFG_EXCLUDED)
    assert all(reason.strip() for reason in OCR_CFG_EXCLUDED.values())


def test_all_three_warn_thresholds_and_the_ink_band_are_inside() -> None:
    """ "Three" is load-bearing and used to be "both". Splitting the confidence signal into a
    per-cell floor plus a character mass added a tunable field, and `INK_FRACTION_BAND` decides
    whether a page reports a coverage number **at all** — a page that returns NaN warns
    `ocr_coverage_unmeasurable` instead of a fabricated low number, so moving the band moves
    which pages carry which warning."""
    for name in (
        "CELL_TRUST_FLOOR",
        "LOW_CONF_MASS_WARN",
        "COVERAGE_WARN",
        "INK_FRACTION_BAND",
    ):
        assert name in OCR_CFG_INPUTS


def test_the_needs_ocr_cuts_are_inside() -> None:
    """These decide whether a page is OCRed at all, and the asymmetry is why they matter most: a
    false "needs OCR" costs 30 seconds, a false "does not" loses the page from the index
    permanently. Retuning one and not re-ingesting keeps the loss."""
    for name in (
        "REPLACEMENT_RATIO_MAX",
        "INVISIBLE_RATIO_MAX",
        "IMAGE_COVERAGE_FULL",
        "IMAGE_COVERAGE_BLANK",
        "MIN_DIGITAL_CHARS",
        "MARGIN_RATIO",
    ):
        assert name in OCR_CFG_INPUTS


# ── the ocr-pipeline definition of done, literally ───────────────────────────


def test_changing_only_the_render_scale_mints_a_new_version(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Docling's default scale is 3.0 = 216 dpi, under Tesseract's documented 300 floor, and
    "OCR quality is mediocre everywhere and nobody can find the bug" is what that produces.
    Fixing it has to re-OCR the pages read at the wrong resolution."""
    before = _version()
    monkeypatch.setattr(guarded, "OCR_SCALE", 3.0)
    assert _version() != before


def test_changing_only_the_cell_trust_floor_mints_a_new_version(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    before = _version()
    monkeypatch.setattr(guarded, "CELL_TRUST_FLOOR", 0.75)
    assert _version() != before


@pytest.mark.parametrize(
    ("name", "value"),
    [
        pytest.param(
            "PREPROCESSING", {"deskew": False, "binarize": False, "denoise": False}, id="deskew-off"
        ),
        pytest.param("COVERAGE_WARN", 0.25, id="coverage-warn"),
        pytest.param("LOW_CONF_MASS_WARN", 0.4, id="mass-warn"),
        pytest.param("INK_FRACTION_BAND", (0.001, 0.10), id="ink-band"),
        pytest.param("MIN_DIGITAL_CHARS", 50, id="digital-chars"),
        pytest.param("OCR_DPI", 400, id="dpi"),
    ],
)
def test_changing_one_tunable_mints_a_new_version(
    monkeypatch: pytest.MonkeyPatch, name: str, value: object
) -> None:
    before = _version()
    monkeypatch.setattr(guarded, name, value)
    assert _version() != before, f"{name} changed and the config version did not"


@pytest.mark.parametrize("name", sorted(OCR_CFG_EXCLUDED))
def test_an_excluded_constant_does_not_re_ocr_the_platform(
    monkeypatch: pytest.MonkeyPatch, name: str
) -> None:
    """The decode caps are the interesting pair. Tightening `MAX_PIXELS` decides whether an
    image is *refused*, and a refusal is not repaired by re-ingesting — the same bytes are
    refused again — so a security tightening must not re-OCR every scan in the platform.
    Loosening one is an explicit reprocess through `force_nonce`."""
    before = _version()
    current = getattr(guarded, name)
    replacement: object
    if name == "OCR_ENGINE_CLASSES":
        # Its KEYS are the closed set of selectable engines and are validated; only the dotted
        # paths they resolve to are excluded, which is the reason recorded on the exclusion —
        # renaming an upstream class is not a change to how a page is read.
        replacement = {engine: f"{path}Renamed" for engine, path in current.items()}
    elif isinstance(current, dict):
        replacement = {"changed": "value"}
    elif isinstance(current, tuple):
        replacement = ("changed",)
    elif isinstance(current, int):
        replacement = current + 1
    else:
        replacement = f"{current}-changed"
    monkeypatch.setattr(guarded, name, replacement)
    assert _version() == before


# ── engine, languages, and the pinned weights ────────────────────────────────


def test_the_engine_is_inside_the_string_and_readable() -> None:
    """A laptop resolving to ocrmac and the Linux worker resolving to RapidOCR emit different
    text for the same scan; with the engine outside this string the config version is identical
    and nothing ever reprocesses. The label is readable so a `source_versions` row says which
    engine read the page."""
    assert _version(engine="rapidocr").startswith(f"{OCR_CFG_SCHEME}:rapidocr:")
    assert _version(engine="tesseract").startswith(f"{OCR_CFG_SCHEME}:tesseract:")
    assert _version(engine="rapidocr") != _version(engine="tesseract")


def test_the_language_list_is_inside_but_its_order_is_not() -> None:
    """`["eng", "hin"]` and `["hin", "eng"]` are one Tesseract configuration, and re-OCRing a
    corpus because somebody reordered a list is a cost with no cause. Adding a language is a
    different configuration and does re-version."""
    assert _version(languages=("eng", "hin")) == _version(languages=("hin", "eng"))
    assert _version(languages=("eng",)) != _version(languages=("eng", "hin"))


def test_a_moved_ocr_model_revision_mints_a_new_version(monkeypatch: pytest.MonkeyPatch) -> None:
    """PP-OCR v5 and v6 are different models under one engine name. Without the pinned revision
    folded in, swapping them is invisible to every string in the system."""
    from app.ingestion.model_pins import ModelPin

    before = _version()
    moved = ModelPin(
        id=OCR_MODEL_PIN,
        repo="RapidAI/RapidOCR",
        repo_host="modelscope",
        revision="1" * 40,
        license="Apache-2.0",
        format="onnx",
        shipped=True,
    )
    monkeypatch.setattr(guarded, "pin", lambda _name: moved)
    assert _version() != before


# ── refusals ─────────────────────────────────────────────────────────────────


def test_an_unknown_engine_raises_rather_than_hashing_cleanly() -> None:
    """An unrecognized name hashes perfectly well and yields a version string for a
    configuration that cannot run. The engine is pinned by class, so the set is closed."""
    with pytest.raises(KbError) as caught:
        _version(engine="easyocr")
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY


def test_an_empty_language_list_raises() -> None:
    """`OcrAutoOptions` defaults `lang=[]` and lets the engine's own default win silently — a
    different model reading the page than the one this string records."""
    with pytest.raises(KbError, match="language"):
        _version(languages=())


def test_it_is_deterministic() -> None:
    assert _version() == _version()


def test_the_current_identity_is_pinned_so_a_reingest_is_never_accidental() -> None:
    """A literal, and it is meant to be edited — but only deliberately, in the same commit as
    the tunable that moved it.

    Every other test here proves the string moves when it *should*. None of them can fail when
    it moves and should not have, which is the more expensive direction: a changed identity
    re-OCRs every document in the platform at whatever the admin's engine costs, and the first
    sign is a queue that will not drain.

    **The case this was written for landed on 2026-08-12.** `assess` gained a third arm,
    `ocr_text_unplaced`, and that arm has no threshold — it is zero-versus-nonzero, so nothing
    entered `OCR_CFG_INPUTS` and this digest did not move. A version of that change that had
    reached for a tunable would have been caught here rather than in production.
    """
    assert _version() == "ocr/v1:rapidocr:9a5e07d644c6"
