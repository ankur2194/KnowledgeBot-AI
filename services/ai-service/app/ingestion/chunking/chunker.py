"""Elements to chunks, and the chunk metadata schema in full.

**Boundaries follow document structure, never character counts.** A chunk ends at a heading, a
paragraph, a list, a row group, a page, a slide, or a sheet edge. A mid-sentence cut is not a
crash and not a warning — it embeds perfectly cleanly, it just embeds a *different meaning*, so
the only symptom is "retrieval is bad for that one document" months later. The size policy
below is a budget the structural pass respects, not a rule that overrides it, and the
highest-severity variant of getting this backwards is splitting a list's introducing stem
("The following are **not** covered:") from its items, which inverts the answer.

**Every chunk carries every field of `ChunkMetadata`.** Citation reads it and deletion reads
it, so a missing field breaks one or both: a chunk without page / slide / sheet + row range is
uncitable — the UI has no excerpt to open — and a chunk without `source_version_id` is
undeletable by identifier, while deletion by text match is forbidden outright. The dataclass
below therefore has **no defaults**: omitting a field is a `TypeError` at construction, which
is the only enforcement that cannot be forgotten under deadline. Fields whose value genuinely
does not exist for a source type are passed explicitly as `None`.

**Chunking is deterministic and its config is part of version identity.** Output is a pure
function of (elements, config). Nothing here consults an LLM, dict iteration order, a language
detector fed a two-word string, or a thread pool, because publication verifies an expected
chunk total before activating — a nondeterministic chunker fails publication intermittently on
documents that succeed on retry, and the point ids move underneath a replay. Changing sizes,
overlap or serialization changes `CHUNKER_VERSION`, which mints new source versions.

**The embedded text is not the raw text.** Each chunk is embedded with a prefix built from its
own metadata — the heading path, then the page or slide label, then the body — which is what
makes a chunk reading "these are not refundable" retrievable by "refund policy". Two
consequences that are easy to get wrong in opposite directions: the prefix counts *inside* the
token budget, and `content_hash` must cover **exactly the string sent to the embedder**. Hash
narrower than the embedder input and a renamed heading leaves stale vectors serving the old
text; hash wider — a run timestamp, a regenerated date line — and every unchanged document
re-embeds on every ingest, forever.

Repeated-content removal is conservative, per-source, and reversible. Legal boilerplate looks
exactly like navigation boilerplate to a frequency counter, and the parser already labels page
headers, footers and footnotes rather than deleting them — filter at index time, record what
was removed, and keep it recoverable. Corpus-wide near-duplicate removal is banned outright:
canonical, well-written text is the most duplicated text, so it is deleted first.
"""

from __future__ import annotations

import hashlib
import re
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass, fields
from datetime import datetime
from typing import Any, Final, Protocol

from app.core.errors import ErrorClass, KbError, Origin

__all__ = [
    "ATOMIC_CONTENT_TYPES",
    "CHUNKER_VERSION",
    "CHUNK_METADATA_FIELDS",
    "CONTENT_TYPES",
    "ELEMENT_KINDS",
    "HEADING_KIND",
    "LIST_ITEM_KIND",
    "LIST_STEM_KIND",
    "LOCATOR_SEPARATOR",
    "MAX_TOKENS",
    "MERGEABLE_CONTENT_TYPES",
    "RANGE_DASH",
    "SENTENCE_TERMINATORS",
    "SIZE_POLICY",
    "TABLE_KIND",
    "Chunk",
    "ChunkContext",
    "ChunkMetadata",
    "DocumentElement",
    "SizePolicy",
    "chunk_document",
    "derive_chunk_id",
    "embed_prefix",
]

#: Config identity. Bumping it mints a new source version for every item, which is the point:
#: a chunking change that does not re-chunk anything is invisible and untestable.
CHUNKER_VERSION: Final[str] = "chunker/v1"

#: The hard ceiling any chunk may reach, measured by the injected `measure` — never by
#: characters. There is no local model any more, so there is also no authoritative tokenizer to
#: measure with: the embedding vendor tokenizes with its own algorithm over its own vocabulary,
#: and the divergence between any two of them is largest exactly on non-Latin scripts, where a
#: `len(text)//4` estimate makes Hindi and Arabic documents come out half the intended size.
#:
#: So `measure` must **over**-estimate. An over-estimate costs a slightly smaller chunk; an
#: under-estimate costs a passage the provider trims with no error and no metric, which reads
#: months later as recall that is fine for FAQ chunks and quietly bad for prose and tables.
#: `embedder.check_window` compares this constant against the configured model's input window
#: before any text is sent, because that window is the one thing a response cannot tell us.
MAX_TOKENS: Final[int] = 700

CONTENT_TYPES: Final[tuple[str, ...]] = (
    "faq",
    "policy",
    "prose",
    "table_rows",
    "sheet_region",
    "slide",
    "code",
)

