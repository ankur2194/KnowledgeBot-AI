"""Docling `DocumentConverter` wiring, and the pinned pipeline option set as data.

**This stage produces elements, and nothing here decides a chunk boundary.** The converter's
job is to hand `kb-chunking-rules` a structure that still has its structure: heading levels,
table membership, page and slide provenance, content layers. Chunking reads that structure and
cannot recover it — once a document is flattened to text, "which heading was this under" and
"which table did this row belong to" are gone, and no downstream pass can reconstruct them.
So: never split, merge, size, or truncate anything in this module.

It is also untrusted-input execution rather than file reading. Docling drives MuPDF,
pypdfium2, Pillow, openpyxl, python-pptx and lxml over tenant bytes, so the option set below
is a security boundary as much as a quality one and every flag in `REMOTE_ACCESS_FLAGS` is off.

The options are constants rather than an inline literal in the builder for three reasons: a
test can assert the *constructed* converter's resolved values equal them (reading the source
proves nothing), `parser_cfg_version` can hash them, and each one can carry the regression it
exists to prevent. Four of them are pure loss-prevention and read as harmless defaults:

* **`HEADING_HIERARCHY_ENABLED`** — off by default. The layout model flags `SECTION_HEADER`
  without a level, so with it off *every* PDF heading stays at level 1: `heading_path` is one
  element deep for the whole corpus and small-to-big retrieval collapses into flat retrieval.
  Its font-style signal additionally requires `GENERATE_PARSED_PAGES`, and is silently skipped
  without it — two flags, one outcome, and neither reports the omission.
* **`INCLUDED_CONTENT_LAYERS`** — the element walk defaults to `BODY` only. PPTX speaker notes
  live in `NOTES` and page headers/footers in `FURNITURE`, so the default silently drops the
  slide notes that carry the actual narration and the per-page legal footer the bot is
  required to be able to cite. We keep them and *label* them; removal happens at index time,
  reversibly, never in the parser.
* **`TABLE_STRUCTURE_MODE`** — accurate mode, because a table whose header row is mis-detected
  produces chunks where values are matched to columns positionally, and the bot answers "the
  Pro plan includes 4,999 seats" with total confidence.
* **`ALLOWED_FORMATS`** — the default is *every* `InputFormat`, including the JATS, METS-GBS,
  USPTO and LaTeX backends carrying 2026 XXE and traversal CVEs. The list must equal the
  upload allow-list, and a test asserts the converter itself rejects an out-of-list file
  rather than trusting that Laravel already did.

Table serialization is **row-wise** — every column name repeated in every row — and never a
markdown or HTML grid. On ConfQuestions that is P@1 0.528 against 0.382 for markdown, and the
mechanism is obvious once a table is split: a bare `4,999 | 25` is two unlabelled integers.
The serializer lives in `serializers.py`; note that `repeat_table_header=True` does not deliver
this on its own, because `get_header_and_body_lines` is implemented only on the Markdown and
HTML serializers while the chunking default is the triplet serializer, which does not override
it — the flag reads as covered and emits nothing.

Docling invokes OCR; `app/ingestion/ocr/` decides which engine, at what DPI, and what its
numbers mean. This module sets `DO_OCR` and applies the `OcrMode` that module's classifier
returns, and owns nothing inside the `OcrOptions` object it is handed.
"""

from __future__ import annotations

import math
from collections.abc import Mapping
from dataclasses import dataclass
from importlib.metadata import PackageNotFoundError, version
from pathlib import Path
from typing import Any, Final

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.cfg_version import compose
from app.ingestion.model_pins import pin
from app.ingestion.ocr.guarded import (
    BANNED_OCR_OPTION_CLASSES,
    DEFAULT_ENGINE,
    OCR_ENGINE_CLASSES,
    OCR_SCALE,
    assess,
    low_confidence_mass,
    page_confidence,
    page_coverage,
)

__all__ = [
    "ACCELERATOR_DEVICE",
    "ACCELERATOR_NUM_THREADS",
    "ALLOWED_FORMATS",
    "ARTIFACTS_PATH",
    "CONFIDENCE_GRADES",
    "DOCUMENT_TIMEOUT_SECONDS",
    "DO_OCR",
    "EXCEL_BACKEND_FLAGS",
    "GENERATE_PARSED_PAGES",
    "GENERATE_PICTURE_IMAGES",
    "HEADING_HIERARCHY_ENABLED",
    "HTML_BACKEND_FLAGS",
    "INCLUDED_CONTENT_LAYERS",
    "PARSER_CFG_EXCLUDED",
    "PARSER_CFG_INPUTS",
    "PARSER_CFG_SCHEME",
    "PARSER_MODEL_PINS",
    "PDF_BACKEND",
    "REMOTE_ACCESS_FLAGS",
    "TABLE_SERIALIZER_VERSION",
    "TABLE_STRUCTURE_MODE",
    "ParseResult",
    "assert_converter_pins",
    "build_converter",
    "converter_for",
    "parse_document",
    "parser_cfg_version",
]

#: Weights are baked into the image and loaded from here — never fetched at run time. ~550 MB
#: downloaded inside a Celery task is an ingestion timeout, and it happens once per worker
#: container rather than once per cluster.
#:
#: **`/models`, and it must stay equal to four other places.** It is the Compose volume
#: mountpoint (`models:/models`) in all three compose files, `KB_MODEL_CACHE_DIR` in both env
#: templates, `Settings.model_cache_dir`'s default, and `HF_HOME` in the Dockerfile — which is
#: the variable that actually drives caching for docling, docling-ibm-models and huggingface_hub.
#: This constant read `/opt/docling-artifacts` and was the only place that value existed
#: anywhere in the repository.
#:
#: The disagreement was invisible in exactly the way that matters: with `HF_HUB_OFFLINE=1` set
#: and the weights present under `/models`, a converter pointed at `/opt/docling-artifacts` does
#: not fall back to a slow download — it fails on the first page a worker tries to parse, in a
#: container with no network, long after startup, and reads as a corrupt document rather than a
#: misconfigured path.
ARTIFACTS_PATH: Final[Path] = Path("/models")

