"""The converter as it is **constructed**, and the status branch that decides whether a
half-parsed document publishes.

Every assertion here reads the object `build_converter` returns. Reading the constants off
`app.ingestion.parsing.converter` would prove only that the module says what it says, and every
failure this file exists to catch is one where the module says the right thing and the
constructed object carries something else: an upstream default that moved, a field that was
renamed, an engine resolved by import probing at run time.

The second half is the branch. Docling is asked not to raise, so *every* outcome arrives as a
value — and a partial success is a populated document with missing pages and no exception. A
caller that reads `result.document` without reading `result.status` indexes the first three
pages of a ten-page scan and reports success, which is the single most expensive silent failure
in this stage.
"""

from __future__ import annotations

from pathlib import Path
from typing import Any, Final

import pytest

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.model_pins import pin
from app.ingestion.ocr.guarded import BANNED_OCR_OPTION_CLASSES, CELL_TRUST_FLOOR
from app.ingestion.parsing import converter as mod
from app.ingestion.parsing.converter import (
    ALLOWED_FORMATS,
    ARTIFACTS_PATH,
    INCLUDED_CONTENT_LAYERS,
    REMOTE_ACCESS_FLAGS,
    ParseResult,
    assert_converter_pins,
    build_converter,
    parse_document,
)

docling = pytest.importorskip("docling", reason="the parser stack is not installed")


@pytest.fixture(scope="module")
def converter() -> Any:
    """One converter for the module. Construction is ~7 s of torch import and no weights are
    loaded — a pipeline is only initialized on the first `convert`."""
    return build_converter()


# ── the pins, on the constructed object ──────────────────────────────────────


def test_the_layout_revision_on_the_constructed_object_is_the_manifest_pin(
    converter: Any,
) -> None:
    """The one assertion the manifest cannot make for itself.

    Upstream pins its own layout spec to `revision="main"` — a branch — so an image rebuilt a
    week later can hold different weights while `docling.__version__`, every constant in the
    module and `parser_cfg_version()` are all unchanged. Nothing reprocesses and the corpus is
    parsed by a model nobody can name.
    """
    from docling.datamodel.base_models import InputFormat

    spec = converter.format_to_options[InputFormat.PDF].pipeline_options.layout_options.model_spec
    expected = pin("docling-layout")
    assert spec.revision == expected.revision
    # Repo travels with the sha: forty hex characters index into nothing on their own, and this
    # row named a repository containing TableFormer and no layout model at all until the shas
    # were resolved.
    assert spec.repo_id == expected.repo


def test_the_engine_resolved_pair_is_pinned_too(converter: Any) -> None:
    """`engine_overrides` can point one engine at a *different* repository at an unpinned
    revision, and upstream's ONNX override does exactly that. Asserting only the base spec
    passes while the engine that runs loads weights no manifest row describes."""
    from docling.datamodel.base_models import InputFormat

    layout = converter.format_to_options[InputFormat.PDF].pipeline_options.layout_options
    expected = pin("docling-layout")
    engine_type = layout.engine_options.engine_type
    assert layout.model_spec.get_repo_id(engine_type) == expected.repo
    assert layout.model_spec.get_revision(engine_type) == expected.revision


def test_artifacts_path_is_set_and_is_the_one_directory_the_weights_are_in(
    converter: Any,
) -> None:
    """Unset means "resolve by download at parse time", which in a worker with no network fails
    on the first page rather than at startup, and reads as a corrupt document."""
    from docling.datamodel.base_models import InputFormat

    resolved = converter.format_to_options[InputFormat.PDF].pipeline_options.artifacts_path
    assert resolved is not None
    assert Path(resolved) == ARTIFACTS_PATH


