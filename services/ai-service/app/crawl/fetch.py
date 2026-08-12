"""The guarded fetch — the only place this service opens a socket to a tenant-chosen host.

This is the most security-sensitive module in the AI service. A tenant types a URL into a
form and this file connects to it, from inside our private network. Everything below is the
egress envelope that stands between that form field and Qdrant.

Three facts that are not style preferences, each stated as what breaks:

**1. Crawl4AI never fetches for us.** Every byte arrives through the validated ``httpx``
request in this module and is handed to Crawl4AI as ``raw:<html>`` (``app/crawl/extract.py``,
``app/crawl/render.py``). The moment a tenant URL reaches ``crawler.arun(url=...)``, DNS
resolution, redirect following, and the scheme allow-list all move inside a library that has
its own opinion about ``file://`` and IPv6 transition forms — and every constant in this
module stops being enforced anywhere at all. Not "enforced more weakly": there is no second
place in this service that applies it. The one and only argument shape permitted at an
``arun`` call site is the ``raw:`` prefix, and CI greps for it.

**2. DNS is re-resolved and re-validated after every redirect hop, and the check is on the
resolved ADDRESS, not the hostname.** A hostname is not a destination. ``getaddrinfo`` is
called once per hop, *every* returned record is judged, and the connection is then made to a
member of that validated set as an IP literal — with ``Host:`` and TLS SNI still carrying the
original name so certificate validation stays honest. Skipping the re-resolution is the
classic rebinding path: the attacker's authoritative server answers the validating lookup
with a public address at TTL 0 and the connecting lookup with ``169.254.169.254``, and
nothing in the code is wrong. Skipping it on the *redirect* is the same bug with a shorter
setup — hop 0 is a real site, hop 1 is the metadata service — and it is the case
implementations miss, because the guard is usually written once around the seed URL. Both are
closed here by looping over hops ourselves with ``follow_redirects=False``; if that flag is
ever flipped to ``True``, httpx resolves and fetches the ``Location`` *inside* the client,
after the validator ran, and the entire SSRF test suite still passes.

**3. This worker runs on the ``application`` and ``observability`` networks, and
deliberately NOT on ``data``.** It is the SSRF pivot for the whole platform: it fetches
attacker-chosen URLs from inside the perimeter, which is the Capital One shape exactly.
Qdrant's REST API on ``http://qdrant:6333`` requires no credentials, so a single coerced
``GET`` or ``DELETE`` issued from this container is unauthenticated index destruction with no
audit row and no failed authentication anywhere. The worker reaches its broker only because
``valkey-core`` also joins ``application``; PostgreSQL, Qdrant and SeaweedFS do not, so
results travel to ``ai-api`` over the signed internal API (``app/crawl/tasks.py``) rather
than through a database credential this container would otherwise have to hold. If a change here
appears to need a data-network route, the change is wrong — network membership is the
backstop for every bypass this module cannot see, including Chromium's own subresource
requests, which are issued by the browser process and never consult a Python validator.

The internal callback to ``ai-api`` uses a **different** client, on purpose: it targets a
private address, so the guarded client would refuse it, correctly. What makes that second
client safe is a single invariant — its URL is built from ``Settings`` and a code-defined
path, and **no component of it is ever derived from crawl input**. The day a fetched page can
influence that URL, the two clients have merged and this one is decorative.

Fixture set this module must refuse (docs/17-testing-performance.md §22.5), each mapping to a
``RejectReason``: loopback, RFC 1918, link-local, IPv4-mapped link-local, CGNAT, ``nip.io``
style wildcard DNS, ``metadata.google.internal``, embedded credentials, ``file:`` and
``gopher:``, a host with one public A record beside a private AAAA record, a host that
resolves differently on a second lookup, redirect-to-metadata at hop 1 and at hop 4, a
declared-10 GB gzip body, and a response whose media type is outside the allow-list.
"""

from __future__ import annotations

import ipaddress
from collections.abc import Mapping
from dataclasses import dataclass
from enum import StrEnum
from typing import Final, NoReturn

import httpx

from app.core.errors import ErrorClass, KbError

