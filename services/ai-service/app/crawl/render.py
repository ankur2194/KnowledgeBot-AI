"""The JS-render policy: when a page is executed rather than merely fetched.

Rendering is the expensive path and the dangerous one, and the two facts are related.

**Expensive:** roughly 20x a plain fetch in wall clock and ~100x in memory. A container
sized for "we mostly do not render" OOMs at ``-c 8`` the first night a tenant enables it on
a large source.

**Dangerous:** a headless browser executing attacker-supplied JavaScript is a far larger
attack surface than an HTTP client reading bytes. Once a page is loaded, its ``<img>``,
``<iframe>``, ``<link>``, ``fetch()`` and XHR requests are issued by the *Chromium process*
and never reach ``app/crawl/fetch.py``'s validator — so a crawled page can probe the internal
network from inside our container regardless of anything Python does. ``page.route`` with
the same address check aborts most of it and is worth wiring, but it is best-effort, not a
boundary. The boundary is the worker's network membership: ``ai-worker-crawl`` is on
``application`` and ``observability`` and **not** on ``data``, which is why a coerced
subresource request cannot reach Qdrant, PostgreSQL or SeaweedFS at all.

**Playwright and Chromium exist only in the ``runtime-crawl`` image stage.** ``ai-api`` and
every other worker image install neither. That is not an image-size optimisation: it means a
browser cannot be launched from a container that *does* sit on the ``data`` network, so the
one process that can execute tenant JavaScript is also the one process with no route to the
datastores. Adding ``crawl4ai-setup`` to another stage quietly removes that property.

So: **rendering is off by default and opt-in per source, with operator approval.** The
auto-escalation below fires at most once per item and only for an approved source. Escalating
on an unapproved source is the difference between fetching a document and executing a
tenant-supplied program inside our network.
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum
from typing import Final

__all__ = [
    "ACCEPT_DOWNLOADS",
    "BLOCKED_RESOURCE_TYPES",
    "JS_ESCALATE_MIN_WORDS",
    "JS_RENDER_DEFAULT_ENABLED",
    "MAX_ESCALATIONS_PER_ITEM",
    "RENDER_BUDGET_SECONDS",
    "RENDER_MAX_PAGES_PER_RUN",
    "RENDER_MAX_SCROLLS",
    "RENDER_NAVIGATION_TIMEOUT_SECONDS",
    "RENDER_SETTLE_SECONDS",
    "TENANT_CONFIGURABLE_BROWSER_FIELDS",
    "RenderDecision",
    "RenderMode",
    "RenderedPage",
    "get_crawler",
    "render_html",
    "route_guard",
    "should_render",
]


#: Off. A source turns it on; nothing turns it on for a source.
JS_RENDER_DEFAULT_ENABLED: Final[bool] = False

#: Auto-escalation threshold. A plain fetch that yields fewer than this many words of
#: markdown looks like a client-rendered shell — but only an approved source escalates, and
#: only once. Set too high and every short page burns a browser launch; set too low and a
#: genuinely short page (a 200-word policy notice) is indexed empty.
JS_ESCALATE_MIN_WORDS: Final[int] = 300

#: Exactly one. A second escalation on the same item is a loop: the render produces the same
#: near-empty markdown, which trips the same threshold.
MAX_ESCALATIONS_PER_ITEM: Final[int] = 1

#: Wall clock for one render, including navigation, settle and scroll. Kept well under the
#: task's 300 s soft limit so one task is always several items' worth of headroom — a render
#: that eats the whole task budget means the task is SIGKILLed with nothing checkpointed and
#: the items it already finished are re-fetched on redelivery.
RENDER_BUDGET_SECONDS: Final[float] = 60.0

RENDER_NAVIGATION_TIMEOUT_SECONDS: Final[float] = 30.0

#: Quiet period after load before the DOM is read. Not `networkidle`: an analytics beacon or
#: a long-poll keeps a page from ever reaching idle, and the render then always spends its
#: full budget.
RENDER_SETTLE_SECONDS: Final[float] = 2.0

#: Lazy-loaded content needs scrolling; an infinite-scroll feed needs a stop.
RENDER_MAX_SCROLLS: Final[int] = 5

#: Per run, across the whole source. A 500-page source that escalates every page is 500
#: browser navigations inside a queue shared by every tenant; past this cap the remaining
#: pages are indexed from their plain fetch and the run reports the shortfall.
RENDER_MAX_PAGES_PER_RUN: Final[int] = 50

#: Aborted in `route_guard` before they leave the browser. None of them contributes text we
#: index, and each is an outbound request that our validator does not see.
BLOCKED_RESOURCE_TYPES: Final[frozenset[str]] = frozenset(
    {"image", "media", "font", "websocket", "eventsource", "manifest"}
)

#: Empty, and it stays empty. GHSA-r253-r9jw-qg44 (critical, 2026) is Chromium
#: launch-argument injection through `BrowserConfig.extra_args`; the same argument applies to
#: `proxy_config`, `js_code` and `hooks`. Every one of those fields is code-defined and
#: constant, so no tenant string can reach a browser launch line. A crawl source's
#: configuration form is a tenant input surface, and this frozenset is the statement that
#: none of it is forwarded.
TENANT_CONFIGURABLE_BROWSER_FIELDS: Final[frozenset[str]] = frozenset()

#: GHSA-2jq4-q6vv-4cp3 is path traversal through Crawl4AI's download path. We index text; we
#: have no use for a downloaded file, so the feature stays off rather than being sandboxed.
ACCEPT_DOWNLOADS: Final[bool] = False


class RenderMode(StrEnum):
    """Recorded on the version, so a citation's provenance says how its text was obtained.

    Without it, a page that silently began rendering (or silently stopped) changes content
    with no visible cause, and the resulting version churn has no explanation in any record.
    """

    FETCH = "fetch"
    RENDER = "render"


@dataclass(frozen=True, slots=True)
class RenderDecision:
    """Whether to render, and the reason — always both.

    ``reason`` is what an operator reads when a source's cost triples. "It rendered" is not
    an answer; "it escalated on 41 of 500 pages below the word threshold" is.
    """

    render: bool
    reason: str
    escalated: bool = False


@dataclass(frozen=True, slots=True)
class RenderedPage:
    """HTML produced by the browser, ready to be handed to extraction as ``raw:``.

    ``blocked_subresources`` is the interception count, kept because a page that tried 40
    internal-looking requests is a security signal even though every one was aborted.
    """

    html: str
    final_url: str
    mode: RenderMode
    elapsed_seconds: float
    scrolls: int
    blocked_subresources: int


def should_render(
    *,
    js_render_approved: bool,
    already_escalated: bool,
    word_total: int,
    rendered_this_run: int,
) -> RenderDecision:
    """The whole policy, in one place.

    Renders when the source is approved and either configured to render outright or the plain
    fetch produced fewer than ``JS_ESCALATE_MIN_WORDS`` words. Refuses when the source is not
    approved — unconditionally, before any other condition is examined, because every other
    input to this function is derived from a page we just fetched from a host the tenant
    chose.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def get_crawler() -> object:
    """The one ``AsyncWebCrawler`` for this prefork child, created lazily on first use.

    Created **inside** the child, never in the parent: a Playwright/Chromium handle made
    before ``fork()`` is unusable in the child, and the failure is an obscure transport error
    rather than anything naming the fork. One per child rather than one per task because
    ``AsyncWebCrawler.__aenter__`` launches the browser eagerly — even for ``raw:`` input,
    which never navigates — so a per-task instance pays a browser launch for every page.

    The child owns **one persistent event loop**, created in ``worker_process_init`` and
    driven with ``loop.run_until_complete``. ``asyncio.run()`` closes the loop it created, and
    the crawler's Playwright objects are bound to it: the second task in the child then fails
    with "Event loop is closed" or "Future attached to a different loop".

    NOTHING CURRENTLY BOUNDS HOW LONG A LEAKED BROWSER CONTEXT LIVES, and this paragraph
    asserted for one revision that something did — "``worker_max_tasks_per_child=100`` on the
    ``crawl`` queue". It is set nowhere: not in ``app/worker/config.py``, not in the
    ``ai-worker-crawl`` command line (``celery -A app.worker worker -Q crawl -c 8
    --pool=prefork``), not in ``env/``. The `celery-workers` and `crawl4ai-crawler` skills both
    specify the value, so it is owed rather than rejected — but it is the worker's owner to set,
    on the ``crawl`` queue alone, since the parsing queues deliberately leave it unset and bound
    memory with ``worker_max_memory_per_child`` instead. Until then a child here lives for the
    life of the container, and ``compose.yaml`` gives ``ai-worker-crawl`` a 4G limit with no
    ``worker_max_memory_per_child`` either, so the only backstop is the OOM killer plus
    ``task_reject_on_worker_lost``.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def route_guard(route: object) -> None:
    """Playwright request interception: abort what the validator would have refused.

    Runs ``fetch.parse_and_validate`` on every browser-initiated request and aborts anything
    it rejects, plus everything in ``BLOCKED_RESOURCE_TYPES``. Treat it as harm reduction: it
    is not confirmed to intercept every browser-initiated subresource type, it cannot see a
    request made by a plugin or a protocol handler, and it is a Python callback in the way of
    a browser that is executing hostile code. The network is the boundary; this is the layer
    above it.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


async def render_html(url: str, *, budget_seconds: float = RENDER_BUDGET_SECONDS) -> RenderedPage:
    """Navigate to a URL in the browser and return its DOM.

    This is the one place in the package where a URL is handed to something that resolves it
    itself, and it is unavoidable — the browser has to navigate. Everything that makes it
    survivable is outside this function:

    * the URL has already passed ``parse_and_validate`` at fetch time, on this run;
    * the source is approved for rendering by an operator;
    * subresources are intercepted by ``route_guard``;
    * the container has no route to the ``data`` network.

    The DOM this returns is handed to ``app/crawl/extract.py`` as ``raw:<html>`` exactly like
    a fetched body. Rendering changes how the bytes were obtained; it changes nothing about
    how they are treated afterwards, and they remain hostile data.
    """
    raise NotImplementedError("TODO(crawler-engineer)")
