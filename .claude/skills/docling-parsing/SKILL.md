---
name: docling-parsing
description: Docling 2.118 document parsing for the FastAPI ingestion path — DocumentConverter wiring, pinned pipeline options, and row-wise table serialization. Use whenever converting a PDF, DOCX, XLSX, PPTX, HTML or image under services/ai-service/app/ingestion/parsing/, changing a parser option, or debugging missing speaker notes, flat heading levels, or lost table headers. Produces elements only; chunk boundaries belong to kb-chunking-rules. Pairs with ocr-pipeline (engine choice and tuning).
---

# Docling Parsing

`docling` **2.118.0** (a meta-package for `docling-slim[standard]` since 2.92.0), `docling-core` **2.90.0**, `docling-parse` **7.x**, Python ≥ 3.10. Config identity is `parser_cfg_version`.
**Authoritative spec:** docs/08-ingestion-pipeline.md §13.2, docs/03-functional-knowledge-sources.md §8.10–8.11, docs/05-tech-stack.md §9.10

## Non-negotiables

- **This is untrusted-input execution, not file reading.** Docling drives MuPDF/pypdfium2, Pillow, openpyxl, python-pptx and lxml over tenant bytes. Pin ≥ 2.94.0, run in the no-network sandbox, keep `enable_remote_services`, `allow_external_plugins`, `enable_remote_fetch`, `enable_local_fetch` and the HTML `render_page` all off, and set `allowed_formats` explicitly. Full rules and version floors: `kb-security-baseline` → `references/file-upload-safety.md`.
- **`parser_cfg_version` covers every option *and* every model revision.** A changed option or model mints a new source version and forces reprocessing (`kb-source-lifecycle` §13.4). Docling pins model specs to `revision="main"`, a moving branch — resolve each repo to a commit SHA at image build and fold those SHAs into the string, or "reprocess with new parser settings" is unfalsifiable.
- **We emit elements; `kb-chunking-rules` decides boundaries.** Never split, merge, or size anything here. Never emit a markdown/HTML table grid into text a chunker will consume — row-wise verbalization measures P@1 **0.528 vs 0.382** for markdown ([RAGonite, WSDM '25](https://arxiv.org/abs/2412.10571)), a deliberate deviation from Unstructured.io's published advice.
- **Models are pre-fetched at image build and loaded from `artifacts_path`.** ~550 MB of weights (layout 172 MB, TableFormer 358 MB, figure classifier 16 MB, plus RapidOCR) downloaded inside a Celery task is an ingestion timeout, and it happens once per worker container, not once per cluster.
- **Every conversion carries all four caps** (§8.10): `document_timeout`, `max_num_pages`, `max_file_size`, `page_range`. All four default to unbounded (`None` / `sys.maxsize`). **`document_timeout` is not this skill's number: `kb-error-taxonomy` owns the timeout ladder and sets document parsing at 300 s per document**, nested inside the 900 s ingestion job. Copy it; never restate it as an independent choice and never lower it (see Gotchas).
- **Parser warnings are advisory, never a failure.** `ConversionStatus.PARTIAL_SUCCESS` and low confidence map to `Ready with warnings`, which is retrievable and identical to `Ready` (`kb-source-lifecycle`). Only `FAILURE` is `Failed`.

## How we use it

### Boundaries

| Concern | Owner |
|---|---|
| Whether a page/region needs OCR, and invoking it through Docling (`do_ocr`, `OcrMode`) | **this skill** |
| OCR engine choice, language sets, `scale`/DPI, image preprocessing, per-page retry, confidence tuning | `ocr-pipeline` |
| Elements → chunks, sizes, overlap, chunk metadata schema | `kb-chunking-rules` |
| Stage orchestration, retries, timeouts, queues | `celery-workers`, `kb-source-lifecycle` |

Concretely: we set `do_ocr` and pick the `OcrMode`; `ocr-pipeline` owns the `OcrOptions` subclass instance we pass in and everything inside it.

```
services/ai-service/app/ingestion/parsing/
├── converter.py     # build_converter(), the pinned options below
├── serializers.py   # RowVerbalizingTableSerializer + provider
├── elements.py      # DoclingDocument -> document_elements rows
└── warnings.py      # ConfidenceReport/ErrorItem -> the §8.11 warning taxonomy
```

`serializers.py` in full — `RowVerbalizingTableSerializer`, `KbSerializerProvider`, and the `get_header_and_body_lines` override the first two Gotchas turn on: **[`references/table-serializer.md`](references/table-serializer.md)**.

Build step, once per image: `docling-tools models download -o /opt/docling-artifacts` (layout + TableFormer + picture classifier + RapidOCR by default), then `DOCLING_ARTIFACTS_PATH=/opt/docling-artifacts`.

Telemetry (`kb-observability-conventions`): this stage is `stage="parse"` — `kb_ingestion_stage_duration_seconds{stage,file_type}`, `kb_ingestion_pages_total{file_type}`, and `kb_ingestion_jobs_total{file_type,outcome,error_class}`. `file_type` is the fixed supported-format enum, never a tenant-supplied extension. `stage="ocr"` and `kb_ingestion_ocr_pages_total{engine,disposition}` belong to `ocr-pipeline`, where `disposition` ∈ `success` `partial` `error` — a partially-OCR'd page is `disposition="partial"`, not an error, and the label is deliberately not the shared four-value `outcome`. A `PARTIAL_SUCCESS` conversion is still `Ready with warnings`, so the job stays `outcome="success"`.

```python
# services/ai-service/app/ingestion/parsing/converter.py
from pathlib import Path

from docling.datamodel.accelerator_options import AcceleratorDevice, AcceleratorOptions
from docling.datamodel.backend_options import HTMLBackendOptions, MsExcelBackendOptions
from docling.datamodel.base_models import ConversionStatus, InputFormat
from docling.datamodel.pipeline_options import (
    HeadingHierarchyOptions, OcrMode, PdfPipelineOptions, RapidOcrOptions,
    TableFormerMode, TableStructureOptions)
from docling.document_converter import (
    DocumentConverter, ExcelFormatOption, HTMLFormatOption, ImageFormatOption,
    PdfFormatOption)
from docling_core.types.doc import ContentLayer
# The chunking-side table serializer lives in serializers.py, not here:
# references/table-serializer.md

ARTIFACTS = Path("/opt/docling-artifacts")   # baked in at build; never fetched at runtime


def build_converter(ocr_options=None) -> DocumentConverter:
    pdf = PdfPipelineOptions(
        artifacts_path=ARTIFACTS,
        # 300 s is `kb-error-taxonomy`'s number, not ours — it owns the timeout ladder
        # and nests this inside the 900 s ingestion job. Never set it lower: see Gotchas.
        document_timeout=300.0,                 # default None = unbounded (§8.10)
        enable_remote_services=False, allow_external_plugins=False,
        accelerator_options=AcceleratorOptions(device=AcceleratorDevice.CPU, num_threads=4),
        do_ocr=True,
        # `ocr-pipeline` owns this object; OcrAutoOptions would pick an engine per host
        # and defaults lang=[], so the engine's own default language silently wins.
        ocr_options=ocr_options or RapidOcrOptions(lang=["en"], mode=OcrMode.DEFAULT),
        table_structure_options=TableStructureOptions(mode=TableFormerMode.ACCURATE),
        heading_hierarchy_options=HeadingHierarchyOptions(enabled=True),  # off by default
        generate_parsed_pages=True,             # required by heading style inference
        generate_picture_images=True,           # §8.11 wants image locations
    )
    return DocumentConverter(
        # Never leave this at its default — that is *every* InputFormat, including the
        # JATS, METS-GBS, USPTO and LaTeX backends carrying 2026 XXE/traversal CVEs.
        allowed_formats=[InputFormat.PDF, InputFormat.DOCX, InputFormat.PPTX,
                         InputFormat.XLSX, InputFormat.HTML, InputFormat.MD,
                         InputFormat.CSV, InputFormat.IMAGE],
        format_options={
            InputFormat.PDF: PdfFormatOption(pipeline_options=pdf),
            InputFormat.IMAGE: ImageFormatOption(pipeline_options=pdf),
            InputFormat.HTML: HTMLFormatOption(backend_options=HTMLBackendOptions(
                render_page=False, fetch_images=False,     # render_page = Playwright
                enable_remote_fetch=False, enable_local_fetch=False)),
            InputFormat.XLSX: ExcelFormatOption(backend_options=MsExcelBackendOptions(
                parse_charts=True, render_chart_images=False)),  # rendering needs LibreOffice
        })


# FURNITURE keeps page headers/footers *labelled* rather than deleted (§14.5), NOTES
# keeps PPTX speaker notes (§8.11). Both are dropped by the default body-only walk.
LAYERS = {ContentLayer.BODY, ContentLayer.FURNITURE, ContentLayer.NOTES}


def parse(path: Path, max_pages: int, max_bytes: int):
    res = build_converter().convert(path, raises_on_error=False,
                                    max_num_pages=max_pages, max_file_size=max_bytes)
    if res.status is ConversionStatus.FAILURE:
        raise ParseFailed([e.model_dump() for e in res.errors])
    items = list(res.document.iterate_items(included_content_layers=LAYERS,
                                            with_groups=True))
    return res.document, items, res.confidence   # confidence -> the §8.11 warnings
```

### Formats

PDF and images run `StandardPdfPipeline` (layout → OCR → TableFormer → reading order). Pick `PdfBackend.DOCLING_PARSE` (the default, docling-parse v5+); `PYPDFIUM2` is faster and structurally weaker; `THREADED_DOCLING_PARSE` parallelises pages *within* one document and fights our per-worker CPU budget — leave it off and get parallelism from Celery. DOCX/PPTX/XLSX/HTML/MD/CSV run `SimplePipeline` (native readers, no models, no OCR). `.doc`/`.ppt`/`.xls` are natively supported since 2.114.0 — see Gotchas.

## Gotchas

- **Symptom: a table splits across chunks and every chunk after the first is unlabelled — despite `repeat_table_header=True`.** `BaseTableSerializer.get_header_and_body_lines` returns `header_lines = []`; only the Markdown and HTML serializers override it, and the chunking default is `TripletTableSerializer`, which does not. `HybridChunker.segment` then computes `table_prefix = ""` and repeats nothing. The flag now **defaults to True**, so it reads as covered. Fix: override the method, as above. Verified against docling-core 2.90.0.
- **Symptom: a table chunk starts mid-cell, e.g. `999; Seats included: 3`.** `TripletTableSerializer` joins the whole table with `". "` — no newlines at all — so `body_lines` is one giant line. `LineBasedTokenChunker` then falls through to `split_by_token_limit`, a binary search over *character* indices snapped to the last space. Our serializer emits one `\n`-terminated line per row precisely so line-based splitting has real boundaries.
- **Symptom: every `heading_path` is one level deep, and `kb-chunking-rules`' small-to-big retrieval collapses.** `HeadingHierarchyOptions.enabled` is **False** by default: the layout model only flags `SECTION_HEADER` without a level, so every PDF heading stays at `level=1`. Enable it. Its font-style signal additionally requires `generate_parsed_pages=True` and is *silently skipped* without it.
- **Symptom: speaker notes never appear in answers, and a per-page legal footer is missing from every document.** `iterate_items()` defaults to `DEFAULT_CONTENT_LAYERS = {ContentLayer.BODY}`. PPTX notes land in `NOTES`, page headers/footers in `FURNITURE`, watermarks in `BACKGROUND`. Pass `included_content_layers` explicitly and label at index time instead of dropping — §14.5 and `kb-chunking-rules`.
- **Symptom: the intro paragraph of a crawled page is gone.** `HTMLBackendOptions.infer_furniture` defaults True — *everything before the first header* becomes furniture. Same fix as above; it is a labelling decision, not a deletion.
- **Symptom: a rebuilt image parses the same corpus differently while `docling.__version__` is unchanged.** Every model spec pins `revision="main"` (`docling-layout-heron` last moved 2026-02-09). `docling-tools models download` snapshots whatever `main` is at build time. Resolve each `repo_id` to a commit SHA, pass it, and fold the SHAs into `parser_cfg_version`.
- **Symptom: the first document on a fresh worker takes minutes, then throughput is normal.** `InferenceSettings.compile_torch_models` defaults **True**, so torch.compile warms up on first inference — and again in every recycled prefork child (`worker_max_tasks_per_child`, see `celery-workers`). Either warm the pipeline in `worker_process_init` or set `DOCLING_INFERENCE_COMPILE_TORCH_MODELS=false`. torch.compile is also a known reproducibility hazard, which matters for §13.5's chunk-count verification.
- **Symptom: a chunk contains the literal text `<!-- rich cell -->`.** A `RichTableCell` (a cell holding nested content) had `_get_text()` called without `doc`. Always thread `doc` and `doc_serializer` through.
- **Symptom: a scanned page returns empty text while OCR is "on".** `OcrMode.DEFAULT` resolves to `PDF_AWARE_LAYOUT_REGIONS`: it OCRs only layout clusters that overlap a bitmap or overlap no PDF text cell. A page with a thin junk text layer therefore has its clusters eliminated and never reaches OCR. That is the `ocr-pipeline` escalation trigger — re-run the page with `OcrMode.FULL_PAGE`. `force_full_page_ocr` is deprecated; set `mode`.
- **Symptom: a spreadsheet answers for one region and hallucinates for an adjacent one.** `MsExcelBackendOptions.gap_tolerance` defaults to 0, so each contiguous block becomes its own `TableItem`. That is what we want — carry the sheet name and per-table index into `table_ref` so two tables on one sheet stay distinguishable. Raising it merges unrelated blocks into one table.
- **Symptom: an ordinary `.docx` is refused, or a `.pptx` blows the memory cap.** OOXML files are ZIP archives; the decompression-bomb, entry-count and ratio gates run **before** Docling. `defusedxml` must be a direct dependency (assert `openpyxl.DEFUSEDXML is True` at worker start). See `kb-security-baseline`.
- **Symptom: a 10-page scan publishes as `Ready with warnings` and only its first pages are ever answerable — the bot says a clause "is not in the document" while the clause is visibly on page 8.** `document_timeout` was set below the owner's budget (it read `180.0` here for a while). `kb-error-taxonomy` allows **300 s per document**, and `ocr-pipeline` caps a task at **10 OCR pages** by exactly that arithmetic — 30 s/page × 10 = 300 s — so a scan sized to that cap needs the full budget and a 180 s timeout cuts it in half. Because `document_timeout` returns `PARTIAL_SUCCESS` rather than raising (next Gotcha), the pages after the cut silently never index and the source lands in a state that reads like a minor OCR-quality note. Fix: take the number from `kb-error-taxonomy` (`document_timeout=300.0`) instead of picking one here, and emit a distinct `parse_timeout` warning rather than folding it into the confidence warnings, so a *truncated* document is distinguishable from a merely blurry one on the source detail.
- **Symptom: `res.document` is populated but half the pages are blank, and nothing raised.** `document_timeout` returns `PARTIAL_SUCCESS` with partial content rather than raising, and `raises_on_error=False` swallows per-page failures into `res.errors`. Always branch on `res.status` and persist `res.errors` (`ErrorItem`: `component_type`, `category`, `page_no`) plus `res.confidence` (`parse_score`/`layout_score`/`table_score`/`ocr_score`, graded `poor` <0.5, `fair` <0.8, `good` <0.9, `excellent` ≥0.9) as the §8.11 warning set.
- **Contradiction with the spec:** docs/05 §9.10 routes legacy binary Office formats through a LibreOffice conversion worker. Docling 2.114.0 added native `.doc`/`.ppt`/`.xls` backends, so that worker is now avoidable — and worth avoiding, given LibreOffice's advisory history. Not resolved here; raise it as an ADR. <!-- UNVERIFIED: fidelity of the native legacy backends vs a LibreOffice round-trip is unmeasured -->
- **Determinism is unproven, and §13.5 depends on it.** Image extraction rasterizes through pypdfium2 with float scaling; torch.compile and GPU kernels add their own variance; issue #1159 reports the same PDF converting at visibly different quality across runs. Golden-file the element count and text hash per format fixture and pin CPU inference for ingestion until measured. <!-- UNVERIFIED: no upstream determinism guarantee exists -->

## Official docs

- [Docling docs](https://docling-project.github.io/docling/) and [supported formats](https://docling-project.github.io/docling/usage/supported_formats/) — the format/backend matrix.
- [Pipeline options reference](https://docling-project.github.io/docling/reference/pipeline_options/) and [DocumentConverter](https://docling-project.github.io/docling/reference/document_converter/) — every field named above, generated from source.
- [Serialization concepts](https://docling-project.github.io/docling/concepts/serialization/) and the [advanced chunking + serialization example](https://docling-project.github.io/docling/_generated/examples/advanced_chunking_and_serialization/) — the provider pattern our table serializer plugs into.
- [Chunking concepts](https://docling-project.github.io/docling/concepts/chunking/) — read for what `kb-chunking-rules` overrides, not as our chunker.
- [Confidence scores](https://docling-project.github.io/docling/concepts/confidence_scores/) — the grades feeding our §8.11 warnings.
- [DoclingDocument](https://docling-project.github.io/docling/concepts/docling_document/) — content layers, provenance, item types.
- [docling-core source: `chunker/hierarchical_chunker.py`](https://github.com/docling-project/docling-core/blob/main/docling_core/transforms/chunker/hierarchical_chunker.py) and [`serializer/base.py`](https://github.com/docling-project/docling-core/blob/main/docling_core/transforms/serializer/base.py) — where the header-repeat no-op lives; re-check on every bump.
- [Installation](https://docling-project.github.io/docling/getting_started/installation/) — `docling-slim` extras for a lean worker image.

## Definition of done

- [ ] `allowed_formats` is explicit and equals the upload allow-list; a test asserts an `.epub`/`.tex`/JATS file is rejected by the converter, not just by Laravel.
- [ ] `enable_remote_services`, `allow_external_plugins`, `enable_remote_fetch`, `enable_local_fetch`, `render_page`, `fetch_images` all False — asserted on the constructed converter, not read off the source.
- [ ] `artifacts_path` is set and the image contains the weights; a test with the network namespace removed converts a fixture successfully.
- [ ] `document_timeout`, `max_num_pages`, `max_file_size` are passed on every `convert()`; a 4 000-page fixture is rejected before parsing. `document_timeout` equals `kb-error-taxonomy`'s **300 s** and a test asserts it is never below `ocr-pipeline`'s 10-page × 30 s cap.
- [ ] `parser_cfg_version` contains `docling.__version__`, the serializer version, and the resolved model commit SHAs; changing one option changes the string and mints a new source version.
- [ ] The custom table serializer overrides `get_header_and_body_lines`, and a test splits a 200-row table and asserts every segment opens with the `Columns:` line.
- [ ] No markdown or HTML table grid reaches chunk text — asserted by a test scanning emitted element text for `|---`.
- [ ] `iterate_items` includes `FURNITURE` and `NOTES`; a PPTX fixture's speaker notes and a PDF fixture's page footer both survive into elements.
- [ ] Heading levels vary on a multi-level PDF fixture (proves `heading_hierarchy_options.enabled` took effect).
- [ ] `res.status`, `res.errors` and `res.confidence` are persisted per version; `PARTIAL_SUCCESS` lands as `Ready with warnings`, `FAILURE` as `Failed`.
- [ ] Golden-file test per format: re-parsing an unchanged fixture yields identical element counts and text hashes.