#: The structural roles the parser labels an element with. They are what makes a boundary
#: structural rather than arithmetic: without them the only edge available is a character
#: offset, which is the failure this whole module is arranged against.
#:
#: `page_header`, `page_footer` and `footnote` are here and are **kept**, not dropped. The
#: parser labels them rather than deleting them precisely so the decision is reversible, and
#: dropping by label here would delete a per-page legal disclaimer from every document that
#: carries one — the highest-cost mistake in the boilerplate direction, and unfalsifiable
#: afterwards because nothing records what went missing. Frequency-based repeated-block removal
#: belongs to `boilerplate.py`, which does not exist yet; until it does, this module removes
#: nothing.
ELEMENT_KINDS: Final[tuple[str, ...]] = (
    "heading",
    "text",
    "list_stem",
    "list_item",
    "table",
    "code",
    "slide",
    "page_header",
    "page_footer",
    "footnote",
)

#: A heading contributes no body of its own. Its text is already in the `heading_path` of
#: everything beneath it — which is what the embedded prefix and the citation both read — so
#: emitting it as a chunk as well would index the same words twice and produce a chunk whose
#: whole content is its own title.
HEADING_KIND: Final[str] = "heading"

#: Delegated to `table_chunker.chunk_table`, which owns the header-repeat invariant.
TABLE_KIND: Final[str] = "table"

#: The introducing stem of a list ("The following are **not** covered:") and its items. The
#: stem is bound to the items and repeated at the head of every chunk of the run, exactly like
#: a table's column names. Splitting the two apart is the highest-severity bug this module can
#: produce, because the items chunk then reads as an inclusion list and the answer inverts —
#: confidently wrong rather than absent.
LIST_STEM_KIND: Final[str] = "list_stem"
LIST_ITEM_KIND: Final[str] = "list_item"

#: The content types where consecutive elements may be packed into one chunk. Everything else
#: is one element per chunk, and that is the size table read as it is written rather than as a
#: byte budget: an FAQ's Q+A pair *is* the retrieval unit ("never two"), a policy clause must be
#: quotable verbatim, a slide is the citation unit at any size. Merging two of any of them
#: produces a chunk that answers two questions and cites one.
MERGEABLE_CONTENT_TYPES: Final[tuple[str, ...]] = ("prose",)

#: Never split, at any size. A split code block is unrunnable and unretrievable, so an
#: over-length one raises instead — a loud failure on one element, with the previous version
#: still serving, rather than two halves of a snippet that both look like code.
ATOMIC_CONTENT_TYPES: Final[tuple[str, ...]] = ("code",)


@dataclass(frozen=True, slots=True)
class SizePolicy:
    """Target band and overlap for one content type. `overlap_ratio` applies **only inside a
    continuous prose run** — never across a heading, page, slide, sheet or table edge, where it
    buys nothing and costs an index slot per chunk."""

    min_tokens: int
    max_tokens: int
    overlap_ratio: float


#: Starting values, to be tuned by evaluation rather than defended by intuition. Each band has
#: a reason, and the reason is the retrieval unit rather than a size preference:
#: an FAQ's Q+A pair *is* the unit and overlap blurs two answers together; a policy clause must
#: be quotable verbatim; a slide is the citation unit even at 80 tokens; a code block is
#: unrunnable and unretrievable once split. Overlap measured on general prose is a bad trade
#: even before the structural argument — published evals show recall *and* precision falling as
#: it rises — so it is capped at 15% and only where the text is genuinely continuous.
SIZE_POLICY: Final[Mapping[str, SizePolicy]] = {
    "faq": SizePolicy(120, 250, 0.0),
    "policy": SizePolicy(250, 450, 0.0),
    "prose": SizePolicy(500, 700, 0.12),
    "table_rows": SizePolicy(0, MAX_TOKENS, 0.0),
    "sheet_region": SizePolicy(0, MAX_TOKENS, 0.0),
    "slide": SizePolicy(0, MAX_TOKENS, 0.0),
    "code": SizePolicy(0, MAX_TOKENS, 0.0),
}


