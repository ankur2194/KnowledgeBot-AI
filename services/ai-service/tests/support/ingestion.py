"""Element and chunk builders for the ingestion tier.

The parsing package owns the concrete element type and does not export one yet, so these are
the structural stand-ins the chunker's Protocols describe — plain dataclasses with the same
attribute names. They are deliberately **not** a second definition of anything: every field
here exists because `DocumentElement` or `TableElement` names it, and a field the protocols
drop should disappear from here in the same change.

`measure_words` is the injected token estimator every test uses. One token per run of
non-whitespace, which makes a chunk's size something a test can *state* rather than discover —
a fixture that measures "about 500 tokens" cannot assert anything about a 500-token boundary.
It is not a model of any tokenizer and is not offered as one; the production `measure` must
over-estimate against a vendor's own tokenizer, and that is `bge-m3-embeddings`' problem, not
a property this file can fake.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Final

from app.ingestion.chunking.chunker import ChunkContext

__all__ = [
    "CROCKFORD",
    "Element",
    "TableFixture",
    "context",
    "measure_words",
    "ulid",
]

#: Crockford base32 — ULID's alphabet. Restated here rather than imported so a test fixture
#: that stops producing legal identifiers is caught by the module under test's own assertion
#: instead of agreeing with whatever the module currently does.
CROCKFORD: Final[str] = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

_WORD: Final[re.Pattern[str]] = re.compile(r"\S+")


def measure_words(text: str) -> int:
    """One token per run of non-whitespace. Deterministic, and exact enough to test a boundary
    with: `"a b c"` is three tokens and nothing else."""
    return len(_WORD.findall(text))


def ulid(tag: str) -> str:
    """A legal ULID whose readable head is `tag`, so a failure message names the fixture.

    26 characters, leading `0`, Crockford alphabet only. Every payload identifier in this
    platform is one of these and the write path asserts the shape — a fixture using a UUID here
    would make the isolation tests pass against code that never wrote a matchable payload.
    """
    body = "".join(character for character in tag.upper() if character in CROCKFORD)
    return ("0" + body).ljust(26, "0")[:26]


def context(*, org: str = "orga", version: str = "verone", **overrides: object) -> ChunkContext:
    """A `ChunkContext` for one version of one organization.

    `org` and `version` are short readable tags rather than identifiers, because every
    two-organization test in this suite reads better as `context(org="orgb")` than as a pair of
    26-character constants — and a two-organization fixture is mandatory for any isolation
    claim: a one-organization test passes against code with no scope at all.
    """
    defaults: dict[str, object] = {
        "org_id": ulid(org),
        "source_id": ulid("src"),
        "source_item_id": ulid("item"),
        "source_version_id": ulid(version),
        "bot_ids": (ulid("bot"),),
        "url": None,
        "created_at": datetime(2026, 8, 11, tzinfo=UTC),
        "effective_at": None,
        "expires_at": None,
        "embedding_model_id": "emb/v1:test:probe:d8:0123456789ab",
        "parser_version": "parser/v1:docling2.118.0:0123456789ab",
    }
    defaults.update(overrides)
    return ChunkContext(**defaults)  # type: ignore[arg-type]


@dataclass
class Element:
    """A parsed document element. Mirrors `chunker.DocumentElement` field for field."""

    element_id: str
    context: ChunkContext
    kind: str = "text"
    content_type: str = "prose"
    text: str = ""
    heading_path: tuple[str, ...] = ()
    page: int | None = None
    slide: int | None = None
    sheet: str | None = None
    anchor: str | None = None
    lang: str = "en"
    char_start: int = 0
    char_end: int = 0
    parent_element_id: str | None = None


@dataclass
class TableFixture(Element):
    """A table element. Mirrors `table_chunker.TableElement`, which extends `DocumentElement`.

    `kind` defaults to `table` and `content_type` to `table_rows` because a table that is not
    labelled as one is routed down the prose path, where it is split by sentence and every
    column name after the first chunk is lost — the failure this whole module exists to stop.
    """

    kind: str = "table"
    content_type: str = "table_rows"
    caption: str | None = None
    table_ref: str | None = None
    header: tuple[str, ...] = ()
    rows: tuple[tuple[str, ...], ...] = field(default_factory=tuple)
    first_row_number: int = 1
