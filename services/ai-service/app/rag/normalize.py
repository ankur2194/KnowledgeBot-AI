"""Stage 4 — query normalization, and the decision that some questions need no retrieval.

Two jobs that pull in opposite directions, which is why they live in one module with one
shared guard between them.

**Normalizing must not destroy what the lexical branch exists to match.** Numbers, product
codes, error codes, quoted spans and acronyms are precisely the tokens the sparse arm scores
on — a query cleaner that lowercases, de-hyphenates, or strips "noise" around them turns
"does the XR-400B cover accidental damage?" into a question about warranties in general, and
the retrieval trace looks *healthy* afterwards because retrieval genuinely succeeded, just
for the wrong question. So every transformation here is checked against the code-like tokens
it started with, and a transformation that loses one is reverted rather than accepted. That
is the same guard :mod:`app.rag.rewrite` applies to a model-authored rewrite, applied here to
our own regexes, because a deterministic cleaner is not more trustworthy than a model — it is
only more predictable about *which* queries it ruins.

**Detecting that a request needs no retrieval must not silence a real question.** "hi" is a
greeting; "hi, what does E-4021 mean?" is a support ticket. The gate is therefore three
conjoined conditions — a full match against a closed pattern set, no code-like token present,
and a short query — rather than a substring search, and it defaults to *needing* retrieval on
every input it is unsure about. Getting it wrong in that direction costs a wasted retrieval;
getting it wrong in the other direction answers a product question from model knowledge with
no evidence and no citations, which is the failure the whole pipeline exists to prevent.

``needs_retrieval = False`` is recorded on the trace rather than inferred later from an empty
candidate list. The playground has one panel for "retrieval found nothing" and it must not
show a greeting there: an operator debugging recall would be looking at a request that never
retrieved on purpose.

**What this module deliberately does not do.** It does not strip injection phrasing, and it
does not sanitize retrieved text — the invisible-character strip belongs at *ingestion*, so
the stored chunk, the embedding and the prompt all agree on the bytes
(``kb-security-baseline``). The same strip is applied here to the *query* only because a query
arrives from a client that may have pasted from a poisoned page, and an invisible instruction
in the question would otherwise reach the conversation section of the prompt, which is a
trusted region. That is one narrow hole, closed narrowly; it is not a prompt-time sanitizer.
"""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass
from typing import Final, Literal

__all__ = [
    "INVISIBLE_CHARACTERS",
    "NO_RETRIEVAL_MAX_WORDS",
    "NO_RETRIEVAL_PATTERNS",
    "PRESERVED_TOKEN_PATTERNS",
    "SCRIPT_SUBTAGS",
    "UI_NOISE_PATTERNS",
    "UNICODE_FORM",
    "NormalizedQuery",
    "detect_script",
    "normalize_query",
    "preserved_tokens",
]

#: The normalization form. NFKC and not NFC, because it is the form ingestion applies before
#: a chunk is embedded, and a query normalized differently from the corpus is a query that
#: cannot match it lexically: full-width digits in a pasted part number stay full-width, the
#: analyzer emits different terms, and the sparse branch returns nothing with no error.
#:
#: NFKC is lossy in ways NFC is not — it folds compatibility characters, so ``①`` becomes
#: ``1`` and a superscript becomes a digit. That loss is *wanted* here for the same reason:
#: it is the loss the indexed side already took.
UNICODE_FORM: Final[Literal["NFKC"]] = "NFKC"

#: Characters that render as nothing and tokenize as something. The Unicode Tags block
#: U+E0000-U+E007F mirrors printable ASCII one-to-one, shows up in no editor, terminal or
#: diff, and reads to a model as ordinary instructions; the zero-width, joiner, bidi-override
#: and BOM ranges are the same trick with older codepoints.
#:
#: Stripped from the *query*, before anything else runs. This is not a substitute for the
#: ingestion-time strip (``kb-security-baseline``) and does not touch retrieved text: it
#: closes the one path a client controls, where a pasted question carrying hidden text would
#: land in the conversation section of the prompt — which is an instruction region.
#:
#: Written as escapes, never as the literal characters: a source file holding the literals is
#: a source file in which this very list is invisible to the reviewer reading it.
INVISIBLE_CHARACTERS: Final[re.Pattern[str]] = re.compile(
    "["
    "\U000e0000-\U000e007f"  # Unicode Tags block, one-to-one onto printable ASCII
    "​-‏"  # zero-width space, ZWNJ, ZWJ, LRM, RLM
    "‪-‮"  # bidi embedding and override
    "⁠-⁤"  # word joiner and the invisible operators
    "⁦-⁩"  # bidi isolates
    "­"  # soft hyphen
    "﻿"  # BOM / zero-width no-break space
    "]"
)