@dataclass(frozen=True, slots=True)
class ChunkMetadata:
    """The complete schema. Every field, every chunk, no defaults — see the module docstring.

    Every identifier here is a **ULID** string, never a UUID. The single genuine UUID anywhere
    in the system is the vector point id, and only because Qdrant accepts nothing else. Writing
    one of these as a UUID produces the worst failure shape in the platform: the tenant filter
    matches nothing, every bot in every organization retrieves zero candidates, and the
    response is a normal-latency HTTP 200 with no exception and no log line. The fix is to
    correct the identifier and rebuild — never to relax the filter, which converts a total
    outage into a cross-tenant leak.
    """

    # ── identity and tenancy ────────────────────────────────────────────────
    #: Relational chunk identity. The vector point id is **not** derived from this: it is the
    #: deterministic uuid5 in `app.ingestion.identity`, because a chunk id minted per run is
    #: not stable across a replay.
    chunk_id: str
    org_id: str
    source_id: str
    #: A sitemap page re-versions and is removed independently of its source.
    source_item_id: str
    #: The active-version filter, and the sole predicate retirement acts on.
    source_version_id: str
    #: Bot-scoped retrieval filter. The shape of this field is `kb-tenancy-isolation`'s call,
    #: not this module's.
    bot_ids: tuple[str, ...]

    # ── position in the document ────────────────────────────────────────────
    #: Document order. Packing merges adjacent chunks and needs it; the point id embeds it, so
    #: it must be deterministic.
    seq: int
    document_element_id: str
    #: A prose chunk spans several elements and the singular field above loses all but one.
    element_ids: tuple[str, ...]
    #: Small-to-big: retrieve the child, return the enclosing section.
    parent_element_id: str | None
    #: The embedded prefix *and* the citation's "where in the document". May be empty only for
    #: a genuinely flat document — an empty path on a structured one means the heading stack
    #: was not carried, and the citation degrades to "Terms.pdf, page 7".
    heading_path: tuple[str, ...]

    # ── citation locators ───────────────────────────────────────────────────
    page: int | None
    #: Set only when a chunk legitimately crosses a page break — which it may do only to
    #: complete an unterminated sentence. Without it the citation points at page 7 while the
    #: excerpt is on page 8.
    page_end: int | None
    slide: int | None
    sheet: str | None
    #: Named table or region. Disambiguates two tables on one sheet, where "rows 2–15" alone
    #: names neither.
    table_ref: str | None
    row_range: tuple[int, int] | None
    url: str | None
    #: HTML id or heading slug. Without it a crawl citation opens the page top and the user
    #: cannot find the sentence.
    anchor: str | None
    #: Offsets into the normalized document — the excerpt highlight the citation contract
    #: promises. A page number alone cannot produce it.
    char_start: int
    char_end: int

    # ── content facts ───────────────────────────────────────────────────────
    lang: str
    content_type: str
    #: From the injected `measure`, excluding any sentinel tokens a tokenizer adds. It is an
    #: estimate now rather than a model's own count, and it is recorded because it is the only
    #: evidence available if a provider is later found to be trimming.
    token_count: int
    #: sha256 of the exact string passed to the embedder, prefix included.
    content_hash: str
    #: The chunk this one overlaps, so packing can drop a near-duplicate instead of spending
    #: two evidence slots on the same paragraph.
    overlap_of: str | None

    # ── time ────────────────────────────────────────────────────────────────
    created_at: datetime
    #: Time-scoped policies retrieval must stop returning once they lapse.
    effective_at: datetime | None
    expires_at: datetime | None

    # ── config identity ─────────────────────────────────────────────────────
    #: `EmbeddingModelIdentity.version` verbatim, e.g.
    #: `emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1` — provider, model id, returned
    #: width, and a digest over a fixed probe set. Not a bare vendor model name: that is an
    #: alias, and an alias can be re-pointed at different weights with no diff anywhere here.
    #:
    #: This field is also the **durable** record of what each vector was made by, since it is
    #: written to the `chunks` row as well as to the point payload. That is what makes ADR-010's
    #: rebuild answerable after a provider swap — PostgreSQL can name every chunk embedded
    #: under the old identity without re-reading a single vector.
    #:
    #: A collection holding two embedding configurations is silently wrong: cosine scores across
    #: them are not comparable and nothing raises.
    embedding_model_id: str
    parser_version: str
    chunker_version: str


@dataclass(frozen=True, slots=True)
class Chunk:
    """A chunk as it leaves this module: the exact string the embedder will receive, plus its
    metadata. `text` already carries the prefix, and `metadata.content_hash` covers `text`
    verbatim — the two are set together or the idempotency check is meaningless."""

    text: str
    metadata: ChunkMetadata


@dataclass(frozen=True, slots=True)
class ChunkContext:
    """The version-level facts every chunk of one run carries identically.

    **Why this type exists at all, since the stub's signature did not have it.** `Chunk` holds a
    `ChunkMetadata` with no defaults, and eleven of that schema's thirty-two fields are
    properties of the *source version* rather than of any element: the four identifiers, the bot
    assignment, the crawl URL, the three timestamps, and the two config-identity strings.
    `chunk_document(elements, measure, policy)` can read none of them off a parsed element —
    `document_elements` carries `organization_id`, `source_version_id`, a parent, a sequence and
    a locator, and nothing else — so without this the function could only return a partially
    populated schema, which is the one defect that breaks citation and deletion at the same
    time. It rides on the element rather than as a fourth parameter so that `chunk_table` needs
    no extra argument either, and it is one shared object referenced by every element of a run,
    never a copy per element.

    Frozen, and compared by value: every element of one call must carry an equal context. A
    batch mixing two versions writes one version's chunks under the other's `source_version_id`,
    and every count downstream still adds up.

    `created_at` is passed in rather than read from a clock here. Chunking is a pure function of
    its inputs — publication verifies an expected chunk total before activating, so a chunker
    that consults anything ambient fails publication intermittently on documents that succeed on
    retry.
    """

    org_id: str
    source_id: str
    source_item_id: str
    source_version_id: str
    bot_ids: tuple[str, ...]
    #: Crawled sources only. `None` for an upload, explicitly.
    url: str | None
    created_at: datetime
    effective_at: datetime | None
    expires_at: datetime | None
    #: `EmbeddingModelIdentity.version`, never a bare vendor model alias — see
    #: `ChunkMetadata.embedding_model_id`.
    embedding_model_id: str
    parser_version: str


