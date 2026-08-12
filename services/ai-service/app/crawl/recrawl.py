"""Change detection: deciding whether a recrawl produced anything new.

**A page whose content is unchanged must not produce a new version.** This is the single
most consequential decision in the crawler, and getting it wrong is quiet. If every page
re-versions nightly then: every page re-embeds, so the embedding bill scales with the
schedule rather than with the content; the index churns 400 points a night per source, so
retirement and deletion accounting drifts away from what is actually stored; and the "last
changed" date on every citation becomes the date of the last crawl, which is to say it
carries no information at all. Nothing errors. The dashboards look busy and healthy.

**Hash normalized content, never raw HTML.** CSRF tokens, render timestamps, ad slots, view
counters and "N users online" change on every response, so a raw-HTML hash reports every page
as changed on every run. The hash is taken over the markdown after chrome labelling and after
the invisible-character strip (``app/crawl/extract.py``) — that is, over exactly the bytes the
embedder will see, because a hash over anything else answers a question nobody asked.

**Conditional headers are the optimization; the hash is the gate.** ``If-None-Match`` and
``If-Modified-Since`` are sent when we hold them, and a 304 is a genuine skip. But most
CMS-backed sites either omit ETags or rotate them per response, so a recrawl that trusts
headers alone both misses real changes (Last-Modified never moved) and re-versions unchanged
pages (the ETag rotated). ``etag`` and ``last_modified`` live on the source item, never on the
version — a version is a snapshot of content, and a header is a property of the endpoint.

**A page that 404s on recrawl is not silently dropped.** It is not deleted, not marked
missing on the first failure, and never removed on the strength of a single run. The counter
only moves on a run that reached a successful terminal state; a 5xx, a timeout or a
``CrawlRejected`` is a fetch *error*, and an error is not evidence about whether a page still
exists. Any rediscovery — presence in the URL set, a 200, or a 304 — resets the counter in the
same transaction, because "consecutive" has to mean consecutive. And the run-level circuit
breaker below refuses to apply any policy at all when too much of the site went missing at
once, which is what a truncated sitemap, an expired certificate or a maintenance page looks
like from here.

This module decides; it writes nothing. The crawl worker has no route to PostgreSQL, so every
verdict here travels to ``ai-api`` as data (``app/crawl/tasks.py``) and Laravel activates
versions.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from enum import StrEnum
from typing import Final

__all__ = [
    "CONTENT_HASH_ALGORITHM",
    "CRAWL_MISSING_FRACTION_MAX",
    "DEFAULT_MISSING_POLICY",
    "DEFAULT_MISSING_THRESHOLD",
    "HASH_NORMALIZATION_VERSION",
    "MissingPolicy",
    "PageDisposition",
    "PageOutcome",
    "RecrawlPlan",
    "breaker_tripped",
    "content_hash",
    "decide_disposition",
    "missing_fraction",
    "normalize_for_hash",
    "plan_run",
]


CONTENT_HASH_ALGORITHM: Final[str] = "sha256"

#: Bump this and every page in the platform re-versions exactly once, on purpose.
#:
#: That is the point: any change to `normalize_for_hash` — a whitespace rule, a new invisible
#: range, a different link-reference treatment — silently re-versions the whole corpus on the
#: next nightly run, with no commit message anywhere near the crawl scheduler explaining the
#: bill. Making the version explicit turns that into a deliberate, dated, greppable event, and
#: lets an operator stage it rather than discover it.
HASH_NORMALIZATION_VERSION: Final[int] = 1

#: Run-level circuit breaker. Above this fraction of known items missing, the run fails, no
#: missing policy is applied, and not one counter moves. A site behind a maintenance page, a
#: sitemap that got truncated, or a login wall that appeared overnight otherwise disables
#: every page of a source in a single night — a change no single-page rule can distinguish
#: from the site genuinely being deleted, and one that no tenant asked for.
CRAWL_MISSING_FRACTION_MAX: Final[float] = 0.20

#: Consecutive confirmed-missing runs before the default policy acts.
DEFAULT_MISSING_THRESHOLD: Final[int] = 3


class MissingPolicy(StrEnum):
    """What happens to an item confirmed missing (docs/03 §8.14).

    The spec's own instruction is that the default is conservative: disable after repeated
    confirmation rather than removing content on one temporary sitemap or server failure.
    Retention-based automatic removal is the only branch that destroys anything, and it runs
    through ``kb-deletion-and-verification`` like every other purge — this module never
    removes a row.
    """

    KEEP_AND_REPORT = "keep_and_report"
    DISABLE_AFTER_ONE = "disable_after_one"
    DISABLE_AFTER_N = "disable_after_n"
    DELETE_AFTER_RETENTION = "delete_after_retention"


DEFAULT_MISSING_POLICY: Final[MissingPolicy] = MissingPolicy.DISABLE_AFTER_N


class PageDisposition(StrEnum):
    """The per-page result, and the ``disposition`` label on ``kb_crawl_pages_total``.

    The label is ``disposition`` and **not** ``outcome``. Six values, of which ``failed`` and
    ``missing`` are outside the platform's shared four-value outcome enum — so labelling this
    family ``outcome`` would put every crawl failure outside the global
    ``outcome=~"error|timeout"`` matcher. That is valid PromQL that silently under-reports:
    the series exist, the sum is simply smaller, and the error-rate panel reads healthy
    through a crawl in which a third of pages failed. Run-level health lives on
    ``kb_crawl_runs_total{outcome}``, which is the shared enum.
    """

    DISCOVERED = "discovered"
    CHANGED = "changed"
    UNCHANGED = "unchanged"
    SKIPPED = "skipped"
    MISSING = "missing"
    FAILED = "failed"


@dataclass(frozen=True, slots=True)
class PageOutcome:
    """One item's result from one run, as reported to ``ai-api``.

    ``error_class`` is populated only for ``FAILED`` and comes from the 18-class taxonomy —
    never a status code, never a free-form string. The four failure shapes a crawl produces
    are genuinely different and must not collapse into one: a 404 is a permanent
    not-retryable ``crawl``; a timeout or 5xx is a retryable ``crawl``; a robots refusal is
    ``SKIPPED`` and not a failure at all; and a ``CrawlRejected`` is a non-retryable ``crawl``
    that additionally carries a ``RejectReason`` for the security counter.
    """

    url: str
    disposition: PageDisposition
    content_hash: str | None = None
    status_code: int | None = None
    error_class: str | None = None
    reject_reason: str | None = None
    render_mode: str | None = None
    etag: str | None = None
    last_modified: str | None = None
    #: Set when this item's URL redirected onto another known item. A canonical fold, not a
    #: missing page: the redirecting item retires against the target and neither counter moves.
    folded_into: str | None = None


@dataclass(frozen=True, slots=True)
class RecrawlPlan:
    """The run's verdict, computed once, over the whole run.

    Built only after every page outcome is in. Applying a missing policy page by page as the
    run proceeds cannot see the circuit breaker, because the breaker is a property of the run.
    """

    outcomes: tuple[PageOutcome, ...] = ()
    #: Items known before the run and neither rediscovered nor conditionally confirmed.
    missing: tuple[str, ...] = ()
    breaker_tripped: bool = False
    #: True when discovery hit its page cap. Suppresses the missing policy entirely: "the
    #: sitemap got shorter" and "we stopped reading at the cap" are indistinguishable here,
    #: and only one of them is evidence.
    discovery_truncated: bool = False
    counters_reset: tuple[str, ...] = ()
    versions_to_create: tuple[str, ...] = ()
    notes: dict[str, str] = field(default_factory=dict)


def normalize_for_hash(markdown: str) -> str:
    """Reduce markdown to the canonical text the hash is taken over.

    Applies the invisible-character strip, collapses trailing whitespace, ends every line the
    same way, and removes the link-reference footnote block that markdown generation appends —
    those references renumber whenever an unrelated link is added, so leaving them in makes an
    edit to a navigation menu look like an edit to the article.

    **This must produce exactly the bytes the embedder receives.** If it does not, the hash
    answers a different question than the index does: a page can hash unchanged while its
    embedded text changed, and the version that would have carried the correction is never
    created.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def content_hash(normalized: str) -> str:
    """``sha256`` over the normalized text, with ``HASH_NORMALIZATION_VERSION`` mixed in.

    The version is inside the digest rather than beside it so that a normalizer change cannot
    produce a matching hash against text normalized by the previous rules — which would be
    the worst outcome available: a real change reported as unchanged.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def decide_disposition(
    *,
    known_hash: str | None,
    fetched_hash: str | None,
    status_code: int | None,
    not_modified: bool,
    robots_skipped: bool,
) -> PageDisposition:
    """One page's disposition, from one run's evidence.

    The decision order is the part worth stating, because each step is a case that gets
    silently absorbed by the next when the order is wrong:

    1. robots refusal -> ``SKIPPED``. Not a failure, not missing.
    2. 304 -> ``UNCHANGED``, and the missing counter resets. A conditional confirmation is
       rediscovery.
    3. an item we have never seen -> ``DISCOVERED``.
    4. hashes equal -> ``UNCHANGED``. No version, no embedding, no index write.
    5. hashes differ -> ``CHANGED``. Exactly one new version for this item, and nothing
       happens to the other 397 items on the site.
    6. 404 or 410 -> ``MISSING``, subject to the run-level breaker and to the policy. Only
       here, and only on an otherwise successful run.
    7. anything else -> ``FAILED``, with an ``error_class``, and the missing counter does not
       move.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def missing_fraction(missing_items: int, known_items: int) -> float:
    """Missing items over known items, for the breaker. Zero known items is zero, never a
    division error inside a failure path."""
    raise NotImplementedError("TODO(crawler-engineer)")


def breaker_tripped(missing_items: int, known_items: int) -> bool:
    """Whether this run may apply any missing policy at all.

    True above ``CRAWL_MISSING_FRACTION_MAX``: the run ends with
    ``kb_crawl_runs_total{outcome="error"}``, applies nothing, and leaves every counter
    untouched. A tripped breaker is louder than a quiet mass-disable and infinitely cheaper to
    recover from.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def plan_run(
    *,
    outcomes: tuple[PageOutcome, ...],
    known_urls: frozenset[str],
    discovery_truncated: bool,
    run_succeeded: bool,
) -> RecrawlPlan:
    """Fold every page outcome into the run's verdict.

    Preconditions that are the whole safety argument, checked here rather than trusted:
    the run reached a successful terminal state, discovery was not truncated, and the breaker
    did not trip. Fail any one and the plan carries an empty missing set — never a partial
    one. A crawl that indexed 3 of 200 pages and reported success is worse than one that
    failed loudly, and a missing policy applied on partial evidence is that same failure with
    consequences attached.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


assert len(PageDisposition) == 6
