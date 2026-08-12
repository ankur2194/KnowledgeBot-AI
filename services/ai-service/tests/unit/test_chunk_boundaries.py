"""Where `chunk_document` puts a boundary, and what every chunk carries when it does.

Two properties, and both fail silently in production. A boundary in the wrong place still
embeds cleanly — it just embeds a different meaning, and the only symptom months later is
"retrieval is bad for that one document". A missing metadata field still upserts — the chunk is
simply uncitable, or undeletable by identifier, or both.

So the assertions here are about the *rule*, not about a golden string: a run that exactly
fills its band stays one chunk and a run one token over it does not, the prefix is inside the
budget and inside the hash, a list stem is never separated from its items, and re-running over
unchanged elements reproduces the same counts, `seq` values and `content_hash`es.
"""

from __future__ import annotations

import hashlib
from typing import Final

import pytest

from app.core.errors import ErrorClass, KbError
from app.ingestion.chunking.chunker import (
    CHUNK_METADATA_FIELDS,
    MAX_TOKENS,
    RANGE_DASH,
    SIZE_POLICY,
    Chunk,
    chunk_document,
    embed_prefix,
)
from tests.support.ingestion import Element, TableFixture, context, measure_words, ulid

CTX = context()

#: `heading_path` plus a page locator, as `_prefix` renders it: `"Refunds > Fees\n[Page 1]\n\n"`
#: measures five tokens under `measure_words`. Stated here because every budget in this file is
#: `band.max_tokens` minus this, and a test that discovers the overhead instead of stating it
#: cannot assert a boundary.
HEADING: Final[tuple[str, ...]] = ("Refunds", "Fees")
PREFIX_TOKENS: Final[int] = 5


def words(count: int, *, word: str = "lorem") -> str:
    return " ".join([word] * count)


def prose(name: str, tokens: int, *, page: int | None = 1, **overrides: object) -> Element:
    fields: dict[str, object] = {
        "element_id": ulid(name),
        "context": CTX,
        "text": words(tokens),
        "heading_path": HEADING,
        "page": page,
        "char_start": 0,
        "char_end": tokens * 6,
    }
    fields.update(overrides)
    return Element(**fields)  # type: ignore[arg-type]


def test_the_stated_prefix_overhead_is_what_the_module_actually_renders() -> None:
    """The control for every budget arithmetic below. Without it, a change to the prefix format
    would silently move every boundary this file claims to pin, and the tests would keep
    passing against the new one."""
    [chunk] = chunk_document(elements=[prose("a", 10)], measure=measure_words)
    assert embed_prefix(chunk.metadata) == "Refunds > Fees\n[Page 1]\n\n"
    assert measure_words(embed_prefix(chunk.metadata)) == PREFIX_TOKENS


# ── the size-policy boundary ─────────────────────────────────────────────────


def test_a_run_that_exactly_fills_its_band_stays_one_chunk() -> None:
    """699 tokens against a 700-token band: the prefix, then two paragraphs that fit beside it.

    The pair with the test below is the point. One of them alone proves nothing — a chunker
    that never merges passes this one's opposite, and a chunker with no cap at all passes this
    one.
    """
    band = SIZE_POLICY["prose"]
    half = (band.max_tokens - PREFIX_TOKENS - 1) // 2  # 347
    chunks = chunk_document(elements=[prose("a", half), prose("b", half)], measure=measure_words)
    assert len(chunks) == 1
    assert chunks[0].metadata.token_count == PREFIX_TOKENS + 2 * half == 699
    assert chunks[0].metadata.token_count <= MAX_TOKENS


def test_one_token_past_the_band_splits_and_the_prefix_is_what_tips_it() -> None:
    """701 tokens of prefix-plus-body against a 700-token band.

    The body alone is 696 and would fit; it is the five-token prefix that does not, and the
    prefix is not optional — it is the single largest measured retrieval lever available here.
    A budget computed over the body only produces one 701-token chunk, which the provider
    accepts and indexes from its first 700 at HTTP 200.
    """
    band = SIZE_POLICY["prose"]
    half = (band.max_tokens - PREFIX_TOKENS) // 2 + 1  # 348
    chunks = chunk_document(elements=[prose("a", half), prose("b", half)], measure=measure_words)
    assert len(chunks) == 2
    assert [chunk.metadata.seq for chunk in chunks] == [0, 1]
    assert all(chunk.metadata.token_count <= MAX_TOKENS for chunk in chunks)


