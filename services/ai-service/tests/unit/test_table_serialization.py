"""Row-wise table serialization, and the header that has to survive every split.

The failure this file exists to catch has a quotable symptom: *"the Pro plan includes 4,999
seats."* A table was split, every chunk after the first lost its column names, and the model
matched values to columns positionally and guessed. It is not a crash, not a warning, and not
visible in any total — the chunk count is right and the text is fluent.

So the assertions are about interpretability of a single row torn off the grid, not about the
rendered string as a whole: take one line out of one chunk, and every column it carries must
still be named.
"""

from __future__ import annotations

import itertools

import pytest

from app.core.errors import ErrorClass, KbError
from app.ingestion.chunking.chunker import MAX_TOKENS, RANGE_DASH
from app.ingestion.chunking.table_chunker import (
    EMPTY_CELL,
    TABLE_CONTENT_TYPE,
    chunk_table,
    render_header,
    render_row,
)
from tests.support.ingestion import TableFixture, context, measure_words, ulid

CTX = context()
HEADER = ("Plan", "Monthly price (INR)", "Seats included", "Priority support")


def table(rows: int = 40, **overrides: object) -> TableFixture:
    fields: dict[str, object] = {
        "element_id": ulid("tbl"),
        "context": CTX,
        "heading_path": ("Pricing",),
        "caption": "Q3 Plans",
        "sheet": "Q3 Plans",
        "table_ref": "Q3_Plans",
        "header": HEADER,
        "rows": tuple(
            (f"Plan {index}", f"{999 * index}", str(index), "Yes" if index % 2 else "No")
            for index in range(1, rows + 1)
        ),
        "first_row_number": 2,
        "page": 3,
        "char_start": 100,
        "char_end": 900,
    }
    fields.update(overrides)
    return TableFixture(**fields)  # type: ignore[arg-type]


# ── one row ──────────────────────────────────────────────────────────────────


def test_one_row_alone_is_still_interpretable() -> None:
    """The whole design in one assertion. After retrieval tears a row off its grid, `4,999`
    and `25` are two unlabelled integers unless the row carries its own column names."""
    line = render_row(HEADER, 3, ("Pro", "4999", "25", "Yes"))
    for column in HEADER:
        assert f"{column}: " in line
    assert line.startswith("Row 3")


def test_an_empty_cell_renders_as_a_dash_rather_than_disappearing() -> None:
    """A dropped pair looks like nothing at all, so the only evidence a reader has that the
    value was *absent* rather than unasked is the named column with nothing after it."""
    line = render_row(HEADER, 4, ("Starter", "999", "", "No"))
    assert f"Seats included: {EMPTY_CELL}" in line


def test_a_ragged_row_raises_instead_of_being_truncated() -> None:
    """`zip` without `strict` truncates to the shorter of the two, which shifts every column to
    the right of the gap onto the wrong values — and the resulting chunk reads as correct. It is
    what an unflattened spanned cell produces."""
    with pytest.raises(KbError) as raised:
        render_row(HEADER, 5, ("Pro", "4999"))
    assert raised.value.error_class is ErrorClass.VALIDATION


def test_a_row_with_no_column_names_raises() -> None:
    with pytest.raises(KbError):
        render_row((), 5, ("Pro", "4999"))


# ── the header block ─────────────────────────────────────────────────────────


def test_the_header_names_where_the_slice_sits_in_the_whole_table() -> None:
    """`of {total}` is not decoration — it is what tells the model it is holding a slice rather
    than the whole table, and it is what the citation renders."""
    block = render_header(table(), 2, 15, 40)
    assert block.splitlines()[0] == "Pricing > Q3 Plans"
    assert "Sheet: Q3 Plans" in block
    assert "Table: Q3_Plans" in block
    assert f"Rows 2{RANGE_DASH}15 of 40" in block


def test_the_header_disambiguates_two_tables_on_one_sheet() -> None:
    """ "Rows 2–15" alone names neither of them."""
    first = render_header(table(table_ref="Q3_Plans"), 2, 15, 40)
    second = render_header(table(table_ref="Q3_Addons"), 2, 15, 40)
    assert first != second


def test_a_table_with_no_caption_or_reference_still_has_a_heading_line() -> None:
    block = render_header(table(caption=None, table_ref=None, sheet=None), 1, 3, 3)
    assert block.splitlines()[0] == "Pricing > Table"


