"""Header-bound row groups — the chunking case that is most often got wrong.

Tables are serialized **row-wise, with every column name repeated in every row**, never as a
markdown or HTML grid. That is not a formatting preference: on the ConfQuestions benchmark it
lifts P@1 from 0.382 (markdown) and 0.368 (HTML) to 0.528, roughly 35% relative. Header and
rows are both embedded — headers alone measure Recall@10 0.208 against 0.741 for header+rows —
and a model-written summary is never indexed in place of the table, because summaries retrieve
*worse* than the table they replace.

The mechanism is visible the moment a table is split. Given `Pricing.xlsx`, sheet `Q3 Plans`,
41 rows:

* a character splitter emits one oversized blob that is hard-truncated, so rows 19–41 vanish
  from the index and the bot answers "the Enterprise plan is not listed";
* a 700-character split emits a second chunk opening `Pro | 4,999 | 25 | Yes`, and the bot
  answers *"the Pro plan includes 4,999 seats"* — confidently, from two unlabelled integers;
* row-wise emits `Row 3 — Plan: Pro; Monthly price (INR): 4,999; Seats included: 25; Priority
  support: Yes`, which survives being retrieved entirely alone.

**A table chunk without its column names is a defect, not a degradation.** Assert on it here;
do not log a warning and continue. Splitting is by *whole rows* only, and the header overhead
counts against every chunk's budget because it is repeated in each one.

Two upstream traps this module exists to route around. Docling's chunker flag that promises to
repeat table headers does nothing under the default table serializer — the header-line method
is implemented only on the Markdown and HTML serializers, so the base returns no header lines
and the prefix is never emitted, while the flag's name reads as covered. And the default
serializer joins an entire table with `". "` and no newlines at all, so any line-based splitter
downstream falls through to a character-index binary search and starts a chunk mid-cell. Our
serializer emits one newline-terminated line per row precisely so a split has real boundaries.

Merged and multi-row headers must already be flattened to one row by the parser. A spanned
cell shifts every column to its right, and the resulting chunk is not detectably wrong — it is
just answering with the wrong column's values.
"""

from __future__ import annotations

import hashlib
from collections.abc import Callable, Iterator
from typing import Final, Protocol

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.chunking.chunker import (
    CHUNKER_VERSION,
    LOCATOR_SEPARATOR,
    MAX_TOKENS,
    RANGE_DASH,
    Chunk,
    ChunkMetadata,
    DocumentElement,
    derive_chunk_id,
)

__all__ = [
    "EMPTY_CELL",
    "ROW_LABEL_SEPARATOR",
    "TABLE_CONTENT_TYPE",
    "TableElement",
    "chunk_table",
    "render_header",
    "render_row",
]

#: What an empty cell renders as. A dash rather than an omission: a dropped `column: value`
#: pair looks like nothing at all in the rendered row, so the only evidence a reader — or a
#: model — has that the value was *absent* rather than *unasked* is that the column is named
#: with nothing after it.
EMPTY_CELL: Final[str] = "\u2014"

#: U+2014 EM DASH, between a row's label and its column/value pairs. Named for the same reason
#: as `RANGE_DASH`: it is inside the embedded string and therefore inside `content_hash`.
ROW_LABEL_SEPARATOR: Final[str] = " \u2014 "

#: The size band and the packing rule both key on this, and citation checks it before deciding
#: a chunk needs a `row_range`.
TABLE_CONTENT_TYPE: Final[str] = "table_rows"


class TableElement(DocumentElement, Protocol):
    """The shape the parser must emit. The concrete type is owned by
    `app/ingestion/parsing/elements.py` — never constructed in this package.

    A table element is a document element with rows, so this extends `DocumentElement` rather
    than restating it: it inherits the identity, the shared `ChunkContext`, the heading path and
    the citation locators, and every one of those is a field of the chunk metadata schema that a
    table chunk has to populate exactly like a prose chunk does. A separate, narrower protocol
    was the earlier shape here and it could not fill `page`, `lang`, `parent_element_id` or
    `char_start` — which is to say it could not produce a citable chunk.

    `header` arrives already flattened to a single row, and `first_row_number` is 1-based, as
    shown in the spreadsheet, so a citation reading "rows 2–15" names the rows the user sees.
    """

    caption: str | None
    #: Named region, e.g. `Q3_Plans` — what distinguishes two tables on one sheet.
    table_ref: str | None
    header: tuple[str, ...]
    rows: tuple[tuple[str, ...], ...]
    first_row_number: int


