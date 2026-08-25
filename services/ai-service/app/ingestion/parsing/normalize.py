"""Docling's document tree to the chunker's element protocol. The pipeline's *normalize* stage.

``parse_document`` returns ``ParseResult.elements`` as raw Docling items — the ``(item, level)``
pairs ``DoclingDocument.iterate_items`` yields — and ``chunk_document`` takes objects satisfying
``chunker.DocumentElement``. Nothing joined the two, which is why the stage order in
``run_version`` names *normalize* between parse and chunk. This module is that stage, and it is
its own file rather than a helper inside either neighbour for a reason worth stating: it is the
**only** place Docling's vocabulary is translated into ours, so a Docling upgrade that renames a
label changes one file, and a reader asking "where did this chunk's ``kind`` come from" has one
answer.

WHY THE TRANSLATION IS A TABLE AND NOT AN ``isinstance`` LADDER
---------------------------------------------------------------
Docling's labels are a closed vocabulary and so are ours, so the mapping is data
(``LABEL_TO_KIND``) and the unmapped case is explicit. An ``isinstance`` ladder over Docling's
item classes would silently route a new item type to whatever the final ``else`` said, and the
symptom is a document that indexes fine with a whole class of its content typed as prose — no
error, and the wrong size band applied to it.

An unmapped label is **dropped, recorded, and never guessed at**, because the two wrong answers
are worse in different directions: typing an unknown item as ``text`` puts furniture in the
index, and typing it as ``heading`` corrupts the heading path of everything beneath it.

WHAT THE HEADING PATH IS, AND THE OFF-BY-ONE THAT MAKES IT LIE
---------------------------------------------------------------
``heading_path`` is the stack of enclosing headings **above** an element, and the heading itself
is never in its own path. Docling's ``level`` is the tree depth of the item rather than the
heading level, so the stack is maintained on the heading's own ``level`` attribute where it has
one. Getting this wrong is not visible in any count: every chunk still exists, still has a path,
and the path simply names the wrong section — which is what a citation renders.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from dataclasses import dataclass
from typing import Any, Final

from app.ingestion.chunking.chunker import ChunkContext

__all__ = [
    "LABEL_TO_KIND",
    "NormalizeReport",
    "NormalizedElement",
    "normalize_elements",
]

#: Docling ``DocItemLabel`` value -> our ``ELEMENT_KINDS`` member. Data, deliberately: see the
#: module docstring for why a class ladder routes new item types silently.
#:
#: ``caption`` maps to ``text`` rather than being dropped: a table's caption is often the only
#: prose naming what the table is, and a table chunk's header repeat does not include it.
#: ``page_header``/``page_footer`` are kept as their own kinds rather than dropped here — the
#: chunker owns boilerplate stripping and needs to see them to detect a repeat across pages.
LABEL_TO_KIND: Final[Mapping[str, str]] = {
    "title": "heading",
    "section_header": "heading",
    "paragraph": "text",
    "text": "text",
    "caption": "text",
    "checkbox_selected": "text",
    "checkbox_unselected": "text",
    "list_item": "list_item",
    "table": "table",
    "code": "code",
    "formula": "code",
    "page_header": "page_header",
    "page_footer": "page_footer",
    "footnote": "footnote",
}

#: Our ``kind`` -> the ``content_type`` that selects the chunker's size band. The bands differ
#: by an order of magnitude, so a wrong answer here does not corrupt anything — it silently
#: produces chunks of the wrong size, which shows up only as worse retrieval.
#:
#: Everything that is not a table, a slide or code is ``prose``, which is the only MERGEABLE
#: type. ``faq`` and ``policy`` are deliberately NOT inferred: they are classifications of what
#: a document *means*, not of what Docling found, and guessing them from a label would apply a
#: different size band on the strength of a heading that happened to say "FAQ".
KIND_TO_CONTENT_TYPE: Final[Mapping[str, str]] = {
    "table": "table_rows",
    "code": "code",
    "slide": "slide",
}


@dataclass(frozen=True, slots=True)
class NormalizedElement:
    """Structurally a ``chunker.DocumentElement``. Frozen because the chunker must not mutate it.

    ``element_id`` is Docling's ``self_ref`` — the item's own path inside the document tree,
    stable for one parse of one document. It is what ``chunks.document_element_id`` points at,
    so it has to survive into the relational row; a positional index would move whenever the
    parser's page range or OCR configuration changed, and every citation anchored to it would
    then point somewhere else after a reprocess that changed nothing a reader can see.
    """

    element_id: str
    parent_element_id: str | None
    kind: str
    content_type: str
    text: str
    heading_path: tuple[str, ...]
    page: int | None
    slide: int | None
    sheet: str | None
    anchor: str | None
    lang: str
    char_start: int
    char_end: int
    context: ChunkContext

    #: Docling's own item, carried through unread by the chunker and read by the table
    #: delegate, which needs the cell grid rather than the flattened text.
    source_item: Any = None

    # THERE IS NO `seq` HERE, AND ITS ABSENCE IS DELIBERATE. `chunk_document` assigns `seq` by
    # COUNTING its input, across the whole element list including the table chunks its delegate
    # produced — so a `seq` carried on the element would be a second, disagreeing answer to the
    # question that feeds both the chunk id and the point id. `document_elements.seq` is the
    # enumeration index at write time, which is the same walk.


@dataclass(frozen=True, slots=True)
class NormalizeReport:
    """What the stage produced, and what it refused to guess about.

    ``dropped_labels`` is not decoration. An unmapped label is a Docling vocabulary change, and
    the only way anyone learns about one is that this count is non-zero on a document that used
    to index completely — so it travels to the readiness report as a warning rather than being
    logged and forgotten.
    """

    elements: list[NormalizedElement]
    dropped_labels: tuple[str, ...]
    empty_elements: int


def normalize_elements(
    raw: Iterable[Any],
    *,
    context: ChunkContext,
    sheet: str | None = None,
    lang: str = "und",
) -> NormalizeReport:
    """Docling items in document order to normalized elements in document order.

    Order is the contract, exactly as it is for the embedding response: ``seq`` is assigned
    downstream by counting this list, and ``seq`` is an input to both the chunk id and the point
    id. Re-ordering here would change every identifier a reprocess produces, orphaning the
    points the previous version wrote under the ids nothing now computes.

    Every element carries the **same** ``ChunkContext`` instance, which the chunker requires and
    checks: the version-level half of the chunk metadata cannot come from a per-element source
    without the halves being able to disagree.
    """
    elements: list[NormalizedElement] = []
    dropped: dict[str, int] = {}
    empty = 0
    heading_stack: list[tuple[int, str]] = []
    offset = 0

    for entry in raw:
        item = entry[0] if isinstance(entry, tuple) else entry

        label = str(getattr(getattr(item, "label", None), "value", getattr(item, "label", "")))
        kind = LABEL_TO_KIND.get(label)
        if kind is None:
            # Recorded, never guessed. Typing an unknown item as `text` puts furniture in the
            # index; typing it as `heading` corrupts the heading path of everything beneath it.
            dropped[label] = dropped.get(label, 0) + 1
            continue

        text = _text_of(item)
        if kind != "table" and not text.strip():
            # An empty element is not an error and not worth a chunk. It is counted because a
            # document that is *mostly* empty elements parsed badly, and nothing else says so.
            empty += 1
            continue

        if kind == "heading":
            level = int(getattr(item, "level", 1) or 1)
            # Pop to the heading's own level BEFORE reading the path, so a heading is never
            # inside its own heading_path — and so a level that jumps (h1 -> h3) does not leave
            # a stale sibling above it.
            while heading_stack and heading_stack[-1][0] >= level:
                heading_stack.pop()

        path = tuple(title for _, title in heading_stack)
        page = _page_of(item)
        length = len(text)

        elements.append(
            NormalizedElement(
                element_id=str(getattr(item, "self_ref", "") or f"#/elements/{len(elements)}"),
                parent_element_id=_parent_ref(item),
                kind=kind,
                content_type=KIND_TO_CONTENT_TYPE.get(kind, "prose"),
                text=text,
                heading_path=path,
                page=page,
                slide=page if _is_slide(item) else None,
                sheet=sheet,
                anchor=str(getattr(item, "self_ref", "") or "") or None,
                # `lang` is per-DOCUMENT here and not per-element, and `und` is the honest
                # default rather than a guess: `ChunkContext` carries no language, Docling
                # reports none per item, and a detector run over a heading of three words
                # returns noise. It is a filter term nothing yet reads, so a wrong value would
                # be invisible until the day it is read and then wrong for the whole corpus.
                lang=lang,
                char_start=offset,
                char_end=offset + length,
                context=context,
                source_item=item,
            )
        )
        offset += length

        if kind == "heading":
            heading_stack.append((int(getattr(item, "level", 1) or 1), text.strip()))

    return NormalizeReport(
        elements=elements,
        dropped_labels=tuple(sorted(dropped)),
        empty_elements=empty,
    )


def _text_of(item: Any) -> str:
    """The item's flattened text.

    ``text`` first, then ``orig``: Docling sets ``orig`` to the pre-normalization string and
    they differ on items whose text was reconstructed from OCR cells. Preferring ``text`` means
    the chunk carries what the parser concluded, which is what ``content_hash`` covers and what
    a citation quotes.
    """
    for attribute in ("text", "orig"):
        value = getattr(item, attribute, None)
        if isinstance(value, str) and value:
            return value
    return ""


def _page_of(item: Any) -> int | None:
    """The 1-based page a item sits on, from its first provenance record.

    ``None`` rather than a guess when there is no provenance: a wrong page number is worse than
    an absent one, because a citation renders it and a reader turns to that page.
    """
    provenance = getattr(item, "prov", None)
    if not provenance:
        return None
    page = getattr(provenance[0], "page_no", None)
    return int(page) if isinstance(page, int) else None


def _parent_ref(item: Any) -> str | None:
    parent = getattr(item, "parent", None)
    ref = getattr(parent, "cref", None) or getattr(parent, "self_ref", None)
    return str(ref) if isinstance(ref, str) and ref else None


def _is_slide(item: Any) -> bool:
    """Whether this item's page is a slide rather than a page.

    Read off the provenance rather than the file extension, because a PPTX converted to PDF
    upstream is a PDF with slide-shaped pages and its citations should still say ``slide``.

    <!-- UNVERIFIED: Docling exposes no slide/page discriminator on the item in 2.118; this
    returns False until one is confirmed, which means a PPTX's `slide` metadata field stays
    None and the citation renders a page number. Recorded rather than faked: a `slide` value
    invented from the input format would be right for PPTX and wrong for every PDF. -->
    """
    return False