def test_allowed_formats_is_the_explicit_list_and_the_xml_backends_are_not_in_it(
    converter: Any,
) -> None:
    """The upstream default is *every* backend, including the JATS, METS-GBS, USPTO and LaTeX
    ones. A format outside the list has no entry to parse with at all."""
    from docling.datamodel.base_models import InputFormat

    assert tuple(fmt.name for fmt in converter.allowed_formats) == ALLOWED_FORMATS
    assert set(converter.format_to_options) == {InputFormat[name] for name in ALLOWED_FORMATS}
    for banned in ("XML_JATS", "XML_USPTO", "METS_GBS", "LATEX"):
        assert InputFormat[banned] not in converter.format_to_options


def test_every_remote_access_flag_is_false_on_something_the_converter_holds(
    converter: Any,
) -> None:
    """Both halves matter, and the second is the one that rots. A flag no constructed object
    carries any more is a flag nothing is checking, and it reads exactly like a flag that is
    correctly off."""
    carriers: dict[str, int] = {}
    for option in converter.format_to_options.values():
        for holder in (option.pipeline_options, option.backend_options):
            if holder is None:
                continue
            for flag, expected in REMOTE_ACCESS_FLAGS.items():
                if hasattr(holder, flag):
                    carriers[flag] = carriers.get(flag, 0) + 1
                    assert getattr(holder, flag) == expected, f"{type(holder).__name__}.{flag}"
    assert set(carriers) == set(REMOTE_ACCESS_FLAGS), sorted(
        set(REMOTE_ACCESS_FLAGS) - set(carriers)
    )


def test_the_resolved_ocr_options_are_the_pinned_engine_and_never_the_auto_one(
    converter: Any,
) -> None:
    """The auto option resolves an engine by import probing, so a laptop and the Linux worker
    emit different text for the same scan while the OCR config version string is identical —
    and an image missing every backend publishes every scanned source as ready with no chunks."""
    from docling.datamodel.base_models import InputFormat
    from docling.datamodel.pipeline_options import RapidOcrOptions

    resolved = converter.format_to_options[InputFormat.PDF].pipeline_options.ocr_options
    assert isinstance(resolved, RapidOcrOptions)
    assert type(resolved).__name__ not in BANNED_OCR_OPTION_CLASSES


def test_the_banned_ocr_option_classes_are_refused_before_a_converter_exists() -> None:
    from docling.datamodel.pipeline_options import OcrAutoOptions

    with pytest.raises(KbError) as raised:
        build_converter(OcrAutoOptions())
    assert raised.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert raised.value.origin is Origin.SELF


def test_the_ocr_render_scale_is_the_ocr_module_constant_not_doclings_default(
    converter: Any,
) -> None:
    """Upstream's default render scale is 3.0 = 216 dpi, below the documented 300 dpi floor.
    The symptom is OCR that is mediocre on every document with nothing to point at."""
    from docling.datamodel.base_models import InputFormat

    from app.ingestion.ocr.guarded import OCR_DPI, OCR_SCALE

    resolved = converter.format_to_options[InputFormat.PDF].pipeline_options.ocr_options
    assert resolved.scale == OCR_SCALE
    assert round(resolved.scale * 72) == OCR_DPI


def test_the_four_loss_prevention_options_survived_into_the_pipeline(converter: Any) -> None:
    """Each reads as a harmless default and each silently degrades the index: flat heading
    levels collapse small-to-big retrieval, fast table mode matches values to columns
    positionally, and the parsed pages carry the cells the whole OCR signal is computed from."""
    from docling.datamodel.base_models import InputFormat

    pipeline = converter.format_to_options[InputFormat.PDF].pipeline_options
    assert pipeline.heading_hierarchy_options.enabled is True
    assert pipeline.table_structure_options.mode.name == "ACCURATE"
    assert pipeline.generate_parsed_pages is True
    assert pipeline.do_ocr is True


def test_the_image_format_shares_the_pdf_pipeline_options(converter: Any) -> None:
    """`IMAGE` runs the same pipeline class. Left on its own default an uploaded scan is not
    OCRed while the identical page inside a PDF is, and nothing in the record says so."""
    from docling.datamodel.base_models import InputFormat

    assert (
        converter.format_to_options[InputFormat.IMAGE].pipeline_options
        is converter.format_to_options[InputFormat.PDF].pipeline_options
    )