#: What a "code-like token" is, and therefore what no transformation in this module or in
#: :mod:`app.rag.rewrite` may drop. The four shapes are the contract's, verbatim: digits,
#: ``[A-Z]{2,}``, hyphenated alphanumerics, quoted spans.
#:
#: They overlap on purpose — ``XR-400B`` matches three of the four — because the guard tests
#: set membership, not a partition, and an overlap only makes the guard stricter.
PRESERVED_TOKEN_PATTERNS: Final[tuple[re.Pattern[str], ...]] = (
    # A run of digits, possibly with internal separators: 4021, 1.5, 10,000, 2026-08-26.
    re.compile(r"\d+(?:[.,\-/]\d+)*"),
    # An acronym or an all-caps identifier: VPN, SSO, XR. Two characters minimum, because a
    # single capital is the first letter of every sentence.
    re.compile(r"\b[A-Z]{2,}\b"),
    # A hyphenated alphanumeric: XR-400B, E-4021, v2-beta. The two lookaheads scan across the
    # hyphens (`[\w-]`, not `[\w]`) because the letter and the digit are usually in different
    # segments — `XR-400B` has no digit before the hyphen and no letter after it, so a
    # lookahead confined to one segment matches nothing and the whole shape goes unpreserved.
    re.compile(r"\b(?=[\w-]*[A-Za-z])(?=[\w-]*\d)\w+(?:-\w+)+\b"),
    # An explicitly quoted span. The user quoting something is the loudest possible statement
    # that the exact wording matters.
    #
    # Double quotes, straight or typographic — and typographic SINGLE quotes only. The
    # straight apostrophe is deliberately absent: "don't worry, it's fine" would otherwise
    # yield a "quoted span" of `t worry, i`, and the entity guard would then require that
    # string to survive into the rewrite and discard every rewrite of every contraction.
    re.compile('["“]([^"“”]{1,200})["”]'),
    re.compile("\u2018([^\u2018\u2019]{2,200})\u2019"),
)

#: UI chrome a client may paste in with a question. **Deliberately tiny, and each entry is an
#: allow-list decision rather than a heuristic.** A broad "noise" filter is the thing that
#: eats product codes: every pattern here is anchored to a whole line or a line prefix, and
#: the whole removal is reverted by :func:`normalize_query` if it costs a preserved token, so
#: a bad entry degrades to a no-op instead of to a silently different question.
UI_NOISE_PATTERNS: Final[tuple[re.Pattern[str], ...]] = (
    # Markdown quote and table-cell markers at the start of a line, from a copied thread.
    re.compile(r"(?m)^[ \t]*[>|]+[ \t]*"),
    # Button and affordance labels that copy as their own line.
    re.compile(
        r"(?im)^[ \t]*(?:copy(?:\s+code)?|copied!?|skip to (?:main )?content"
        r"|was this (?:page )?helpful\??|edit this page|share|reply)[ \t]*$"
    ),
)

#: Requests that are complete without any source. Full-match patterns, never substring
#: searches: ``re.fullmatch`` against the whole normalized query is what stops "thanks, but
#: which model is the XR-400B?" from being answered as a pleasantry.
NO_RETRIEVAL_PATTERNS: Final[tuple[re.Pattern[str], ...]] = (
    re.compile(
        r"(?i)\s*(?:hi|hey|hello|yo|hiya|good\s+(?:morning|afternoon|evening)|greetings)"
        r"[\s!.,]*(?:there|folks|team|everyone)?[\s!.,]*"
    ),
    re.compile(r"(?i)\s*(?:thanks|thank\s+you|thx|ty|cheers|much\s+appreciated)[\s!.,]*"),
    re.compile(r"(?i)\s*(?:ok(?:ay)?|got\s+it|understood|perfect|great|nice)[\s!.,]*"),
    re.compile(r"(?i)\s*(?:bye|goodbye|see\s+you|have\s+a\s+(?:good|nice)\s+\w+)[\s!.,]*"),
    re.compile(r"(?i)\s*(?:ok(?:ay)?|alright)?[\s,]*(?:thanks|thank\s+you)[\s!.,]*"),
)