class DocumentElement(Protocol):
    """The shape the parse stage must emit. The concrete type is owned by the parsing package
    and is never constructed here.

    Structural facts only, plus the shared `context`. `heading_path` arrives already maintained
    by the parser's element walk — this module does not rebuild a heading stack, it groups on
    the path it is given, so an empty path on a structured document is a parser defect that
    shows up as a citation reading "Terms.pdf, page 7" and nothing more.

    `char_start` / `char_end` are offsets into the **normalized document**, half-open, and they
    are what makes the excerpt highlight possible; a page number alone cannot produce one.
    """

    element_id: str
    parent_element_id: str | None
    #: One of `ELEMENT_KINDS`.
    kind: str
    #: One of `CONTENT_TYPES`. The classifier's call, not this module's — it selects the size
    #: band, and the bands differ by an order of magnitude.
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


#: Derived from the dataclass so it cannot drift from it. Used by the payload builder and by
#: the schema test that fails on an unset field.
CHUNK_METADATA_FIELDS: Final[tuple[str, ...]] = tuple(f.name for f in fields(ChunkMetadata))

#: Crockford's base32 — ULID's alphabet, with `I`, `L`, `O` and `U` absent so a transcribed id
#: cannot be misread. `_chunk_id` below encodes 128 bits into 26 of these, which is exactly
#: `ULID_PATTERN`'s shape: 26 characters whose first is `0`–`7`, because 128 bits leave three in
#: the leading five-bit group.
_CROCKFORD: Final[str] = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

#: Sentence ends, for the one case where a structural unit is still too large for the budget.
#: Deliberately more than `.!?`: `।` is the Devanagari danda and `。！？` are the CJK forms, and
#: a splitter blind to them treats a whole Hindi or Japanese paragraph as one sentence — which
#: turns "split at a sentence boundary" into "raise", on exactly the scripts where a silent
#: mis-size is least likely to be noticed.
#:
#: The trailing `\s+` is required rather than optional, and that is what keeps `3.14` and
#: `v1.2` in one piece: a zero-width boundary after every full stop splits inside a number, and
#: the resulting chunk opens with `14` — the numeric twin of the unlabelled-table-row bug.
#:
#: The terminator set is a named constant built from escapes: a full-width `?` and an ASCII one
#: are one glyph apart in a diff, which is exactly what the linter's ambiguous-character rule
#: exists to stop somebody sliding past a reviewer.
SENTENCE_TERMINATORS: Final[str] = ".!?\u2026\u0964\u3002\uff01\uff1f"

#: U+2013 EN DASH — the range separator in every rendered locator (`Rows 2–15`, `Pages 7–8`).
#: Named rather than inlined because it is inside the string the embedder receives and
#: therefore inside `content_hash`: swapping it for a hyphen re-hashes every chunk carrying a
#: range and re-embeds them at a provider's per-token price.
RANGE_DASH: Final[str] = "\u2013"

#: U+00B7 MIDDLE DOT, between the parts of a sheet locator. Same argument as `RANGE_DASH`.
LOCATOR_SEPARATOR: Final[str] = " \u00b7 "

_SENTENCE_END: Final[re.Pattern[str]] = re.compile(
    rf"(?<=[{re.escape(SENTENCE_TERMINATORS)}])[\"')\]]*\s+|\n+"
)

#: Closing punctuation that may follow a terminator at the end of a sentence.
_CLOSERS: Final[str] = "\"')]"


@dataclass(frozen=True, slots=True)
class _Segment:
    """One indivisible piece of body text, with the span of the normalized document it came
    from. A chunk is a contiguous run of these, which is what lets `char_start`/`char_end` be
    exact rather than approximated from the elements they happened to start and end in."""

    text: str
    element: Any
    char_start: int
    char_end: int
    tokens: int


def _fail(message: str, *, error_class: ErrorClass = ErrorClass.VALIDATION) -> KbError:
    """Every refusal in this module, in one place, so the class is decided once.

    `validation` and never `parsing`: the document parsed. What failed is that a structural unit
    cannot be expressed inside the budget, and the taxonomy makes `validation` non-retryable —
    which is right, because the next attempt builds the identical chunk from the identical
    elements and fails identically. A retryable class here burns the delivery cap on a
    certainty.
    """
    return KbError(error_class, message, origin=Origin.SELF)


