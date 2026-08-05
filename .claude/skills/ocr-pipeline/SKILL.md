---
name: ocr-pipeline
description: OCR engine choice, DPI and preprocessing, confidence handling, and the untrusted-image security envelope for the KnowledgeBot ingestion workers. Use whenever selecting or configuring an OCR engine, setting render scale or preprocessing, deciding whether a page needs OCR at all, propagating OCR confidence into warnings, or hardening the image decode path in services/ai-service/. Docling invokes OCR; this skill decides which engine, at what DPI, and what its numbers mean. Pairs with docling-parsing (the caller).
---

# OCR Engine Selection, Tuning, and Confidence

Pinned: **Docling 2.118.0** · **RapidOCR 3.9.2** on **ONNX Runtime 1.28.0** (default) · **Tesseract 5.5.3** + **tesserocr 2.11.0** (multi-script alternative) · Pillow ≥ 12.3.0 · PyMuPDF ≥ 1.26.7 · `opencv-python-headless`. Versions read from the PyPI JSON API and the GitHub releases API on 2026-08-04 — rendered docs pages mis-date several of these releases to 2024, so trust the APIs.
**Authoritative spec:** docs/08-ingestion-pipeline.md §13.2 (stage 7), §13.4, §13.7, docs/03-functional-knowledge-sources.md §8.11, §8.16, docs/13-security.md §18.7, docs/14-reliability.md §19.4

## Non-negotiables

- **The engine is pinned by class; `OcrAutoOptions` is banned.** Docling 2.118 defaults `PdfPipelineOptions.do_ocr=True` **and** `ocr_options=OcrAutoOptions()`, and `OcrAutoModel.__init__` picks the engine by *import probing* — ocrmac → Nemotron → RapidOCR/onnxruntime → EasyOCR → RapidOCR/torch → **none, logged at WARNING with no exception**, after which `__call__` yields the page batch unchanged. The engine becomes a property of the image build rather than of configuration: a macOS laptop and the Linux worker emit different text for the same scan while `ocr_cfg_version` is identical, so nothing reprocesses; and an image missing every backend publishes every scanned source `Ready` with zero chunks.
- **`ocr_cfg_version` covers engine id, model revision (PP-OCR v5 vs v6 is a *different model*), language list, render scale, the preprocessing chain, and both confidence thresholds.** §13.3's idempotency key omits OCR entirely while §13.4 makes an OCR change a version trigger (`docs/22-spec-findings-and-decisions.md` defect 1); `kb-source-lifecycle` puts `ocr_cfg_version` back. Anything you tune here that is not folded into that string is a change that silently never reaches a re-ingest.
- **Confidence is normalized to 0–1 at our adapter and never consumed raw.** `TextCell.confidence` is one float carrying whatever the backend reported: tesserocr writes `MeanTextConf()` on Tesseract's **0–100** scale, RapidOCR and EasyOCR write **0–1**, and digital (non-OCR) cells default to **1.0**. Comparing across engines without normalizing compares two different units.
- **A low-confidence page is `Ready with warnings`, never `Failed`.** §8.11 requires the warning; §13.7 makes partial OCR a configurable success. Per-page results are `kb_ingestion_ocr_pages_total{engine,disposition}`, `disposition` ∈ `success` `partial` `error` — deliberately **not** the shared four-value `outcome`, because a partially-OCR'd page is a real third result and an out-of-enum value on an `outcome` label would fall outside the global `outcome=~"error|timeout"` matcher, leaving the error rate reading healthy while pages fail (`kb-observability-conventions`). Only a page that raised gets `error_class="ocr"` (`kb-error-taxonomy`: retryable per page, bounded).
- **Every decode runs inside the upload envelope**: decoder allow-list, pixel *and* frame caps, a Celery **hard** `time_limit`, and a worker container with no network namespace (`kb-security-baseline` → `references/file-upload-safety.md`). A `soft_time_limit` cannot interrupt a C-level decode loop, so it is not a control — and no OCR backend may make a network call, which rules out `KserveV2OcrOptions` outright.
- **OCR does not launder text.** Output is untrusted source content exactly like parsed text: invisible-Unicode stripping, prompt delimiting, and the full `kb-chunking-rules` metadata schema all still apply.

## How we use it

### Boundary with `docling-parsing`