#: `kb-error-taxonomy`'s number, not ours — it owns the timeout ladder and nests this inside
#: the 900 s ingestion job. **Never lower it.** The OCR page cap is derived from exactly this
#: budget (30 s/page x 10 pages), so a scan sized to that cap needs all 300 s. And exceeding it
#: returns partial success with partial content rather than raising, so the pages after the cut
#: never index while the source publishes as a minor warning. Default is unbounded.
DOCUMENT_TIMEOUT_SECONDS: Final[float] = 300.0

DO_OCR: Final[bool] = True

#: The default `docling-parse` v5+ backend. `PYPDFIUM2` is faster and structurally weaker;
#: `THREADED_DOCLING_PARSE` parallelizes pages *within* one document and fights the per-worker
#: CPU budget — parallelism comes from the queue, not from inside a document.
PDF_BACKEND: Final[str] = "DOCLING_PARSE"

#: CPU pinned for ingestion. Determinism is unproven upstream and §13.5's expected-total check
#: depends on it, so GPU kernels and torch.compile variance stay out until measured.
ACCELERATOR_DEVICE: Final[str] = "CPU"
ACCELERATOR_NUM_THREADS: Final[int] = 4

TABLE_STRUCTURE_MODE: Final[str] = "ACCURATE"
HEADING_HIERARCHY_ENABLED: Final[bool] = True
GENERATE_PARSED_PAGES: Final[bool] = True
GENERATE_PICTURE_IMAGES: Final[bool] = True

#: Must equal the upload allow-list. Explicit, never defaulted.
ALLOWED_FORMATS: Final[tuple[str, ...]] = (
    "PDF",
    "DOCX",
    "PPTX",
    "XLSX",
    "HTML",
    "MD",
    "CSV",
    "IMAGE",
)

#: `BACKGROUND` is excluded on purpose — watermarks are not content. `FURNITURE` and `NOTES`
#: are included for the reasons in the module docstring.
INCLUDED_CONTENT_LAYERS: Final[tuple[str, ...]] = ("BODY", "FURNITURE", "NOTES")

#: Every one False. `render_page` is Playwright; `enable_local_fetch` reads the worker's own
#: filesystem on behalf of a tenant document. The worker also runs with no network namespace,
#: so these are the second layer, not the only one.
REMOTE_ACCESS_FLAGS: Final[Mapping[str, bool]] = {
    "enable_remote_services": False,
    "allow_external_plugins": False,
    "enable_remote_fetch": False,
    "enable_local_fetch": False,
    "render_page": False,
    "fetch_images": False,
}

#: `infer_furniture` defaults True and classifies *everything before the first header* as
#: furniture, which on a crawled page is the intro paragraph. It stays on, because the layer is
#: kept and labelled rather than dropped — but the labelling is what makes that safe.
HTML_BACKEND_FLAGS: Final[Mapping[str, bool]] = {
    "render_page": False,
    "fetch_images": False,
    "enable_remote_fetch": False,
    "enable_local_fetch": False,
}

#: `gap_tolerance` stays at 0 so each contiguous block on a sheet becomes its own table — that
#: is what lets `table_ref` distinguish two tables on one sheet. Raising it merges unrelated
#: blocks into one table and the bot answers one region from another's rows. Chart *rendering*
#: needs LibreOffice, which the worker does not have.
EXCEL_BACKEND_FLAGS: Final[Mapping[str, bool | int]] = {
    "parse_charts": True,
    "render_chart_images": False,
    "gap_tolerance": 0,
}

#: Docling's own grade bands for `parse_score` / `layout_score` / `table_score` / `ocr_score`.
#: Persisted per version and rendered as the §8.11 warning set. Note the document-level
#: `ocr_score` is a plain mean, so four unreadable pages in 200 move it by ~0.02 — warn per
#: page and roll up a total; never gate on the document mean.
CONFIDENCE_GRADES: Final[Mapping[str, float]] = {
    "poor": 0.5,
    "fair": 0.8,
    "good": 0.9,
    "excellent": 1.0,
}

#: Row-wise table verbalization, every column name repeated in every row — never a markdown or
#: HTML grid. Versioned separately from the option set because it changes the *text that is
#: embedded* without changing any Docling option, which is the one way this stage can alter the
#: index while every constant above stays put.
#:
#: It belongs in `serializers.py` beside the serializer itself, and moves there when that module
#: lands. **Moving it must not change its value** — a relocation is not a reprocess.
TABLE_SERIALIZER_VERSION: Final[str] = "rowwise/v1"

#: Bumped only to force a global reparse of every document. See `app/ingestion/cfg_version.py`.
PARSER_CFG_SCHEME: Final[str] = "parser/v1"

#: The model revisions this stage's output depends on, by `models.manifest.toml` id. Docling
#: pins its own specs to `revision="main"`, a moving branch, so without these a rebuilt image
#: parses the same corpus differently while every constant above — and `docling.__version__` —
#: is unchanged.
PARSER_MODEL_PINS: Final[tuple[str, ...]] = ("docling-layout", "docling-tableformer")

#: The option constants folded into `parser_cfg_version`, **as data**, so a test can assert none
#: was dropped rather than hoping a reviewer notices. A tunable outside this tuple is a retune
#: that never reaches a document: the content hash is unchanged, the ingest key matches the
#: completed run, and the admin sees "already processed".
PARSER_CFG_INPUTS: Final[tuple[str, ...]] = (
    "ACCELERATOR_DEVICE",
    "ACCELERATOR_NUM_THREADS",
    "ALLOWED_FORMATS",
    "DOCUMENT_TIMEOUT_SECONDS",
    "DO_OCR",
    "EXCEL_BACKEND_FLAGS",
    "GENERATE_PARSED_PAGES",
    "GENERATE_PICTURE_IMAGES",
    "HEADING_HIERARCHY_ENABLED",
    "HTML_BACKEND_FLAGS",
    "INCLUDED_CONTENT_LAYERS",
    "PDF_BACKEND",
    "REMOTE_ACCESS_FLAGS",
    "TABLE_SERIALIZER_VERSION",
    "TABLE_STRUCTURE_MODE",
)