def test_torch_compile_is_off_on_the_layout_engine(converter: Any) -> None:
    """`ACCELERATOR_DEVICE` records the decision: torch.compile variance stays out until it is
    measured, because publication verifies an expected total. Measured cost on the four-page
    scanned fixture: 210.5 s compiled against 21.8 s not."""
    from docling.datamodel.base_models import InputFormat

    engine = converter.format_to_options[
        InputFormat.PDF
    ].pipeline_options.layout_options.engine_options
    assert getattr(engine, "compile_model", False) is False


def test_the_pdf_backend_is_the_pinned_class(converter: Any) -> None:
    from docling.backend.docling_parse_backend import DoclingParseDocumentBackend
    from docling.datamodel.base_models import InputFormat

    assert converter.format_to_options[InputFormat.PDF].backend is DoclingParseDocumentBackend


# ── the assertion itself has to be able to fail ──────────────────────────────


@pytest.mark.parametrize(
    ("mutate", "expected"),
    [
        pytest.param(
            lambda pipeline: setattr(pipeline.layout_options.model_spec, "revision", "main"),
            "layout revision",
            id="revision-back-to-the-moving-branch",
        ),
        pytest.param(
            lambda pipeline: setattr(pipeline, "artifacts_path", None),
            "artifacts_path is unset",
            id="artifacts-path-unset",
        ),
        pytest.param(
            lambda pipeline: setattr(pipeline, "enable_remote_services", True),
            "enable_remote_services",
            id="remote-services-on",
        ),
    ],
)
def test_a_converter_whose_resolved_values_moved_is_refused(mutate: Any, expected: str) -> None:
    """A gate that finds nothing is indistinguishable from a gate that is nothing. Each of
    these is exactly the shape the check exists for: the module source is untouched and correct,
    and the object that would parse the document is wrong."""
    from docling.datamodel.base_models import InputFormat

    fresh = build_converter()
    mutate(fresh.format_to_options[InputFormat.PDF].pipeline_options)
    with pytest.raises(KbError, match=expected):
        assert_converter_pins(fresh)


def test_a_flag_nothing_carries_any_more_fails_rather_than_passing(
    monkeypatch: pytest.MonkeyPatch, converter: Any
) -> None:
    """The rot case. If upstream renames a field, every `hasattr` goes false, every comparison
    is skipped, and the sweep passes with nothing checked."""
    monkeypatch.setattr(
        mod, "REMOTE_ACCESS_FLAGS", {**REMOTE_ACCESS_FLAGS, "enable_time_travel": False}
    )
    with pytest.raises(KbError, match="enable_time_travel"):
        assert_converter_pins(converter)


def test_converter_for_reuses_one_converter_per_ocr_configuration() -> None:
    """Rebuilding per document reloads the layout weights, TableFormer and three ONNX sessions
    per document."""
    first = mod.converter_for(None)
    assert mod.converter_for(None) is first
    assert build_converter() is not first


# ── the status branch, on a stub converter ───────────────────────────────────


class _Rect:
    r_x0 = r_x1 = r_x2 = r_x3 = 0.0
    r_y0 = r_y1 = r_y2 = r_y3 = 0.0


class _Cell:
    """The three fields the statistics read, plus the rectangle the coverage arm maps."""

    def __init__(self, text: str, confidence: float) -> None:
        self.text = text
        self.confidence = confidence
        self.from_ocr = True
        self.rect = _Rect()


class _ParsedPage:
    def __init__(self, cells: list[_Cell]) -> None:
        self.textline_cells = cells


class _Page:
    def __init__(self, page_no: int, cells: list[_Cell]) -> None:
        self.page_no = page_no
        self.parsed_page = _ParsedPage(cells)
        #: No raster retained, so the coverage arm reports "cannot tell" rather than a number
        #: that is not one. The confidence arm needs no raster and is the one under test here.
        self.image = None
        self.size = None