def test_no_chunk_of_any_content_type_exceeds_its_band() -> None:
    """The invariant behind the pair above, over every band at once."""
    elements = [
        prose("faq1", 240, content_type="faq"),
        prose("faq2", 240, content_type="faq"),
        prose("pol", 400, content_type="policy"),
        prose("p1", 400),
        prose("p2", 400),
    ]
    for chunk in chunk_document(elements=elements, measure=measure_words):
        band = SIZE_POLICY[chunk.metadata.content_type]
        assert chunk.metadata.token_count <= band.max_tokens
        assert chunk.metadata.token_count <= MAX_TOKENS


def test_two_faq_pairs_are_never_merged_even_though_they_would_fit() -> None:
    """The Q+A pair *is* the retrieval unit. Two of them in one chunk answers two questions and
    cites one, and it fits comfortably inside the band — so a size-driven chunker merges them
    and nothing complains."""
    chunks = chunk_document(
        elements=[prose("q1", 30, content_type="faq"), prose("q2", 30, content_type="faq")],
        measure=measure_words,
    )
    assert len(chunks) == 2


def test_an_unsplittable_sentence_raises_rather_than_being_cut() -> None:
    """No structural boundary is left, and both ways past this — a mid-word cut or a vendor
    truncation parameter — turn a loud chunker failure into a silent retrieval one."""
    with pytest.raises(KbError) as raised:
        chunk_document(elements=[prose("long", MAX_TOKENS + 50)], measure=measure_words)
    assert raised.value.error_class is ErrorClass.VALIDATION
    assert not raised.value.retryable


def test_an_oversized_code_block_raises_rather_than_being_split() -> None:
    """A split code block is unrunnable and unretrievable, so it is never split — even at a
    sentence boundary, which a code block does not really have."""
    element = prose("code", MAX_TOKENS + 50, content_type="code")
    element.text = ". ".join(words(50) for _ in range(15))
    with pytest.raises(KbError) as raised:
        chunk_document(elements=[element], measure=measure_words)
    assert "atomic" in raised.value.message


# ── structural boundaries ────────────────────────────────────────────────────


def test_a_heading_change_starts_a_new_chunk_however_small_the_text() -> None:
    elements = [
        prose("a", 10),
        Element(ulid("h"), CTX, kind="heading", text="Cancellations", heading_path=HEADING),
        prose("b", 10, heading_path=(*HEADING, "Cancellations")),
    ]
    chunks = chunk_document(elements=elements, measure=measure_words)
    assert len(chunks) == 2
    assert chunks[0].metadata.heading_path == HEADING
    assert chunks[1].metadata.heading_path == (*HEADING, "Cancellations")


def test_a_heading_is_not_itself_a_chunk() -> None:
    """Its text is already in the `heading_path` of everything beneath it, so emitting it too
    indexes the same words twice and produces a chunk whose whole content is its own title."""
    chunks = chunk_document(
        elements=[Element(ulid("h"), CTX, kind="heading", text="Refunds")],
        measure=measure_words,
    )
    assert chunks == []


def test_a_page_break_is_crossed_only_to_finish_a_sentence_and_then_sets_page_end() -> None:
    """A citation that points at page 7 while the excerpt is on page 8 is the failure this
    guards, and it is invisible from the answer."""
    continued = [prose("a", 10), prose("b", 10, page=2)]
    continued[0].text = words(10) + " and"
    [chunk] = chunk_document(elements=continued, measure=measure_words)
    assert (chunk.metadata.page, chunk.metadata.page_end) == (1, 2)
    assert f"[Pages 1{RANGE_DASH}2]" in chunk.text

    finished = [prose("a", 10), prose("b", 10, page=2)]
    finished[0].text = words(10) + "."
    chunks = chunk_document(elements=finished, measure=measure_words)
    assert len(chunks) == 2
    assert [chunk.metadata.page_end for chunk in chunks] == [None, None]