def derive_chunk_id(source_version_id: str, seq: int) -> str:
    """A ULID-shaped chunk id derived from `(chunker version, source version, seq)`.

    **Deterministic, and that is a deliberate correction to the schema note above**, which says
    a chunk id is "minted per run". A per-run id makes the relational half of a replay
    non-idempotent: a redelivered run re-inserts every chunk row under fresh ids, so the
    `chunks` table doubles while the vector total — keyed on the deterministic point id — stays
    exactly right and verification passes. Deriving it here makes both halves replay to the same
    rows, which is what "safe to run twice" has to mean if it is to mean anything.

    Nothing else about the point id changes: it stays the `uuid5` of
    `(org, version, seq)` in `app.ingestion.identity`, because Qdrant rejects a ULID outright
    and that is the primary reason, not the mutability one.

    128 bits into 26 Crockford characters gives the leading character three bits of range,
    which is exactly why `ULID_PATTERN` anchors it to `[0-7]`.
    """
    digest = hashlib.blake2b(
        f"{CHUNKER_VERSION}|{source_version_id}|{seq}".encode(), digest_size=16
    ).digest()
    value = int.from_bytes(digest, "big")
    out = [""] * 26
    for index in range(25, -1, -1):
        out[index] = _CROCKFORD[value & 0b11111]
        value >>= 5
    return "".join(out)


def _locator(
    *,
    page: int | None,
    page_end: int | None,
    slide: int | None,
    sheet: str | None,
    table_ref: str | None,
    row_range: tuple[int, int] | None,
) -> str:
    """The one line of the prefix that says *where in the document this is*.

    Exactly one branch fires: a slide has no page, a sheet has no page, and a PDF has neither of
    the other two. The order is most-specific-first so a source type that legitimately carries
    two never silently renders the weaker one.
    """
    if slide is not None:
        return f"[Slide {slide}]"
    if sheet is not None:
        parts = [f"Sheet: {sheet}"]
        if table_ref:
            parts.append(f"Table: {table_ref}")
        if row_range is not None:
            parts.append(f"Rows {row_range[0]}{RANGE_DASH}{row_range[1]}")
        return "[" + LOCATOR_SEPARATOR.join(parts) + "]"
    if page is not None:
        if page_end is not None and page_end != page:
            return f"[Pages {page}{RANGE_DASH}{page_end}]"
        return f"[Page {page}]"
    return ""


def _prefix(
    *,
    heading_path: Sequence[str],
    page: int | None,
    page_end: int | None,
    slide: int | None,
    sheet: str | None,
    table_ref: str | None,
    row_range: tuple[int, int] | None,
) -> str:
    """`embed_prefix` over loose fields, so the chunker can build the string *before* the
    metadata it would otherwise have to read it from exists.

    The order is not cosmetic: `token_count` and `content_hash` are both taken over the finished
    string, so the prefix has to be known before the metadata, and the metadata cannot therefore
    be the input to the prefix. One implementation, two entry points.
    """
    lines: list[str] = []
    if heading_path:
        lines.append(" > ".join(heading_path))
    locator = _locator(
        page=page,
        page_end=page_end,
        slide=slide,
        sheet=sheet,
        table_ref=table_ref,
        row_range=row_range,
    )
    if locator:
        lines.append(locator)
    return "\n".join(lines) + "\n\n" if lines else ""


def embed_prefix(metadata: ChunkMetadata) -> str:
    """The identifying context prepended before embedding — heading path, then the page or
    slide or sheet label.

    This is the single largest measured lever available here: deterministic document-level
    metadata prefixes moved SEC-filing accuracy from roughly 50–60% to 72–75% in a published
    study, and *beat* per-chunk model-generated context, which lost ground. It costs nothing at
    query time and requires no second model, so it comes before any contextual-retrieval
    proposal.

    Whatever this returns is inside the token budget and inside `content_hash`.

    **The table path has its own, richer prefix** — `table_chunker.render_header`, which adds
    `of {total}` so the model can tell it is holding a slice of a larger table. That total is
    not on `ChunkMetadata` (`row_range` says which rows, never how many exist), so this function
    cannot reproduce it and does not try; the two agree on the heading and locator lines and the
    table's own header block is a superset. Calling this on a `table_rows` chunk therefore
    returns a prefix that is correct and shorter than the one that was actually embedded.
    """
    return _prefix(
        heading_path=metadata.heading_path,
        page=metadata.page,
        page_end=metadata.page_end,
        slide=metadata.slide,
        sheet=metadata.sheet,
        table_ref=metadata.table_ref,
        row_range=metadata.row_range,
    )