`docling-parsing` states this in its own words; we match it exactly — do not let the two drift.

| Decision | Owner |
|---|---|
| `DocumentConverter`, `PdfPipelineOptions`, `do_ocr`, applying the `OcrMode`, page batching, `res.confidence`/`res.errors` → the §8.11 warning taxonomy, elements → `document_elements` | `docling-parsing` |
| *Which* `OcrOptions` subclass and model revision, language list, `scale`/DPI, preprocessing, confidence **normalization** and thresholds, per-page retry, the decode/timeout/network envelope, the engine image layer | **this skill** |
| The per-page classifier that *chooses* the `OcrMode` (or skips the page), and escalation to `FULL_PAGE` when a page came back empty | **this skill** — `docling-parsing` names this the `ocr-pipeline` escalation trigger |
| Version identity, `Ready with warnings`, restart boundaries | `kb-source-lifecycle` |

### Engine selection — the decision and the evidence

**Decision: `RapidOcrOptions` (PP-OCR, ONNX) pinned as the default, `TesseractOcrOptions` (tesserocr) pinned as the alternative**, both admin-selectable through an `ocr_engine` setting that feeds `ocr_cfg_version`. All eight candidates — versions, licence chains, footprints, why each rejection — are in **[references/engine-selection.md](references/engine-selection.md)**. Four independent lines of evidence, strongest first:

1. **Accuracy.** OmniDocBench's text-OCR track is the only public benchmark putting these engines on one axis (edit distance, lower better): **PaddleOCR 0.071 EN / 0.055 ZH · Tesseract 0.096 / 0.551 · EasyOCR 0.26 / 0.398.** PP-OCR wins on English and beats Tesseract **10×** on Chinese.
2. **Licence.** Surya's weights carry a **$5M operator-revenue cap** (modified AI Pubs OpenRAIL-M) and KnowledgeBot is self-hosted by tenants we do not vet, so we cannot ship them. Nemotron requires CUDA and Python exactly 3.12. `kserve_v2_ocr` is a network call. PP-OCR carries no such condition.
3. **Footprint.** RapidOCR wheel 27.3 MB + `onnxruntime` 19.2 MB ≈ **47 MB**, against `paddlepaddle` 194.8 MB or `torch` 502 MB, on a CPU-first fleet that scales per tenant.
4. **Latency.** PP-OCRv6 on an Intel Xeon 8350C with OpenVINO: **0.20 s/image (tiny), 0.59 s (small), 1.40 s (medium)** — comfortably inside §19.4's 30 s/page budget even at 300 dpi.

Tesseract earns its place on one capability PP-OCR lacks: `lang=["eng","hin"]` is joined to `eng+hin` and recognized in **one** pass, and `lang=["auto"]` runs OSD to detect *script* (not language) and orientation per page, swapping in the matching `script/*` reader. Tesseract's own timings put the multi-language cost under 15% (`-l eng+hin` 0.442 s vs `script/Devanagari` 0.391 s), so a short targeted language list is cheap. RapidOCR's Docling adapter takes `lang[0]` and logs `"RapidOCR uses a single language; using %r and ignoring %r"` — a mixed-script page loses a script with no error. **Route by corpus: single-script tenants → RapidOCR; mixed-script → tesserocr with `["auto"]`.**

### When to OCR at all

OCRing a text-native PDF costs the full 30 s/page budget and produces *worse* text than the embedded layer, which already carries exact glyph codes, word boundaries and reading order. `needs_ocr` below classifies each page and returns the `OcrMode` that `docling-parsing` applies — or `None` to skip. Thresholds carry their provenance: the centre-75% interior box is OCRmyPDF's `margin_ratio = 0.125`, and the 0.75 / 0.05 image-coverage cuts are Docling's own `BITMAP_COVERAGE_TRESHOLD` and `bitmap_area_threshold` from before 2.111 removed them. The character-count and mojibake cuts are **ours** — no project publishes one — so tune them against docs/16-evaluation.md. The asymmetry is deliberate: a false "needs OCR" costs 30 s, a false "does not" loses the page from the index permanently.

### DPI and preprocessing

