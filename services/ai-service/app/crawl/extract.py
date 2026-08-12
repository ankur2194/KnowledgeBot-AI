"""Conservative HTML-to-Markdown. Crawl4AI is a normalizer here and nothing else.

**No content filter ever touches indexed text.** ``PruningContentFilter``,
``BM25ContentFilter`` and ``LLMContentFilter`` are a preview and summarisation feature. They
are banned from the indexed path, and each for its own reason:

* ``PruningContentFilter`` decomposes ``nav``, ``footer``, ``header``, ``aside``, ``form``
  and ``iframe`` before it scores anything, and its tag-weight map has no entry for ``a`` or
  ``strong`` — so those score 0 and vanish *mid-sentence*, leaving grammatical, plausible,
  gutted prose (crawl4ai issue #582). The text it removes most reliably is the disclaimer,
  the pricing caveat, and the policy link: exactly what a grounded bot exists to cite, and
  exactly what nobody notices is missing, because what remains reads fine.
* ``BM25ContentFilter`` needs a query. Ingestion has none, and inventing one means the stored
  text depends on a string nobody chose.
* ``LLMContentFilter`` is non-deterministic, so the same page normalizes differently on every
  recrawl, hashes differently, and re-versions forever (``app/crawl/recrawl.py``).

The related trap is ``excluded_tags=["nav","footer","aside"]``, which appears in every
tutorial and hand-removes the legal text. Crawl4AI's own ``cleaned_html`` removes only
``script``, ``style``, ``link``, ``meta`` and ``noscript`` — the footer survives until
somebody configures it away. ``EXCLUDED_TAGS`` here is empty and stays empty.

**We label chrome; we never remove it.** Deciding that a block is boilerplate needs
cross-page frequency, and a single page fetch does not have it. This module supplies the
structural half of the signal — "this text came out of a ``<footer>`` subtree" — and
``kb-chunking-rules``' boilerplate pass, which can see every page, supplies the frequency
half and makes the verdict. Anything ever dropped is recorded as a ``RemovedBlock`` carrying
its own text, so a removal is auditable and reversible; a block that disappears with no
record is content silently lost from evidence a model will later be told to trust.

**Read ``raw_markdown``, never ``fit_markdown``.** With no content filter configured,
``DefaultMarkdownGenerator`` sets ``fit_markdown`` to the empty string — not to a duplicate
of ``raw_markdown``. Code written against a tutorial that reads ``fit_markdown`` indexes
nothing at all, on every page, and verification passes because zero chunks were exactly what
was produced. ``assert_extractable`` exists to make that failure loud.
"""

from __future__ import annotations

from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any, Final

__all__ = [
    "BANNED_CONTENT_FILTERS",
    "CACHE_MODE",
    "CHECK_ROBOTS_TXT",
    "CHROME_TAGS",
    "CONTENT_FILTER",
    "CONTENT_SOURCE",
    "EXCLUDED_TAGS",
    "INVISIBLE_CHARACTER_RANGES",
    "MARKDOWN_OPTIONS",
    "MIN_EXTRACTED_CHARACTERS",
    "WORD_THRESHOLD",
    "ChromeBlock",
    "ExtractedPage",
    "RemovedBlock",
    "assert_extractable",
    "chrome_text_blocks",
    "conservative_run_config",
    "extract_page",
]


#: ``None``, deliberately, and this constant exists so the choice is greppable rather than
#: implied by an absent keyword argument. The decision this whole module is written around.
CONTENT_FILTER: Final[None] = None

#: CI greps ``app/crawl/`` for these names. A test asserting their absence is cheap; noticing
#: that a footer disclaimer stopped being retrievable six weeks ago is not.
BANNED_CONTENT_FILTERS: Final[tuple[str, ...]] = (
    "PruningContentFilter",
    "BM25ContentFilter",
    "LLMContentFilter",
)

#: Empty, permanently. See the module docstring.
EXCLUDED_TAGS: Final[tuple[str, ...]] = ()

#: ``cleaned_html`` strips script/style/link/meta/noscript and nothing else. ``fit_html``
#: would be the filtered document; ``html`` would be the raw one including scripts.
CONTENT_SOURCE: Final[str] = "cleaned_html"

#: Links and images are kept because a citation-grounded answer frequently needs the anchor
#: text and the link target, and alt text is often the only description of a diagram.
#: ``body_width=0`` disables hard wrapping, which otherwise inserts newlines mid-sentence and
#: changes the hash whenever a paragraph's length changes.
MARKDOWN_OPTIONS: Final[Mapping[str, Any]] = {
    "ignore_links": False,
    "ignore_images": False,
    "body_width": 0,
}

#: One. Crawl4AI's default discards short blocks, and a one-line business-hours notice or a
#: single-row price table is a short block that is the entire reason a source was added.
WORD_THRESHOLD: Final[int] = 1

#: ``BYPASS``. PostgreSQL's ``source_versions`` owns freshness; Crawl4AI's cache is a
#: per-container SQLite file, so a hit on one worker is a miss on the other seven and the
#: cache's only measurable effect is inconsistency between children.
CACHE_MODE: Final[str] = "BYPASS"

#: False. robots.txt is ours (``app/crawl/politeness.py``), fetched through the guard. True
#: makes Crawl4AI fetch it itself, unvalidated.
CHECK_ROBOTS_TXT: Final[bool] = False