def chunk_document(
    *,
    elements: list[Any],
    measure: Callable[[str], int],
    policy: Mapping[str, SizePolicy] = SIZE_POLICY,
) -> list[Chunk]:
    """Group elements into chunks. Pure function of its inputs; no I/O, no model, no clock.

    `measure` is the token estimator, injected so the chunker stays pure and testable. With no
    local model there is no authoritative tokenizer behind it, so it must over-estimate — see
    `MAX_TOKENS`. Note the argument is deliberately not named `count`: CI's tenancy gate greps
    all of `app/` for the Qdrant point-total call shape with no include filter, and an ordinary
    string method of the same name is indistinguishable from a vector call to a regex.

    The size cap is enforced **here**, at the structural pass. The embed step must *raise* on
    an over-length chunk and never truncate, and it must never pass a vendor truncation
    parameter to make one fit: both convert a loud chunker bug into a silent retrieval bug, and
    the symptom is one document retrieving badly with chunks that start mid-word.

    Table elements are delegated to `table_chunker.chunk_table`, which owns the header-repeat
    invariant. Each sheet of a workbook is chunked as its own unit; flattening a workbook into
    one text stream makes `sheet` wrong for everything past the first.

    Each element carries the shared `ChunkContext`, and every element of one call must carry an
    equal one — read that type's docstring for why the version-level half of the metadata schema
    could not come from anywhere else.

    `seq` is assigned here, 0-based, in document order, across the whole element list including
    the table chunks the delegate produced. It is the input to both the point id and the chunk
    id, so it is counted and never derived.
    """
    # Imported inside the function: `table_chunker` imports `Chunk` and `MAX_TOKENS` from this
    # module, so a module-level import here is a cycle. The delegation is one direction — this
    # module owns the walk, that one owns the header-repeat invariant — and the local import is
    # what keeps that readable rather than inverting the dependency to please the loader.
    from app.ingestion.chunking.table_chunker import chunk_table

    if not elements:
        return []

    context = _shared_context(elements)
    chunks: list[Chunk] = []
    index = 0
    while index < len(elements):
        element = elements[index]
        _validate_element(element, policy)

        if element.kind == HEADING_KIND:
            # No body of its own; it has already done its work by changing `heading_path`.
            index += 1
            continue

        if element.kind == TABLE_KIND:
            chunks.extend(chunk_table(element, measure, first_seq=len(chunks)))
            index += 1
            continue

        run_end = index + 1
        while run_end < len(elements):
            _validate_element(elements[run_end], policy)
            if not _continues(elements[run_end - 1], elements[run_end]):
                break
            run_end += 1

        chunks.extend(
            _chunk_run(
                elements[index:run_end],
                measure=measure,
                band=policy[element.content_type],
                context=context,
                first_seq=len(chunks),
            )
        )
        index = run_end

    return chunks


def _shared_context(elements: Sequence[Any]) -> ChunkContext:
    """The one `ChunkContext` every element of this call carries.

    Raises on a mixed batch. Two versions chunked together would write the second version's
    chunks under the first version's `source_version_id`: the totals still add up, the upsert
    still succeeds, and the second version is unpublishable and undeletable at the same time.
    """
    contexts: set[ChunkContext] = {element.context for element in elements}
    if len(contexts) != 1:
        raise _fail(
            f"chunk_document was given elements from {len(contexts)} source versions. One call "
            "is one version: a mixed batch writes one version's chunks under another's "
            "identifiers, and every count downstream still agrees"
        )
    return contexts.pop()


def _validate_element(element: Any, policy: Mapping[str, SizePolicy]) -> None:
    """Reject an element the size policy cannot describe, before any text is measured.

    An unknown `content_type` has no band, and the tempting fallback — treat it as prose — is
    how an FAQ is sized like an essay and two answers land in one chunk. An unknown `kind` means
    the parser and this module disagree about the document's structure, and the boundaries this
    module produces are only as good as those labels.
    """
    if element.kind not in ELEMENT_KINDS:
        raise _fail(
            f"element {element.element_id!r} has kind {element.kind!r}, which is not one of "
            f"{ELEMENT_KINDS}. Structure is what a boundary follows; an unlabelled element can "
            "only be split arithmetically"
        )
    if element.content_type not in CONTENT_TYPES or element.content_type not in policy:
        raise _fail(
            f"element {element.element_id!r} has content_type {element.content_type!r}, which "
            "has no size band. Defaulting it to prose sizes an FAQ like an essay and puts two "
            "answers in one chunk"
        )


def _ends_sentence(text: str) -> bool:
    """Whether `text` ends on a sentence terminator, closing punctuation allowed.

    Written with string methods rather than a regex on purpose, and the reason is a CI one
    worth stating: `\\.search\\(` is one alternative of the Qdrant verb detector, which greps
    all of `app/` with no include filter. A compiled pattern's own search method reads
    identically to a vector search to that grep, so it fails the tenancy job on a module that
    has never seen a client — the same trap the `measure` parameter is named around. **And
    this sentence originally tripped it too**, by quoting the call form it was warning about:
    the detector has no comment filter either, so prose about a call is a call.
    """
    tail = text.rstrip().rstrip(_CLOSERS)
    return bool(tail) and tail[-1] in SENTENCE_TERMINATORS