#: Constants deliberately left OUT, each with the reason, so the exclusion is a decision on the
#: record rather than an omission. A test asserts inputs and exclusions together cover every
#: option constant this module defines, which is what stops a new constant from being silently
#: neither.
PARSER_CFG_EXCLUDED: Final[Mapping[str, str]] = {
    "ARTIFACTS_PATH": (
        "where the weights sit on disk, not which weights they are — the revisions carry that, "
        "and moving the directory must not reprocess a corpus"
    ),
    "CONFIDENCE_GRADES": (
        "labels applied to scores that are persisted raw per version, so re-grading is a "
        "recomputation over stored numbers and needs no reparse"
    ),
}


@dataclass(frozen=True, slots=True)
class ParseResult:
    """What the parse stage commits. `status` and `errors` are persisted alongside the
    elements because partial success is the dangerous outcome: the document is populated,
    half the pages are blank, and nothing raised.

    `truncated` is separate from the confidence warnings on purpose — a document cut short by
    the timeout and a document that is merely blurry are different failures with the same
    lifecycle state, and folding them together makes "the clause is missing from page 8"
    indistinguishable from "the scan is grainy" on the source detail page.
    """

    document: Any
    elements: list[Any]
    confidence: Any
    errors: tuple[Mapping[str, Any], ...]
    status: str
    truncated: bool


#: The one PDF backend name this module accepts, resolved to its class lazily. A name outside
#: this mapping is a configuration error rather than a fallback: `PdfFormatOption`'s own default
#: happens to be the same class today, so a typo in `PDF_BACKEND` would otherwise be invisible.
_PDF_BACKEND_CLASSES: Final[Mapping[str, tuple[str, str]]] = {
    "DOCLING_PARSE": ("docling.backend.docling_parse_backend", "DoclingParseDocumentBackend"),
}

#: Docling statuses that are a hard failure of the whole document. `SKIPPED` is here because it
#: is what an out-of-`ALLOWED_FORMATS` file produces: the converter refuses it and returns, and
#: treating that as success publishes an empty version for a file nobody parsed. `PENDING` and
#: `STARTED` cannot survive a completed `convert()` — if one does, the pipeline returned early
#: and the document is not there.
_FAILED_STATUSES: Final[frozenset[str]] = frozenset({"failure", "skipped", "pending", "started"})

#: One converter per distinct OCR configuration, because constructing one is cheap and
#: *initializing its pipeline* loads the layout weights, TableFormer and three ONNX sessions.
#: Docling caches initialized pipelines on the converter instance, so a converter rebuilt per
#: document reloads ~500 MB of weights per document. Keyed on the OCR options rather than held
#: as a single global: the engine is admin-selectable per source.
_CONVERTERS: dict[str, Any] = {}


def _ocr_key(ocr_options: Any | None) -> str:
    """A stable cache key for one OCR configuration.

    `model_dump_json` rather than `repr`: two pydantic models with equal fields have equal JSON
    and unequal default reprs on some versions, and a key that changes per instance turns the
    cache into a memory leak that reloads the weights anyway.
    """
    if ocr_options is None:
        return "default"
    dump = getattr(ocr_options, "model_dump_json", None)
    body = dump() if callable(dump) else repr(ocr_options)
    return f"{type(ocr_options).__module__}.{type(ocr_options).__qualname__}:{body}"


def _fail(detail: str) -> KbError:
    """Our own defect, so `Origin.SELF`: no retry repairs a converter built wrong, and the
    correct blast radius is the worker container rather than one tenant's document."""
    return KbError(ErrorClass.INTERNAL_DEPENDENCY, detail, origin=Origin.SELF)


def _resolve(dotted: str) -> Any:
    from importlib import import_module

    module_name, _, attribute = dotted.rpartition(".")
    try:
        return getattr(import_module(module_name), attribute)
    except (ImportError, AttributeError) as exc:
        raise _fail(f"cannot resolve {dotted!r}: {exc}") from exc


def _default_ocr_options() -> Any:
    """The pinned default engine class, never Docling's auto option.

    Only `scale` is set, and it is `app/ingestion/ocr/`'s constant rather than ours: Docling's
    default render scale is 3.0 = 216 dpi, below Tesseract's documented 300 dpi floor, and the
    symptom is mediocre OCR everywhere with nothing to point at. Everything else inside the
    object — language, mode, thresholds — belongs to that module and arrives through the
    `ocr_options` argument.
    """
    return _resolve(OCR_ENGINE_CLASSES[DEFAULT_ENGINE])(scale=OCR_SCALE)


def _engine_name(ocr_options: Any) -> str:
    """The engine id behind a resolved options object — the argument every statistic in
    `guarded` takes, because one confidence field carries three different units."""
    dotted = f"{type(ocr_options).__module__}.{type(ocr_options).__qualname__}"
    for name, path in OCR_ENGINE_CLASSES.items():
        if path == dotted or path.rsplit(".", 1)[-1] == type(ocr_options).__name__:
            return name
    raise _fail(
        f"OCR options {dotted} belong to no engine in OCR_ENGINE_CLASSES; the confidence scale "
        "of an unknown engine is unknown, and reading a 0-100 score as 0-1 silently removes "
        "every low-confidence warning"
    )