__all__ = [
    "ALLOWED_MEDIA_TYPES",
    "ALLOWED_PORTS",
    "ALLOWED_SCHEMES",
    "CONNECT_TIMEOUT_SECONDS",
    "DEFAULT_PORTS",
    "DEFAULT_USER_AGENT",
    "DENIED_HOSTNAMES",
    "DENIED_IPV4_NETWORKS",
    "DENIED_IPV6_NETWORKS",
    "FETCH_WALL_CLOCK_SECONDS",
    "MAX_REDIRECT_HOPS",
    "MAX_RESPONSE_BYTES",
    "MAX_URL_LENGTH",
    "METADATA_ADDRESSES",
    "POOL_TIMEOUT_SECONDS",
    "READ_TIMEOUT_SECONDS",
    "REQUEST_HEADER_ALLOW_LIST",
    "WRITE_TIMEOUT_SECONDS",
    "CrawlRejected",
    "FetchResult",
    "RejectReason",
    "ValidatedTarget",
    "build_guarded_client",
    "guarded_get",
    "is_fetchable_address",
    "parse_and_validate",
    "reject",
    "resolve_all_records",
]


# ── the scheme and port envelope ─────────────────────────────────────────────
#: Deny by default. `file:`, `gopher:`, `dict:`, `ftp:`, `data:`, `jar:`, `ldap:` and
#: `netdoc:` are all live SSRF primitives in some client library, so the rule is an
#: allow-list of the two we serve rather than a block-list of the ones anyone remembered.
ALLOWED_SCHEMES: Final[frozenset[str]] = frozenset({"http", "https"})

DEFAULT_PORTS: Final[Mapping[str, int]] = {"http": 80, "https": 443}

#: Defence in depth behind the address check, not instead of it. Public sites live on 80 and
#: 443; every interesting internal target does not — Qdrant 6333/6334, PostgreSQL 5432,
#: Valkey 6379, SeaweedFS 8333, ai-api 8000, the Traefik dashboard 8080. Widening this set
#: is a review decision, because each added port is an internal service that a future
#: address-check regression would then reach.
ALLOWED_PORTS: Final[frozenset[int]] = frozenset({80, 443})

#: Checked on the *response* `Content-Type`, parameters stripped and lowercased — never on
#: the URL extension, because `https://x/report.pdf` may answer `text/html` and a sitemap is
#: routinely served as either XML type.
#:
#: Gzipped sitemaps (`application/gzip`, `sitemap.xml.gz`) are deliberately absent: accepting
#: them means decompressing an attacker-controlled archive inside this worker, and the
#: uncompressed-size cap that would make that safe belongs to the upload path, not here.
ALLOWED_MEDIA_TYPES: Final[frozenset[str]] = frozenset(
    {
        "text/html",
        "application/xhtml+xml",
        "text/xml",
        "application/xml",
        "text/plain",
    }
)

#: Outbound request headers are an allow-list too. A crawl source may configure its
#: user agent (docs/03 §8.12); without this list, a configurable header set is an
#: `Authorization:` header aimed at an internal service one address-check bug away.
REQUEST_HEADER_ALLOW_LIST: Final[frozenset[str]] = frozenset(
    {
        "host",
        "user-agent",
        "accept",
        "accept-encoding",
        "accept-language",
        "if-none-match",
        "if-modified-since",
    }
)

#: Identifies us in the target's logs and gives robots.txt authors a token to name. A
#: source-configured agent string is appended to this, never substituted for it — a crawler
#: that cannot be addressed by a robots rule is an anonymous one.
DEFAULT_USER_AGENT: Final[str] = "KnowledgeBotAI-Crawler/1.0 (+https://knowledgebot.ai/crawler)"


# ── the response envelope ────────────────────────────────────────────────────
#: Enforced on DECOMPRESSED bytes while streaming. `Content-Length` is attacker-controlled
#: and frequently absent, and a 1 MB gzip body declaring 10 GB is the whole point of counting
#: as we read rather than trusting a header.
MAX_RESPONSE_BYTES: Final[int] = 10 << 20  # 10 MiB

#: Hops, not requests: hop 0 is the seed. Every hop re-runs the full validation pipeline.
MAX_REDIRECT_HOPS: Final[int] = 5

MAX_URL_LENGTH: Final[int] = 2048

#: Wall clock for the whole fetch including all hops, enforced with an outer `wait_for`.
#: Per-phase timeouts alone do not bound a server that dribbles one byte per second: each
#: individual read lands inside `READ_TIMEOUT_SECONDS` and the worker is held forever.
#: `app/crawl/tasks.py` budgets against this number, and §20.2 tracks fetch latency on it.
FETCH_WALL_CLOCK_SECONDS: Final[float] = 20.0