#: Structural chrome — labelled and carried alongside the markdown, never removed from it.
CHROME_TAGS: Final[tuple[str, ...]] = ("nav", "footer", "header", "aside")

#: Stripped before hashing and before indexing. U+E0000-U+E007F (Unicode Tags) maps one to
#: one onto printable ASCII, renders as nothing in every viewer, and tokenizes as
#: instructions — an injection payload no reviewer can see. The zero-width and bidi ranges
#: are the same problem with more history.
#:
#: The authoritative strip for indexed text is ingestion's (`kb-security-baseline`). This
#: module applies the identical ranges because the content hash is computed here, and a hash
#: taken over unstripped text disagrees with the bytes the embedder sees — which re-versions
#: a page whose only change was an invisible character. The two lists must stay byte-for-byte
#: identical; a test compares them directly rather than trusting the comment.
INVISIBLE_CHARACTER_RANGES: Final[tuple[tuple[int, int], ...]] = (
    (0x00AD, 0x00AD),  # soft hyphen
    (0x200B, 0x200F),  # zero-width space/non-joiner/joiner, LRM, RLM
    (0x202A, 0x202E),  # bidi embedding and override
    (0x2060, 0x2064),  # word joiner, invisible operators
    (0x2066, 0x2069),  # bidi isolates
    (0xFEFF, 0xFEFF),  # zero-width no-break space / BOM
    (0xE0000, 0xE007F),  # Unicode Tags
)

#: Below this, the page is treated as an extraction failure rather than as empty content.
#: A page that yields three characters produced no evidence, and indexing it creates a
#: version, a citation URL and an embedding for nothing.
MIN_EXTRACTED_CHARACTERS: Final[int] = 32


@dataclass(frozen=True, slots=True)
class ChromeBlock:
    """One structurally-chrome text block, kept beside the markdown rather than cut from it.

    ``text`` is present in ``ExtractedPage.markdown`` too — this is a label, not an
    extraction. Consumers that treat it as a removal list will delete the footer, which is
    the failure the whole module is arranged to prevent.
    """

    tag: str
    text: str
    #: Position in the markdown, so the boilerplate pass can act without re-parsing the DOM.
    start: int
    end: int


@dataclass(frozen=True, slots=True)
class RemovedBlock:
    """Something that *was* dropped, with its text, so the drop is auditable and reversible.

    Nothing in this module produces one today, and that is the intended state. The type
    exists so that if a removal is ever added, there is no version of it that loses the text
    silently.
    """

    reason: str
    text: str


@dataclass(frozen=True, slots=True)
class ExtractedPage:
    """What one page contributes to a version."""

    markdown: str
    title: str | None
    #: The page's own `<link rel="canonical">`, unresolved. `discovery.canonicalize` decides
    #: whether to honour it; page content does not get to reassign an item identity by itself.
    canonical_link: str | None
    chrome_blocks: tuple[ChromeBlock, ...]
    removed_blocks: tuple[RemovedBlock, ...]
    #: Words of markdown, the input to the JS-render escalation threshold.
    word_total: int
    #: True when invisible-character stripping changed the text. Not an error — worth
    #: recording, because a page carrying Unicode Tags is carrying them on purpose.
    invisible_characters_stripped: bool


def conservative_run_config() -> Any:
    """Build the single ``CrawlerRunConfig`` this package uses, from the constants above.

    One config, built here, used everywhere. A second config constructed at a call site is
    how ``excluded_tags`` or a content filter arrives without appearing in any review of this
    file.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def extract_page(html: str, *, base_url: str) -> ExtractedPage:
    """Normalize already-fetched HTML into markdown.

    The argument is HTML we already hold, and the call into Crawl4AI is
    ``arun(url="raw:" + html, ...)``. ``raw:`` means no navigation, no DNS and no fetch — it
    is what makes using this library compatible with the envelope in ``fetch.py``. A URL
    passed here instead would be resolved by Crawl4AI, and CI greps for exactly that.

    Reads ``result.markdown.raw_markdown``. See the module docstring for why
    ``fit_markdown`` is empty and why reading it fails silently.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def chrome_text_blocks(cleaned_html: str) -> tuple[ChromeBlock, ...]:
    """Label ``CHROME_TAGS`` subtrees with lxml. Removes nothing.

    Parsed with entity resolution disabled — this is attacker-supplied markup being handed to
    a parser inside a container the attacker would like to reach.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def assert_extractable(page: ExtractedPage) -> None:
    """Fail loudly when a page produced no usable text.

    Guards the silent-empty family of bugs: ``fit_markdown`` read instead of ``raw_markdown``,
    a client-rendered shell that was never escalated to a render, a body that turned out to be
    a cookie wall. All three produce a successful crawl, a published version, and zero
    retrievable content — and every counter reads healthy, because zero chunks is what the
    pipeline was handed.

    Raises a ``KbError(PARSING)`` that is **not** retryable: refetching an empty page yields
    an equally empty page. The item's disposition is ``failed``, never ``unchanged`` — an
    empty extraction must not be hashed and compared, or the first empty crawl becomes the
    baseline that every later good crawl is measured against.
    """
    raise NotImplementedError("TODO(crawler-engineer)")