# ── splitting ────────────────────────────────────────────────────────────────


def test_every_chunk_of_a_split_table_repeats_the_header() -> None:
    """The header overhead is charged against every chunk's budget precisely because it is in
    every chunk. A split table whose second chunk opens `Pro | 4,999 | 25 | Yes` is the bug."""
    element = table(rows=200)
    chunks = list(chunk_table(element, measure_words))
    assert len(chunks) > 1, "the fixture must actually split, or this proves nothing"
    for chunk in chunks:
        assert "Pricing > Q3 Plans" in chunk.text
        for line in chunk.text.splitlines():
            if not line.startswith("Row "):
                continue
            for column in HEADER:
                assert f"{column}: " in line


def test_the_row_ranges_are_contiguous_and_cover_every_row_exactly_once() -> None:
    """A gap loses rows from the index with no error; an overlap indexes a row twice and spends
    two evidence slots on it."""
    element = table(rows=200)
    chunks = list(chunk_table(element, measure_words))
    ranges = [chunk.metadata.row_range for chunk in chunks]
    assert ranges[0] is not None and ranges[0][0] == element.first_row_number
    assert ranges[-1] is not None
    assert ranges[-1][1] == element.first_row_number + len(element.rows) - 1
    for earlier, later in itertools.pairwise(ranges):
        assert earlier is not None and later is not None
        assert later[0] == earlier[1] + 1


def test_no_table_chunk_exceeds_the_ceiling() -> None:
    for chunk in chunk_table(table(rows=200), measure_words):
        assert chunk.metadata.token_count <= MAX_TOKENS
        assert chunk.metadata.token_count == measure_words(chunk.text)


def test_a_row_is_never_split() -> None:
    """Splitting a row leaves a column name in one half and its value in the other, and neither
    half is interpretable."""
    for chunk in chunk_table(table(rows=200), measure_words):
        rows = [line for line in chunk.text.splitlines() if line.startswith("Row ")]
        first, last = chunk.metadata.row_range or (0, 0)
        assert len(rows) == last - first + 1


def test_every_table_chunk_carries_its_citation_locators() -> None:
    """A table chunk missing its row range is uncitable, and the assertion for that belongs in
    the module rather than in a reviewer's head."""
    for chunk in chunk_table(table(rows=60), measure_words):
        assert chunk.metadata.content_type == TABLE_CONTENT_TYPE
        assert chunk.metadata.row_range is not None
        assert chunk.metadata.sheet == "Q3 Plans"
        assert chunk.metadata.table_ref == "Q3_Plans"
        assert chunk.metadata.page == 3
        assert chunk.metadata.heading_path == ("Pricing",)


def test_chunks_are_numbered_from_the_document_position_they_were_given() -> None:
    """`seq` feeds the point id. A table numbered from zero inside a document that already has
    chunks gives its first chunk the same point id as the document's first paragraph, and the
    second upsert silently overwrites the first."""
    chunks = list(chunk_table(table(rows=60), measure_words, first_seq=7))
    assert [chunk.metadata.seq for chunk in chunks] == list(range(7, 7 + len(chunks)))


def test_an_oversized_single_row_raises() -> None:
    """There is no boundary left below a row."""
    huge = table(rows=1)
    huge.rows = ((" ".join(["word"] * MAX_TOKENS), "1", "1", "Yes"),)
    with pytest.raises(KbError) as raised:
        list(chunk_table(huge, measure_words))
    assert raised.value.error_class is ErrorClass.VALIDATION


def test_a_table_with_no_column_names_raises() -> None:
    """Assert on it here; do not log a warning and continue."""
    with pytest.raises(KbError):
        list(chunk_table(table(header=()), measure_words))


def test_an_empty_table_yields_nothing() -> None:
    assert list(chunk_table(table(rows=0), measure_words)) == []


def test_re_running_reproduces_the_same_chunks() -> None:
    element = table(rows=200)

    def identities() -> list[tuple[str, str]]:
        return [
            (chunk.metadata.chunk_id, chunk.metadata.content_hash)
            for chunk in chunk_table(element, measure_words)
        ]

    first = identities()
    second = identities()
    assert first == second