**DPI is the knob with an official number behind it.** Tesseract's *ImproveQuality*: *"Tesseract works best on images which have a DPI of at least 300 dpi."* Its FAQ is more precise — the governing variable is **x-height in pixels: target ≈ 20 px, hard floor ≈ 10 px, and an LSTM ceiling near 30 px above which accuracy degrades again.** Docling's `OcrOptions.scale` defaults to **3.0**, and its own field description says so outright: *"The page is rendered at 72 DPI times this factor, so the default 3 yields 216 DPI."* Set `scale = 300/72 ≈ 4.17`, and budget the memory: `pypdfium2_backend.get_page_image` renders at `scale × 1.5` before downsampling, so the peak raster is 2.25× the pixels you asked for — a US-Letter page at 300 dpi peaks near 3825×4950 px ≈ 57 MB RGB.

- **Deskew everything; it is the one step with a measured win.** Susanto et al. (IJET 2018) report CER/WER reductions of **up to 35.53% / 34.51%** from vertical-projection deskewing across a ±10° sweep — "up to", one corpus, so read it as evidence that deskew matters, not as a coefficient. Liebl & Burghardt concur for neural recognizers: *"deskewing … is still a very useful operation for CRNNs."* Detect with Leptonica's `pixFindSkew` (projection-profile, not Hough; accurate to ~0.03°, refuses to rotate below 0.1° or at confidence < 3.0) or take Tesseract's OSD angle — note OCRmyPDF parses that field in **radians**. Rotate with `INTER_CUBIC` + `BORDER_REPLICATE`; nearest-neighbour shreds thin strokes.
- **Binarization is a Tesseract *parameter*, not a preprocessing step — and it actively hurts the DL engines.** Tesseract binarizes internally; `thresholding_method` takes `0 = Otsu` (**the default**), `1 = LeptonicaOtsu`, `2 = Sauvola`. Switch to Sauvola for unevenly lit pages (phone photos, book gutters) rather than pre-thresholding yourself. **Never binarize for RapidOCR:** PP-OCR's detector *is* Differentiable Binarization, a learned threshold over 3 channels, and PaddleOCR's official document-preprocessing pipeline contains **no binarization and no denoising at all** — only orientation classification and unwarping, both defaulting on. Measured: on 19th-century Fraktur, the hardest case where binarization *should* win, Liebl & Burghardt's top-5 architectures by CER are **all grayscale** (0.85–0.86% CER), with no binarized configuration in the top 5 of either table.
- **Denoising is opt-in per source, never global**, and applies only to the image fed to OCR — never to the stored artifact. OCRmyPDF's `--clean` invokes unpaper with every destructive filter explicitly disabled (`--no-grayfilter --no-blackfilter --no-border-align --no-mask-center`) and by design "does not alter the final output". Copy that split.
- **Upscaling helps only while it moves x-height toward ~20 px.** Docling's own guidance on `scale`: *"Lower it when the source image is already high resolution and upscaling degrades recognition."* Interpolation adds no glyph information. Re-render the PDF page at a higher `scale`; never resample an already-rendered raster.

### Confidence, and what a low-confidence page does

Docling reports `ConfidenceReport.ocr_score` (graded `poor` <0.5, `fair` <0.8, `good` <0.9, `excellent` ≥0.9) and `docling-parsing` persists it. **It is neither sufficient nor on a fixed scale** — see the first two Gotchas. Ours sits alongside it: normalize to 0–1, aggregate **character-weighted over `from_ocr=True` cells**, and act on two independent signals, because each engine deletes its own weak output before we see it. Starting thresholds, tuned by docs/16-evaluation.md: warn below **0.75** mean confidence, or below **0.30** OCR text coverage on a page `needs_ocr` sent to OCR.

### The guarded OCR path

