---
name: ocr-pipeline
description: OCR engine choice, DPI and preprocessing, confidence handling, and the untrusted-image security envelope for the KnowledgeBot ingestion workers. Use whenever selecting or configuring an OCR engine, setting render scale or preprocessing, deciding whether a page needs OCR at all, propagating OCR confidence into warnings, or hardening the image decode path in services/ai-service/. Docling invokes OCR; this skill decides which engine, at what DPI, and what its numbers mean. Pairs with docling-parsing (the caller).
---

# OCR Engine Selection, Tuning, and Confidence

Pinned: **Docling 2.118.0** · **RapidOCR 3.9.2** on **ONNX Runtime 1.28.0** (default) · **Tesseract 5.5.3** + **tesserocr 2.11.0** (multi-script alternative) · Pillow ≥ 12.3.0 · PyMuPDF ≥ 1.26.7 · `opencv-python-headless`. Versions read from the PyPI JSON API and the GitHub releases API on 2026-08-04 — rendered docs pages mis-date several of these releases to 2024, so trust the APIs.
**Authoritative spec:** docs/08-ingestion-pipeline.md §13.2 (stage 7), §13.4, §13.7, docs/03-functional-knowledge-sources.md §8.11, §8.16, docs/13-security.md §18.7, docs/14-reliability.md §19.4

## Non-negotiables

- **The engine is pinned by class; `OcrAutoOptions` is banned.** Docling 2.118 defaults `PdfPipelineOptions.do_ocr=True` **and** `ocr_options=OcrAutoOptions()`, and `OcrAutoModel.__init__` picks the engine by *import probing* — ocrmac → Nemotron → RapidOCR/onnxruntime → EasyOCR → RapidOCR/torch → **none, logged at WARNING with no exception**, after which `__call__` yields the page batch unchanged. The engine becomes a property of the image build rather than of configuration: a macOS laptop and the Linux worker emit different text for the same scan while `ocr_cfg_version` is identical, so nothing reprocesses; and an image missing every backend publishes every scanned source `Ready` with zero chunks.
- **`ocr_cfg_version` covers engine id, model revision (PP-OCR v5 vs v6 is a *different model*), language list, render scale, the preprocessing chain, and all *three* warn thresholds** — `CELL_TRUST_FLOOR`, `LOW_CONF_MASS_WARN`, `COVERAGE_WARN` — **plus `INK_BAND`**, which decides whether a page reports a coverage number at all. It said "both" while there were two; splitting the confidence signal into a floor and a mass added a tunable field, and a tunable field outside this string is a retune that never reaches a document. §13.3's idempotency key omits OCR entirely while §13.4 makes an OCR change a version trigger (`docs/22-spec-findings-and-decisions.md` defect 1); `kb-source-lifecycle` puts `ocr_cfg_version` back. Anything you tune here that is not folded into that string is a change that silently never reaches a re-ingest.
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

Docling reports `ConfidenceReport.ocr_score` (graded `poor` <0.5, `fair` <0.8, `good` <0.9, `excellent` ≥0.9) and `docling-parsing` persists it. **It is neither sufficient nor on a fixed scale** — see the first two Gotchas. Ours sits alongside it: normalize to 0–1, weight **by character over `from_ocr=True` cells**, and act on two independent signals, because the engine deletes its own weak output before we see it.

**Do not threshold the page mean. It was measured and it cannot fire.** A sweep across five degradation axes (ink density, defocus blur, render resolution, JPEG quantisation, additive noise) on two page shapes put the character-weighted page mean above **0.75 on every page still bearing text**, degraded or not — the worst degraded page reached 0.766 while the best clean page reached 0.860, a window under a tenth of a point wide with both edges an artefact of the engine build. The mean also *rises* again as a page degrades further, because the survivors are an increasingly selective subset. The signals are therefore:

| Warning | Question it answers | Statistic | Threshold |
|---|---|---|---|
| `ocr_low_confidence` | of the text the engine *did* read, how much is untrustworthy? | share of OCR **characters** in cells below a per-cell trust floor — never a mean, never "any cell below X" | mass > **0.30** below a floor of **0.80** |
| `ocr_low_coverage` | how much of the page did the engine not read at all? | **ink-in-box**: foreground ink inside OCR cell rects / all foreground ink | < **0.30** |
| `ocr_text_unplaced` | the engine read the page — did any of it become an element? | count of text elements the layout model placed on the page, against the presence of OCR cells | **none**, and that is deliberate |

**The third one was added 2026-08-12 and it is the only one that fires while the other two are silent *and correct*** (`docs/22` § G17). At Gaussian blur ≥ 3.0 the layout model classifies the whole page as a picture: cell recall stays **1.000**, confidence and coverage are both high and honest, and element recall is **0.000** — the text is read, is correct, and never reaches the index. Before this arm existed the page produced `warnings: []`.

**It has no threshold, and that is the design rather than an omission.** The signal is zero-versus-nonzero, so nothing entered `OCR_CFG_INPUTS` and `ocr_cfg_version` did not move — which is the only reason it could ship without re-OCR'ing every document in the platform. **If you ever give it a threshold you must add that constant to `OCR_CFG_INPUTS` in the same edit**, and pay a full reingest. The count comes from the caller (`parsing/converter.py`), never from this module: it is a fact about the document tree, and `app/ingestion/ocr/` must not learn to read the layout model's output.

Character *mass* is what makes the confidence signal work. Every second clean page carries a junk cell — a fold line, a page border, a speck — reading 0.63–0.73, so "any cell below the floor" fires on ordinary documents; and a mean lets a handful of good cells drown the bad. Mass asks the question that actually matters: what share of the text about to enter the index came from cells we do not trust. Measured separation, character-weighted: the worst acceptable page reaches 0.168, the best genuinely-degraded page 0.616.

**Coverage means ink-in-box, and the definition is not optional.** The only coverage numbers ever measured were "recovered characters / ground-truth characters", which the eval corpus can compute because it generated the page and **production cannot compute at all**. The obvious substitute is wrong in the dangerous direction: text-box area over page area scores a clean four-line signature block (0.0150) *below* a deliberately degraded page (0.0209), so it warns on the clean page and stays silent on the bad one. Normalising by the page's own ink removes the density term and reproduces the ground-truth measurement — 0.207 against a true 0.1965 on the corpus's degraded page, against 1.000 / 0.993 / 0.911 on its clean ones.

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
CELL_TRUST_FLOOR   = 0.80      # per-CELL floor; the page MEAN cannot be thresholded at all
LOW_CONF_MASS_WARN = 0.30      # warn when >30% of OCR *characters* fall below that floor
COVERAGE_WARN      = 0.30      # ink-in-box, not box-area/page-area - see the prose above
INK_BAND   = (0.0005, 0.12)    # plausible foreground fraction; outside it Otsu is reading noise

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

def _weights(cells: list[TextCell], engine: str) -> list[tuple[float, int]]:
    """(confidence, non-whitespace chars) over OCR cells only. Digital cells sit at
    1.0 and would hide an unreadable figure inside a born-digital page."""
    return [(normalize(c, engine), len("".join(c.text.split())))
            for c in cells if c.from_ocr and c.text.strip()]

def page_confidence(cells: list[TextCell], engine: str) -> float | None:
    """REPORTING ONLY - §8.16 shows it, no threshold reads it. See the prose above."""
    w = _weights(cells, engine)
    return sum(s * n for s, n in w) / sum(n for _, n in w) if w else None

def low_confidence_mass(cells: list[TextCell], engine: str) -> float | None:
    """The thresholded confidence statistic. None (not 0.0) when nothing was read:
    0.0 would read as 'all text trusted' on a page with no text."""
    w = _weights(cells, engine)
    if not w: return None
    return sum(n for s, n in w if s < CELL_TRUST_FLOOR) / sum(n for _, n in w)