def _backend_options(options_class: Any, *extra: Mapping[str, Any]) -> Any:
    """One backend-options object, carrying every flag from `REMOTE_ACCESS_FLAGS` the class
    actually has, plus the per-format constants.

    Filtered by `model_fields` rather than restated per format: a flag that upstream moves or
    renames then disappears from the constructed object, and `assert_converter_pins` fails on
    the flag nobody is checking any more instead of passing quietly.
    """
    values: dict[str, Any] = {}
    for source in (REMOTE_ACCESS_FLAGS, *extra):
        values.update({k: v for k, v in source.items() if k in options_class.model_fields})
    return options_class(**values)


def build_converter(ocr_options: Any | None = None) -> Any:
    """Construct the `DocumentConverter` from the constants above.

    Docling and its transitive torch stack are imported **inside** this function, not at module
    scope: `app.ingestion.tasks` is imported by autodiscovery in every worker and by the API
    image's registration check, and neither should pay a multi-second torch import to find out
    which tasks exist.

    `ocr_options` is owned by `app/ingestion/ocr/`. Passing `None` here means "use the pinned
    default engine class", never "let Docling choose" — the auto option resolves the engine by
    import probing, which makes the engine a property of the image rather than of
    configuration, and its no-engine path logs a warning and passes pages through untouched.

    THE LAYOUT REVISION MUST BE PASSED HERE, OR THE MANIFEST PIN IS DECORATION
    -------------------------------------------------------------------------
    This is the only place the pin becomes real, and skipping it costs nothing visible.
    `DOCLING_LAYOUT_HERON` — `LayoutOptions.model_spec`'s default — is
    `LayoutModelConfig(name='docling_layout_heron', repo_id='docling-project/docling-layout-heron',
    revision='main')`. **`main` is a branch.** The Dockerfile's `docling-tools models download`
    step requests it by that same default, so what the image contains is main's head on the day
    the image was built, not `models.manifest.toml`'s `8f39ad3c…`. Two images built a week apart
    from an identical commit can therefore hold different layout weights while
    `docling.__version__`, every constant in this module, and `parser_cfg_version()` are all
    identical — so nothing reprocesses, and the corpus is parsed by a model nobody can name.

    Take **both** `repo_id` and `revision` from the pin. A sha is not an identity on its own:
    forty hex characters mean nothing without the repository they index into, and this row
    named `ds4sd/docling-models` — which contains TableFormer and no layout model at all —
    until the shas were resolved.

    TableFormer needs no equivalent: `TableStructureModel.download_models` already requests
    `revision="v2.3.0"`, and the manifest pins the commit that tag names.

    TWO CORRECTIONS MEASURED AGAINST THE PINNED 2.118.0, BOTH IN THE DIRECTION THAT MATTERS
    ---------------------------------------------------------------------------------------
    * **`LayoutOptions` is deprecated upstream and warns on construction.** The default
      `PdfPipelineOptions.layout_options` is `LayoutObjectDetectionOptions`, whose `model_spec`
      is an `ObjectDetectionModelSpec` — a different class that happens to carry the same
      `repo_id` / `revision` fields, so the startup assert below reads the same path either way.
      The suite runs `-W error::DeprecationWarning`, so building the deprecated object would
      make every test that constructs a converter fail on the warning rather than the wiring.
    * **With `artifacts_path` set, the revision is not consulted at load time.**
      `resolve_model_artifacts_path` returns `artifacts_path / repo_id.replace("/", "--")` and
      never looks at the revision — so `repo_id` is load-bearing at run time and `revision` is
      not. That does not make passing it pointless, it makes it *evidence*: the value travels
      into `parser_cfg_version`, and the assert below is what fails a container whose upstream
      default has moved to weights the manifest does not name. What proves the bytes on disk are
      the pinned commit is the image build, not this call — and it is checkable:
      `/models/hub/models--docling-project--docling-layout-heron/refs/main` in
      `knowledgebot/ai-service:dev` holds `8f39ad3c0b4c58e9c2d2c84a38465abf757272d8`, which is
      this row's revision. The download step resolved the branch to the same commit the manifest
      names, so the image and the pin agree today. Nothing enforces that they keep agreeing.

    `compile_model` is switched off on the layout engine, and that is the same decision
    `ACCELERATOR_DEVICE` records rather than a new one: torch.compile variance stays out until
    it is measured. It is not free either way — measured on the four-page scanned fixture,
    210.5 s with it on against 21.8 s with it off, for identical output.

    A startup assert belongs on the **constructed** object, comparing
    `converter.format_to_options[InputFormat.PDF].pipeline_options.layout_options.model_spec.revision`
    against `pin("docling-layout").revision`. Reading it off this source proves nothing, and the
    failure this catches is silent by construction. See `assert_converter_pins`, which this
    calls before returning — a converter that fails it is never handed to a document.
    """
    from docling.datamodel.accelerator_options import AcceleratorDevice, AcceleratorOptions
    from docling.datamodel.backend_options import (
        HTMLBackendOptions,
        MarkdownBackendOptions,
        MsExcelBackendOptions,
        MsPowerpointBackendOptions,
        MsWordBackendOptions,
        PdfBackendOptions,
    )
    from docling.datamodel.base_models import InputFormat
    from docling.datamodel.pipeline_options import (
        HeadingHierarchyOptions,
        LayoutObjectDetectionOptions,
        PdfPipelineOptions,
        TableFormerMode,
        TableStructureOptions,
    )
    from docling.document_converter import (
        CsvFormatOption,
        DocumentConverter,
        ExcelFormatOption,
        HTMLFormatOption,
        ImageFormatOption,
        MarkdownFormatOption,
        PdfFormatOption,
        PowerpointFormatOption,
        WordFormatOption,
    )

    resolved_ocr = _default_ocr_options() if ocr_options is None else ocr_options
    if type(resolved_ocr).__name__ in BANNED_OCR_OPTION_CLASSES:
        raise _fail(
            f"{type(resolved_ocr).__name__} may not reach the converter: the auto option "
            "resolves an engine by import probing, so the same scan reads differently on a "
            "laptop and on the worker while the OCR config version is identical, and the "
            "KServe option is a network call from a container that has no network"
        )

    layout_pin = pin("docling-layout")
    layout_options = LayoutObjectDetectionOptions()
    if hasattr(layout_options.engine_options, "compile_model"):
        layout_options.engine_options = layout_options.engine_options.model_copy(
            update={"compile_model": False}
        )
    layout_options.model_spec = layout_options.model_spec.model_copy(
        update={"repo_id": layout_pin.repo, "revision": layout_pin.revision}, deep=True
    )

    pdf_pipeline = PdfPipelineOptions(
        document_timeout=DOCUMENT_TIMEOUT_SECONDS,
        artifacts_path=ARTIFACTS_PATH,
        accelerator_options=AcceleratorOptions(
            device=AcceleratorDevice[ACCELERATOR_DEVICE],
            num_threads=ACCELERATOR_NUM_THREADS,
        ),
        do_ocr=DO_OCR,
        ocr_options=resolved_ocr,
        do_table_structure=True,
        table_structure_options=TableStructureOptions(
            mode=TableFormerMode[TABLE_STRUCTURE_MODE], do_cell_matching=True
        ),
        layout_options=layout_options,
        heading_hierarchy_options=HeadingHierarchyOptions(enabled=HEADING_HIERARCHY_ENABLED),
        generate_parsed_pages=GENERATE_PARSED_PAGES,
        generate_picture_images=GENERATE_PICTURE_IMAGES,
        **{
            flag: value
            for flag, value in REMOTE_ACCESS_FLAGS.items()
            if flag in PdfPipelineOptions.model_fields
        },
    )

    module_name, class_name = _PDF_BACKEND_CLASSES[PDF_BACKEND]
    pdf_backend = _resolve(f"{module_name}.{class_name}")

    # `IMAGE` shares `StandardPdfPipeline` with `PDF`, so it shares the pipeline options —
    # including `do_ocr`. Giving it its own default would leave uploaded scans un-OCRed while
    # the identical page inside a PDF is read, with nothing in the record saying so.
    options_by_format: dict[str, Any] = {
        "PDF": PdfFormatOption(
            pipeline_options=pdf_pipeline,
            backend=pdf_backend,
            backend_options=_backend_options(PdfBackendOptions),
        ),
        "IMAGE": ImageFormatOption(pipeline_options=pdf_pipeline),
        "DOCX": WordFormatOption(backend_options=_backend_options(MsWordBackendOptions)),
        "PPTX": PowerpointFormatOption(
            backend_options=_backend_options(MsPowerpointBackendOptions)
        ),
        "XLSX": ExcelFormatOption(
            backend_options=_backend_options(MsExcelBackendOptions, EXCEL_BACKEND_FLAGS)
        ),
        "HTML": HTMLFormatOption(
            backend_options=_backend_options(HTMLBackendOptions, HTML_BACKEND_FLAGS)
        ),
        "MD": MarkdownFormatOption(backend_options=_backend_options(MarkdownBackendOptions)),
        # The CSV backend takes no options object upstream, so there is nothing to pin here and
        # nothing for the flag sweep to read on it.
        "CSV": CsvFormatOption(),
    }
    unbuilt = [name for name in ALLOWED_FORMATS if name not in options_by_format]
    if unbuilt:
        raise _fail(
            f"{unbuilt} are in ALLOWED_FORMATS with no explicit format option; Docling would "
            "fall back to its own default for them, which is how a backend nobody chose ends "
            "up parsing a tenant document"
        )

    converter = DocumentConverter(
        allowed_formats=[InputFormat[name] for name in ALLOWED_FORMATS],
        format_options={InputFormat[name]: options_by_format[name] for name in ALLOWED_FORMATS},
    )
    assert_converter_pins(converter)
    return converter