#: Third condition on the no-retrieval gate. A greeting is short; a question that happens to
#: open with one is not, and the length check is what makes the pattern set safe to extend.
NO_RETRIEVAL_MAX_WORDS: Final[int] = 6

#: Unicode script name to the BCP-47 script subtag recorded on the trace. **It is a script,
#: not a language**, and the field is named accordingly everywhere it travels: Latin script
#: is English, Spanish, Vietnamese and Turkish alike, and a two-word query carries nothing
#: that would separate them. It is recorded for the playground and it is **never** used as a
#: retrieval filter — a language facet derived from this would drop half a tenant's corpus on
#: a query that happened to be four words long.
SCRIPT_SUBTAGS: Final[dict[str, str]] = {
    "LATIN": "Latn",
    "CYRILLIC": "Cyrl",
    "GREEK": "Grek",
    "ARABIC": "Arab",
    "HEBREW": "Hebr",
    "DEVANAGARI": "Deva",
    "BENGALI": "Beng",
    "TAMIL": "Taml",
    "THAI": "Thai",
    "HANGUL": "Hang",
    "HIRAGANA": "Hira",
    "KATAKANA": "Kana",
    "CJK": "Hani",
}


@dataclass(frozen=True, slots=True)
class NormalizedQuery:
    """Stage 4's output, and the three fields the trace panel reads.

    ``original`` is kept byte-for-byte beside ``text`` and never replaced by it. The original
    wording is what the generation prompt shows as the user's question (§12.5), and it is also
    the only way an operator looking at a bad answer can tell whether this stage was the thing
    that broke it.
    """

    #: Exactly what arrived, untouched, including whitespace and invisible characters.
    original: str
    #: The normalized retrieval-facing form.
    text: str
    #: BCP-47 script subtag form, e.g. ``und-Latn``. ``None`` when nothing scriptable was
    #: found (a query of digits and punctuation only). See :data:`SCRIPT_SUBTAGS`.
    script: str | None
    #: False only for requests that are complete without evidence. Defaults towards True on
    #: every uncertain input.
    needs_retrieval: bool
    #: The code-like tokens found in ``text``, in first-appearance order. Carried so stage 5's
    #: entity guard compares against what stage 4 actually produced rather than re-deriving it
    #: from a string that has since been rewritten.
    preserved: tuple[str, ...]
    #: True when a UI-noise pattern matched but its removal was reverted because it would have
    #: cost a preserved token. Recorded rather than silent: a pattern that fires this often is
    #: a pattern to delete.
    noise_removal_reverted: bool = False


def preserved_tokens(text: str) -> tuple[str, ...]:
    """Every code-like token in ``text``, in first-appearance order, deduplicated.

    Deduplicated because the guard is a set question — did this token survive — and a query
    repeating a part number twice must not need it twice in the rewrite.

    Quoted spans contribute their *contents* rather than the quotes, so a rewrite that drops
    the quote marks but keeps the phrase is not reported as an entity drop. Dropping the
    phrase is; dropping the punctuation around it is a formatting change.
    """
    found: list[str] = []
    for pattern in PRESERVED_TOKEN_PATTERNS:
        for match in pattern.finditer(text):
            # Group 1 when the pattern captures (the quoted forms), group 0 otherwise.
            token = match.group(1) if match.groups() else match.group(0)
            token = token.strip()
            if token and token not in found:
                found.append(token)
    return tuple(found)