def page_coverage(polys, raster) -> float:
    """Ink-in-box. NaN when the foreground mask is not measuring ink."""
    gray = raster if raster.ndim == 2 else cv2.cvtColor(raster, cv2.COLOR_RGB2GRAY)
    _, b = cv2.threshold(cv2.GaussianBlur(gray, (5, 5), 0), 0, 255,
                         cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    fg = cv2.morphologyEx(b, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8)) > 0
    ink = int(fg.sum())
    if not ink: return 1.0                       # blank; needs_ocr already skipped it
    if not (INK_BAND[0] <= ink / fg.size <= INK_BAND[1]): return float("nan")
    boxes = np.zeros(gray.shape, np.uint8)
    for p in polys: cv2.fillPoly(boxes, [np.asarray(p, np.int32)], 1)
    return float((fg & (boxes > 0)).sum()) / ink

def assess(cells: list[TextCell], engine: str, coverage: float | None,
           placed_text: int | None = None) -> list[str]:
    """Three signals — they answer different questions and do not move together.
    Coverage collapses ~3x faster than any confidence statistic, and the third fires
    while both of the others are silent and right. Warnings only (§8.11)."""
    w, mass = [], low_confidence_mass(cells, engine)
    if mass is not None and mass > LOW_CONF_MASS_WARN:
        w.append(f"ocr_low_confidence:{mass:.2f}")
    if coverage is None:                         # no OCR ran; nothing to say
        pass
    elif coverage != coverage:                   # NaN: the mask was not measuring ink
        w.append("ocr_coverage_unmeasurable")
    elif coverage < COVERAGE_WARN:
        w.append(f"ocr_low_coverage:{coverage:.2f}")
    # `None` is NOT zero. An unmeasurable count must be silent — read as zero it is the
    # warning condition, so every page of every document warns. And `from_ocr` is filtered
    # HERE: every arm filters its own input, and trusting the caller made this fire on a
    # born-digital page carrying one digital cell.
    if placed_text is not None and placed_text == 0 and any(c.from_ocr for c in cells):
        w.append("ocr_text_unplaced")
    return w