CONNECT_TIMEOUT_SECONDS: Final[float] = 5.0
READ_TIMEOUT_SECONDS: Final[float] = 10.0
WRITE_TIMEOUT_SECONDS: Final[float] = 5.0
POOL_TIMEOUT_SECONDS: Final[float] = 5.0


# ── the address envelope ─────────────────────────────────────────────────────
# READ THIS BEFORE USING THE TUPLES BELOW.
#
# The rule in code is `ipaddress.ip_address(a).is_global` on every resolved record, after
# unwrapping `.ipv4_mapped`. It is NOT membership of these tuples. Iterating a hand-written
# range list is how CGNAT gets through (`100.64.1.1` reports `is_private=False` AND
# `is_global=False` — they are not complements, CVE-2024-4032) and how `::ffff:169.254.169.254`
# gets through (it reports `is_link_local=False`, because link-local is an IPv4 property and
# the object is IPv6).
#
# These tuples exist for three other jobs, all of them real:
#   * the egress firewall / forward-proxy rules on `ai-worker-crawl`, which are written in
#     iptables or proxy config and cannot call `is_global`;
#   * the SSRF fixture set, so a test asserts a representative of each range is refused;
#   * documentation of what "not globally routable" actually covers, for the next reader.
#
# Requires CPython >= 3.12.4 (pinned in pyproject): below it, `is_global` and `is_private`
# disagree with the IANA special-purpose registries and this entire check mis-classifies
# addresses with nothing in any test suite noticing.
#
# Each entry is built with the FAMILY-SPECIFIC constructor — `IPv4Network(c)` / `IPv6Network(c)`,
# never `ip_network(c)`. `ip_network` dispatches on the string, so a v6 CIDR pasted into the v4
# tuple would be accepted at import and then never match an IPv4 address: a silent hole in a
# deny-list, in the one place where a missing range is the whole failure. The explicit
# constructor turns that paste into a `ValueError` the moment this module is imported — which,
# because the worker imports it at startup, is a crash rather than a quiet mis-classification.
# It also gives each tuple its declared element type without a cast.
DENIED_IPV4_NETWORKS: Final[tuple[ipaddress.IPv4Network, ...]] = tuple(
    ipaddress.IPv4Network(c)
    for c in (
        "0.0.0.0/8",  # "this network"
        "10.0.0.0/8",  # RFC 1918
        "100.64.0.0/10",  # RFC 6598 CGNAT — the range a `is_private` check lets through
        "127.0.0.0/8",  # loopback
        "169.254.0.0/16",  # link-local, and every cloud metadata endpoint
        "172.16.0.0/12",  # RFC 1918
        "192.0.0.0/24",  # IETF protocol assignments
        "192.0.2.0/24",  # TEST-NET-1
        "192.88.99.0/24",  # 6to4 relay anycast
        "192.168.0.0/16",  # RFC 1918
        "198.18.0.0/15",  # benchmarking
        "198.51.100.0/24",  # TEST-NET-2
        "203.0.113.0/24",  # TEST-NET-3
        "224.0.0.0/4",  # multicast
        "240.0.0.0/4",  # reserved
        "255.255.255.255/32",  # broadcast
    )
)

DENIED_IPV6_NETWORKS: Final[tuple[ipaddress.IPv6Network, ...]] = tuple(
    ipaddress.IPv6Network(c)
    for c in (
        "::/128",  # unspecified
        "::1/128",  # loopback
        "::ffff:0:0/96",  # IPv4-mapped — judged as its embedded IPv4 address
        "64:ff9b:1::/48",  # local-use NAT64
        "100::/64",  # discard-only
        "2001::/23",  # IETF protocol assignments, incl. Teredo
        "2001:db8::/32",  # documentation
        "2002::/16",  # 6to4
        "fc00::/7",  # unique local
        "fe80::/10",  # link-local
        "ff00::/8",  # multicast
    )
)

#: Already covered by the address rule. Listed so the SSRF fixture set can name them and so
#: an operator reading an alert recognises the target.
METADATA_ADDRESSES: Final[tuple[str, ...]] = (
    "169.254.169.254",  # AWS / GCP / Azure / DigitalOcean / Oracle IMDS
    "169.254.170.2",  # AWS ECS task metadata
    "fd00:ec2::254",  # AWS IMDS over IPv6
    "100.100.100.200",  # Alibaba Cloud
)