def test_a_list_stem_is_repeated_in_every_chunk_of_its_run() -> None:
    """The highest-severity bug this module can produce, and the only one where the answer is
    confidently *inverted* rather than absent: split the stem from its items and "the following
    are not covered" becomes an inclusion list.

    Note the content type — `policy`, whose band merges nothing. Without the list clause in
    `_continues` the stem would be its own chunk here, which is the failure exactly.
    """
    stem = Element(
        ulid("stem"),
        CTX,
        kind="list_stem",
        content_type="policy",
        text="The following are not covered:",
        heading_path=("Coverage",),
    )
    items = [
        Element(
            ulid(f"i{index}"),
            CTX,
            kind="list_item",
            content_type="policy",
            text=words(150),
            heading_path=("Coverage",),
        )
        for index in range(5)
    ]
    chunks = chunk_document(elements=[stem, *items], measure=measure_words)

    assert len(chunks) > 1, "the fixture must actually split, or this proves nothing"
    for chunk in chunks:
        assert "The following are not covered:" in chunk.text
        assert stem.element_id in chunk.metadata.element_ids


def test_overlap_applies_inside_a_prose_run_and_is_recorded() -> None:
    """`overlap_of` is what lets packing collapse a near-duplicate family to one slot instead
    of spending three evidence slots on the same paragraph."""
    elements = [prose(f"p{index}", 50) for index in range(20)]
    chunks = chunk_document(elements=elements, measure=measure_words)
    assert len(chunks) > 1
    assert chunks[0].metadata.overlap_of is None
    assert chunks[1].metadata.overlap_of == chunks[0].metadata.chunk_id
    band = SIZE_POLICY["prose"]
    for chunk in chunks:
        assert chunk.metadata.token_count <= band.max_tokens


def test_overlap_never_crosses_a_structural_edge() -> None:
    """Across a heading it buys nothing and costs an index slot per chunk."""
    elements = [prose(f"a{index}", 50) for index in range(20)]
    elements += [prose(f"b{index}", 50, heading_path=("Other",)) for index in range(3)]
    chunks = chunk_document(elements=elements, measure=measure_words)
    crossing = [
        chunk
        for chunk in chunks
        if chunk.metadata.heading_path == ("Other",) and chunk.metadata.overlap_of is not None
    ]
    assert crossing == []


# ── the metadata schema ──────────────────────────────────────────────────────


def mixed_document() -> list[Element]:
    """One document exercising the shapes that actually break: a two-level heading tree, a
    table with a header row, a slide with its notes, and prose either side."""
    return [
        Element(ulid("h1"), CTX, kind="heading", text="Pricing"),
        prose("intro", 40, heading_path=("Pricing",)),
        TableFixture(
            element_id=ulid("tbl"),
            context=CTX,
            heading_path=("Pricing",),
            caption="Q3 Plans",
            sheet="Q3 Plans",
            table_ref="Q3_Plans",
            header=("Plan", "Monthly price (INR)", "Seats included"),
            rows=tuple((f"Plan {index}", str(999 * index), str(index)) for index in range(1, 41)),
            first_row_number=2,
            page=3,
            char_start=100,
            char_end=900,
        ),
        Element(
            ulid("slide"),
            CTX,
            content_type="slide",
            text="Roadmap. Speaker notes: ship the thing.",
            slide=4,
            heading_path=("Pricing", "Roadmap"),
        ),
    ]


def test_every_chunk_carries_every_metadata_field() -> None:
    """The dataclass has no defaults, so an omitted field is a `TypeError` at construction —
    this asserts the schema did not grow a field the chunker forgot, which the dataclass cannot
    catch."""
    chunks = chunk_document(elements=mixed_document(), measure=measure_words)
    assert chunks
    for chunk in chunks:
        for field in CHUNK_METADATA_FIELDS:
            assert hasattr(chunk.metadata, field), field


def test_the_citation_locator_is_populated_for_every_source_shape() -> None:
    """A chunk without page / slide / sheet + row range is uncitable: the UI has no excerpt to
    open. Asserted per shape rather than as "something is set", because "something" is
    satisfied by the wrong locator."""
    chunks = chunk_document(elements=mixed_document(), measure=measure_words)
    by_type: dict[str, list[Chunk]] = {}
    for chunk in chunks:
        by_type.setdefault(chunk.metadata.content_type, []).append(chunk)

    for chunk in by_type["table_rows"]:
        assert chunk.metadata.row_range is not None
        assert chunk.metadata.sheet == "Q3 Plans"
        assert chunk.metadata.table_ref == "Q3_Plans"
    for chunk in by_type["slide"]:
        assert chunk.metadata.slide == 4
    for chunk in by_type["prose"]:
        assert chunk.metadata.page == 1