def assert_converter_pins(converter: Any) -> None:
    """Every startup assertion, on the **constructed** object. Raises; never returns a verdict.

    Reading these values off this module's source proves only that the source says what it
    says. Each failure below is silent by construction — an upstream default that moved, a flag
    that was renamed, an engine resolved by import probing — so the check has to interrogate the
    object that will actually parse a document.

    All problems are collected before raising. A converter with three of these wrong should say
    so once, not three deploys running.
    """
    from docling.datamodel.base_models import InputFormat

    problems: list[str] = []
    layout_pin = pin("docling-layout")
    pdf_option = converter.format_to_options[InputFormat.PDF]
    pipeline = pdf_option.pipeline_options

    spec = pipeline.layout_options.model_spec
    if spec.revision != layout_pin.revision:
        problems.append(
            f"layout revision is {spec.revision!r}, not {layout_pin.revision!r} — upstream pins "
            "its own spec to a moving branch, so this is how a rebuilt image parses the same "
            "corpus differently with every config version unchanged"
        )
    if spec.repo_id != layout_pin.repo:
        problems.append(f"layout repo is {spec.repo_id!r}, not {layout_pin.repo!r}")

    # The engine-resolved pair, not just the base spec: `engine_overrides` can point one engine
    # at a different repository at an unpinned revision, and the ONNX override upstream does
    # exactly that.
    engine_type = pipeline.layout_options.engine_options.engine_type
    effective_repo = spec.get_repo_id(engine_type)
    effective_revision = spec.get_revision(engine_type)
    if (effective_repo, effective_revision) != (layout_pin.repo, layout_pin.revision):
        problems.append(
            f"the {engine_type} engine resolves to {effective_repo!r}@{effective_revision!r}, "
            f"not {layout_pin.repo!r}@{layout_pin.revision!r} — an engine override names "
            "weights that no row of models.manifest.toml describes"
        )

    if pipeline.artifacts_path is None:
        problems.append(
            "artifacts_path is unset, so the weights are resolved by download at parse time — "
            "in a worker with no network that fails on the first page, long after startup"
        )
    elif Path(pipeline.artifacts_path) != ARTIFACTS_PATH:
        problems.append(f"artifacts_path is {pipeline.artifacts_path!r}, not {ARTIFACTS_PATH!r}")

    allowed = tuple(fmt.name for fmt in converter.allowed_formats)
    if allowed != ALLOWED_FORMATS:
        problems.append(
            f"allowed_formats is {allowed!r}, not {ALLOWED_FORMATS!r}; the upstream default is "
            "every backend, including the XML ones carrying XXE and traversal advisories"
        )

    resolved_ocr = pipeline.ocr_options
    ocr_class = f"{type(resolved_ocr).__module__}.{type(resolved_ocr).__qualname__}"
    if ocr_class not in set(OCR_ENGINE_CLASSES.values()):
        problems.append(
            f"the resolved OCR options are {ocr_class}, which is not one of "
            f"{sorted(OCR_ENGINE_CLASSES.values())}"
        )

    # Every flag, wherever it lives, and a coverage check on top: a flag that no constructed
    # object carries is a flag this function is no longer checking, which reads identically to
    # a flag that is correctly False.
    checked: set[str] = set()
    for input_format, option in converter.format_to_options.items():
        for holder in (option.pipeline_options, option.backend_options):
            if holder is None:
                continue
            for flag, expected in REMOTE_ACCESS_FLAGS.items():
                if not hasattr(holder, flag):
                    continue
                checked.add(flag)
                actual = getattr(holder, flag)
                if actual != expected:
                    problems.append(
                        f"{input_format.name}.{type(holder).__name__}.{flag} is {actual!r}, "
                        f"not {expected!r}"
                    )
    unchecked = sorted(set(REMOTE_ACCESS_FLAGS) - checked)
    if unchecked:
        problems.append(
            f"no constructed option object carries {unchecked}, so nothing verifies them; "
            "upstream renamed or moved the field and the flag is now a comment"
        )

    if problems:
        raise _fail("the constructed converter does not match its pins: " + "; ".join(problems))


