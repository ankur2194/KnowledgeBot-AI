"""Being a well-behaved client: robots.txt, crawl delay, and the concurrency caps.

**Politeness is per HOST, not per source and not per org.** Two tenants who both crawl
``example.com`` share one limiter. If the limiter carries an org segment, ten orgs crawling
the same popular documentation site produce ten independent "one request per second"
promises and the site receives ten requests per second from one IP range — which is the
definition of the abusive client, and the reason we would be blocked at their edge rather
than merely throttled. The host is the party being protected, so the host is the key.

**Quota is per ORG, and it is a different limiter with a different purpose.** The per-org
concurrency cap exists so one tenant's 50 000-URL sitemap cannot hold every slot on the
``crawl`` queue while every other tenant's crawl waits. Merging the two — one key, one
number — means either the host limiter starves tenants or the org limiter overruns hosts.
Both are taken, in that order, around every fetch.

Both live in Valkey and nowhere else. ``RateLimiter`` and ``MemoryAdaptiveDispatcher`` from
Crawl4AI throttle within one Python process; the ``crawl`` queue runs prefork ``-c 8`` per
container across N containers, so an in-process limiter is 8N independent limiters, which is
no limiter at all. The symptom is a target that throttles us while our own dashboard shows
the configured delay being honoured perfectly.

robots.txt is fetched through ``app/crawl/fetch.guarded_get`` like everything else, and
``check_robots_txt`` stays ``False`` in every ``CrawlerRunConfig``: setting it True makes
Crawl4AI fetch robots itself, unvalidated, into a per-container SQLite cache — an unguarded
fetch of a tenant-supplied URL, which is the one thing this package exists to prevent.
"""

from __future__ import annotations

from collections.abc import Mapping
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Final

__all__ = [
    "CRAWL_DELAY_CEILING_SECONDS",
    "DEFAULT_CRAWL_DELAY_SECONDS",
    "HOST_LIMITER_KEY",
    "HOST_SLOT_KEY",
    "MAX_CONCURRENT_PER_HOST",
    "MAX_CONCURRENT_PER_ORG",
    "MIN_CRAWL_DELAY_SECONDS",
    "ORG_SLOT_KEY",
    "ROBOTS_CACHE_KEY",
    "ROBOTS_CACHE_TTL_SECONDS",
    "ROBOTS_MAX_BYTES",
    "ROBOTS_PATH",
    "ROBOTS_UNREACHABLE_TTL_SECONDS",
    "SLOT_TTL_SECONDS",
    "RobotsPolicy",
    "RobotsRules",
    "Slot",
    "acquire_host_slot",
    "acquire_org_slot",
    "effective_delay",
    "host_limiter_key",
    "is_allowed",
    "load_robots",
]


# ── delay ────────────────────────────────────────────────────────────────────
#: Used when the source configures nothing and robots.txt declares nothing. One request per
#: second per host is the conventional floor for an unannounced crawler.
DEFAULT_CRAWL_DELAY_SECONDS: Final[float] = 1.0

#: A configured delay below this is clamped up. A tenant may not configure zero: the target
#: has no say in our configuration form, and an unthrottled crawl of a small origin is
#: indistinguishable from a denial-of-service against it.
MIN_CRAWL_DELAY_SECONDS: Final[float] = 0.5

#: A robots.txt declaring `Crawl-delay: 3600` means a 500-page site takes three weeks. We
#: honour the declaration up to this ceiling; beyond it the run does not quietly speed up —
#: it ends with a "site declines to be crawled at a usable rate" outcome for an operator to
#: read. Speeding up past a declared delay is the one failure here that damages someone
#: else's service rather than ours.
CRAWL_DELAY_CEILING_SECONDS: Final[float] = 30.0


# ── concurrency ──────────────────────────────────────────────────────────────
#: Simultaneous in-flight requests to one host, across every worker child, container, and
#: tenant. Two, not one, so a single slow page does not serialize an entire site — and not
#: eight, because the delay above is meaningless if eight requests leave together.
MAX_CONCURRENT_PER_HOST: Final[int] = 2