```

The task wrapping this declares **both** limits — `@celery_app.task(queue="ingest", soft_time_limit=900, time_limit=960)` (`celery-workers`) — and caps pages per task so §19.4's 30 s/page × pages stays inside the 300 s document budget: **10 OCR pages per task**, fanned out beyond that.

## Gotchas

- **The same PDF yields different text on a laptop and in production, and no reprocess is ever triggered.** `OcrAutoOptions` probed imports and found `ocrmac` on macOS, RapidOCR in the Linux image. Pin the class. Uglier variant: an image with no backend installed logs `"No OCR engine found. Please review the install details."` at WARNING and passes pages through untouched — every scanned source publishes `Ready` with zero chunks. Assert the resolved engine class at worker startup and fail the *container*, not the document.
- **Switching the engine to Tesseract makes every low-confidence warning disappear — including Docling's own.** `tesseract_ocr_model.py` writes `MeanTextConf()` — Tesseract's documented 0–100 scale (`AllWordConfidences`: *"between 0 and 100"*) — straight into `TextCell.confidence`, where RapidOCR wrote 0–1. Our `< 0.75` test then passes for every cell, and so does Docling's: `base_ocr_model` sets `ocr_score = np.mean([c.confidence for c in final_cells if c.from_ocr])` and `_score_to_grade` calls anything ≥ 0.9 `excellent`. Under Tesseract every page grades `excellent`, including a blank one. Normalize before anything reads a threshold.
- **A visibly terrible scan produces a short page at 0.9 confidence and no warning — and lowering `text_score` does not fix it.** The obvious culprit is `Global.text_score` (default **0.5**, and Docling's `EasyOcrOptions.confidence_threshold` likewise), so the obvious fix is to lower it and recover the weak lines. **Measured: it recovers essentially nothing** — re-running the degradation sweep at `text_score=0.0` recovers 0.003 of coverage. There were never weak lines to delete. Three places drop text inside PP-OCR, and the loss is essentially all in the **first**:
  1. **the DB detector proposes no region at all** below its own probability threshold — this is where the text goes. Detected-box counts collapse with degradation while nothing downstream removes any of them: 13 → 15 → 6 → 3 → 1 → 0 across an ink sweep on one page shape, and 5 on the eval corpus's degraded page against 10 / 10 / 4 on its clean ones;
  2. `RapidOCR.build_final_output` drops every box whose recognition came back empty — `valid_ids = [i for i, v in enumerate(rec_res.txts) if v.strip()]` — unconditionally, before any score threshold, **with no option to disable it**. Know it exists; measured, it fired on 1 of 14 pages and there it removed the last box of a page already down to one;
  3. `text_score` — at most one box on 2 of 14 pages.

  **Count these by instrumenting the pipeline's own stages, not by calling them separately:** `__call__` runs `preprocess_img` before detection, so a direct `text_det(raw_image)` call feeds the detector a different input and badly overstates its box count (13 against a true 3 on one measured page).

  A region the detector proposes is a region with clear enough glyph evidence that the recogniser then reads it confidently, and a recognition score compounds this — it is a mean over the characters that *decoded* (`ch_ppocr_rec/utils.py`: `np.mean(conf_list)`), so a partial decode scores on its confident survivors. Every statistic over recogniser confidence is conditioned on a selection made before recognition ran. **Degradation on this engine is expressed as absence, not as low confidence** — which is why the thresholded confidence signal is character mass below a per-cell floor, and why coverage carries the page-level judgement.

- **The detector's own scores look like the missing signal and are not.** `ch_ppocr_det/main.py` computes a per-box score and the pipeline then discards it — `RapidOCROutput.scores` is overwritten with the recogniser's. Plumbing it through is wasted work: measured, detector scores are *lowest* on a page rendered at a tenth of resolution that the engine reads at 0.92 coverage (det mean 0.577) and *highest* on a page destroyed by noise (0.800). It ranks pages by how crisp their edges are, not by whether the text was read.
- **A 200-page scan with four unreadable pages reports `ocr_score` = `good` and raises nothing.** Document-level `ocr_score` is a plain `np.nanmean` over pages, while `parse_score` deliberately uses `np.nanquantile(..., q=0.10)` "to emphasise problems". Four bad pages in 200 shift the mean by ~0.02. §8.11 wants *pages* flagged: warn per page, roll up a count, never gate on the document mean.
- **Tesseract silently decides your 300 dpi render is 70 dpi.** `kMinCredibleResolution = 70` / `kMaxCredibleResolution = 2400`: a raster with no DPI metadata, or one outside that band, is forced to **70** with only a `"Warning: Invalid resolution"` line. Both Sauvola and LeptonicaOtsu size their windows as `factor × yres`, so the binarizer is then tuned for a page 4× smaller than reality and noise removal eats small glyphs. Stamp a real DPI on every image handed to Tesseract.
- **OCR quality is mediocre everywhere and nobody can find the bug.** `OcrOptions.scale` is still 3.0 = **216 dpi**, under Tesseract's documented 300 floor. Raising it is not free: the pypdfium backend renders at `scale × 1.5` then downsamples, so peak allocation is 2.25× — raise `scale` and the worker `memory.max` in the same change, or you have traded bad text for OOM kills.
- **An already-OCRed scan gets OCRed again, doubling the error rate and the bill.** The invisible layer an OCR engine writes is PDF text render mode 3, and `get_text("dict")` cannot see render mode — the spans look like ordinary text. Use `page.get_texttrace()` (`type`, `opacity`). If you reach for `char_flags` instead (bit 3 filled, bit 4 stroked, new in PyMuPDF 1.25.2), **do not copy the snippet in PyMuPDF's own docs**: `char_flags & 2**3 & 2**4` is always 0, so it marks every character invisible. The correct test is `not (char_flags & (8 | 16))`. pdfplumber and stock pdfminer.six expose no render mode at all.
- **You set `bitmap_area_threshold=0.05` and nothing changes.** It was real — default 0.05, with a companion `BITMAP_COVERAGE_TRESHOLD = 0.75` (the typo is upstream) — through Docling 2.110.0, and it is **gone in 2.118**. Region choice now lives in `base_ocr_model._find_pdf_aware_layout_ocr_rects`, which keeps layout clusters overlapping a bitmap or overlapping no PDF cell, dilated by `DEFAULT_DILATION_SIZE = 20`. Policy is `OcrMode`; those fractions survive only as thresholds in our own `needs_ocr`.
- **A Hindi/English page loses one script silently.** RapidOCR is single-language per instance; Docling's adapter uses `lang[0]` and warns about the rest at log level. Multi-script corpora run tesserocr with `lang=["eng","hin"]` or `["auto"]` — and `["auto"]` needs the `osd` and `script/*` traineddata present under `TESSDATA_PREFIX` or the reader raises at init. Related trap from Docling's own docs: *"torch on PP-OCRv5 supports ONLY chinese."*
- **The parser container has no network and OCR fails on the first scanned page — or hangs until the hard timeout.** `docling-tools models download -o /opt/docling-artifacts` (the build step `docling-parsing` owns) fetches RapidOCR's *default* language pack only; any other configured language re-resolves checkpoints at run time. Prefetch each one explicitly — `docling-tools models download rapidocr --rapidocr-backend-lang onnxruntime:<lang> -o /opt/docling-artifacts` — and set `EasyOcrOptions.download_enabled=False` if EasyOCR is ever installed, since it defaults to **`True`**. That flag is also a security control: Docling's EasyOCR downloader was a Zip Slip → RCE, fixed in 2.94.0.
- **`import cv2` raises `ImportError: libxcb.so.1` (or `libGL.so.1`) from inside RapidOCR — and the build that worked yesterday was not correct, it was lucky.** `rapidocr` declares plain `opencv-python`; we declare `opencv-python-headless`. **Both distributions' `RECORD` files claim the same path, `cv2/cv2.abi3.so`, and neither declares a conflict with the other**, so with both resolved the winner is decided by **unpack order**. The image was not merely broken, it was *nondeterministic*: measured on this tree, the pre-existing `knowledgebot/ai-service:dev` had both dist-infos present and happened to land on the headless `.so`, and a cold-cache rebuild of the identical tree landed on the plain wheel and died in the model-download step. So the image's OCR behaviour was not determined by its manifest.

  **"Install headless over it" is the folk fix and it is wrong** — it leaves both distributions installed and the winner still decided by unpack order. So is `apt-get install libxcb1`/`libgl1`: adding X11 to a server image makes the build pass while hiding the nondeterminism, and `runtime` sits on the `data` network where Chromium's libraries deliberately do not live. **The fix is to drop the edge**, in `services/ai-service/pyproject.toml`'s `[tool.uv] override-dependencies`: `"opencv-python ; python_version < '3'"` — a marker that can never be true under `requires-python = "==3.13.*"`, which is how uv removes a requirement it cannot edit. `uv.lock`'s `[manifest]` block records it, so `uv sync --frozen` fails if the two ever disagree.

  **That override has a silent failure mode, which is why `gates.yml` asserts it: uv ignores an override naming a package nothing requires — no warning, no error.** A rapidocr bump that renames or drops the `opencv-python` requirement leaves the line in place, looking correct and doing nothing, and the next cold build is a coin flip again. The gate greps `requirements.lock` for exactly one `opencv*` distribution and that it is the headless one, plus the override in `pyproject.toml` and the `[manifest]` line in `uv.lock`, with a non-vacuity floor.

  **The operational rule: a `rapidocr` bump must re-run the OCR proof, not just the build.** A green build proves the resolution, not the behaviour. The proof is a real detect + classify + recognise pass over a scanned fixture inside the built image (`samples/corpus/documents/scanned/`), compared against the confidence the fixture README records — not `import cv2`, and not `hasattr`.

- **`hasattr(cv2, "imshow")` proves nothing about which OpenCV you have.** Headless OpenCV 5 ships the highgui *symbols* without the Qt/GTK backends behind them: measured, `hasattr` returns `True` and the call raises `cv2.error … (-2:Unspecified error) The function is not implemented.` So a startup check written that way passes under both distributions. If it mattered, it would matter in the worst way — the override converts a build failure into a runtime failure inside OCR on a tenant's document — so RapidOCR was checked against headless directly instead: all 30 `cv2.*` symbols it references are array/drawing ops present in headless, and nothing in site-packages outside `cv2` itself calls a GUI function. Re-run those two greps, not a `hasattr`, when the pin moves.
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
- [ ] `ocr_cfg_version` hashes engine id, **model revision** (v5 vs v6), `lang`, `scale`, preprocessing chain, **all three warn thresholds and `INK_BAND`**; a test changes **only** `scale` and asserts a new `source_version` (`kb-source-lifecycle`), and a second test changes **only** `CELL_TRUST_FLOOR` and asserts the same.
- [ ] `scale ≥ 300/72` asserted against the configured DPI; every raster handed to Tesseract carries explicit DPI metadata inside `[70, 2400]`; worker `memory.max` sized for the 2.25× peak render.
- [ ] Confidence normalized at the adapter: a fixture OCRed under both engines yields mean page confidences within 0.05, and a Tesseract-only test proves a 0–100 value never reaches a 0–1 threshold.
- [ ] Page confidence aggregates `from_ocr=True` cells only, character-weighted; a mixed digital/OCR fixture asserts the digital cells do not lift the mean, and the same for `low_confidence_mass`.
- [ ] **No threshold anywhere reads a page-mean confidence.** A test asserts the mean of the corpus's deliberately-degraded page is still above 0.75, so a reintroduced `mean < CONF_WARN` fails loudly instead of silently never firing.
- [ ] `low_confidence_mass` warns on the degraded corpus page and on none of the clean ones, weighted by character — a fixture with one junk cell among four good ones must **not** warn, and the same page cell-counted must.
- [ ] `page_coverage` is ink-in-box; a test asserts a sparse-but-clean page scores ~1.0 while box-area-over-page-area would put it under the threshold, and that a speckle-dominated page returns NaN → `ocr_coverage_unmeasurable` rather than a fabricated low number.
- [ ] A degraded scan produces `Ready with warnings` (not `Failed`), **both** `ocr_low_confidence` and `ocr_low_coverage` surfaced in §8.16's source detail, and `kb_ingestion_ocr_pages_total{disposition="partial"}`.
- [ ] A page the layout model calls a picture — reproducible at Gaussian blur ≥ 3.0 — emits `ocr_text_unplaced`, and the per-page record carries `placed_text` beside `ocr_cells`; a page with OCR cells *and* placed elements is silent, and a page whose count could not be measured (`None`) is silent rather than warning. `ocr_cfg_version()` is byte-identical before and after any change to this arm, or the arm has grown a tunable that owes a reingest.
- [ ] `needs_ocr` asserted against six fixtures — text-native, scanned-image-only, already-OCRed invisible layer, mojibake `(cid:NN)` layer, header-only-plus-scan, and vector-only — and a text-native 200-page PDF completes with **zero** OCR pages counted.
- [ ] Models baked into the image for **every** configured language, `artifacts_path` set, `download_enabled=False`, worker on `--network none`, with a passing OCR integration test.
- [ ] **Exactly one OpenCV distribution resolves, and it is the headless one.** `requirements.lock` carries a single `opencv*` name; `pyproject.toml` still carries the `[tool.uv] override-dependencies` entry dropping `opencv-python`; `uv.lock`'s `[manifest]` block records it. CI asserts all three, because uv **silently ignores** an override naming a package nothing requires. On any `rapidocr` bump, re-run the **OCR proof** — a full detect/classify/recognise pass over `samples/corpus/documents/scanned/scanned-po-88214.pdf` inside the built image, mean confidence compared against the fixture README — not just the build, and never `import cv2` or a `hasattr` check.
- [ ] `Image.open` called with `formats=DECODERS` everywhere; the §22.5 fixture set (50 000-frame GIF, decompression bomb, polyglot, oversized raster) refused with `error_class="parsing"` and no retry.
- [ ] Every OCR task declares `soft_time_limit` **and** `time_limit`; a synthetic C-level stall proves the hard limit kills it and the soft limit does not.
- [ ] `openpyxl.DEFUSEDXML is True`, `lxml >= 6.1.0`, `Pillow >= 12.3.0`, clamd running with `AlertExceedsMax yes` — asserted at worker startup, not in a comment.
