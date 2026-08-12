"""The only place an untrusted image or page raster reaches an OCR engine.

Four things live here, and each one is a decision that reads like a default until it bites.

**The engine is pinned by class.** Docling's auto option picks an engine by *import probing* —
ocrmac, then Nemotron, then RapidOCR/onnxruntime, then EasyOCR, then RapidOCR/torch, then
none, logged at WARNING with no exception, after which pages pass through untouched. Two
distinct failures follow. A developer laptop and the Linux worker emit different text for the
same scan while the OCR config version string is identical, so nothing ever reprocesses; and
an image missing every backend publishes every scanned source as ready with zero chunks. The
resolved engine class is asserted at worker startup, and a mismatch fails the *container* —
failing the document would hide a broken image behind a stream of per-document warnings.

**DPI is the one knob with an official number behind it.** Tesseract documents a 300 dpi
floor, and the governing variable underneath it is x-height in pixels: ~20 px target, ~10 px
hard floor, degradation again above ~30 px. Docling's render scale is a multiplier on 72 dpi
and defaults to 3.0 = 216 dpi, under the floor, which is why "OCR quality is mediocre
everywhere and nobody can find the bug" is a real bug rather than a mood. Raising it is not
free: the pypdfium backend renders at scale x 1.5 before downsampling, so peak allocation is
2.25x the pixels requested — raise the scale and the worker memory limit in the same change.

**Confidence is normalized at this adapter and never consumed raw.** One float field carries
three different units: tesserocr writes Tesseract's 0–100 scale, RapidOCR and EasyOCR write
0–1, and digital (non-OCR) cells default to 1.0. Switching engine to Tesseract therefore makes
every low-confidence warning disappear — ours *and* Docling's own, since every page then grades
`excellent`, including a blank one.

**The page-mean confidence is not a weak signal, it is an unreachable one, and it was measured.**
A sweep across five independent degradation axes (ink density, defocus blur, render resolution,
JPEG quantisation, additive noise) on two page shapes found the character-weighted page mean
never falls below ~0.72 on any page still bearing text, and stays above 0.81 on every page whose
degradation is realistic. It *rises* again as a page degrades further, because the surviving
cells are an increasingly selective subset. A threshold at 0.75 under that floor is a warning
that never fires; a threshold just above it fires on everything or nothing depending on the
engine build.

Three places inside PP-OCR can silently drop text, and **the loss is essentially all in the
first one.** Counted by instrumenting the pipeline's own stages — not by calling them
separately, which feeds the detector a different image than `__call__` does and inflates its
count badly:

1. **The DB detector proposes no region at all** for text below its own probability threshold.
   This is where the text goes. Detected-box counts collapse with degradation while nothing
   downstream removes any of them — on one page shape 13 (clean) → 15 → 6 → 3 → 1 → 0 across
   the ink sweep, and on the eval corpus's scanned fixture 5 on the degraded page against 10,
   10 and 4 on the clean ones.
2. `RapidOCR.build_final_output` drops every box whose recognition came back **empty**
   (``valid_ids = [i for i, v in enumerate(rec_res.txts) if v.strip()]``), unconditionally,
   before any score threshold and with no option to disable it. Worth knowing it exists; it is
   **not** what is happening here. Measured, it dropped a box on 1 of 14 pages, and there it
   removed the last surviving box of a page already reduced to one.
3. `Global.text_score` (0.5) — at most one box on 2 of 14 pages. Re-running the whole sweep at
   `text_score=0.0` recovers a measured 0.003 of coverage.

So the survivors are chosen by the *detector*, and a region the detector proposes is a region
with clear enough glyph evidence that the recogniser then reads it confidently. A recognition
score compounds this: it is a mean over the characters that *decoded*, so a partial decode
scores on its confident survivors. Every statistic over recogniser confidence — mean, minimum,
any percentile — is therefore conditioned on a selection that happened before recognition ever
ran, and no post-hoc statistic can undo it. **Degradation on this engine is expressed as
absence, not as low confidence.**

**So both signals were redefined, and there are now three.** They detect different failures
rather than being three statistics of the same one — the third was added 2026-08-12 and is the
only one that can fire while the other two are silent *and correct*:

* `ocr_low_coverage` — *how much of the page did the engine fail to read at all*. Measured as
  ink-in-box: foreground ink inside OCR cell rectangles over all foreground ink on the page.
  This is the signal that survives, and it moves roughly three times faster than any confidence
  statistic. It needs no ground truth, which the previous definition quietly did.
* `ocr_low_confidence` — *of the text it did read, how much is untrustworthy*. Measured as
  character **mass** below a per-cell trust floor, never a page mean. Mass is what makes it
  work: clean pages routinely carry a junk cell (a fold line, a page border, a speck) reading
  0.63–0.73 — not all of them, but over half of those measured — so "any cell below X" fires on
  ordinary documents, while a mean lets a handful of good cells drown the bad. Mass asks the
  question we actually care about — what share of the text
  about to enter the index came from cells we do not trust.
* `ocr_text_unplaced` — *the engine read the page and none of it became an element*. Neither
  statistic above can see this: at blur >= 3.0 the layout model classifies the whole page as a
  picture, so cell recall stays 1.000 and confidence and coverage are both **high and honest**
  while element recall is 0.000. It is measured by the caller, not here — it needs the layout
  model's output, which this module never sees — and it is the one signal with no threshold to
  tune, so it is also the one that is not in `OCR_CFG_INPUTS`. See `assess`.

**Every decode runs inside an envelope.** A decoder allow-list, pixel *and* frame caps, and a
hard task time limit. A soft time limit raises from a Python signal handler that a thread
inside a Pillow, Leptonica or ONNX Runtime C loop never returns to receive, so it is not a
control here — only the hard limit is. The worker has no network namespace, which rules out
any engine that makes a call, and means every language pack must be baked into the image.

OCR output is untrusted source text exactly like parsed text. It is not laundered by having
passed through a model: invisible-Unicode stripping, prompt delimiting and the full chunk
metadata schema all still apply downstream.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from typing import Any, Final, Literal, Protocol, runtime_checkable

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.cfg_version import compose
from app.ingestion.model_pins import pin

__all__ = [
    "BANNED_OCR_OPTION_CLASSES",
    "CELL_TRUST_FLOOR",
    "COVERAGE_WARN",
    "DECODERS",
    "DEFAULT_ENGINE",
    "IMAGE_COVERAGE_BLANK",
    "IMAGE_COVERAGE_FULL",
    "INK_FRACTION_BAND",
    "INVISIBLE_RATIO_MAX",
    "LOW_CONF_MASS_WARN",
    "MARGIN_RATIO",
    "MAX_FRAMES",
    "MAX_PIXELS",
    "MIN_DIGITAL_CHARS",
    "OCR_CFG_EXCLUDED",
    "OCR_CFG_INPUTS",
    "OCR_CFG_SCHEME",
    "OCR_DPI",
    "OCR_ENGINE_CLASSES",
    "OCR_MODEL_PIN",
    "OCR_SCALE",
    "PREPROCESSING",
    "REPLACEMENT_RATIO_MAX",
    "TESSERACT_DPI_BAND",
    "OcrCell",
    "OcrModeName",
    "OcrRejected",
    "PageProfile",
    "assess",
    "escalate_after_empty",
    "low_confidence_mass",
    "needs_ocr",
    "normalize_confidence",
    "ocr_cfg_version",
    "open_guarded",
    "page_confidence",
    "page_coverage",
    "profile_page",
]

#: Pinned **by class**, by dotted path, so the assertion at startup compares a resolved class
#: against a name rather than against a config string that says what we hoped for. RapidOCR
#: (PP-OCR, ONNX) is the default on accuracy, licence, footprint and latency; tesserocr earns
#: its place on one capability PP-OCR lacks — several scripts recognized in one pass. Docling's
#: RapidOCR adapter takes `lang[0]` and logs the rest away, so a Hindi/English page loses a
#: script with no error: route mixed-script tenants to tesserocr.
OCR_ENGINE_CLASSES: Final[dict[str, str]] = {
    "rapidocr": "docling.datamodel.pipeline_options.RapidOcrOptions",
    "tesseract": "docling.datamodel.pipeline_options.TesseractOcrOptions",
}

#: Neither may reach the converter, and a test proves it. The auto option resolves per host
#: (see the module docstring); the KServe option is a network call from a container that has no
#: network, which fails on the first scanned page or hangs until the hard limit.
BANNED_OCR_OPTION_CLASSES: Final[tuple[str, ...]] = ("OcrAutoOptions", "KserveV2OcrOptions")

DEFAULT_ENGINE: Final[str] = "rapidocr"

#: The single highest-value control in this module. Passed to every open, so the GD, BDF, PCF,
#: FITS and EPS decoder CVE paths are never reachable from a tenant upload.
DECODERS: Final[tuple[str, ...]] = ("JPEG", "PNG", "WEBP", "GIF", "TIFF", "BMP")

#: Below Pillow's own warn band, so *our* raise fires first and is an error rather than a
#: warning. A warning is not a defense; the decompression-bomb warning is also promoted to an
#: exception at worker start.
MAX_PIXELS: Final[int] = 40_000_000

#: The pixel cap never consults frame count, so a 50 000-frame GIF passes it trivially.
MAX_FRAMES: Final[int] = 8

OCR_DPI: Final[int] = 300

#: Docling's render scale is a multiplier on 72 dpi. 300/72 = 4.1667, against a 3.0 default
#: that yields 216 dpi — under Tesseract's documented floor.
OCR_SCALE: Final[float] = OCR_DPI / 72

#: Tesseract forces any raster outside this band — or carrying no DPI metadata at all — to
#: 70 dpi with only a warning line, and both its thresholding methods size their windows from
#: that number. The binarizer is then tuned for a page four times smaller than reality and
#: noise removal eats small glyphs. Stamp a real DPI on every raster handed to it.
TESSERACT_DPI_BAND: Final[tuple[int, int]] = (70, 2400)

#: Deskew is the one preprocessing step with a measured win across engines. Binarization is a
#: Tesseract *parameter*, not a step, and actively hurts the neural engines — PP-OCR's detector
#: *is* a learned binarization over three channels, and PaddleOCR's own preprocessing pipeline
#: contains no binarization and no denoising at all. Denoising is opt-in per source and applies
#: only to the raster fed to OCR, never to the stored artifact.
PREPROCESSING: Final[dict[str, bool]] = {
    "deskew": True,
    "binarize": False,
    "denoise": False,
}

#: Only the centre 75% x 75% of a page counts as real text, so a page whose only text is a
#: running header is still judged to need OCR.
MARGIN_RATIO: Final[float] = 0.125

#: Per-cell trust floor. A cell scoring below this is not *wrong*, it is *unverified*: its
#: characters count toward `low_confidence_mass` but the text is still indexed. This is a floor
#: on the per-cell distribution, which spans roughly 0.63–1.00 on clean and degraded pages
#: alike — deliberately NOT a floor on the page mean, which saturates near 0.81 and cannot be
#: thresholded at all (module docstring).
CELL_TRUST_FLOOR: Final[float] = 0.80

#: Warn when more than this share of a page's OCR characters sit below `CELL_TRUST_FLOOR`.
#: Measured separation on rapidocr 3.9.2 / PP-OCRv6, character-weighted, across two page shapes
#: and five degradation axes: the worst clean or acceptable page reaches 0.168, the best
#: genuinely-degraded page reaches 0.616. 0.30 sits in that gap with roughly 1.8x margin below
#: and 2.1x above, so an engine build that reads somewhat better or worse does not flip it.
LOW_CONF_MASS_WARN: Final[float] = 0.30

#: Warn below this ink-in-box coverage. **This is a catastrophe floor, not a quality gate**, and
#: the difference is the whole of what follows.
#:
#: RE-DERIVED 2026-08-12 THROUGH `parse_document`, WHICH IS THE MEASUREMENT THAT REACHES IT.
#: --------------------------------------------------------------------------------------
#: Corpus: `samples/corpus/documents/scanned/scanned-po-88214.pdf`, its three clean pages
#: rasterized at 300 dpi and put through 24 degradation variants each along five axes (ink
#: density, Gaussian blur, JPEG quantisation, additive noise, skew angle), plus the four pages
#: as shipped. 74 page-parses in `knowledgebot/ai-service:dev`, rapidocr 3.9.2 / PP-OCRv6,
#: docling 2.118.0. Every number below is `confidence["pages"][n]["ocr_coverage"]` out of a real
#: `parse_document`, labelled by word recall of the page's OCR cells against the same page
#: undegraded — so the threshold is calibrated against "did we lose the text", not against
#: itself.
#:
#:     population                                  n    coverage range
#:     pages that lost nothing (recall >= 0.90)   59    0.4638 – 1.0000
#:     pages that lost half or more (recall<=0.5) 15    0.2053 – 0.4811
#:
#: **The two populations overlap**, so there is no gap of the kind `CELL_TRUST_FLOOR` and
#: `LOW_CONF_MASS_WARN` sit in, and no threshold separates them. The best that exists is
#: 0.44–0.46: 12 of 15 degraded pages caught with no false warning — chosen 3% below a page that
#: lost nothing, which is a coincidence rather than a margin.
#:
#: SKEW IS WHAT SETS THE CEILING, AND IT IS MEASURED, NOT FEARED.
#: -------------------------------------------------------------
#: Rotation alone, with the engine reading the page **perfectly** (cell recall 1.000 at every
#: angle), on three page shapes:
#:
#:     skew     0.5°     1°      2°      3°      5°      7°
#:     dense  1.0000  0.9825  0.7636  0.6075  0.3909  0.4849
#:     prose  0.9949  0.9857  0.7920  0.5705  0.4969  0.5425
#:     sparse 1.0000  1.0000  0.9375  0.7696  0.5613  0.4460
#:
#: A 5° crooked scan that lost not one character reads **0.3909**. So every threshold at 0.40 or
#: above false-warns on an ordinary crooked page, and the 0.44–0.46 optimum above is unusable.
#: The mechanism is in `page_coverage`: the OCR rectangles are axis-aligned and the ink is not.
#:
#: 0.30 SURVIVES THE RE-DERIVATION, WITH ITS STATED PROVENANCE CORRECTED.
#: ---------------------------------------------------------------------
#: It sits 1.30x below the worst clean-but-crooked page (0.3909) and catches the pages where the
#: detector has essentially stopped proposing regions (0.2053, 0.2584, 0.2843). The old note
#: here claimed a 4.4x gap between a 0.207 degraded page and a 0.911 clean one; **that pair was
#: measured somewhere else** — offline, against a standalone engine call on the 300 dpi raster.
#: Through the pipeline the same shipped page reads **0.5054**, because docling's own invocation
#: recovers 11 OCR cells on it where the offline measurement recovered 5. Nothing about the
#: threshold was wrong; the sentence describing it named a measurement the code never sees.
#:
#: So this arm does **not** fire on the corpus's degraded page and must not be tuned until it
#: does — `ocr_low_confidence` is what catches that page (mass 0.9276 against a 0.30 floor), and
#: the two signals were split precisely so that each covers what the other cannot. Raising this
#: line to reach one fixture page would warn on every crooked scan in the platform.
#:
#: Changing this value changes `ocr_cfg_version` (it is in `OCR_CFG_INPUTS`) and therefore
#: re-OCRs and re-versions **every scanned source in the platform**. That is a real cost on the
#: scale of a corpus, and it is a second reason not to move a number for a 3% gain.
COVERAGE_WARN: Final[float] = 0.30

#: Plausibility band on the foreground-ink fraction of a page raster, guarding `page_coverage`.
#: Real text pages measured 0.0027–0.065 across every degradation tested. A page above the upper
#: bound is speckle-dominated: Otsu then binarises noise as ink, most of that "ink" falls outside
#: every text box, and coverage collapses to a false warning (measured 0.26 against a true 0.83
#: on a page the engine in fact read correctly). Out of band means the coverage signal is not
#: trustworthy on this page and says so, rather than reporting a number that looks like evidence.
INK_FRACTION_BAND: Final[tuple[float, float]] = (0.0005, 0.12)

#: `needs_ocr` cuts. The image-coverage pair are Docling's own former thresholds; the character
#: and mojibake cuts are **ours** — no project publishes one — so tune them against the eval
#: corpus. The asymmetry is deliberate and should stay: a false "needs OCR" costs 30 s, a false
#: "does not" loses the page from the index permanently.
REPLACEMENT_RATIO_MAX: Final[float] = 0.10
INVISIBLE_RATIO_MAX: Final[float] = 0.90
IMAGE_COVERAGE_FULL: Final[float] = 0.75
IMAGE_COVERAGE_BLANK: Final[float] = 0.05
MIN_DIGITAL_CHARS: Final[int] = 100

#: The two modes this classifier may return. Kept as names rather than the Docling enum so this
#: module carries no parser import; `parsing/converter.py` maps the name onto `OcrMode`.
OcrModeName = Literal["FULL_PAGE", "PDF_AWARE_LAYOUT_REGIONS"]

#: Bumped only to force a global re-OCR. See `app/ingestion/cfg_version.py`.
OCR_CFG_SCHEME: Final[str] = "ocr/v1"

#: The weights this stage runs, by `models.manifest.toml` id. **PP-OCR v5 and v6 are different
#: models**, not versions of one, so the pinned revision is what distinguishes them — the engine
#: name alone cannot, and the manifest row is where the PP-OCR version is recorded.
OCR_MODEL_PIN: Final[str] = "rapidocr-onnx"

#: The tunables folded into `ocr_cfg_version`, **as data**, so a test can assert none was
#: dropped. The three warn thresholds are all here — it used to be two, and splitting the
#: confidence signal into a per-cell floor plus a character mass added a tunable field; a
#: tunable field outside this tuple is a retune that never reaches a document.
#: `INK_FRACTION_BAND` belongs here too because it decides whether a page reports a coverage
#: number *at all*.
OCR_CFG_INPUTS: Final[tuple[str, ...]] = (
    "CELL_TRUST_FLOOR",
    "COVERAGE_WARN",
    "IMAGE_COVERAGE_BLANK",
    "IMAGE_COVERAGE_FULL",
    "INK_FRACTION_BAND",
    "INVISIBLE_RATIO_MAX",
    "LOW_CONF_MASS_WARN",
    "MARGIN_RATIO",
    "MIN_DIGITAL_CHARS",
    "OCR_DPI",
    "OCR_SCALE",
    "PREPROCESSING",
    "REPLACEMENT_RATIO_MAX",
    "TESSERACT_DPI_BAND",
)

#: Left out, each with its reason on the record. The decode caps are the interesting pair: they
#: decide whether an image is *rejected*, not how it is read, and a rejection is not repaired by
#: re-ingesting under a new version — the same bytes are refused again. Loosening a cap is
#: therefore an explicit reprocess (`force_nonce`), not an automatic one, and putting them here
#: keeps a security tightening from re-OCRing every scan in the platform.
OCR_CFG_EXCLUDED: Final[Mapping[str, str]] = {
    "BANNED_OCR_OPTION_CLASSES": "a guard rail, not a setting; no configuration may select one",
    "DECODERS": "decides whether an image is refused, not how it is read — see above",
    "DEFAULT_ENGINE": (
        "the fallback when no engine is configured; the engine actually used is an argument to "
        "`ocr_cfg_version` and is inside the string"
    ),
    "MAX_FRAMES": "decode cap — see above",
    "MAX_PIXELS": "decode cap — see above",
    "OCR_ENGINE_CLASSES": (
        "the dotted paths the engine names resolve to; renaming an upstream class is not a "
        "change to how a page is read"
    ),
}


class OcrRejected(KbError):
    """A tenant image that will never decode safely — oversized, too many frames, or an
    unlisted decoder.

    `parsing`, and permanent: the next attempt decodes the identical bytes, so retrying is
    only a way to spend the budget three times before failing anyway.
    """

    __slots__ = ()

    def __init__(self, message: str) -> None:
        super().__init__(ErrorClass.PARSING, message, retryable=False)


@runtime_checkable
class OcrCell(Protocol):
    """The three fields of `docling_core.types.doc.page.TextCell` these statistics read.

    A Protocol rather than the import, so this module keeps its property of carrying no parser
    dependency — and so a test can exercise the thresholds on measured numbers without
    constructing Docling objects or running an engine.
    """

    @property
    def text(self) -> str: ...

    @property
    def confidence(self) -> float: ...

    @property
    def from_ocr(self) -> bool: ...


@dataclass(frozen=True, slots=True)
class PageProfile:
    """What the page classifier measures, before any engine runs."""

    #: Non-whitespace characters inside the interior box only.
    chars: int
    #: Union of image bounding boxes over page area.
    image_coverage: float
    #: Share of characters drawn in text render mode 3 — i.e. an OCR layer already exists.
    invisible_ratio: float
    #: Share of U+FFFD — a broken or absent ToUnicode map, so the text layer is unusable.
    replacement_ratio: float


def open_guarded(fp: Any) -> Any:
    """Open a tenant image with the decoder allow-list and the decode caps.

    The formats argument is not optional and not a default: Pillow will otherwise sniff and
    dispatch to any registered decoder, which is the whole attack surface.

    The caller stamps a real DPI on the returned image before handing it to Tesseract — see
    `TESSERACT_DPI_BAND`.

    **The pixel cap is checked before anything is decoded, and that ordering is the cap.**
    `Image.open` parses a header and returns a lazy object; `size` is read off that header, so a
    declared 12 000 x 12 000 costs nothing until someone calls `load()` or `convert()`. Check
    after a decode and the allocation the cap exists to prevent has already happened — the raise
    then reports a bomb that already went off.

    `MAX_PIXELS` sits deliberately below Pillow's own `MAX_IMAGE_PIXELS`, whose lower band is
    only a *warning*; ours is the raise. The two bomb signals Pillow can still produce are
    translated rather than allowed to escape: `DecompressionBombError` above its hard band, and
    `DecompressionBombWarning` in between once worker startup promotes it to an exception. Both
    are the same event this function names — a tenant image that will never decode safely — and
    neither is an `OSError`, so without this they would leave as unclassified exceptions.
    """
    # Deferred for the reason `page_coverage` records: this module's constants and cell-side
    # statistics stay importable in an image whose imaging stack is broken, because the startup
    # assertion that reports the breakage reads them.
    from PIL import Image, UnidentifiedImageError

    try:
        image = Image.open(fp, formats=list(DECODERS))
    except (UnidentifiedImageError, OSError) as exc:
        raise OcrRejected(
            f"the image did not decode as one of the allowed formats {DECODERS}: {exc}"
        ) from exc
    except (Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
        raise OcrRejected(f"the image header declares a decompression bomb: {exc}") from exc
    except Exception as exc:
        # The measured backstop. `Image.open` is *documented* to raise `UnidentifiedImageError`
        # or an `OSError`, and it converts a plugin's `SyntaxError` into the former — but only
        # that one. A fuzz over truncated and bit-flipped uploads in all six allowed formats
        # also produced `ValueError("Truncated IHDR chunk")` straight out of the PNG plugin.
        # Every one of these is the same event — a hostile file that will never decode — and
        # letting it leave untranslated files it as a defect in this service instead.
        raise OcrRejected(
            f"the image header could not be parsed ({type(exc).__name__}): {exc}"
        ) from exc

    width, height = image.size
    if width * height > MAX_PIXELS:
        raise OcrRejected(
            f"the image declares {width}x{height} = {width * height} pixels, above the "
            f"{MAX_PIXELS} cap; nothing has been decoded and nothing will be"
        )

    # Broad on purpose, and measured rather than defensive. Reading `n_frames` walks the file's
    # frame table, and on a corrupted one Pillow's plugins raise outside the `OSError` family: a
    # 1 800-sample fuzz over truncated and bit-flipped uploads in all six allowed formats
    # produced `IndexError` from the GIF scanner and `TypeError("Missing dimensions")` from the
    # TIFF one, while `Image.open` and `.size` stayed inside `OSError` throughout. An image
    # whose frame total cannot be read is one we cannot bound, so it is refused — never assumed
    # to hold a single frame, which is how a frame bomb would walk straight through this cap.
    try:
        frames = int(getattr(image, "n_frames", 1))
    except Exception as exc:
        raise OcrRejected(f"the image's frame table could not be read: {exc}") from exc
    if frames > MAX_FRAMES:
        raise OcrRejected(
            f"the image carries {frames} frames, above the {MAX_FRAMES} cap; the pixel cap "
            "never consults frame count, so a many-framed small image passes it trivially"
        )

    return image


#: The two bbox-log operations that paint a bitmap onto a page. `fill-imgmask` is a stencil
#: mask — a 1-bit image painted in the current fill colour — and it is how several scanner
#: drivers emit a black-and-white scan, so dropping it makes exactly the pages this classifier
#: exists for look like empty vector pages.
#:
#: Private, and deliberately not an `ocr_cfg_version` input: it is MuPDF's own vocabulary for
#: "an image was drawn", not a setting. Renaming an upstream operation is not a change to how a
#: page is read, which is the reason already on the record for `OCR_ENGINE_CLASSES`.
_IMAGE_PAINT_OPS: Final[frozenset[str]] = frozenset({"fill-image", "fill-imgmask"})

#: PDF text render mode (`Tr`). Its low two bits select the painting: 0 fill, 1 stroke, 2 both,
#: 3 neither; 4 and above add a clip to the same four. So "was this glyph painted at all" is
#: `mode % 4 != 3`, and mode 3 is exactly what an OCR engine writes over a scan.
_RENDER_MODE_INVISIBLE: Final[int] = 3


def _paint_mask() -> int:
    """`FZ_STEXT_FILLED | FZ_STEXT_STROKED`, read from MuPDF instead of written as a literal.

    **The literal `8 | 16` is a previous flag layout and is wrong against the pinned MuPDF.**
    Measured on pymupdf 1.28.0 / MuPDF 1.29: `FZ_STEXT_FILLED` is 16, `FZ_STEXT_STROKED` is 32,
    and **8 is `FZ_STEXT_BOLD`**. Frozen at `8 | 16` the invisibility test reads "neither bold
    nor filled", which is wrong in both directions at once: a stroked-only heading is counted
    as an invisible OCR layer, and a bold invisible layer is counted as visible text. Neither
    produces an error — one re-OCRs a born-digital page, the other skips a scan permanently.
    """
    from pymupdf import mupdf

    return int(mupdf.FZ_STEXT_FILLED | mupdf.FZ_STEXT_STROKED)


def _span_is_invisible(span: Mapping[str, Any], *, painted: int) -> bool:
    """Was this span's text painted, or is it an OCR layer hiding under a raster?

    Two spellings of one fact, because the two APIs that carry it disagree and which one is
    present is a pymupdf version question — the floor here is 1.26.7 and the resolved build is
    1.28.0. `char_flags` is the direct statement and wins when the build supplies it; the trace
    always carries the render mode, so that is the fallback rather than a second-best guess.

    Note the bitmask, not a chain: `not (flags & (FILLED | STROKED))`. Written `FILLED &
    STROKED` the mask is 0, every `&` against it is 0, and **every character on every page**
    grades invisible — which sends every scanned page down the "already has an OCR layer" branch
    and silently stops OCR platform-wide.
    """
    flags = span.get("char_flags")
    if flags is not None:
        return not (int(flags) & painted)
    return int(span["type"]) % 4 == _RENDER_MODE_INVISIBLE


def _union_area(
    rects: Sequence[tuple[float, float, float, float]],
    bounds: tuple[float, float, float, float],
) -> float:
    """Area of the **union** of axis-aligned rectangles, clipped to `bounds`.

    Summing rectangle areas is the obvious version and it is wrong in the way that matters
    here: two overlapping placements of one image — a logo drawn twice, a scan laid down in
    strips that abut with a hair of overlap — sum past the page area, so `image_coverage`
    exceeds 1.0 and trips `IMAGE_COVERAGE_FULL` on a page that is not full of image at all.

    Exact rather than a raster mask, and that is worth the eight extra lines: a mask needs a
    resolution, a resolution is a tunable, and a tunable that moves which pages get OCRed but
    sits outside `ocr_cfg_version` is a change that never reaches a document. Compressing the
    coordinates removes the parameter — the grid's cells are the rectangle edges themselves, so
    the answer is exact for any page size and any number of images.
    """
    import numpy as np

    page_x0, page_y0, page_x1, page_y1 = bounds
    clipped: list[tuple[float, float, float, float]] = []
    for x0, y0, x1, y1 in rects:
        left, top = max(x0, page_x0), max(y0, page_y0)
        right, bottom = min(x1, page_x1), min(y1, page_y1)
        if right > left and bottom > top:
            clipped.append((left, top, right, bottom))
    if not clipped:
        return 0.0

    xs = sorted({value for rect in clipped for value in (rect[0], rect[2])})
    ys = sorted({value for rect in clipped for value in (rect[1], rect[3])})
    x_index = {value: position for position, value in enumerate(xs)}
    y_index = {value: position for position, value in enumerate(ys)}
    covered = np.zeros((len(ys) - 1, len(xs) - 1), dtype=bool)
    for left, top, right, bottom in clipped:
        covered[y_index[top] : y_index[bottom], x_index[left] : x_index[right]] = True
    cell_areas = np.outer(np.diff(ys), np.diff(xs))
    return float(cell_areas[covered].sum())


def profile_page(page: Any) -> PageProfile:
    """Classify a PDF page's existing text layer.

    Must read the text *trace*, not the text dict: only the trace exposes render mode, and mode
    3 is precisely how an OCR engine writes an invisible searchable layer onto a scan. Through
    the dict those spans look like ordinary text, so an already-OCRed document gets OCRed
    again, doubling both the error rate and the bill.

    The bitmask trap is worth naming because the broken form appears in upstream docs: the test
    for "invisible" is `not (char_flags & (FILLED | STROKED))`; chaining the two bits with `&`
    is always 0 and marks every character invisible. The bit *values* are read from MuPDF and
    not written out — see `_paint_mask`, where the familiar `8 | 16` is measurably wrong against
    the pinned build.

    `page` is a `pymupdf.Page`. It is typed `Any` for the reason the whole module carries no
    parser import: the constants and the cell-side statistics must stay importable in an image
    whose imaging stack is broken, because the startup assertion that reports the breakage reads
    them.

    ALL THREE CHARACTER STATISTICS ARE OVER ONE POPULATION
    ------------------------------------------------------
    Non-whitespace characters whose glyph origin lies inside the centre box `MARGIN_RATIO`
    defines. One population for `chars`, `invisible_ratio` and `replacement_ratio` together, so
    the three are comparable: `needs_ocr` reads "text present *and* mostly invisible" as one
    condition, and a ratio taken over a wider population than the total it is compared against
    can satisfy both halves from different text. The margin exclusion is what keeps a running
    header or a page number from counting as a page's text.

    `image_coverage` is over the **whole** page, not the centre box — a full-bleed scan runs to
    the edges, and measuring it inside the margin would report 0.75 for a page that is entirely
    image.
    """
    painted = _paint_mask()
    page_x0, page_y0, page_x1, page_y1 = tuple(page.rect)
    width, height = page_x1 - page_x0, page_y1 - page_y0
    margin_x, margin_y = width * MARGIN_RATIO, height * MARGIN_RATIO
    interior = (page_x0 + margin_x, page_y0 + margin_y, page_x1 - margin_x, page_y1 - margin_y)

    chars = 0
    invisible = 0
    replacement = 0
    for span in page.get_texttrace():
        span_invisible = _span_is_invisible(span, painted=painted)
        for glyph in span["chars"]:
            code = int(glyph[0])
            if not 0 < code <= 0x10FFFF:
                # MuPDF reports an unmapped glyph as a non-positive code point; `chr` would
                # raise on it and on anything past the Unicode range, and an exception here
                # would fail a page for the one defect this profile exists to detect.
                continue
            character = chr(code)
            if character.isspace():
                continue
            origin_x, origin_y = glyph[2]
            if not (interior[0] <= origin_x <= interior[2]):
                continue
            if not (interior[1] <= origin_y <= interior[3]):
                continue
            chars += 1
            if span_invisible:
                invisible += 1
            if character == "�":
                replacement += 1

    images: list[tuple[float, float, float, float]] = []
    for operation, rect in page.get_bboxlog():
        if operation in _IMAGE_PAINT_OPS:
            image_x0, image_y0, image_x1, image_y1 = rect
            images.append((image_x0, image_y0, image_x1, image_y1))
    page_area = width * height
    coverage = (
        _union_area(images, (page_x0, page_y0, page_x1, page_y1)) / page_area
        if page_area > 0
        else 0.0
    )

    return PageProfile(
        chars=chars,
        image_coverage=coverage,
        invisible_ratio=invisible / chars if chars else 0.0,
        replacement_ratio=replacement / chars if chars else 0.0,
    )


def needs_ocr(profile: PageProfile) -> OcrModeName | None:
    """`None` means skip this page entirely; otherwise the mode the converter applies.

    Order matters, because a page can be text-bearing *and* unusable at the same time — the
    mojibake test therefore runs before the "already has text" test:

    1. mojibake above `REPLACEMENT_RATIO_MAX` → full page;
    2. text present and mostly invisible → skip (redoing OCR only adds error);
    3. image coverage at or above `IMAGE_COVERAGE_FULL` → full page;
    4. at least `MIN_DIGITAL_CHARS` characters → skip, it is born digital;
    5. image coverage below `IMAGE_COVERAGE_BLANK` → skip; blank or vector-only, never
       rasterize;
    6. otherwise a mixed page → OCR the bitmap regions.

    The escalation trigger is **not** here, and the reason is in the signature: it is
    `escalate_after_empty`, which takes the mode that was attempted rather than a page. See
    that function.
    """
    if profile.replacement_ratio > REPLACEMENT_RATIO_MAX:
        return "FULL_PAGE"
    if profile.chars > 0 and profile.invisible_ratio > INVISIBLE_RATIO_MAX:
        return None
    if profile.image_coverage >= IMAGE_COVERAGE_FULL:
        return "FULL_PAGE"
    if profile.chars >= MIN_DIGITAL_CHARS:
        return None
    if profile.image_coverage < IMAGE_COVERAGE_BLANK:
        return None
    return "PDF_AWARE_LAYOUT_REGIONS"


def escalate_after_empty(attempted: OcrModeName) -> OcrModeName | None:
    """The one retry in this stage: a page the region-limited mode read *nothing* from is
    re-run at full page. `None` means there is no escalation left and the page is done.

    WHY THIS IS A SEPARATE FUNCTION RATHER THAN A FLAG ON `needs_ocr`
    ----------------------------------------------------------------
    `needs_ocr` is a classification of a `PageProfile`, and a `PageProfile` is "what the page
    classifier measures, **before any engine runs**". "The last attempt came back empty" is an
    outcome, not a measurement, so it fits neither the dataclass nor the signature — the docstring
    on `needs_ocr` used to promise an escalation the type could not express.

    A keyword on `needs_ocr` was the other candidate and it is worse than it looks. The
    escalation has to *override* the classification rather than participate in it: the page this
    exists for carries a thin junk text layer, which is precisely the shape that steps 2, 4 and
    5 send to `None`. So the flag would short-circuit the whole ordered spec above it, and a
    function whose first line ignores its own six documented steps is two functions sharing a
    name.

    Taking the attempted mode rather than nothing at all is what makes the ladder terminate in
    the type instead of in a caller's discipline: full page has nothing above it, so a second
    empty result there is the page's answer and not a reason to run the identical request again.

    The mechanism it repairs is `PDF_AWARE_LAYOUT_REGIONS` itself, which keeps only layout
    clusters that overlap a bitmap or overlap no PDF cell. A page with a junk text layer has its
    clusters eliminated and never reaches the engine at all — so the failure is not a bad read,
    it is an empty result with no error, which is why it needs an explicit second attempt rather
    than a warning.
    """
    return "FULL_PAGE" if attempted == "PDF_AWARE_LAYOUT_REGIONS" else None


def normalize_confidence(raw: float, engine: str) -> float:
    """One field, three units — see the module docstring. Divide by 100 for Tesseract, pass
    through for the 0–1 engines.

    Every threshold in this module reads a normalized value. A raw 0–100 score compared against
    a 0–1 floor passes for every cell that has any confidence at all, which is how switching
    engines silently removes the entire warning class.
    """
    return raw / 100.0 if engine.startswith("tesser") else raw


def _ocr_weights(cells: Sequence[OcrCell], engine: str) -> list[tuple[float, int]]:
    """`(normalized confidence, character weight)` for OCR cells carrying text.

    OCR cells only: digital cells sit at 1.0 and would lift every statistic on a mixed page, so
    a mostly-digital page with one unreadable scanned figure would look excellent. Whitespace is
    excluded from the weight so a cell of five spaces cannot outvote a cell of five characters.
    """
    weighted = []
    for cell in cells:
        if not cell.from_ocr:
            continue
        weight = len("".join(cell.text.split()))
        if weight:
            weighted.append((normalize_confidence(cell.confidence, engine), weight))
    return weighted


def page_confidence(cells: Sequence[OcrCell], engine: str) -> float | None:
    """Character-weighted mean over OCR cells only. `None` when the page has no OCR text at
    all, which is not the same number as zero confidence.

    **Retained for reporting, never for a threshold.** It is what §8.16 shows an admin on the
    source detail and what a support conversation quotes, and it is worth persisting. It is not
    a page-quality gate and cannot be made into one: see the module docstring on the ~0.81
    floor. `low_confidence_mass` is the thresholded statistic.
    """
    weighted = _ocr_weights(cells, engine)
    if not weighted:
        return None
    total = sum(weight for _, weight in weighted)
    return sum(conf * weight for conf, weight in weighted) / total


def low_confidence_mass(cells: Sequence[OcrCell], engine: str) -> float | None:
    """Share of the page's OCR characters sitting in cells below `CELL_TRUST_FLOOR`.

    `None` when the page has no OCR text, matching `page_confidence` — a page the engine read
    nothing on is a coverage problem, and reporting 0.0 here would read as "all text trusted".

    Character-weighted rather than cell-counted, and that is the whole design. Every clean page
    carries one or two junk cells — a fold line, a page border, a speck of dust — reading
    0.63–0.73; counting cells makes those a third of a four-cell signature block and fires on a
    perfectly good page. Weighting by characters makes a two-character junk cell worth two
    characters, which is what it is.
    """
    weighted = _ocr_weights(cells, engine)
    if not weighted:
        return None
    total = sum(weight for _, weight in weighted)
    below = sum(weight for conf, weight in weighted if conf < CELL_TRUST_FLOOR)
    return below / total


def page_coverage(text_polygons: Sequence[Sequence[tuple[float, float]]], raster: Any) -> float:
    """Ink-in-box: foreground ink inside OCR cell rectangles over all foreground ink.

    **This is the definition that was missing, and the missing definition was the real defect.**
    `assess` has always taken `coverage` as a parameter, and the only coverage numbers ever
    measured for it were "recovered characters over ground-truth characters" — computable in the
    eval corpus, which generated the page and therefore knows its text, and *not computable in
    production*, which does not. Any implementation had to invent one.

    The obvious invention is wrong. Text-box area over page area inverts on sparse pages: on the
    corpus's scanned fixture the clean four-line signature block scores 0.0150 and the
    deliberately-degraded page scores 0.0209, so the clean page warns and the bad one does not,
    and every threshold in the plausible range is below every page in the document.

    Normalising by the page's own ink instead removes the page-density term, and it ranks the
    pipeline's own pages correctly: through `parse_document` the shipped fixture reads 1.000 /
    0.995 / **0.505** / 1.000, the degraded page last by a factor of two.

    Foreground is Otsu over a lightly blurred raster, then a 3x3 morphological opening to drop
    isolated speckle. When the resulting ink fraction falls outside `INK_FRACTION_BAND` the mask
    is not measuring ink and this returns `float("nan")` — `assess` reads that as "cannot tell"
    and warns about the page rather than reporting a coverage number that is not one.

    **THE RASTER IS 72 DPI, NOT 300, AND THIS FUNCTION DOES NOT GET TO CHOOSE.** The only raster
    that exists after conversion is whatever docling cached at `images_scale`, which is 1.0 —
    measured, an A4 page arrives here as 595x842. The opening still costs little at that scale
    (it removes 3–10% of the mask on the fixture's four pages), but any argument for the 3x3
    kernel that starts "glyph strokes at 300 dpi" is an argument about a raster this function
    never sees. OCR itself runs at `OCR_SCALE`; the measurement does not.

    **THE RECTANGLES ARE AXIS-ALIGNED AND THE INK NEED NOT BE, WHICH BOUNDS THE WHOLE SIGNAL.**
    Measured on docling 2.118.0: every `TextCell.rect` handed here has `r_x0 == r_x3` and
    `r_y0 == r_y1` — the quad is a rectangle in page coordinates, tight to a *deskewed* line
    crop. Rotate the page and the text runs diagonally out of its own box while the box gets
    shorter, so ink the engine read perfectly counts as ink it missed. On the fixture's prose
    page, 3° of skew takes coverage from 0.995 to 0.571 and 5° to 0.497 with cell recall 1.000
    throughout; dilating the mask 8 px restores it to 0.997, and rotating the mask makes it
    worse at every angle, which is how the tightness rather than a frame mismatch was
    identified. This is why `COVERAGE_WARN` is a catastrophe floor and not a quality gate — see
    the distribution recorded there.

    Takes polygons rather than cells so it stays independent of the cell type, and so the caller
    in `parsing/` owns the `TextCell.rect` mapping.
    """
    # Deferred deliberately, not by accident. RapidOCR depends on `opencv-python`, not the
    # headless build, so `import cv2` is the line that raises `libGL.so.1: cannot open shared
    # object file` in a slim worker image. Every constant and every cell-side statistic in this
    # module must stay importable when that happens — the startup assertion that reports the
    # broken image reads them.
    import cv2
    import numpy as np

    gray = raster if raster.ndim == 2 else cv2.cvtColor(raster, cv2.COLOR_RGB2GRAY)
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)
    _, binary = cv2.threshold(blurred, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    foreground = cv2.morphologyEx(binary, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8)) > 0

    ink = int(foreground.sum())
    if not ink:
        # No measurable ink: nothing to read, so nothing was missed. `needs_ocr` skips blank
        # pages before this runs; warning on them would be pure noise.
        return 1.0
    low, high = INK_FRACTION_BAND
    if not (low <= ink / foreground.size <= high):
        return float("nan")

    boxes = np.zeros(gray.shape, dtype=np.uint8)
    for polygon in text_polygons:
        cv2.fillPoly(boxes, [np.asarray(polygon, dtype=np.int32)], 1)
    return float((foreground & (boxes > 0)).sum()) / ink


def assess(
    cells: Sequence[OcrCell],
    engine: str,
    coverage: float | None,
    placed_text: int | None = None,
) -> list[str]:
    """The per-page warning list. Warnings only — never a failure.

    `coverage` carries three states and they are not interchangeable. A number is a measurement.
    `None` means **no OCR ran on this page**, so there is nothing for this signal to be about —
    exactly what `low_confidence_mass` already says by returning `None`, and the two arms are
    now symmetric. `float("nan")` means OCR ran and the ink mask could not be trusted, which is
    a fact about the page and is reported.

    The distinction is not academic: **measured 2026-08-12, every page of every born-digital
    document in the corpus reported `ocr_coverage_unmeasurable`** — 14 of 14 handbook pages, 2
    of 2 datasheet pages — because a page with no OCR cells has no polygons, which the coverage
    arm read as a failed measurement. A warning that fires on every page of every clean PDF is
    not a warning, and it would have been the most common string in the platform's first real
    ingest.

    Two signals, still, and deliberately: `coverage` measures what the engine never read,
    `low_confidence_mass` measures how much of what it *did* read is untrustworthy. They do not
    move together — coverage collapses roughly three times faster than any confidence statistic
    — and each catches pages the other misses. What changed is that the confidence signal is now
    a mass below a per-cell floor instead of a page mean; the mean version could not fire on the
    pinned engine at any level of degradation. See the module docstring for the measurements.

    A low-confidence page is `Ready with warnings`, which is retrievable and behaves exactly
    like `Ready`; partial OCR is a configurable success, not an error. Per-page results are
    recorded with `disposition` in `success` / `partial` / `error`, deliberately not the shared
    four-value outcome label: a partial page emitted as an out-of-enum `outcome` falls outside
    the global error matcher, so the error rate reads healthy while pages fail.

    Only a page that *raised* gets `error_class="ocr"` — retryable per page, bounded.

    `placed_text` IS A THIRD SIGNAL AND IT CATCHES A FAILURE THE OTHER TWO CANNOT SEE
    ---------------------------------------------------------------------------------
    It is the number of text elements the layout model placed on this page — a count the
    caller has and this module does not, which is why it is a parameter. `None` means the
    caller could not measure it and is silent, the same three-state convention `coverage`
    uses one arm up.

    The failure it catches: **at Gaussian blur >= 3.0 the layout model classifies the whole
    page as a picture.** OCR runs and reads it *perfectly* — measured cell recall 1.000 — and
    then not one of those cells becomes a text element, so element recall is 0.000. Both
    signals above stay silent and are right to: confidence is high because the cells really
    were read confidently, and coverage is high because the ink really is inside the cell
    rectangles. The text exists, it is correct, and it never reaches the index. **The page
    arrives as silence**, which is the one disposition a quality signal must never allow —
    an admin reading `ocr_cells: 41, ocr_confidence: 0.94, warnings: []` on a page that
    contributed nothing has been told the opposite of what happened.

    THIS SIGNAL HAS NO TUNABLE, AND THAT IS LOAD-BEARING RATHER THAN A CONVENIENCE.
    It is zero-versus-nonzero: OCR produced cells, the layout placed no text. So there is
    nothing to fold into `ocr_cfg_version` and **the identity string does not move** — see
    that function for why a tunable outside it is a retune that silently never re-ingests.
    The converse is what makes this landable at all: a signal with a threshold would have
    re-OCR'd every document in the platform to add a warning. Anyone adding a threshold here
    later must add it to `OCR_CFG_INPUTS` in the same edit, and pay that cost deliberately.

    It is a warning and not a failure for the same reason the rest of this list is: a page
    whose text went nowhere is still a page, other pages of the document are unaffected, and
    a raise would take the document with it. What it buys is that the state is *visible*.
    """
    warnings: list[str] = []

    mass = low_confidence_mass(cells, engine)
    if mass is not None and mass > LOW_CONF_MASS_WARN:
        warnings.append(f"ocr_low_confidence:{mass:.2f}")

    if coverage is None:
        pass  # no OCR on this page; this signal has nothing to say about it
    elif coverage != coverage:  # NaN: the ink mask was not measuring ink (see `page_coverage`)
        warnings.append("ocr_coverage_unmeasurable")
    elif coverage < COVERAGE_WARN:
        warnings.append(f"ocr_low_coverage:{coverage:.2f}")

    # Gated on OCR cells as well as on the count, so it can only fire where there was read text
    # to lose. A page with no OCR cells and no text elements is a blank page, not a defect.
    #
    # `from_ocr` IS FILTERED HERE AND NOT INHERITED FROM THE CALLER. Every arm of this function
    # filters its own input — `low_confidence_mass` does it through `_ocr_weights` — because
    # `assess` takes the page's whole cell list and the one caller that happens to pre-filter is
    # not the contract. Trusting the caller made this arm fire on a born-digital page carrying a
    # digital cell and no text element, which is the exact false positive that put
    # `ocr_coverage_unmeasurable` on all 16 clean pages of the corpus.
    if placed_text is not None and placed_text == 0 and any(cell.from_ocr for cell in cells):
        warnings.append("ocr_text_unplaced")

    return warnings


def ocr_cfg_version(*, engine: str, languages: Sequence[str]) -> str:
    """The identity string: engine id, **model revision** (v5 and v6 are different models),
    language list, render scale, the preprocessing chain, and **all three** warn thresholds —
    `CELL_TRUST_FLOOR`, `LOW_CONF_MASS_WARN`, `COVERAGE_WARN`.

    "Three" is load-bearing and used to be "both". Splitting the confidence signal into a
    per-cell floor plus a mass threshold added a tunable field, and a tunable field outside this
    string is a retune that never reaches a document — which is the exact failure the rest of
    this docstring describes. `INK_FRACTION_BAND` belongs here too: it changes which pages
    report a coverage number at all.

    It is a component of the ingest key. §13.3's key list omits OCR entirely while §13.4 makes
    an OCR change a version trigger — the gap means a retune leaves the content hash unchanged,
    the request dedupes against the completed run, the admin sees "already processed", and the
    new settings never reach a document. Anything tuned in this module that is not folded into
    this string is a change that silently never re-ingests.

    `engine` and `languages` are **arguments, not constants**, because neither is a property of
    this module: the engine is admin-selectable and the language list is per-corpus — a
    single-script tenant routes to RapidOCR, a mixed-script one to tesserocr, whose one real
    advantage is recognizing several scripts in a single pass. Both are inside the string all
    the same, and keyword-only so a call site cannot swap them and produce a valid version for
    the wrong configuration.

    The language list is **order-insensitive**, deliberately, and this is the one place a
    sequence is sorted: `["eng", "hin"]` and `["hin", "eng"]` are the same Tesseract
    configuration, and re-OCRing an entire corpus because someone reordered a list would be a
    cost with no cause. It is not free of meaning everywhere — RapidOCR uses `lang[0]` and logs
    the rest away — but a *route* to a single-language engine with two languages configured is
    a misconfiguration to catch at startup, not a distinction to encode here.
    """
    if engine not in OCR_ENGINE_CLASSES:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"unknown OCR engine {engine!r}; the engine is pinned by class and the known ones "
            f"are {sorted(OCR_ENGINE_CLASSES)}. An unrecognized name here would hash cleanly "
            "and produce a version string for a configuration that cannot run",
            origin=Origin.SELF,
        )
    if not languages:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "no OCR language configured; an empty list lets the engine's own default win "
            "silently, which is a different model reading the page than the one recorded",
            origin=Origin.SELF,
        )

    inputs: dict[str, Any] = {name: globals()[name] for name in OCR_CFG_INPUTS}
    inputs["engine"] = engine
    inputs["languages"] = tuple(sorted(languages))
    return compose(
        OCR_CFG_SCHEME,
        label=engine,
        inputs=inputs,
        pins=[pin(OCR_MODEL_PIN)],
    )