def render_row(header: tuple[str, ...], number: int, cells: tuple[str, ...]) -> str:
    """One row, verbalized: `Row {n} — {col}: {value}; {col}: {value}` …

    Every column name is repeated. An empty cell renders as a dash rather than being omitted,
    because a dropped pair shifts nothing visually but makes the row's remaining pairs the only
    evidence a reader has that a value was absent rather than unasked.

    A row whose cell count does not match the header raises. `zip` would truncate to the shorter
    of the two silently, and a truncated row is not detectably wrong — it answers with the wrong
    column's values, which is what a spanned cell that was never flattened produces.
    """
    if not header:
        raise _fail(
            f"row {number} has no column names. A table chunk without its header is a defect, "
            "not a degradation: every value in it becomes a bare number that the model matches "
            "to a column positionally, and guesses"
        )
    if len(cells) != len(header):
        raise _fail(
            f"row {number} has {len(cells)} cells against {len(header)} column names "
            f"{header!r}. Truncating to the shorter of the two shifts every column to the "
            "right of the gap onto the wrong values, and the resulting chunk reads as correct"
        )
    pairs = "; ".join(
        f"{name}: {value.strip() or EMPTY_CELL}" for name, value in zip(header, cells, strict=True)
    )
    return f"Row {number}{ROW_LABEL_SEPARATOR}{pairs}"


def render_header(element: TableElement, first: int, last: int, total: int) -> str:
    """The block every chunk of a table opens with: heading path plus caption or table
    reference, the sheet and table reference when present, and `Rows {first}–{last} of
    {total}`.

    The "of {total}" is not decoration — it is what tells the model it is holding a slice
    rather than the whole table, and it is what the citation renders.

    This is the table's embedded prefix, and it is deliberately a superset of
    `chunker.embed_prefix`: that one builds from `ChunkMetadata`, which carries `row_range` but
    no row total, so it can never say "of 40". The two agree on the heading line and on the
    sheet and table reference.
    """
    lines = [" > ".join((*element.heading_path, element.caption or element.table_ref or "Table"))]
    if element.sheet:
        reference = element.table_ref or EMPTY_CELL
        lines.append(f"Sheet: {element.sheet}{LOCATOR_SEPARATOR}Table: {reference}")
    lines.append(f"Rows {first}{RANGE_DASH}{last} of {total}")
    lines.append("")
    return "\n".join(lines)


def _fail(message: str) -> KbError:
    """Every refusal in this module, one class. `validation`: the document parsed, and the next
    attempt builds the identical rows from the identical element, so a retryable class here
    burns the delivery cap on a certainty. Mirrors `chunker._fail`."""
    return KbError(ErrorClass.VALIDATION, message, origin=Origin.SELF)