#: Simultaneous in-flight crawl requests for one organization, across all its sources and
#: hosts. This is the fairness cap: it is what stops one tenant's enormous sitemap from
#: occupying every prefork child on every crawl container.
MAX_CONCURRENT_PER_ORG: Final[int] = 8


# ── robots ───────────────────────────────────────────────────────────────────
ROBOTS_PATH: Final[str] = "/robots.txt"

#: RFC 9309 requires parsers to process at least 500 KiB. Beyond that we parse what we have
#: and continue rather than refusing the site: a robots.txt larger than this is a broken
#: origin, and treating it as "disallow everything" disables a site over a formatting bug.
ROBOTS_MAX_BYTES: Final[int] = 512 * 1024

#: One robots fetch per host per hour, shared by every org — the same sharing argument as
#: the limiter. Re-reading robots.txt before each of 500 pages doubles our request volume
#: against the target for no new information.
ROBOTS_CACHE_TTL_SECONDS: Final[int] = 3_600

#: A 5xx or a timeout on robots.txt is cached only briefly, because the resulting posture is
#: "refuse the whole site" and a five-minute origin blip must not disable a source for an
#: hour.
ROBOTS_UNREACHABLE_TTL_SECONDS: Final[int] = 300


# ── Valkey keys ──────────────────────────────────────────────────────────────
# `crawl:rl:` is the existing family in `valkey-keyspaces` / `crawl4ai-crawler`. The
# deviation, stated plainly so review sees it: the skill writes
# `crawl:rl:{org_id}:{registrable_domain}`, and this module drops the org segment, because
# an org segment gives every tenant its own private promise to the same host. The org is not
# absent from the system — it owns `ORG_SLOT_KEY` below, a *different* limiter measuring a
# different thing. Putting the org back into the host key needs the paragraph at the top of
# this file answered first.
HOST_LIMITER_KEY: Final[str] = "crawl:rl:{registrable_domain}"
HOST_SLOT_KEY: Final[str] = "crawl:conc:host:{registrable_domain}"
ORG_SLOT_KEY: Final[str] = "crawl:conc:org:{org_id}"
ROBOTS_CACHE_KEY: Final[str] = "crawl:robots:{registrable_domain}"

#: Every slot carries a TTL longer than the per-URL wall clock and shorter than the task's
#: soft limit. A worker SIGKILLed mid-fetch cannot release its slot, so an untimed slot leaks
#: capacity permanently: the host limiter drifts toward zero over days and every crawl of
#: that site hangs, with no error anywhere and nothing to inspect but a stuck integer.
SLOT_TTL_SECONDS: Final[int] = 60

# All four keys live on `valkey-core`, never on the evicting cache instance. An evicted
# limiter fails open — the crawl proceeds at full speed against a site that asked us not to,
# and nothing in this service observes that it happened.


class RobotsPolicy(StrEnum):
    """What a robots.txt fetch outcome means for the run.

    RFC 9309 distinguishes *unavailable* from *unreachable*, and conflating them is the
    common bug in both directions:

    * ``ALLOW_ALL`` — 4xx. The file is unavailable, which the RFC reads as full allow. A
      crawler that refuses a site because robots.txt returned 404 will not crawl most of
      the web.
    * ``DENY_ALL`` — 5xx, timeout, or a ``CrawlRejected``. The file is unreachable, so the
      site's wishes are unknown and we assume the most restrictive reading. This is why the
      unreachable TTL is short: the posture is severe and the cause is usually temporary.
    * ``PARSED`` — 2xx. Rules apply as written.

    A source may switch robots handling off entirely (docs/03 §8.12 makes it configurable).
    That defaults on, is an operator decision recorded on the source, and is never a per-run
    flag and never a fallback when parsing fails.
    """

    ALLOW_ALL = "allow_all"
    DENY_ALL = "deny_all"
    PARSED = "parsed"