def _continues(previous: Any, current: Any) -> bool:
    """Whether `current` may join the same chunk group as `previous`.

    Every clause is a structural edge, and the page clause is the one exception the doctrine
    grants: a chunk crosses a page break **only** to complete an unterminated sentence, and the
    chunk that does then carries `page_end` so the citation cannot point at page 7 while the
    excerpt is on page 8.

    A list run is a run whatever its content type. That is the whole stem-binding rule: without
    this clause a list inside a policy document — where the bands are per clause and nothing
    merges — puts the stem in one chunk and the items in the next, and "the following are **not**
    covered" becomes an inclusion list.
    """
    if previous.heading_path != current.heading_path:
        return False
    if previous.slide != current.slide or previous.sheet != current.sheet:
        return False
    if previous.content_type != current.content_type:
        return False
    if {previous.kind, current.kind} & {HEADING_KIND, TABLE_KIND}:
        return False

    list_run = current.kind == LIST_ITEM_KIND and previous.kind in (
        LIST_STEM_KIND,
        LIST_ITEM_KIND,
    )
    if not list_run and current.content_type not in MERGEABLE_CONTENT_TYPES:
        return False
    if previous.page != current.page:
        return list_run or not _ends_sentence(previous.text)
    return True


def _segments(element: Any, measure: Callable[[str], int], *, budget: int) -> list[_Segment]:
    """One element as the pieces a chunk may be built from.

    Whole by default — the element *is* the structural unit — and split at sentence boundaries
    only when it alone cannot fit the budget. The offsets are carried through the split so a
    chunk's `char_start`/`char_end` still name the exact span of the normalized document it
    holds, which is what the excerpt highlight opens.
    """
    text = element.text.strip()
    if not text:
        return []
    whole = _Segment(text, element, element.char_start, element.char_end, measure(text))
    if whole.tokens <= budget:
        return [whole]

    if element.content_type in ATOMIC_CONTENT_TYPES:
        raise _fail(
            f"element {element.element_id!r} is {whole.tokens} tokens and its content type "
            f"{element.content_type!r} is atomic (budget {budget}). Splitting it would produce "
            "halves that are individually unrunnable and unretrievable, and truncating it "
            "would index a prefix at HTTP 200 — so this fails the version instead, with the "
            "previous one still serving"
        )

    pieces: list[_Segment] = []
    cursor = 0
    # Where the stripped text begins inside the raw element text, so the offsets below still
    # name real positions in the normalized document rather than positions in a local copy.
    offset = element.text.index(text)
    for boundary in [match.start() for match in _SENTENCE_END.finditer(text)] + [len(text)]:
        raw_piece = text[cursor:boundary]
        piece = raw_piece.strip()
        if piece:
            lead = len(raw_piece) - len(raw_piece.lstrip())
            start = element.char_start + offset + cursor + lead
            pieces.append(_Segment(piece, element, start, start + len(piece), measure(piece)))
        cursor = boundary
    return pieces or [whole]


def _chunk_run(
    run: Sequence[Any],
    *,
    measure: Callable[[str], int],
    band: SizePolicy,
    context: ChunkContext,
    first_seq: int,
) -> list[Chunk]:
    """One structural run into one or more chunks.

    The prefix and the repeated list stem are charged against the budget before any body text
    is, because both are inside the string the embedder receives — a budget that counts only the
    body is a budget for a string nobody sends.
    """
    stem = run[0] if run[0].kind == LIST_STEM_KIND else None
    body = run[1:] if stem is not None else run
    head, tail = run[0], run[-1]

    # Widest form of the locator this run can produce, so the overhead is over-estimated rather
    # than discovered to be two tokens short on the chunk that crosses the page break.
    prefix = _prefix(
        heading_path=head.heading_path,
        page=head.page,
        page_end=tail.page,
        slide=head.slide,
        sheet=head.sheet,
        table_ref=None,
        row_range=None,
    )
    stem_text = f"{stem.text.strip()}\n" if stem is not None else ""
    overhead = measure(prefix + stem_text) if (prefix or stem_text) else 0
    budget = band.max_tokens - overhead
    if budget <= 0:
        raise _fail(
            f"the prefix and list stem for {head.element_id!r} are {overhead} tokens against a "
            f"{band.max_tokens}-token band, leaving no room for the text itself. The prefix is "
            "not optional — it is the largest measured retrieval lever here — so this is a "
            "heading path or a stem that needs shortening upstream, not a budget to widen"
        )

    segments: list[_Segment] = []
    for element in body:
        segments.extend(_segments(element, measure, budget=budget))
    if not segments:
        return []

    chunks: list[Chunk] = []
    buffer: list[_Segment] = []
    used = 0
    overlap_of: str | None = None
    for segment in segments:
        if segment.tokens > budget:
            raise _fail(
                f"a single sentence of element {segment.element.element_id!r} measures "
                f"{segment.tokens} tokens against a {budget}-token body budget. There is no "
                "structural boundary left to split on, and the two ways past this — a mid-word "
                "cut or a vendor truncation parameter — both convert a loud chunker failure "
                "into a silent retrieval one"
            )
        if buffer and used + segment.tokens > budget:
            chunks.append(
                _emit(
                    buffer,
                    stem=stem,
                    context=context,
                    measure=measure,
                    seq=first_seq + len(chunks),
                    overlap_of=overlap_of,
                )
            )
            carried = _overlap(buffer, band)
            # The carry is charged against the same budget as everything else. Dropped rather
            # than trimmed when it will not fit beside the segment that forced the flush:
            # keeping it would put the chunk over the ceiling, and the ceiling is the one number
            # the embed step cannot recover from being wrong about.
            if sum(piece.tokens for piece in carried) + segment.tokens > budget:
                carried = ()
            overlap_of = chunks[-1].metadata.chunk_id if carried else None
            buffer = list(carried)
            used = sum(piece.tokens for piece in carried)
        buffer.append(segment)
        used += segment.tokens

    chunks.append(
        _emit(
            buffer,
            stem=stem,
            context=context,
            measure=measure,
            seq=first_seq + len(chunks),
            overlap_of=overlap_of,
        )
    )
    return chunks