class _Document:
    def __init__(self) -> None:
        self.walk_kwargs: dict[str, Any] = {}

    def iterate_items(self, **kwargs: Any) -> list[tuple[str, int]]:
        self.walk_kwargs = kwargs
        return [("heading", 1), ("paragraph", 2)]


class _Error:
    def __init__(self, message: str, category: str) -> None:
        self._row = {"error_message": message, "category": category, "page_no": 4}

    def model_dump(self, mode: str = "python") -> dict[str, Any]:
        return dict(self._row)


class _Input:
    def __init__(self, page_count: int) -> None:
        self.page_count = page_count


class _Result:
    def __init__(
        self,
        status: str,
        errors: list[_Error],
        pages: list[_Page],
        *,
        page_count: int | None = None,
    ) -> None:
        self.status = status
        self.errors = errors
        self.pages = pages
        self.document = _Document()
        self.confidence = None
        self.input = _Input(len(pages) if page_count is None else page_count)


class _FormatOptions:
    def __init__(self, ocr_options: Any) -> None:
        self._option = type(
            "_Option", (), {"pipeline_options": type("_P", (), {"ocr_options": ocr_options})()}
        )()

    def __getitem__(self, _key: Any) -> Any:
        return self._option


class _Converter:
    def __init__(self, result: _Result) -> None:
        from docling.datamodel.pipeline_options import RapidOcrOptions

        self._result = result
        self.calls: list[dict[str, Any]] = []
        self.format_to_options = _FormatOptions(RapidOcrOptions())

    def convert(self, source: Any, **kwargs: Any) -> _Result:
        self.calls.append({"source": source, **kwargs})
        return self._result


_GOOD_CELLS: Final[list[_Cell]] = [_Cell("a legible line of text", 0.99)]
_BAD_CELLS: Final[list[_Cell]] = [_Cell("mangled scan output here", CELL_TRUST_FLOOR - 0.2)]


@pytest.fixture
def stub(monkeypatch: pytest.MonkeyPatch) -> Any:
    def install(result: _Result) -> _Converter:
        fake = _Converter(result)
        monkeypatch.setattr(mod, "converter_for", lambda ocr_options=None: fake)
        return fake

    return install


def test_a_hard_failure_is_parsing_and_is_not_retryable(stub: Any) -> None:
    """The same bytes go to the same parser on the next attempt, so a retryable classification
    spends the ingestion budget three times and fails anyway."""
    stub(_Result("failure", [_Error("page 4 backend died", "backend_failure")], []))
    with pytest.raises(KbError) as raised:
        parse_document(path=Path("doc.pdf"), max_pages=10, max_bytes=1_000)
    assert raised.value.error_class is ErrorClass.PARSING
    assert raised.value.retryable is False
    assert "page 4 backend died" in raised.value.message


def test_an_out_of_allow_list_file_is_a_failure_and_not_an_empty_success(stub: Any) -> None:
    """`SKIPPED` is what the converter returns for a format it will not parse. Treated as
    success it publishes a version with zero chunks for a file nobody read."""
    stub(_Result("skipped", [], []))
    with pytest.raises(KbError) as raised:
        parse_document(path=Path("patent.xml"), max_pages=10, max_bytes=1_000)
    assert raised.value.error_class is ErrorClass.PARSING