@dataclass(frozen=True, slots=True)
class RobotsRules:
    """One host's parsed robots.txt, as cached.

    ``crawl_delay`` is separate from the parsed rule set because ``Crawl-delay`` is not in
    RFC 9309 and ``urllib.robotparser`` does not surface it for every agent form. A crawler
    that reads only the standard parser silently ignores the one directive the site wrote
    specifically to slow it down.
    """

    policy: RobotsPolicy
    #: Agent-matched allow/disallow rules, in source order. Empty for ALLOW_ALL/DENY_ALL.
    rules: tuple[tuple[str, bool], ...] = ()
    crawl_delay: float | None = None
    sitemap_urls: tuple[str, ...] = ()
    fetched_at: float = 0.0


@dataclass(frozen=True, slots=True)
class Slot:
    """A held concurrency slot. Released by its async context manager on every path,
    including the failure path — a fetch that raised still consumed the host's attention."""

    key: str
    token: str
    expires_at: float
    labels: Mapping[str, str] = field(default_factory=dict)


def effective_delay(configured: float | None, robots_declared: float | None) -> float:
    """The slower of the two, clamped into ``[MIN_CRAWL_DELAY_SECONDS, ceiling]``.

    **The slower, i.e. ``max()``.** ``crawl4ai-crawler`` phrases this as "refilled at
    ``min(configured_delay, robots Crawl-delay)``", which read as two *delays* means the
    faster one and would let a tenant configure 0.5 s past a site's declared 10 s; read as a
    refill *rate* the same words mean the slower one. The prose is ambiguous, and the
    ambiguity resolves in favour of the target: whichever party asked us to go slower wins.
    This deviation from the skill's literal wording is deliberate and wants a skill edit
    rather than a silent reversal here.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def host_limiter_key(hostname: str) -> str:
    """Format ``HOST_LIMITER_KEY`` from a hostname via ``discovery.registrable_domain``.

    Exists so the key is composed in exactly one place. A limiter keyed on the full hostname
    at one call site and on the registrable domain at another is two limiters, both of which
    look correct in isolation.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def load_robots(hostname: str, *, user_agent: str) -> RobotsRules:
    """Fetch ``/robots.txt`` for a host through ``guarded_get``, parse it, and cache it.

    The fetch is subject to every rule in ``fetch.py`` — scheme, port, address validation,
    redirect revalidation, byte cap. "It is only robots.txt" is not an exemption: the URL is
    still built from a tenant-supplied hostname, and a redirect from it is still a redirect
    to wherever the origin says.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def is_allowed(rules: RobotsRules, url: str, user_agent: str) -> bool:
    """Whether robots.txt permits this URL for this agent.

    A refusal here is ``RejectReason.ROBOTS`` and a page disposition of ``skipped`` — never
    ``failed`` and never ``missing``. The distinction matters to the missing-page pass: a
    page we were asked not to fetch has not gone away, and counting it as missing disables a
    tenant's source because the site owner added a ``Disallow`` line.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def acquire_host_slot(hostname: str, delay: float) -> Slot:
    """Wait for this host's turn, then hold one of its ``MAX_CONCURRENT_PER_HOST`` slots.

    An async context manager. Waiting happens here rather than inside ``guarded_get`` so the
    fetch's 20 s wall clock measures the fetch and not the queueing. A task that cannot get a
    slot within its remaining budget re-queues with a countdown instead of blocking a prefork
    child — a blocked child is a slot the whole queue loses, not just this tenant.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def acquire_org_slot(org_id: str) -> Slot:
    """Hold one of the org's ``MAX_CONCURRENT_PER_ORG`` slots.

    Taken **outside** the host slot, always in that order, at every call site. Inconsistent
    ordering deadlocks two orgs crawling two hosts, and it surfaces as a ``crawl`` queue that
    stops draining rather than as any error at all.
    """
    raise NotImplementedError("TODO(crawler-engineer)")