```python
# services/ai-service/app/ingestion/ocr/guarded.py
"""The only place an untrusted image or page raster reaches an OCR engine."""
from __future__ import annotations
import warnings
from dataclasses import dataclass

import pymupdf                                    # >= 1.26.7 (kb-security-baseline)
from PIL import Image
from docling.datamodel.pipeline_options import OcrMode
from docling_core.types.doc.page import TextCell

DECODERS   = ["JPEG", "PNG", "WEBP", "GIF", "TIFF", "BMP"]  # allow-list kills the GD/BDF/PCF/FITS/EPS CVE paths
MAX_PIXELS = 40_000_000        # below Pillow's 89.5M warn band, so OUR raise fires first
MAX_FRAMES = 8                 # MAX_IMAGE_PIXELS never consults n_frames
OCR_DPI    = 300               # Tesseract's documented floor; Docling scale = 300/72 = 4.17
MARGIN     = 0.125             # OCRmyPDF's margin_ratio: only the centre 75%x75% is "real" text
CONF_WARN, COVERAGE_WARN = 0.75, 0.30

warnings.simplefilter("error", Image.DecompressionBombWarning)  # a warning is not a defense

class OcrRejected(Exception):
    """-> error_class 'parsing', permanent, never retried (kb-error-taxonomy)."""

def open_guarded(fp) -> Image.Image:
    im = Image.open(fp, formats=DECODERS)         # single highest-value control
    w, h = im.size
    frames = getattr(im, "n_frames", 1)
    if frames > MAX_FRAMES or w * h * frames > MAX_PIXELS:
        raise OcrRejected(f"{w}x{h}x{frames} exceeds decode caps")
    return im                                     # caller must stamp a real DPI - see Gotchas

@dataclass(frozen=True)
class PageProfile:
    chars: int                  # non-whitespace chars inside the interior box
    image_coverage: float       # union of image bboxes / page area
    invisible_ratio: float      # render mode 3 -> an OCR layer already exists
    replacement_ratio: float    # U+FFFD share -> broken or absent ToUnicode CMap

def profile_page(page: pymupdf.Page) -> PageProfile:
    """get_texttrace(), NOT get_text('dict'): only the trace exposes text render
    mode, and mode 3 is precisely how an OCR engine writes an invisible
    searchable layer onto a scan. Margins are excluded so a page whose only
    'text' is a running header is still judged to need OCR."""
    r = page.rect
    interior = pymupdf.Rect(r.x0 + MARGIN * r.width, r.y0 + MARGIN * r.height,
                            r.x1 - MARGIN * r.width, r.y1 - MARGIN * r.height)
    chars = inv = bad = 0
    for sp in page.get_texttrace():
        if not pymupdf.Rect(sp["bbox"]).intersects(interior):
            continue
        n = len(sp["chars"]); chars += n
        bad += sum(1 for c in sp["chars"] if c[0] == 0xFFFD)   # c[0] is the codepoint
        if sp["type"] == 3 or sp["opacity"] == 0:              # already-OCRed layer
            inv += n
    img = sum(abs(pymupdf.Rect(i["bbox"])) for i in page.get_image_info())
    return PageProfile(chars, min(img / abs(r), 1.0) if abs(r) else 0.0,
                       inv / chars if chars else 0.0, bad / chars if chars else 0.0)

def needs_ocr(p: PageProfile) -> OcrMode | None:
    """None = skip this page entirely; otherwise the mode `docling-parsing` applies.
    Order matters: a page can be text-bearing AND unusable at the same time."""
    if p.replacement_ratio > 0.10:  return OcrMode.FULL_PAGE   # mojibake layer; ours, tune it
    if p.chars and p.invisible_ratio > 0.90: return None       # already OCRed; redoing adds error
    if p.image_coverage >= 0.75:    return OcrMode.FULL_PAGE   # Docling's BITMAP_COVERAGE_TRESHOLD
    if p.chars >= 100:              return None                # born digital; ours, tune it
    if p.image_coverage < 0.05:     return None                # blank or vector-only: never rasterize
    return OcrMode.PDF_AWARE_LAYOUT_REGIONS                    # mixed page: OCR the bitmap regions

def normalize(cell: TextCell, engine: str) -> float:
    """Docling stores the engine's RAW score: tesserocr -> MeanTextConf() 0..100,
    rapidocr/easyocr -> 0..1, digital cells -> 1.0. One field, three units."""
    return cell.confidence / 100.0 if engine.startswith("tesser") else cell.confidence

def page_confidence(cells: list[TextCell], engine: str) -> float | None:
    ocr = [c for c in cells if c.from_ocr]        # digital cells sit at 1.0 and lift the mean
    n = sum(len(c.text) for c in ocr)
    return sum(normalize(c, engine) * len(c.text) for c in ocr) / n if n else None

def assess(cells: list[TextCell], engine: str, coverage: float) -> list[str]:
    """Two signals, because RapidOCR's Global.text_score (0.5) and EasyOCR's
    confidence_threshold (0.5) DELETE weak lines: a bad scan arrives short, not
    low-confidence. Coverage is what still notices. Warnings only (§8.11)."""
    conf, w = page_confidence(cells, engine), []
    if conf is not None and conf < CONF_WARN:
        w.append(f"ocr_low_confidence:{conf:.2f}")
    if coverage < COVERAGE_WARN:
        w.append(f"ocr_low_coverage:{coverage:.2f}")
    return w
```