def converter_for(ocr_options: Any | None = None) -> Any:
    """The cached converter for one OCR configuration. See `_CONVERTERS`.

    Separate from `build_converter` so a test can always get a fresh object, and so the cache is
    never what a pin assertion is read from.
    """
    key = _ocr_key(ocr_options)
    converter = _CONVERTERS.get(key)
    if converter is None:
        converter = build_converter(ocr_options)
        _CONVERTERS[key] = converter
    return converter


def parse_document(
    *,
    path: Path,
    max_pages: int,
    max_bytes: int,
    ocr_options: Any | None = None,
) -> ParseResult:
    """Convert one document to elements.

    All four caps travel on the call — `DOCUMENT_TIMEOUT_SECONDS`, `max_pages`, `max_bytes`,
    and the page range — because all four default to unbounded and a 4 000-page fixture is
    otherwise a worker that never returns.

    `raises_on_error=False`, then branch on the status explicitly: a hard failure is
    `error_class="parsing"`; partial success is a *warning* set and publishes as `Ready with
    warnings`, which is retrievable and identical to `Ready`. Swallowing the per-page errors is
    how a ten-page scan indexes its first three pages and reports success.

    The element walk passes `INCLUDED_CONTENT_LAYERS` explicitly and `with_groups=True`.

    WHY `max_pages` TRAVELS TWICE
    -----------------------------
    As `max_num_pages` it *rejects* an over-long document, and that is the intended outcome:
    silently indexing ten pages of a four-thousand-page file publishes a version that answers
    confidently from 0.25% of the source. As the end of `page_range` it *bounds the parse*, and
    it is not redundant — upstream only checks `max_num_pages` when the backend is paginated
    and can report a page total, so for every backend that cannot the page range is the only
    cap that applies.

    `elements` is the walk's own `(item, level)` pairs, unflattened and unnormalized. `level` is
    tree depth and exists nowhere on the item, so dropping it here loses the nesting that
    `with_groups=True` was set to preserve. The normalization into the `TableElement`-shaped
    records `chunking/` describes belongs to `app/ingestion/parsing/elements.py`, which does not
    exist in this tree.
    """
    from docling_core.types.doc.common.content_layer import ContentLayer

    converter = converter_for(ocr_options)
    result = converter.convert(
        path,
        raises_on_error=False,
        max_num_pages=max_pages,
        max_file_size=max_bytes,
        page_range=(1, max_pages),
    )

    status = str(getattr(result.status, "value", result.status))
    errors = tuple(_error_row(item) for item in result.errors)

    if status in _FAILED_STATUSES:
        # Permanent: the next attempt hands the identical bytes to the identical parser. A
        # retryable classification here spends the ingestion budget three times and fails anyway.
        raise KbError(
            ErrorClass.PARSING,
            f"docling returned {status} for {path.name}: "
            + ("; ".join(str(row.get("error_message", "")) for row in errors) or "no detail"),
            retryable=False,
        )

    layers = {ContentLayer[name] for name in INCLUDED_CONTENT_LAYERS}
    elements = list(result.document.iterate_items(with_groups=True, included_content_layers=layers))

    page_total = getattr(result.input, "page_count", None)
    parsed_pages = len(result.pages)
    truncated = any(_is_timeout(row) for row in errors) or bool(
        page_total and parsed_pages and parsed_pages < page_total
    )

    return ParseResult(
        document=result.document,
        elements=elements,
        confidence=_confidence_record(result, engine=_engine_name(_resolved_ocr(converter))),
        errors=errors,
        status=status,
        truncated=truncated,
    )


def _resolved_ocr(converter: Any) -> Any:
    """The OCR options the converter actually resolved, read back off the constructed object
    rather than off the argument — the argument may have been `None`."""
    from docling.datamodel.base_models import InputFormat

    return converter.format_to_options[InputFormat.PDF].pipeline_options.ocr_options


