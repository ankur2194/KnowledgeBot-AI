"""Determinism plumbing and a tiny PDF layout engine for the golden corpus generator.

**Byte-reproducibility is the requirement this module exists for.** A fixture whose digest
changes when it is regenerated makes `corpus/manifest.toml` stale the moment anyone reruns the
generator, and the manifest is the only thing tying an evaluation score to specific bytes. Three
formats fight back, in three different ways, and each needed a different answer:

* **PDF (reportlab).** Writes `/CreationDate`, `/ModDate` and a random `/ID`. `rl_config.invariant`
  plus `Canvas(invariant=1)` pins all three. Verified: identical digest across separate processes
  and across `TZ` values.
* **OOXML zip entry timestamps (docx, xlsx, pptx).** `ZipFile.writestr(name, blob)` takes the
  entry's `date_time` from `time.localtime()`, so every part's header carries the moment it was
  written. `normalize_ooxml` rewrites every entry with a fixed timestamp, in sorted name order,
  with fixed permissions and compression.
* **`docProps/core.xml`.** This one is the trap. **openpyxl overwrites `dcterms:modified` with the
  current time inside `save()`, discarding whatever `wb.properties.modified` was set to** — so
  pinning the property does nothing and zip normalisation does not reach it. Measured: two runs of
  an otherwise identical workbook produced normalised digests `348ffacc3989aac1` and
  `11ac5018b18f4b12`. `normalize_ooxml` therefore rewrites the timestamp elements in the XML body
  as well, for all three formats, so no library's save-time behaviour can reintroduce a clock.

The rule this module follows everywhere: **pin, then prove.** `tools/generate_corpus.py --verify`
generates the whole corpus twice into two different directories and compares digests.

Not a runtime dependency. Nothing under `services/ai-service/` imports this, and the generators it
needs (reportlab, python-docx, openpyxl, python-pptx, pymupdf, pillow) are installed into a
throwaway container venv and must never enter `pyproject.toml`.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import io
import re
import zipfile
from collections.abc import Callable, Iterable, Sequence
from pathlib import Path

import reportlab.rl_config

# Must be set before any canvas is constructed: it pins the document ID and the date strings.
reportlab.rl_config.invariant = 1

from reportlab.lib.pagesizes import A4  # noqa: E402
from reportlab.lib.utils import ImageReader  # noqa: E402
from reportlab.pdfbase import pdfmetrics  # noqa: E402
from reportlab.pdfgen import canvas as rl_canvas  # noqa: E402

__all__ = [
    "AUTHOR",
    "FIXED_DT",
    "FIXED_ZIP_TIME",
    "PdfDoc",
    "digest_bytes",
    "normalize_ooxml",
    "save_ooxml",
    "write_bytes",
    "write_text",
]

#: One timestamp for the whole corpus. Any date works; a fixed one is the point.
FIXED_DT: dt.datetime = dt.datetime(2026, 1, 1, 0, 0, 0, tzinfo=dt.UTC)

#: The DOS epoch zip entries are pinned to. 1980-01-01 is the earliest a zip can express, so it
#: is unmistakably synthetic rather than a plausible-looking build date somebody might trust.
FIXED_ZIP_TIME: tuple[int, int, int, int, int, int] = (1980, 1, 1, 0, 0, 0)

AUTHOR: str = "Kelpwright Instruments (synthetic corpus generator)"

_CORE_XML = "docProps/core.xml"
_TIMESTAMP_ELEMENTS = ("dcterms:created", "dcterms:modified")
_FIXED_STAMP = "2026-01-01T00:00:00Z"


def digest_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _pin_core_properties(xml: bytes) -> bytes:
    """Force every timestamp in `docProps/core.xml` to `_FIXED_STAMP`.

    Applied to docx, xlsx and pptx alike rather than only to the one library known to misbehave.
    A per-library fix is a fix that lasts until the next release; this one holds whatever any of
    them decides to write, because it operates on the finished bytes.
    """
    text = xml.decode("utf-8")
    for element in _TIMESTAMP_ELEMENTS:
        text = re.sub(
            rf"(<{element}[^>]*>)[^<]*(</{element}>)",
            rf"\g<1>{_FIXED_STAMP}\g<2>",
            text,
        )
    # `cp:revision` increments on some save paths and is meaningless for a generated artefact.
    text = re.sub(r"(<cp:revision>)[^<]*(</cp:revision>)", r"\g<1>1\g<2>", text)
    return text.encode("utf-8")


def normalize_ooxml(raw: bytes) -> bytes:
    """Rewrite an OOXML package so its bytes are a pure function of its content.

    Entries are re-emitted in sorted name order with a fixed timestamp, fixed external
    attributes and fixed compression, and `docProps/core.xml` has its timestamps pinned. Sorting
    matters as much as the timestamps: `dict` iteration order inside a writer is stable within
    one library version and is not a guarantee anybody made to us.
    """
    source = zipfile.ZipFile(io.BytesIO(raw))
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as target:
        for name in sorted(source.namelist()):
            body = source.read(name)
            if name == _CORE_XML:
                body = _pin_core_properties(body)
            info = zipfile.ZipInfo(name, date_time=FIXED_ZIP_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o600 << 16
            info.create_system = 0
            target.writestr(info, body)
    return out.getvalue()


def write_bytes(path: Path, data: bytes) -> str:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
    return digest_bytes(data)


def write_text(path: Path, text: str) -> str:
    """Always `\\n`, always UTF-8, always exactly one trailing newline."""
    return write_bytes(path, (text.rstrip("\n") + "\n").encode("utf-8"))


def save_ooxml(document: object, path: Path) -> str:
    """`document.save(buffer)` for any of python-docx / openpyxl / python-pptx, then normalise."""
    buffer = io.BytesIO()
    document.save(buffer)  # type: ignore[attr-defined]
    return write_bytes(path, normalize_ooxml(buffer.getvalue()))


# ─────────────────────────────────────────────────────────────────────────────
# A small canvas-based PDF layout engine
# ─────────────────────────────────────────────────────────────────────────────
#
# platypus would be less code, but page count is what several fixtures are specified in and
# platypus decides it for you. Here the flow is explicit, so `page_count` in the manifest is
# something to ASSERT rather than to discover after the fact.


class PdfDoc:
    """Word-wrapped text flow onto a reportlab canvas, with a per-page footer callback.

    Heading levels are expressed as font size and weight because that is what a PDF actually
    carries — there is no tagged-heading structure in a generated PDF, and Docling's layout model
    infers headings from exactly these cues. The sizes are deliberately far apart so the
    inference is unambiguous.
    """

    LEVEL_FONT: dict[int, tuple[str, float, float]] = {
        0: ("Helvetica-Bold", 22.0, 16.0),  # title
        1: ("Helvetica-Bold", 15.0, 12.0),
        2: ("Helvetica-Bold", 12.5, 9.0),
        3: ("Helvetica-BoldOblique", 11.0, 7.0),
    }
    BODY_FONT = ("Helvetica", 10.0)
    LEADING = 14.0

    def __init__(
        self,
        path: Path,
        *,
        title: str,
        subject: str,
        footer: Callable[[rl_canvas.Canvas, int], None] | None = None,
        margin: float = 64.0,
    ) -> None:
        self.path = path
        self.buffer = io.BytesIO()
        self.width, self.height = A4
        self.margin = margin
        self.footer = footer
        self.page_index = 1
        self.canvas = rl_canvas.Canvas(self.buffer, pagesize=A4, invariant=1)
        self.canvas.setTitle(title)
        self.canvas.setSubject(subject)
        self.canvas.setAuthor(AUTHOR)
        self.canvas.setCreator(AUTHOR)
        self.canvas.setProducer(AUTHOR)
        self.y = self.height - self.margin

    # -- internals ------------------------------------------------------------

    @property
    def _bottom(self) -> float:
        return self.margin + 26.0

    def _wrap(self, text: str, font: str, size: float, indent: float) -> list[str]:
        usable = self.width - 2 * self.margin - indent
        words = text.split()
        lines: list[str] = []
        current = ""
        for word in words:
            trial = f"{current} {word}".strip()
            if pdfmetrics.stringWidth(trial, font, size) <= usable:
                current = trial
            else:
                if current:
                    lines.append(current)
                current = word
        if current:
            lines.append(current)
        return lines

    def _ensure(self, needed: float) -> None:
        if self.y - needed < self._bottom:
            self.page_break()

    # -- public API -----------------------------------------------------------

    def page_break(self) -> None:
        if self.footer is not None:
            self.footer(self.canvas, self.page_index)
        self.canvas.showPage()
        self.page_index += 1
        self.y = self.height - self.margin

    def heading(self, text: str, level: int = 1) -> None:
        font, size, space = self.LEVEL_FONT[level]
        self._ensure(size + space + self.LEADING)
        self.y -= space
        self.canvas.setFont(font, size)
        self.canvas.drawString(self.margin, self.y, text)
        self.y -= size + 4.0

    def para(self, text: str, *, indent: float = 0.0, gap: float = 6.0) -> None:
        font, size = self.BODY_FONT
        self.canvas.setFont(font, size)
        for line in self._wrap(text, font, size, indent):
            self._ensure(self.LEADING)
            self.canvas.setFont(font, size)
            self.canvas.drawString(self.margin + indent, self.y, line)
            self.y -= self.LEADING
        self.y -= gap

    def bullet(self, text: str) -> None:
        font, size = self.BODY_FONT
        self._ensure(self.LEADING)
        self.canvas.setFont(font, size)
        self.canvas.drawString(self.margin + 8.0, self.y, "•")
        self.para(text, indent=22.0, gap=2.0)

    def spacer(self, height: float = 12.0) -> None:
        self.y -= height

    def pad_to(self, pages: int, filler: Callable[[PdfDoc], None]) -> None:
        """Append fixed filler pages until the document is exactly `pages` long."""
        while self.page_index < pages:
            self.page_break()
            filler(self)

    def save(self, *, expect_pages: int | None = None) -> tuple[str, int]:
        if self.footer is not None:
            self.footer(self.canvas, self.page_index)
        self.canvas.showPage()
        total = self.page_index
        self.canvas.save()
        if expect_pages is not None and total != expect_pages:
            raise AssertionError(
                f"{self.path.name}: laid out {total} pages, manifest declares {expect_pages}. "
                "Reconcile the manifest with the artefact or the layout with the spec — never "
                "leave a page_count that describes a different document."
            )
        return write_bytes(self.path, self.buffer.getvalue()), total


def image_only_pdf(path: Path, images: Sequence[bytes], *, dpi: int = 300) -> str:
    """Build a PDF whose every page is a single raster image and which carries no text layer.

    `images` are PNG/JPEG bytes, one per page. The page box is derived from the pixel size and
    the render DPI so the result is A4-shaped rather than arbitrarily scaled.
    """
    buffer = io.BytesIO()
    first = ImageReader(io.BytesIO(images[0]))
    pixel_width, pixel_height = first.getSize()
    points = (pixel_width * 72.0 / dpi, pixel_height * 72.0 / dpi)
    pdf = rl_canvas.Canvas(buffer, pagesize=points, invariant=1)
    pdf.setTitle("Scanned document")
    pdf.setAuthor(AUTHOR)
    pdf.setCreator(AUTHOR)
    pdf.setProducer(AUTHOR)
    for blob in images:
        reader = ImageReader(io.BytesIO(blob))
        pdf.drawImage(reader, 0, 0, width=points[0], height=points[1])
        pdf.showPage()
    pdf.save()
    return write_bytes(path, buffer.getvalue())


def tree_digest(root: Path, scheme: bytes = b"kb-corpus-tree/v1\n") -> tuple[str, int]:
    """The directory-fixture digest, matching `app/evaluation/corpus.py` exactly.

    Re-stated here rather than imported because the generator runs in a throwaway container with
    no access to the service's package. The two implementations agreeing is asserted by
    `verify_corpus()` accepting what this writes — which is the only check that matters.
    """
    digest = hashlib.sha256()
    digest.update(scheme)
    files: Iterable[Path] = sorted(
        (path for path in root.rglob("*") if path.is_file()),
        key=lambda path: path.relative_to(root).as_posix(),
    )
    count = 0
    for path in files:
        digest.update(path.relative_to(root).as_posix().encode("utf-8"))
        digest.update(b"\0")
        digest.update(hashlib.sha256(path.read_bytes()).hexdigest().encode("ascii"))
        digest.update(b"\n")
        count += 1
    return digest.hexdigest(), count