The task wrapping this declares **both** limits — `@celery_app.task(queue="ingest", soft_time_limit=900, time_limit=960)` (`celery-workers`) — and caps pages per task so §19.4's 30 s/page × pages stays inside the 300 s document budget: **10 OCR pages per task**, fanned out beyond that.

## Gotchas

- **The same PDF yields different text on a laptop and in production, and no reprocess is ever triggered.** `OcrAutoOptions` probed imports and found `ocrmac` on macOS, RapidOCR in the Linux image. Pin the class. Uglier variant: an image with no backend installed logs `"No OCR engine found. Please review the install details."` at WARNING and passes pages through untouched — every scanned source publishes `Ready` with zero chunks. Assert the resolved engine class at worker startup and fail the *container*, not the document.
- **Switching the engine to Tesseract makes every low-confidence warning disappear — including Docling's own.** `tesseract_ocr_model.py` writes `MeanTextConf()` — Tesseract's documented 0–100 scale (`AllWordConfidences`: *"between 0 and 100"*) — straight into `TextCell.confidence`, where RapidOCR wrote 0–1. Our `< 0.75` test then passes for every cell, and so does Docling's: `base_ocr_model` sets `ocr_score = np.mean([c.confidence for c in final_cells if c.from_ocr])` and `_score_to_grade` calls anything ≥ 0.9 `excellent`. Under Tesseract every page grades `excellent`, including a blank one. Normalize before anything reads a threshold.
- **A visibly terrible scan produces a short page at 0.9 confidence and no warning.** RapidOCR's `Global.text_score` (default **0.5**) and Docling's `EasyOcrOptions.confidence_threshold` (default **0.5**) drop weak lines *inside the engine*; only the surviving confident subset reaches us. Mean confidence alone cannot detect a bad page — pair it with coverage, as `assess()` does.
- **A 200-page scan with four unreadable pages reports `ocr_score` = `good` and raises nothing.** Document-level `ocr_score` is a plain `np.nanmean` over pages, while `parse_score` deliberately uses `np.nanquantile(..., q=0.10)` "to emphasise problems". Four bad pages in 200 shift the mean by ~0.02. §8.11 wants *pages* flagged: warn per page, roll up a count, never gate on the document mean.
- **Tesseract silently decides your 300 dpi render is 70 dpi.** `kMinCredibleResolution = 70` / `kMaxCredibleResolution = 2400`: a raster with no DPI metadata, or one outside that band, is forced to **70** with only a `"Warning: Invalid resolution"` line. Both Sauvola and LeptonicaOtsu size their windows as `factor × yres`, so the binarizer is then tuned for a page 4× smaller than reality and noise removal eats small glyphs. Stamp a real DPI on every image handed to Tesseract.
- **OCR quality is mediocre everywhere and nobody can find the bug.** `OcrOptions.scale` is still 3.0 = **216 dpi**, under Tesseract's documented 300 floor. Raising it is not free: the pypdfium backend renders at `scale × 1.5` then downsamples, so peak allocation is 2.25× — raise `scale` and the worker `memory.max` in the same change, or you have traded bad text for OOM kills.
- **An already-OCRed scan gets OCRed again, doubling the error rate and the bill.** The invisible layer an OCR engine writes is PDF text render mode 3, and `get_text("dict")` cannot see render mode — the spans look like ordinary text. Use `page.get_texttrace()` (`type`, `opacity`). If you reach for `char_flags` instead (bit 3 filled, bit 4 stroked, new in PyMuPDF 1.25.2), **do not copy the snippet in PyMuPDF's own docs**: `char_flags & 2**3 & 2**4` is always 0, so it marks every character invisible. The correct test is `not (char_flags & (8 | 16))`. pdfplumber and stock pdfminer.six expose no render mode at all.
- **You set `bitmap_area_threshold=0.05` and nothing changes.** It was real — default 0.05, with a companion `BITMAP_COVERAGE_TRESHOLD = 0.75` (the typo is upstream) — through Docling 2.110.0, and it is **gone in 2.118**. Region choice now lives in `base_ocr_model._find_pdf_aware_layout_ocr_rects`, which keeps layout clusters overlapping a bitmap or overlapping no PDF cell, dilated by `DEFAULT_DILATION_SIZE = 20`. Policy is `OcrMode`; those fractions survive only as thresholds in our own `needs_ocr`.
- **A Hindi/English page loses one script silently.** RapidOCR is single-language per instance; Docling's adapter uses `lang[0]` and warns about the rest at log level. Multi-script corpora run tesserocr with `lang=["eng","hin"]` or `["auto"]` — and `["auto"]` needs the `osd` and `script/*` traineddata present under `TESSDATA_PREFIX` or the reader raises at init. Related trap from Docling's own docs: *"torch on PP-OCRv5 supports ONLY chinese."*
- **The parser container has no network and OCR fails on the first scanned page — or hangs until the hard timeout.** `docling-tools models download -o /opt/docling-artifacts` (the build step `docling-parsing` owns) fetches RapidOCR's *default* language pack only; any other configured language re-resolves checkpoints at run time. Prefetch each one explicitly — `docling-tools models download rapidocr --rapidocr-backend-lang onnxruntime:<lang> -o /opt/docling-artifacts` — and set `EasyOcrOptions.download_enabled=False` if EasyOCR is ever installed, since it defaults to **`True`**. That flag is also a security control: Docling's EasyOCR downloader was a Zip Slip → RCE, fixed in 2.94.0.
- **`import cv2` raises `libGL.so.1: cannot open shared object file` in the slim worker image.** RapidOCR depends on `opencv_python`, not `opencv-python-headless`, dragging GUI libs into a container that has none. Install `opencv-python-headless` over it (both provide `cv2`) rather than adding `libgl1` — smaller, and the worker has no display.
- **A malicious image larger than your ClamAV limits is reported clean.** `AlertExceedsMax` defaults to **`no`**, so anything past `MaxFileSize`/`MaxScanSize`/`MaxRecursion` returns a verdict indistinguishable from OK, and `StreamMaxLength` defaults to **100 MB** (not 25). Set `AlertExceedsMax yes`, fail closed on `Heuristics.Limits.Exceeded`, and treat a closed stream as an error, not a pass.
- **Your "hardened" XML parser is not.** Image-bearing OOXML reaches the same readers: lxml left `iterparse()` at `resolve_entities=True` until **6.1.0** (CVE-2026-41066), and openpyxl's `iterparse` silently falls back to stdlib `xml.etree` unless `defusedxml` is importable — so dropping a transitive dependency downgrades XXE hardening with no error and no failing test. Pin `defusedxml` directly and assert `openpyxl.DEFUSEDXML is True` at startup.
- **A worker pinned at 100% CPU that no limit ever kills.** `soft_time_limit` raises from a Python signal handler; a thread inside a Pillow, Leptonica, or ONNX Runtime C loop never returns to the interpreter to receive it. The hard `time_limit` kills the child — declare both on every OCR task, and give any `subprocess.run` its own `timeout=`.