def chunk_table(
    element: TableElement, measure: Callable[[str], int], *, first_seq: int = 0
) -> Iterator[Chunk]:
    """Split a table into chunks of whole contiguous rows, each at most `MAX_TOKENS`.

    Never splits a row. The header overhead is measured once and charged against every chunk,
    since it is repeated in each. Emits `content_type="table_rows"`, a `row_range`, and the
    sheet and table reference where the source has them — a table chunk missing its row range
    is uncitable, and the assertion for that belongs here rather than in a reviewer's head.

    `measure` is the token estimator, injected — over-estimating, since no local tokenizer
    exists to be authoritative. The parameter is deliberately not named `count`:
    CI's tenancy gate greps all of `app/` for the Qdrant point-total call shape, with no
    include filter, and an ordinary string method of that name matches it.

    `first_seq` is where this table's chunks sit in document order. It is a parameter rather
    than a zero because `seq` is the input to both the point id and the chunk id: numbering a
    table from 0 inside a document that already has chunks would give the table's first chunk
    the same point id as the document's first paragraph, and the second upsert would silently
    overwrite the first. `chunk_document` passes the running total; a caller chunking a lone
    table may leave it at 0.
    """
    total = len(element.rows)
    if not element.header:
        raise _fail(
            f"table {element.element_id!r} has no column names. Assert on it here rather than "
            "logging and continuing: every chunk it would produce is a grid of bare values, and "
            "the model answers from them positionally"
        )
    if total == 0:
        return

    first_row = element.first_row_number
    # Measured once, over the WIDEST header this table can render — the full row span, which
    # has the most digits in it. The header is repeated in every chunk, so its cost is charged
    # against every chunk's budget; measuring it over the narrowest render would under-charge
    # the last chunk by exactly the digits it grew by.
    overhead = measure(render_header(element, first_row, first_row + total - 1, total))
    budget = MAX_TOKENS - overhead
    if budget <= 0:
        raise _fail(
            f"table {element.element_id!r} has a {overhead}-token header block against a "
            f"{MAX_TOKENS}-token ceiling, so no row fits beside it. The header is not optional "
            "— a row torn off the grid without its column names is two unlabelled integers"
        )

    seq = first_seq
    buffer: list[str] = []
    first = first_row
    used = 0
    for offset, row in enumerate(element.rows):
        line = render_row(element.header, first_row + offset, row)
        cost = measure(line)
        if cost > budget:
            raise _fail(
                f"row {first_row + offset} of table {element.element_id!r} measures {cost} "
                f"tokens against a {budget}-token body budget. Splitting a row is the one thing "
                "this module may never do: the halves carry a column name each and a value each, "
                "and neither is interpretable"
            )
        if buffer and used + cost > budget:
            yield _emit(element, buffer, first, first_row + offset - 1, total, measure, seq)
            seq += 1
            buffer, first, used = [], first_row + offset, 0
        buffer.append(line)
        used += cost

    yield _emit(element, buffer, first, first_row + total - 1, total, measure, seq)


def _emit(
    element: TableElement,
    lines: list[str],
    first: int,
    last: int,
    total: int,
    measure: Callable[[str], int],
    seq: int,
) -> Chunk:
    """One header-bound row group as a chunk, with every field of the schema populated.

    `row_range` is set on every one of them, and that is asserted rather than hoped: it is the
    citation — "Pricing.xlsx — Q3 Plans, rows 2–15" — and it is what re-maps a chunk after a
    re-chunk. `char_start`/`char_end` name the whole table element for every chunk of it, since
    a row group has no narrower span in the normalized document; the row range is the locator
    that distinguishes them.
    """
    context = element.context
    text = render_header(element, first, last, total) + "\n".join(lines)
    token_count = measure(text)
    if token_count > MAX_TOKENS:
        raise _fail(
            f"table chunk rows {first}-{last} of {element.element_id!r} measures {token_count} "
            f"tokens against the {MAX_TOKENS}-token ceiling. A table that outgrows it is trimmed "
            "by the provider at HTTP 200, and for a table that means the last rows vanish from "
            "the index while the chunk total still looks right"
        )

    row_range = (first, last)
    assert row_range[0] <= row_range[1], "a table chunk must carry a non-empty row range"
    return Chunk(
        text=text,
        metadata=ChunkMetadata(
            chunk_id=derive_chunk_id(context.source_version_id, seq),
            org_id=context.org_id,
            source_id=context.source_id,
            source_item_id=context.source_item_id,
            source_version_id=context.source_version_id,
            bot_ids=context.bot_ids,
            seq=seq,
            document_element_id=element.element_id,
            element_ids=(element.element_id,),
            parent_element_id=element.parent_element_id,
            heading_path=tuple(element.heading_path),
            page=element.page,
            page_end=None,
            slide=element.slide,
            sheet=element.sheet,
            table_ref=element.table_ref,
            row_range=row_range,
            url=context.url,
            anchor=element.anchor,
            char_start=element.char_start,
            char_end=element.char_end,
            lang=element.lang,
            content_type=TABLE_CONTENT_TYPE,
            token_count=token_count,
            content_hash=hashlib.sha256(text.encode("utf-8")).hexdigest(),
            overlap_of=None,
            created_at=context.created_at,
            effective_at=context.effective_at,
            expires_at=context.expires_at,
            embedding_model_id=context.embedding_model_id,
            parser_version=context.parser_version,
            chunker_version=CHUNKER_VERSION,
        ),
    )


# The budget is shared with the prose path; a table chunk that outgrows it is trimmed by the
# embedding provider with no warning — HTTP 200, a plausible vector — which for a table means
# the last rows silently vanish from the index while the chunk total still looks right.
assert MAX_TOKENS > 0