#: Hostname-shaped filtering CANNOT work — `10.0.0.1.nip.io` is an ordinary name that
#: resolves to RFC 1918, and the address check is what refuses it. These names are refused
#: earlier only so the rejection reads clearly in a log line; removing this tuple must not
#: change any outcome, and a test asserts exactly that.
DENIED_HOSTNAMES: Final[frozenset[str]] = frozenset(
    {
        "metadata.google.internal",
        "metadata",
        "localhost",
        "qdrant",
        "postgres",
        "valkey-core",
        "valkey-cache",
        "seaweedfs-s3",
        "ai-api",
    }
)


class RejectReason(StrEnum):
    """Why the guard refused, and the ``reason`` label on ``kb_crawl_robots_blocked_total``.

    The enum is **eight** values, exactly as the metric catalog lists them. A rejection whose
    reason is not in this set increments no counter at all: nothing errors, the fetch is
    still refused, the log line still exists, and the dashboard simply shows fewer blocks
    than happened — so a tenant probing ``file://`` or a rebinding host reads as zero
    traffic. A ninth reason is a metric-catalog change first, never a new string invented at
    a call site.
    """

    ROBOTS = "robots"
    SCHEME = "scheme"
    CREDENTIALS = "credentials"
    DNS = "dns"
    PRIVATE_IP = "private_ip"
    REDIRECT = "redirect"
    CONTENT_TYPE = "content_type"
    SIZE = "size"


class CrawlRejected(KbError):
    """The guard refused this URL. Never retryable, on any reason.

    All eight reasons describe the URL or the response — not the network — so a retry
    re-runs identical inputs through identical checks and produces an identical refusal,
    three times, with an exponential wait in between. A genuine transport failure (resolver
    outage, connection reset, read timeout) is a plain ``KbError(CRAWL)`` raised by the
    transport layer, which *is* retryable; the two must not be conflated, because doing so
    either retries an SSRF probe or gives up on a flapping origin after one attempt.
    """

    __slots__ = ("reason",)

    def __init__(self, reason: RejectReason, detail: str = "") -> None:
        super().__init__(
            ErrorClass.CRAWL,
            f"crawl target refused: {reason.value}",
            retryable=False,
        )
        #: The metric label. Never carries the host or the URL — a tenant-controlled target
        #: set is an unbounded label dimension, and `detail` is for the log line only.
        self.reason = reason
        self.detail = detail