def _overlap(previous: Sequence[_Segment], band: SizePolicy) -> tuple[_Segment, ...]:
    """The trailing whole sentences of `previous` that the next chunk repeats.

    Whole sentences, capped by `band.overlap_ratio`, and never all of them — a chunk that
    contains the whole of its predecessor is a duplicate that competes with it for every
    evidence slot. Zero-ratio bands get nothing, which is every band except prose: overlap
    across a heading, page, slide, sheet or table edge buys nothing and costs an index slot per
    chunk, and published evals show recall *and* precision falling as it rises even within
    continuous text.
    """
    if band.overlap_ratio <= 0 or len(previous) < 2:
        return ()
    total = sum(segment.tokens for segment in previous)
    allowance = total * band.overlap_ratio
    carried: list[_Segment] = []
    taken = 0
    for segment in reversed(previous[1:]):
        # The cap binds on the FIRST sentence too, which is why the loop takes nothing when the
        # trailing sentence is on its own larger than the allowance. Taking it anyway — the
        # obvious "at least one" convenience — makes the ratio advisory: a run of four
        # 200-token paragraphs would repeat a third of the chunk under a 12% setting, and the
        # measured cost of overlap is a precision loss that rises with exactly that ratio.
        if taken + segment.tokens > allowance:
            break
        carried.insert(0, segment)
        taken += segment.tokens
    return tuple(carried)


def _emit(
    segments: Sequence[_Segment],
    *,
    stem: Any | None,
    context: ChunkContext,
    measure: Callable[[str], int],
    seq: int,
    overlap_of: str | None,
) -> Chunk:
    """One chunk, with every field of the schema populated or explicitly `None`.

    `text` is assembled first and `token_count` and `content_hash` are both taken over it
    verbatim, in that order. Hashing anything narrower leaves stale vectors serving the old text
    after a heading is renamed; hashing anything wider — a timestamp, a regenerated date line —
    re-embeds an unchanged corpus on every ingest, forever.
    """
    head, tail = segments[0].element, segments[-1].element
    page_end = tail.page if tail.page != head.page else None
    prefix = _prefix(
        heading_path=head.heading_path,
        page=head.page,
        page_end=page_end,
        slide=head.slide,
        sheet=head.sheet,
        table_ref=None,
        row_range=None,
    )
    stem_text = f"{stem.text.strip()}\n" if stem is not None else ""
    text = prefix + stem_text + "\n".join(segment.text for segment in segments)

    token_count = measure(text)
    if token_count > MAX_TOKENS:
        raise _fail(
            f"chunk {seq} of version {context.source_version_id} measures {token_count} tokens "
            f"against the {MAX_TOKENS}-token ceiling. The cap is enforced here, at the "
            "structural pass, precisely so the embed step never has to choose between raising "
            "and letting a provider index a prefix"
        )

    element_ids: list[str] = []
    for element in ([stem] if stem is not None else []) + [s.element for s in segments]:
        if element.element_id not in element_ids:
            element_ids.append(element.element_id)

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
            document_element_id=head.element_id,
            element_ids=tuple(element_ids),
            parent_element_id=head.parent_element_id,
            heading_path=tuple(head.heading_path),
            page=head.page,
            page_end=page_end,
            slide=head.slide,
            sheet=head.sheet,
            table_ref=None,
            row_range=None,
            url=context.url,
            anchor=head.anchor,
            char_start=segments[0].char_start,
            char_end=segments[-1].char_end,
            lang=head.lang,
            content_type=head.content_type,
            token_count=token_count,
            content_hash=hashlib.sha256(text.encode("utf-8")).hexdigest(),
            overlap_of=overlap_of,
            created_at=context.created_at,
            effective_at=context.effective_at,
            expires_at=context.expires_at,
            embedding_model_id=context.embedding_model_id,
            parser_version=context.parser_version,
            chunker_version=CHUNKER_VERSION,
        ),
    )


# A field lost here is a broken citation or an undeletable chunk, and neither fails loudly at
# the point of loss. Checked at import so a partially-populated schema cannot reach a worker.
assert len(CHUNK_METADATA_FIELDS) == 32
assert "source_version_id" in CHUNK_METADATA_FIELDS
assert set(SIZE_POLICY) == set(CONTENT_TYPES)
assert all(p.max_tokens <= MAX_TOKENS for p in SIZE_POLICY.values())