def _error_row(item: Any) -> Mapping[str, Any]:
    """One upstream `ErrorItem` as a plain, persistable mapping.

    Plain because these are stored beside the elements and rendered on the source detail page: a
    pydantic model here makes the persisted shape a property of an upstream version.
    """
    dump = getattr(item, "model_dump", None)
    if not callable(dump):  # pragma: no cover - upstream shape change
        return {"error_message": str(item)}
    return dict(dump(mode="json"))


def _is_timeout(row: Mapping[str, Any]) -> bool:
    return str(row.get("category", "")).lower() == "timeout"


def _number(value: Any) -> float | None:
    """NaN to `None`.

    Upstream writes NaN for a score it did not measure — `parse_score` and `table_score` are NaN
    on a document with no born-digital text and no table. NaN is not JSON, and it compares false
    to itself, so a stored NaN reads as "measured, and every comparison you make against it will
    be false" rather than as "not measured".
    """
    if value is None:
        return None
    number = float(value)
    return None if math.isnan(number) else number


def _grade(value: Any) -> str | None:
    if value is None:
        return None
    return str(getattr(value, "value", value))


def _confidence_record(result: Any, *, engine: str) -> dict[str, Any]:
    """The persisted quality record: upstream's grades, our two OCR signals, per page and
    rolled up.

    **This is what makes `assess` live.** Every threshold in `app/ingestion/ocr/` was measured
    and then read by nothing, because no caller ever handed it cells. It is fed here.

    Per page and never on the document mean, for the reason `CONFIDENCE_GRADES` records: the
    document `ocr_score` is a plain mean, so four unreadable pages in two hundred move it by
    about 0.02 and no document-level threshold can see them.

    The coverage arm needs a page raster, and the raster available after conversion is whatever
    upstream cached at `images_scale` — 1.0, so 72 dpi, measured as 595x842 for A4. It is used
    as it is rather than paying seventeen times the page memory to retain a 300 dpi one; what
    that costs the signal is recorded on `page_coverage`, which is where the 3x3 opening lives.

    **`ocr_cells` counts OCR cells, and used to count all of them.** `_text_cells` returns the
    page's whole `textline_cells` list, digital and OCR together, and every other field on this
    record is over the OCR subset alone. Measured 2026-08-12, that made a born-digital handbook
    page report `ocr_cells: 41` beside `ocr_confidence: None` — a page with no OCR on it at all.
    The count is what an admin reads on the source detail page to decide whether OCR ran.

    **`placed_text` is measured here and nowhere else, because it is the only place both halves
    exist.** `assess`'s third arm needs the layout model's output — how many text elements landed
    on the page — and `app/ingestion/ocr/` never sees it. At blur >= 3.0 the layout model calls
    the whole page a picture: OCR reads it perfectly, no cell becomes an element, and both of the
    older signals stay correctly silent while the page contributes nothing to the index. See
    `assess` for the measurement.

    Nothing in here may fail a parse. A quality signal that raises is a quality signal that
    takes the document with it, so a raster we cannot measure is recorded as unmeasurable —
    which is a value `assess` already has a word for, and a document shape we cannot walk is
    recorded as `None` rather than as zero placed elements.
    """
    report = getattr(result, "confidence", None)
    per_page: dict[int, dict[str, Any]] = {}
    rolled: list[str] = []

    upstream_pages = dict(getattr(report, "pages", {}) or {})
    placed = _placed_text_per_page(getattr(result, "document", None))
    for page in result.pages:
        scores = upstream_pages.get(page.page_no)
        cells = _text_cells(page)
        ocr_cells = [cell for cell in cells if cell.from_ocr]
        coverage = _page_coverage(page, ocr_cells)
        placed_text = None if placed is None else placed.get(page.page_no, 0)
        # Gated on the OCR cells, not on every text cell. The first two arms of `assess` are
        # statistics over OCR cells alone, so a born-digital page with forty digital cells has
        # nothing for either of them to measure — and used to be told so, once per page, forever.
        # The third arm needs OCR cells too: text that was never read cannot have failed to be
        # placed, and an unplaced-text warning on a page with no OCR is the same false-positive
        # class `ocr_coverage_unmeasurable` was.
        warnings = assess(ocr_cells, engine, coverage, placed_text) if ocr_cells else []
        rolled.extend(warnings)
        per_page[page.page_no] = {
            "parse_score": _number(getattr(scores, "parse_score", None)),
            "layout_score": _number(getattr(scores, "layout_score", None)),
            "table_score": _number(getattr(scores, "table_score", None)),
            "ocr_score": _number(getattr(scores, "ocr_score", None)),
            "mean_grade": _grade(getattr(scores, "mean_grade", None)),
            "low_grade": _grade(getattr(scores, "low_grade", None)),
            "ocr_cells": len(ocr_cells),
            # Beside `ocr_cells` deliberately: the pair is what separates "OCR did not run" from
            # "OCR ran and the text went nowhere", and neither number says it alone.
            "placed_text": placed_text,
            "ocr_confidence": page_confidence(ocr_cells, engine) if ocr_cells else None,
            "ocr_low_confidence_mass": (
                low_confidence_mass(ocr_cells, engine) if ocr_cells else None
            ),
            "ocr_coverage": None if coverage is None else _number(coverage),
            "warnings": warnings,
        }

    return {
        "engine": engine,
        "document": {
            "parse_score": _number(getattr(report, "parse_score", None)),
            "layout_score": _number(getattr(report, "layout_score", None)),
            "table_score": _number(getattr(report, "table_score", None)),
            "ocr_score": _number(getattr(report, "ocr_score", None)),
            "mean_grade": _grade(getattr(report, "mean_grade", None)),
            "low_grade": _grade(getattr(report, "low_grade", None)),
        },
        "pages": per_page,
        # Sorted rather than set-ordered: this string reaches the source detail page and a set's
        # iteration order would make two identical parses render differently.
        "warnings": sorted(set(rolled)),
    }