def reject(reason: RejectReason, detail: str = "") -> NoReturn:
    """Refuse, count, and raise — the single exit for every refusal in this package.

    One function so that emitting ``kb_crawl_robots_blocked_total{reason}`` cannot be
    forgotten at a call site. A ``raise CrawlRejected(...)`` written inline is a rejection
    that never reaches a dashboard.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


@dataclass(frozen=True, slots=True)
class ValidatedTarget:
    """A URL that has passed all seven validation steps, plus the addresses it passed with.

    ``connect_address`` is a member of ``addresses``, and ``addresses`` is the result of the
    *one* resolution that validated it. Re-resolving between this object and the connection
    re-opens the rebinding gap that constructing it closed, so nothing downstream may call
    ``getaddrinfo`` on ``hostname`` again.
    """

    scheme: str
    hostname: str
    port: int
    path: str
    query: str
    #: Re-serialized from the parsed parts. If this differs from what would actually be
    #: fetched, the URL is refused — that divergence is the entire parser-confusion bug class.
    normalized_url: str
    #: Every record returned by the single resolution, all of them validated.
    addresses: tuple[str, ...]
    connect_address: str


@dataclass(frozen=True, slots=True)
class FetchResult:
    """What one successful guarded fetch produced.

    ``final_url`` is the *name* URL after redirects, never the pinned IP form — it is what a
    citation shows and what canonicalization and scope checks operate on. ``body`` is
    decompressed bytes, already capped.
    """

    requested_url: str
    final_url: str
    status_code: int
    headers: Mapping[str, str]
    body: bytes
    media_type: str
    #: For the conditional refetch on the next run. Stored on the source item, never on the
    #: version (`kb-source-lifecycle`).
    etag: str | None
    last_modified: str | None
    redirect_hops: int
    elapsed_seconds: float
    #: Which validated address answered. Logged, never used as a cache key and never shown
    #: to a tenant.
    connect_address: str


def build_guarded_client() -> httpx.AsyncClient:
    """Construct the one client this package fetches with.

    ``follow_redirects=False`` is the load-bearing argument and the single most common way
    this whole module is defeated: with it True, httpx resolves and fetches the ``Location``
    internally, after the validator ran, and the SSRF suite stays green while production
    reads the metadata service. HTTP/2 is off because a multiplexed connection outlives the
    single-address pin it was validated for.

    One client per worker child, built inside ``worker_process_init`` — a connection pool
    created before ``fork()`` hands every child the same sockets.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def parse_and_validate(raw_url: str) -> ValidatedTarget:
    """Steps 1-6: parse, re-serialize, check scheme, port, credentials and host, resolve
    once, validate every record, and pick the address to connect to.

    Parsing is ``urllib.parse.urlsplit`` and never a regular expression. ``0177.0.0.1``,
    ``2130706433``, ``0x7f.1`` and ``127.1`` are all loopback to some resolvers; the parser
    refuses most of them outright, which is exactly why parsing beats matching.

    Embedded credentials are refused as a form, not sanitized: ``https://metadata.google
    .internal@evil.com/`` and its inverse parse to different hosts in different libraries,
    and refusing the shape removes the ambiguity rather than picking a side.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def resolve_all_records(hostname: str, port: int) -> tuple[str, ...]:
    """Resolve exactly once and return every address, so every one can be judged.

    ``socket.getaddrinfo`` returns a list. A name with one public A record beside a private
    AAAA record is an attack, not a misconfiguration, and validating only ``addrs[0]`` means
    the outcome depends on the resolver's ordering — which the attacker also controls.

    The caller connects to a member of *this* tuple. That is the only reason one resolution
    is enough.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def is_fetchable_address(addr: str) -> bool:
    """``ip.is_global`` after unwrapping ``.ipv4_mapped``, and nothing else.

    Do not write ``if ip.is_private: refuse``. ``is_private`` and ``is_global`` are not
    complements: ``100.64.1.1`` reports False for both, so a private-flag check fetches it.
    Do not enumerate flags either: ``::ffff:169.254.169.254`` reports ``is_link_local=False``
    because the object is IPv6 and link-local is an IPv4 property — unwrap first, judge
    second.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def guarded_get(
    client: httpx.AsyncClient,
    url: str,
    *,
    headers: Mapping[str, str] | None = None,
    budget_seconds: float = FETCH_WALL_CLOCK_SECONDS,
) -> FetchResult:
    """Fetch one URL under the full envelope. **Every** outbound tenant-directed request in
    this package goes through this function — no exceptions, and specifically not for
    robots.txt, not for a sitemap, not for a redirect target, and not in a test helper.

    The hop loop, in order, repeated in full for each hop up to ``MAX_REDIRECT_HOPS``:

    1. ``parse_and_validate`` the current URL — scheme, port, credentials, host, resolve,
       validate every record.
    2. Build the request against the validated **IP literal**, with ``Host:`` and the
       ``sni_hostname`` extension both set to the original name, so the certificate is still
       checked against the name. Pinning without the SNI extension trades SSRF for MITM.
    3. Send with ``stream=True`` and no client-side redirect following.
    4. On a 3xx: close the response, resolve ``Location`` against the **name** URL rather
       than the pinned one, and start again at step 1. A hop that changes scheme outside the
       allow-list, introduces credentials, or leaves the source's declared domain scope is
       refused here.
    5. On a final response: check the parsed media type against ``ALLOWED_MEDIA_TYPES``,
       then stream the body counting decompressed bytes and abort at ``MAX_RESPONSE_BYTES``.

    Running out of hops is ``RejectReason.REDIRECT``. The whole call sits inside an outer
    ``asyncio.wait_for(budget_seconds)`` because per-phase timeouts do not compose into a
    wall clock.

    Politeness is the caller's: a slot on the per-host limiter is held across this call
    (``app/crawl/politeness.py``), never taken inside it, or a retry loop here would bypass
    the limiter that a slow origin depends on.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


# Every reason has a place in the metric catalog's closed enum, checked at import rather
# than in a test: a rejection that reaches a counter with an unknown label value is a
# rejection nobody sees.
assert len(RejectReason) == 8