## Official docs

- [Docling — OCR concepts](https://docling-project.github.io/docling/concepts/OCR/) and [pipeline options](https://docling-project.github.io/docling/reference/pipeline_options/) — the backend list, `OcrMode`, `OcrOptions.scale`, every `*OcrOptions` subclass and its defaults.
- [`stages/ocr/auto_ocr_model.py`](https://github.com/docling-project/docling/blob/main/docling/models/stages/ocr/auto_ocr_model.py) — the import-probe ladder `OcrAutoOptions` actually runs, and its silent no-engine path.
- [`models/base_ocr_model.py`](https://github.com/docling-project/docling/blob/main/docling/models/base_ocr_model.py) — OCR-region selection per `OcrMode` and the `ocr_score` computation; read beside [confidence scores](https://docling-project.github.io/docling/concepts/confidence_scores/), which documents the grades but not the units.
- [Tesseract — ImproveQuality](https://tesseract-ocr.github.io/tessdoc/ImproveQuality.html) and the [x-height FAQ](https://github.com/tesseract-ocr/tessdoc/blob/main/tess3/FAQ-Old.md) — the 300 dpi floor and the 10/20/30 px x-height band that actually governs it.
- [`tesseractclass.cpp`](https://github.com/tesseract-ocr/tesseract/blob/main/src/ccmain/tesseractclass.cpp) — `thresholding_method` and its window/kfactor/tile parameters, all DPI-scaled.
- [Tesseract C++ API](https://tesseract-ocr.github.io/tessapi/5.x/a02438.html) — `MeanTextConf()` / `AllWordConfidences()` and the 0–100 scale.
- [OmniDocBench](https://github.com/opendatalab/OmniDocBench) — the engine comparison quoted above. **The dataset is research-only; it may not be used in a commercial eval harness** (relevant to docs/16-evaluation.md).
- [PP-OCRv6](https://github.com/PaddlePaddle/PaddleOCR/blob/main/docs/version3.x/algorithm/PP-OCRv6/PP-OCRv6.en.md) — the CPU latency table, and the project's own note that v6 metrics are *not* comparable to v5's.
- [Liebl & Burghardt, arXiv:2008.02777](https://arxiv.org/abs/2008.02777) — grayscale beats binarized across every depth and line height tested; deskew still helps CRNNs.
- [OCRmyPDF — advanced](https://ocrmypdf.readthedocs.io/en/latest/advanced.html) and [`pdfinfo/info.py`](https://github.com/ocrmypdf/OCRmyPDF/blob/main/src/ocrmypdf/pdfinfo/info.py) — mode semantics and `_page_has_text`'s margin rule; the reference implementation of "does this page already have text".
- [PyMuPDF — TextPage](https://pymupdf.readthedocs.io/en/latest/textpage.html) — `get_texttrace()`, `char_flags`, and the broken bitmask example.
- [Surya — commercial usage](https://github.com/datalab-to/surya#commercial-usage) — the OpenRAIL-M weights clause. Datalab's [on-prem docs](https://documentation.datalab.to/docs/on-prem/overview) still say GPL and $2M, contradicting the README's Apache-2.0 and $5M; get written confirmation before anyone reopens this.

## Definition of done

- [ ] `ocr_options` is an explicit `RapidOcrOptions` or `TesseractOcrOptions`; a startup assertion fails the worker when the resolved engine class is not the configured one, and a test proves neither `OcrAutoOptions` nor `KserveV2OcrOptions` can reach the converter.
- [ ] `ocr_cfg_version` hashes engine id, **model revision** (v5 vs v6), `lang`, `scale`, preprocessing chain, and both thresholds; a test changes **only** `scale` and asserts a new `source_version` (`kb-source-lifecycle`).
- [ ] `scale ≥ 300/72` asserted against the configured DPI; every raster handed to Tesseract carries explicit DPI metadata inside `[70, 2400]`; worker `memory.max` sized for the 2.25× peak render.
- [ ] Confidence normalized at the adapter: a fixture OCRed under both engines yields mean page confidences within 0.05, and a Tesseract-only test proves a 0–100 value never reaches a 0–1 threshold.
- [ ] Page confidence aggregates `from_ocr=True` cells only, character-weighted; a mixed digital/OCR fixture asserts the digital cells do not lift the mean.
- [ ] A degraded scan produces `Ready with warnings` (not `Failed`), an `ocr_low_coverage` warning surfaced in §8.16's source detail, and `kb_ingestion_ocr_pages_total{disposition="partial"}`.
- [ ] `needs_ocr` asserted against six fixtures — text-native, scanned-image-only, already-OCRed invisible layer, mojibake `(cid:NN)` layer, header-only-plus-scan, and vector-only — and a text-native 200-page PDF completes with **zero** OCR pages counted.
- [ ] Models baked into the image for **every** configured language, `artifacts_path` set, `download_enabled=False`, worker on `--network none`, with a passing OCR integration test.
- [ ] `Image.open` called with `formats=DECODERS` everywhere; the §22.5 fixture set (50 000-frame GIF, decompression bomb, polyglot, oversized raster) refused with `error_class="parsing"` and no retry.
- [ ] Every OCR task declares `soft_time_limit` **and** `time_limit`; a synthetic C-level stall proves the hard limit kills it and the soft limit does not.
- [ ] `openpyxl.DEFUSEDXML is True`, `lxml >= 6.1.0`, `Pillow >= 12.3.0`, clamd running with `AlertExceedsMax yes` — asserted at worker startup, not in a comment.