def test_partial_success_returns_a_warning_set_and_does_not_raise(stub: Any) -> None:
    """**The branch this whole file is for.** A ten-page scan that lost seven pages comes back
    as a value, with a populated document, and nothing raises. It publishes as `Ready with
    warnings` — retrievable and identical to `Ready` — so what must survive is the *record*:
    the status, the per-page errors, and the fact that it was cut short.
    """
    result = _Result(
        "partial_success",
        [_Error("Document processing timeout: exceeded 300.000s limit", "timeout")],
        [_Page(1, _GOOD_CELLS), _Page(2, _GOOD_CELLS), _Page(3, _BAD_CELLS)],
        page_count=10,
    )
    stub(result)

    parsed = parse_document(path=Path("scan.pdf"), max_pages=10, max_bytes=1_000)

    assert isinstance(parsed, ParseResult)
    assert parsed.status == "partial_success"
    assert parsed.truncated is True, "three pages of ten and the record does not say so"
    assert len(parsed.errors) == 1
    assert parsed.errors[0]["category"] == "timeout"
    assert parsed.errors[0]["page_no"] == 4
    # And the pages that *were* read still carry their quality, per page — never a document
    # mean, which four unreadable pages in two hundred move by about 0.02.
    page_three = parsed.confidence["pages"][3]
    assert (
        f"ocr_low_confidence:{page_three['ocr_low_confidence_mass']:.2f}"
        in (page_three["warnings"])
    )
    assert "ocr_low_confidence" not in " ".join(parsed.confidence["pages"][1]["warnings"])
    assert "ocr_low_confidence:1.00" in parsed.confidence["warnings"]


def test_a_page_whose_raster_was_not_retained_says_so_rather_than_scoring_it(
    stub: Any,
) -> None:
    """The coverage arm needs a page raster, and `GENERATE_PICTURE_IMAGES` is what keeps one.
    If it ever goes off, every page loses the arm — and the honest record of that is the word
    `assess` already has for it, not a coverage number invented from nothing and not silence.
    """
    stub(_Result("success", [], [_Page(1, _GOOD_CELLS)]))
    parsed = parse_document(path=Path("clean.pdf"), max_pages=10, max_bytes=1_000)
    assert parsed.confidence["pages"][1]["ocr_coverage"] is None
    assert parsed.confidence["pages"][1]["warnings"] == ["ocr_coverage_unmeasurable"]


def test_a_clean_success_carries_no_warnings(stub: Any) -> None:
    stub(_Result("success", [], [_Page(1, _GOOD_CELLS)]))
    parsed = parse_document(path=Path("clean.pdf"), max_pages=10, max_bytes=1_000)
    assert parsed.status == "success"
    assert parsed.truncated is False
    assert parsed.confidence["pages"][1]["ocr_confidence"] == pytest.approx(0.99)
    assert parsed.confidence["pages"][1]["ocr_low_confidence_mass"] == 0.0


def test_all_four_caps_travel_on_the_call(stub: Any) -> None:
    """All four default to unbounded upstream. `max_pages` travels twice on purpose: as the
    page total it *rejects* an over-long document, and as the end of the range it bounds the
    parse for every backend that cannot report a page total, where the total is never checked."""
    fake = stub(_Result("success", [], [_Page(1, _GOOD_CELLS)]))
    parse_document(path=Path("big.pdf"), max_pages=7, max_bytes=99_000)
    call = fake.calls[0]
    assert call["raises_on_error"] is False
    assert call["max_num_pages"] == 7
    assert call["max_file_size"] == 99_000
    assert call["page_range"] == (1, 7)


def test_the_element_walk_asks_for_the_groups_and_the_three_content_layers(stub: Any) -> None:
    """The walk defaults to `BODY` only, which silently drops the speaker notes carrying the
    narration and the per-page footer the bot must be able to cite. `with_groups=False` drops
    the nesting that `level` is the only record of."""
    result = _Result("success", [], [_Page(1, _GOOD_CELLS)])
    stub(result)
    parsed = parse_document(path=Path("deck.pptx"), max_pages=10, max_bytes=1_000)

    assert result.document.walk_kwargs["with_groups"] is True
    layers = {layer.name for layer in result.document.walk_kwargs["included_content_layers"]}
    assert layers == set(INCLUDED_CONTENT_LAYERS)
    assert "BACKGROUND" not in layers, "watermarks are not content"
    # The pairs, not the items: `level` is tree depth and exists nowhere on the item.
    assert parsed.elements == [("heading", 1), ("paragraph", 2)]
