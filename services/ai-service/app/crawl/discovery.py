"""Which URLs a crawl run is allowed to visit, and how they are found.

Discovery is ours, not Crawl4AI's. ``AsyncUrlSeeder`` and ``BFSDeepCrawlStrategy`` both fetch
on their own account, which means a sitemap entry or a discovered link would reach the
network without passing ``app/crawl/fetch.guarded_get`` — and a sitemap is exactly where an
attacker puts ``http://169.254.169.254/`` once the seed URL is guarded. Every fetch in this
module is ``guarded_get``: robots.txt, every sitemap, every sitemap index, every page.

Scope is a budget, not a security boundary. The security boundary is the address check in
``fetch.py``; scope exists so that one tenant pointing at a link farm cannot spend the whole
crawl queue, and so that a crawl of ``docs.example.com/guide`` does not silently index the
company blog. Both failure modes are quiet — the run succeeds, the numbers are just wrong —
which is why the caps below are constants rather than something each source may raise.

What breaks without the ordering here: discovery must complete and be truncated to the page
budget *before* any page is fetched. A crawler that discovers and fetches interleaved has no
idea it is over budget until it already spent it, and the run's own missing-page arithmetic
(``app/crawl/recrawl.py``) compares a truncated URL set against the full known set and
concludes that 3 000 pages vanished.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from enum import StrEnum
from typing import Final

__all__ = [
    "DEFAULT_INDEX_FILENAMES",
    "DEFAULT_MAX_DEPTH",
    "DEFAULT_MAX_PAGES",
    "DEFAULT_SCOPE_MODE",
    "FRONTIER_KEY",
    "FRONTIER_TTL_SECONDS",
    "MAX_DEPTH_CEILING",
    "MAX_PAGES_CEILING",
    "MAX_SITEMAP_ENTRIES",
    "MAX_SITEMAP_NESTING",
    "SEEN_KEY",
    "TRACKING_PARAMETERS",
    "CrawlScope",
    "DiscoveredUrl",
    "DiscoveryResult",
    "ScopeMode",
    "canonicalize",
    "discover",
    "extract_links",
    "in_scope",
    "read_sitemap",
    "registrable_domain",
]


class ScopeMode(StrEnum):
    """How far from the seed a run may wander.

    ``REGISTRABLE_DOMAIN`` needs a public-suffix source to be correct — naive "last two
    labels" logic treats ``example.co.uk`` as the suffix ``co.uk`` and folds every
    ``*.co.uk`` site into one scope. No public-suffix package is in this service's
    dependencies today, so the mode is declared and its resolver is not yet written; picking
    it without that dependency is the bug, not an approximation.
    """

    EXACT_HOST = "exact_host"
    HOST_AND_SUBDOMAINS = "host_and_subdomains"
    REGISTRABLE_DOMAIN = "registrable_domain"


#: The safe default: a run seeded at ``docs.example.com`` stays on ``docs.example.com`` and
#: its subdomains and does not need a public-suffix list to be right.
DEFAULT_SCOPE_MODE: Final[ScopeMode] = ScopeMode.HOST_AND_SUBDOMAINS

#: Per source. A tenant may configure lower; nothing may configure higher, because the
#: ceiling is what bounds one org's share of the `crawl` queue and of the embedding bill.
DEFAULT_MAX_PAGES: Final[int] = 500
MAX_PAGES_CEILING: Final[int] = 5_000

#: Depth 0 is the seed. Three levels reaches the whole of a normal documentation site;
#: beyond the ceiling a link-farm or a calendar widget generates URLs indefinitely.
DEFAULT_MAX_DEPTH: Final[int] = 3
MAX_DEPTH_CEILING: Final[int] = 10

#: Sitemap index files may reference sitemap index files. Unbounded recursion here is a
#: fetch amplifier that costs the target more than it costs us.
MAX_SITEMAP_NESTING: Final[int] = 3

#: Hard stop on entries read across all sitemaps in one run, independent of the page budget:
#: parsing 50 000 entries to then discard 49 500 is work we do inside the 300 s task limit.
MAX_SITEMAP_ENTRIES: Final[int] = 50_000

#: Stripped during canonicalization. Every one of these changes the URL without changing the
#: page, so leaving them in produces one `source_item` per campaign link and re-versions
#: nothing while quadrupling the item set.
TRACKING_PARAMETERS: Final[frozenset[str]] = frozenset(
    {
        "utm_source",
        "utm_medium",
        "utm_campaign",
        "utm_term",
        "utm_content",
        "utm_id",
        "utm_source_platform",
        "utm_creative_format",
        "utm_marketing_tactic",
        "gclid",
        "gbraid",
        "wbraid",
        "dclid",
        "fbclid",
        "msclkid",
        "yclid",
        "igshid",
        "mc_cid",
        "mc_eid",
        "_hsenc",
        "_hsmi",
        "ref",
        "ref_src",
    }
)

#: Folded onto the directory URL during canonicalization, so `/guide/` and `/guide/index.html`
#: are one item rather than two versions of the same text with two citation URLs.
DEFAULT_INDEX_FILENAMES: Final[frozenset[str]] = frozenset(
    {"index.html", "index.htm", "index.php", "default.html", "default.htm"}
)

# ── Valkey keys ──────────────────────────────────────────────────────────────
# Run-scoped and org-scoped, following the platform's key shape: the org is a leading
# segment on everything a tenant owns (`kb-tenancy-isolation`). Both are ordinary keys on
# `valkey-core`, not on the evicting cache instance — an evicted frontier is a run that
# silently indexes a subset and reports success.
#
# These two families are additions to `valkey-keyspaces`, which today names only the
# politeness limiter under the `crawl:` prefix. They are listed here so the shape is in one
# place; the keyspace skill needs the same two rows.
FRONTIER_KEY: Final[str] = "crawl:frontier:{org_id}:{run_id}"
SEEN_KEY: Final[str] = "crawl:seen:{org_id}:{run_id}"

#: Comfortably longer than the longest legitimate run (page budget x per-URL budget) so a
#: crashed run's keys expire on their own, and short enough that they do not accumulate.
FRONTIER_TTL_SECONDS: Final[int] = 86_400


@dataclass(frozen=True, slots=True)
class CrawlScope:
    """The tenant's crawl configuration, already clamped to the ceilings above.

    Clamping happens once, where this object is built, and never at a use site. A scope
    object that still holds a raw tenant number is a budget that depends on which code path
    reads it.
    """

    org_id: str
    source_id: str
    run_id: str
    seed_urls: tuple[str, ...]
    sitemap_url: str | None = None
    mode: ScopeMode = DEFAULT_SCOPE_MODE
    include_globs: tuple[str, ...] = ()
    exclude_globs: tuple[str, ...] = ()
    max_pages: int = DEFAULT_MAX_PAGES
    max_depth: int = DEFAULT_MAX_DEPTH
    respect_robots: bool = True
    user_agent_suffix: str | None = None
    #: Opt-in per source, and approved by an operator — see `app/crawl/render.py`.
    js_render_approved: bool = False


@dataclass(frozen=True, slots=True)
class DiscoveredUrl:
    """One canonical URL and how it was reached."""

    url: str
    depth: int
    #: `sitemap`, `robots_sitemap`, `seed`, `link`. Kept because a URL that appears only via
    #: a link, never in the sitemap, is the case the missing-page policy misreads most often.
    origin: str
    lastmod: str | None = None


@dataclass(frozen=True, slots=True)
class DiscoveryResult:
    """The complete, truncated, deduplicated URL set for one run.

    ``truncated`` is not cosmetic. A truncated set must never be handed to the missing-page
    pass as if it were the site: ``app/crawl/recrawl.py`` refuses to apply any missing policy
    on a run whose discovery was truncated, because "the sitemap got shorter" and "we stopped
    reading at the cap" look identical downstream.
    """

    urls: tuple[DiscoveredUrl, ...] = ()
    truncated: bool = False
    sitemaps_read: tuple[str, ...] = ()
    #: URLs refused during discovery, grouped by `fetch.RejectReason` value. Counted, never
    #: silently discarded — a sitemap full of private addresses is a signal, not noise.
    rejected: dict[str, int] = field(default_factory=dict)


async def discover(scope: CrawlScope) -> DiscoveryResult:
    """Build the run's URL set: robots.txt, then sitemaps, then link discovery.

    Order, and why it is this order:

    1. ``robots.txt`` through ``guarded_get`` (``app/crawl/politeness.py`` parses it). Its
       ``Sitemap:`` lines join the configured sitemap URL.
    2. Every sitemap, recursively to ``MAX_SITEMAP_NESTING``, capped at
       ``MAX_SITEMAP_ENTRIES``.
    3. Only for sources with no usable sitemap: breadth-first link discovery to
       ``max_depth``. BFS after sitemaps, never instead of them — a sitemap is the site's own
       statement of what exists, and BFS on a JS-heavy site finds almost nothing.
    4. Apply include/exclude globs, ``in_scope``, and ``canonicalize``; fold duplicates.
    5. Truncate to ``max_pages`` and set ``truncated``.

    Every fetch in every step is ``guarded_get``. Discovery is where the tenant supplies the
    most URLs and the least scrutiny is applied by default.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def read_sitemap(
    scope: CrawlScope, url: str, *, nesting: int = 0
) -> tuple[DiscoveredUrl, ...]:
    """Read one sitemap or sitemap index through the guarded fetch and parse it with lxml.

    Parsed with entity resolution off. A sitemap is attacker-supplied XML pointed at by an
    attacker-supplied URL, so it is an XXE vector aimed at a container that can reach the
    internal network — the same target as the SSRF, arriving through the parser instead of
    the fetcher.

    A sitemap that references itself, or two that reference each other, terminate on
    ``nesting`` and on the run's seen-set, not on recursion depth in Python.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def extract_links(html: str, base_url: str, scope: CrawlScope) -> tuple[str, ...]:
    """Pull in-scope ``<a href>`` targets out of already-fetched HTML.

    Operates on bytes we already hold. It resolves relative references against ``base_url``
    and returns candidates; it never fetches, and nothing it returns is fetched without
    passing back through the full validation pipeline — a link is a tenant-supplied URL like
    any other, and "we found it on their own page" is not a provenance that means anything.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def in_scope(url: str, scope: CrawlScope) -> bool:
    """Domain scope, path prefix, and the include/exclude globs, in that order.

    Exclude wins over include. The seed's path is the prefix: a source seeded at
    ``example.com/docs/`` stays under ``/docs/``, because "same host" alone turns a
    documentation crawl into a whole-site crawl on the first navigation link.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def canonicalize(url: str, *, canonical_link: str | None = None) -> str:
    """Reduce a URL to the one form that identifies a ``source_item``.

    Lowercase scheme and host, remove the default port, drop the fragment, strip
    ``TRACKING_PARAMETERS``, order the surviving query parameters, fold
    ``DEFAULT_INDEX_FILENAMES`` onto the directory, and honour a page's own
    ``<link rel="canonical">`` when it stays in scope.

    A ``rel=canonical`` pointing outside the scope is ignored rather than followed: it is
    page content, which is to say attacker-controlled, and following it lets a crawled page
    reassign one of our item identities to a URL the tenant never approved.

    Getting this wrong is expensive in a specific way: two spellings of one page become two
    items, each with its own version history and its own citation URL, and the recrawl
    considers both changed forever because neither is ever the one that was fetched last
    time.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def registrable_domain(hostname: str) -> str:
    """The politeness key's host component (``app/crawl/politeness.py``).

    Returns the eTLD+1 when a public-suffix source is available and the full hostname
    otherwise. Falling back to the full hostname is deliberate and is the safe direction: it
    splits one limiter into several, which is less polite than intended but never *more*
    aggressive than a per-host promise — whereas a wrong suffix guess merges unrelated sites
    onto one bucket and throttles a site nobody is crawling.
    """
    raise NotImplementedError("TODO(crawler-engineer)")