def _placed_text_per_page(document: Any) -> dict[int, int] | None:
    """How many text elements the layout model placed on each page, or `None` if that cannot be
    measured from this result.

    This is the input to `assess`'s third arm, and it lives here rather than in `app/ingestion/
    ocr/` because it is a fact about the **document tree**, not about OCR: the OCR module never
    sees the layout model's output and must not start.

    Counted over `document.texts` and keyed by the page each item's first provenance names.
    Text items are the whole population deliberately — a page whose only content is a table or
    a picture is a legitimate page, and counting tables here would make a scanned form that
    parsed into one table look like a failure. The failure being detected is *OCR read this
    page and none of it became text*, so text is what is counted.

    `None` rather than an empty dict when there is no document or no `texts` attribute. An
    empty dict would say "every page has zero text elements", which is the warning condition —
    so a result shape this function does not understand would warn on every page of every
    document. Returning `None` makes `assess` silent instead, which is the correct behaviour
    for a signal that could not be measured: the two other arms already work that way, and
    `_confidence_record`'s own docstring records that nothing in it may fail a parse.
    """
    if document is None:
        return None
    texts = getattr(document, "texts", None)
    if texts is None:
        return None

    counts: dict[int, int] = {}
    for item in texts:
        for prov in getattr(item, "prov", ()) or ():
            page_no = getattr(prov, "page_no", None)
            if isinstance(page_no, int):
                counts[page_no] = counts.get(page_no, 0) + 1
            # Only the FIRST provenance. An item spanning a page break would otherwise count
            # once per page it touches, which is right for "is there text here" and wrong for
            # nothing else this number is used for — and the loop exists at all because `prov`
            # is a list that is legitimately empty on a synthesized item.
            break
    return counts


def _text_cells(page: Any) -> list[Any]:
    """The page's text cells, **digital and OCR together**, which `GENERATE_PARSED_PAGES` is
    what retains — upstream drops `parsed_page` entirely when it is off, and the whole OCR
    signal disappears with it.

    Named for what it returns. As `_ocr_cells` it read like the OCR subset, and the one caller
    duly passed the whole list to three statistics that are defined over the subset.
    """
    parsed = getattr(page, "parsed_page", None)
    if parsed is None:
        return []
    return list(getattr(parsed, "textline_cells", ()) or ())


def _page_coverage(page: Any, ocr_cells: list[Any]) -> float | None:
    """Ink-in-box coverage for one page. `None` when no OCR ran, NaN when it could not be
    measured — and the two are different answers, which is why this no longer returns one value
    for both.

    `None` is "there is nothing to measure": no OCR cell, so no polygon, so the question does
    not arise. NaN is "OCR ran and the measurement is untrustworthy" — a page with no raster, an
    ink mask outside `INK_FRACTION_BAND`, or an imaging stack that raised. `assess` warns about
    the second and is silent about the first. Collapsing them put `ocr_coverage_unmeasurable` on
    every page of every born-digital document in the corpus.

    The polygons upstream reports are in PDF points with a top-left origin; the raster is in
    pixels at `images_scale`. Handing point coordinates to a pixel mask puts every text box in
    the top-left eighth of the page and reports a page the engine read perfectly as almost
    entirely missed, so the scale factor is taken from the raster and the page size rather than
    assumed.
    """
    if not ocr_cells:
        return None

    raster_image = getattr(page, "image", None)
    size = getattr(page, "size", None)
    if raster_image is None or size is None or not size.width or not size.height:
        return float("nan")

    scale_x = raster_image.width / size.width
    scale_y = raster_image.height / size.height
    polygons: list[list[tuple[float, float]]] = []
    for cell in ocr_cells:
        rect = cell.rect
        polygons.append(
            [
                (rect.r_x0 * scale_x, rect.r_y0 * scale_y),
                (rect.r_x1 * scale_x, rect.r_y1 * scale_y),
                (rect.r_x2 * scale_x, rect.r_y2 * scale_y),
                (rect.r_x3 * scale_x, rect.r_y3 * scale_y),
            ]
        )

    try:
        import numpy

        return page_coverage(polygons, numpy.asarray(raster_image.convert("RGB")))
    except Exception:  # a quality signal may never fail a document
        # `page_coverage` defers `import cv2` because RapidOCR pulls the non-headless build, so
        # in an image whose imaging stack is broken this is an ImportError on libGL rather than
        # anything about the page. Either way the answer is "cannot tell", not "no document".
        return float("nan")


def parser_cfg_version() -> str:
    """The identity string covering every option above **and** every model revision.

    `"parser/v1:docling2.118.0:6a1f0c9e33bd"`. It is a component of the ingest key, so a changed
    option mints new source versions and forces reprocessing. Docling pins its own model specs
    to a moving branch, so the resolved commit shas fold in here — without them "reprocess with
    new parser settings" is unfalsifiable, and a rebuilt image parses the same corpus
    differently while the library version string is unchanged.

    The Docling version is read from the **distribution metadata**, not from `docling.__version__`
    — `importlib.metadata` reads a file, while the attribute costs a multi-second torch import
    to learn a string, in a module that is imported by autodiscovery in every worker.
    """
    try:
        docling_version = version("docling")
    except PackageNotFoundError as exc:  # pragma: no cover - a worker without docling
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "docling is not installed, so the parser config version cannot name the library "
            "that produced the elements; this process cannot parse a document either",
            origin=Origin.SELF,
        ) from exc

    inputs = {name: globals()[name] for name in PARSER_CFG_INPUTS}
    return compose(
        PARSER_CFG_SCHEME,
        label=f"docling{docling_version}",
        inputs=inputs,
        pins=[pin(name) for name in PARSER_MODEL_PINS],
    )