def test_the_identity_fields_are_never_empty() -> None:
    """A chunk without `source_version_id` is undeletable by identifier, and deletion by text
    match is forbidden outright — so the field being present is the whole of deletion's
    handle on it."""
    for chunk in chunk_document(elements=mixed_document(), measure=measure_words):
        for field in ("chunk_id", "org_id", "source_id", "source_item_id", "source_version_id"):
            assert getattr(chunk.metadata, field), field
        assert chunk.metadata.bot_ids
        assert chunk.metadata.embedding_model_id.startswith("emb/v1:")


def test_seq_is_document_order_and_contiguous_across_the_delegation() -> None:
    """`seq` is the input to both the point id and the chunk id. A table numbered from zero
    inside a document that already has chunks gives its first chunk the same point id as the
    document's first paragraph, and the second upsert silently overwrites the first."""
    chunks = chunk_document(elements=mixed_document(), measure=measure_words)
    assert [chunk.metadata.seq for chunk in chunks] == list(range(len(chunks)))
    assert len({chunk.metadata.chunk_id for chunk in chunks}) == len(chunks)


def test_the_content_hash_covers_exactly_the_string_the_embedder_receives() -> None:
    """Hash narrower and a renamed heading leaves stale vectors serving the old text; hash
    wider and every unchanged document re-embeds on every ingest, forever."""
    for chunk in chunk_document(elements=mixed_document(), measure=measure_words):
        assert chunk.metadata.content_hash == hashlib.sha256(chunk.text.encode()).hexdigest()
        assert chunk.metadata.token_count == measure_words(chunk.text)


def test_the_prefix_is_part_of_the_embedded_text() -> None:
    for chunk in chunk_document(elements=mixed_document(), measure=measure_words):
        if chunk.metadata.content_type == "table_rows":
            # The table's own header block is a superset of `embed_prefix` — it adds the row
            # total, which the metadata does not carry. Both open with the heading path.
            assert chunk.text.startswith("Pricing > Q3 Plans")
            continue
        assert chunk.text.startswith(embed_prefix(chunk.metadata))


# ── determinism, which is what makes a replay safe ───────────────────────────


def test_re_running_over_unchanged_elements_reproduces_everything() -> None:
    """Publication verifies an expected chunk total before activating, so a non-deterministic
    chunker fails publication intermittently on documents that succeed on retry — and moves
    every point id underneath a replay while it is at it."""
    first = chunk_document(elements=mixed_document(), measure=measure_words)
    second = chunk_document(elements=mixed_document(), measure=measure_words)
    assert len(first) == len(second)
    assert [chunk.metadata.seq for chunk in first] == [chunk.metadata.seq for chunk in second]
    assert [chunk.metadata.chunk_id for chunk in first] == [
        chunk.metadata.chunk_id for chunk in second
    ]
    assert [chunk.metadata.content_hash for chunk in first] == [
        chunk.metadata.content_hash for chunk in second
    ]
    assert [chunk.text for chunk in first] == [chunk.text for chunk in second]


def test_a_batch_mixing_two_versions_is_refused() -> None:
    """Two organizations' elements in one call. The chunks would carry the first version's
    identifiers, the totals would still agree, and the second version would be unpublishable
    and undeletable at the same time."""
    other = context(org="orgb", version="vertwo")
    elements = [prose("a", 10), Element(ulid("b"), other, text=words(10))]
    with pytest.raises(KbError) as raised:
        chunk_document(elements=elements, measure=measure_words)
    assert "source versions" in raised.value.message


def test_an_unknown_content_type_is_refused_rather_than_defaulted_to_prose() -> None:
    """Defaulting sizes an FAQ like an essay and puts two answers in one chunk."""
    with pytest.raises(KbError):
        chunk_document(elements=[prose("a", 10, content_type="minutes")], measure=measure_words)