def detect_script(text: str) -> str | None:
    """The dominant Unicode script of ``text`` as a BCP-47 script subtag, or ``None``.

    Character-class counting over :mod:`unicodedata` names, not language identification. It is
    a deliberately weak signal published under a deliberately weak name: a real language
    identifier is a model, this pipeline runs no local models (ADR-030), and a provider call
    to identify the language of a six-word query would spend a round trip inside a 1.5 s
    retrieval budget to fill a panel field.

    Ties break towards the first script seen, which makes the result stable for a replay.
    """
    counts: dict[str, int] = {}
    for character in text:
        if not character.isalpha():
            continue
        try:
            name = unicodedata.name(character)
        except ValueError:  # unnamed codepoint
            continue
        head = name.split(" ")[0]
        subtag = SCRIPT_SUBTAGS.get(head)
        if subtag is None:
            continue
        counts[subtag] = counts.get(subtag, 0) + 1
    if not counts:
        return None
    # `max` over the insertion-ordered items returns the FIRST maximal entry, which is the
    # stated tie-break. Sorting by count alone would break ties by whatever order the dict
    # happened to be in, and a replay of the trace would report a different script.
    best = max(counts, key=lambda subtag: counts[subtag])
    return f"und-{best}"


def _strip_ui_noise(text: str) -> str:
    for pattern in UI_NOISE_PATTERNS:
        text = pattern.sub(" ", text)
    return text


def _collapse_whitespace(text: str) -> str:
    """One space between tokens, no leading or trailing space, no newlines.

    Newlines go too. A multi-line question is one retrieval query, and the embedding provider
    is handed a single string either way — keeping the line structure only makes two queries
    that differ by a line break embed differently and cache differently.
    """
    return re.sub(r"\s+", " ", text).strip()


def normalize_query(raw: str) -> NormalizedQuery:
    """Stage 4. Normalize, detect, and decide whether retrieval is needed at all.

    The order is fixed and each step is checked against the last:

    1. Strip invisible characters, then NFKC-normalize. Both before anything is measured, so
       the preserved-token baseline is taken from text a human could actually see.
    2. Take the preserved-token baseline.
    3. Remove UI noise — **and revert the removal entirely if it lost a preserved token.**
       All-or-nothing rather than per-pattern, because the patterns compose: reverting only
       the guilty one leaves the text in a state no single pattern produced, which is not
       reproducible from the trace.
    4. Collapse whitespace, which cannot lose a token.
    5. Decide ``needs_retrieval`` from the *normalized* text.

    Records ``kb.query.normalize`` — the span is opened by the runner, which owns timing and
    the trace fragment; this function is pure and has no clock, no client and no I/O.
    """
    visible = INVISIBLE_CHARACTERS.sub("", raw)
    normalized = unicodedata.normalize(UNICODE_FORM, visible)

    baseline = preserved_tokens(normalized)
    stripped = _strip_ui_noise(normalized)
    reverted = False
    if not set(baseline) <= set(preserved_tokens(stripped)):
        # The cleaner ate an identifier. Reverting is not a fallback to be tidied away later:
        # a normalized query missing a part number retrieves generic pages and produces a
        # trace in which every stage looks healthy.
        stripped = normalized
        reverted = True

    text = _collapse_whitespace(stripped)
    preserved = preserved_tokens(text)

    return NormalizedQuery(
        original=raw,
        text=text,
        script=detect_script(text),
        needs_retrieval=_needs_retrieval(text, preserved),
        preserved=preserved,
        noise_removal_reverted=reverted,
    )


def _needs_retrieval(text: str, preserved: tuple[str, ...]) -> bool:
    """Three conjoined conditions, and the default is True.

    * A **full** match against :data:`NO_RETRIEVAL_PATTERNS`. Not a search: "thanks, does the
      XR-400B ship with a charger?" contains a greeting and is a question.
    * **No preserved token.** A code-like token in a query is a request for evidence about
      that code, whatever the surrounding words look like. This condition alone would catch
      the example above; it is kept beside the full match because they fail differently and
      a closed pattern set is easier to extend when it is not the only guard.
    * **Short.** Long text that full-matches a greeting pattern is a pattern bug, and the
      length cap bounds its blast radius to queries where being wrong is cheap.
    """
    if not text:
        # An empty question is stage 1's rejection, not a greeting. Reaching retrieval with
        # one would query for the empty string, which matches by cosine similarity alone.
        return True
    if preserved:
        return True
    if len(text.split()) > NO_RETRIEVAL_MAX_WORDS:
        return True
    return not any(pattern.fullmatch(text) for pattern in NO_RETRIEVAL_PATTERNS)
